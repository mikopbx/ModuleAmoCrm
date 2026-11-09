<?php

declare(strict_types=1);

// #57: отказ getContactsData в AmoCdrDaemon — пауза вместо повтора каждую секунду,
// отказ от открытия карточки после CARD_OPEN_DEADLINE, восстановление.

namespace MikoPBX\Core\Workers {
    class WorkerBase {}
}

namespace Modules\ModuleAmoCrm\Lib {
    class Logger
    {
        /** @var array<int, array{string, string}> */
        public static array $lines = [];
        public function writeSummary(...$args): void {}
        public function writeInfo($data, string $title = ''): void { self::$lines[] = ['info', $title !== '' ? $title : (string)print_r($data, true)]; }
        public function writeError($data, string $title = ''): void { self::$lines[] = ['error', $title]; }
    }
    class AmoCrmMain
    {
        public static function getPhoneIndex($number)
        {
            $number = preg_replace('/\D+/', '', (string)$number);
            return substr($number, -10);
        }
    }
}

namespace Modules\ModuleAmoCrm\bin {
    class ConnectorDb
    {
        public static bool $down = false;
        public static int $requests = 0;
        public static ?int $lastTimeout = null;
        public static function invoke($method, $args, $retVal = true)
        {
            throw new \RuntimeException("Unexpected ConnectorDb::invoke($method)");
        }
        public static function tryInvoke($method, $args, $timeout = 60)
        {
            if ($method !== 'getContactsData') {
                throw new \RuntimeException("Unexpected ConnectorDb::$method");
            }
            self::$requests++;
            self::$lastTimeout = $timeout;
            if (self::$down) {
                return null;
            }
            $result = [];
            foreach ($args[0] as $phone) {
                $result[$phone] = null;
            }
            return $result;
        }
    }
    class WorkerAmoHTTP {}
}

namespace {
    use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
    use Modules\ModuleAmoCrm\bin\ConnectorDb;
    use Modules\ModuleAmoCrm\Lib\Logger;
    use Modules\ModuleAmoCrm\Lib\RetryBackoff;

    $root = dirname(__DIR__);
    $bootstrapDir = sys_get_temp_dir() . '/amocrm-backoff-' . bin2hex(random_bytes(8));
    mkdir($bootstrapDir);
    file_put_contents($bootstrapDir . '/Globals.php', '<?php');
    $includePath = get_include_path();
    set_include_path($bootstrapDir . PATH_SEPARATOR . $includePath);
    try {
        require_once $root . '/Lib/ResponsibleResolver.php';
        require_once $root . '/Lib/CallTypeResolver.php';
        require_once $root . '/Lib/IncompleteCallTypes.php';
        require_once $root . '/Lib/RetryBackoff.php';
        require_once $root . '/bin/AmoCdrDaemon.php';
    } finally {
        set_include_path($includePath);
        unlink($bootstrapDir . '/Globals.php');
        rmdir($bootstrapDir);
    }
    set_error_handler(static function ($severity, $message, $file, $line): void {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    function checkCd($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
                . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    const PHONE     = '+79001234567';
    const PHONE_IDX = '9001234567';
    const LINKED    = '1700000000.10';

    $class = new ReflectionClass(AmoCdrDaemon::class);
    $method = static function (string $name) use ($class): ReflectionMethod {
        $m = $class->getMethod($name);
        $m->setAccessible(true);
        return $m;
    };
    $prop = static function (string $name) use ($class): ReflectionProperty {
        $p = $class->getProperty($name);
        $p->setAccessible(true);
        return $p;
    };
    $prepare = $method('prepareDataCreatingEntities');
    $expire  = $method('expireIncompleteAnswered');
    $sync    = $method('cdrSyncInternal');

    $newWorker = static function (int $answeredAt) use ($class, $prop) {
        $worker = $class->newInstanceWithoutConstructor();
        $prop('logger')->setValue($worker, new Logger());
        $prop('entitySettings')->setValue($worker, []);
        $prop('incompleteAnswered')->setValue($worker, [PHONE_IDX => [
            'uniq' => LINKED, 'phone' => PHONE, 'id' => LINKED, 'did' => '',
            'responsible' => '201', 'type' => null, 'answeredAt' => $answeredAt,
        ]]);
        return $worker;
    };
    $runPrepare = static function ($worker) use ($prepare): bool {
        $calls = [];
        $counter = [];
        return $prepare->invokeArgs($worker, [&$calls, &$counter]);
    };
    $errors = static function (): int {
        return count(array_filter(Logger::$lines, static function ($l) { return $l[0] === 'error'; }));
    };

    // 1. Отказ: false, короткий таймаут, пауза назначена, ошибка записана один раз.
    ConnectorDb::$down = true;
    $worker = $newWorker(time());
    checkCd(false, $runPrepare($worker), 'failed request returns false');
    checkCd(10, ConnectorDb::$lastTimeout, 'getContactsData uses the short timeout');
    $backoff = $prop('contactsBackoff')->getValue($worker);
    checkCd(true, $backoff instanceof RetryBackoff, 'backoff is created');
    checkCd(1, $backoff->failures(), 'failure registered');
    checkCd(false, $backoff->canAttempt(time()), 'next attempt is postponed');
    checkCd(1, $errors(), 'first failure is logged');

    // 2. Во время паузы cdrSyncInternal не обращается ни к ConnectorDb, ни к CDR.
    $before = ConnectorDb::$requests;
    $sync->invoke($worker); // CDRDatabaseProvider не загружен: обращение к нему упало бы
    checkCd($before, ConnectorDb::$requests, 'no request while backoff is active');

    // 3. Повторная неудача сразу после первой не пишет ERROR.
    checkCd(false, $runPrepare($worker), 'second failure returns false');
    checkCd(1, $errors(), 'repeated failure within log interval is not logged');

    // 4. Свежий звонок не помечается finished; старше CARD_OPEN_DEADLINE — помечается.
    $state = $prop('incompleteAnswered')->getValue($worker);
    checkCd(false, isset($state[PHONE_IDX]['finished']), 'fresh call keeps waiting for the card');
    $old = $newWorker(time() - 61);
    checkCd(false, $runPrepare($old), 'failed request for an old call');
    $state = $prop('incompleteAnswered')->getValue($old);
    checkCd(true, $state[PHONE_IDX]['finished'] ?? null, 'call past the deadline gives up the card');

    // То же при паузе: expireIncompleteAnswered вызывается без запроса.
    $paused = $newWorker(time() - 61);
    $expire->invoke($paused, time());
    checkCd(true, $prop('incompleteAnswered')->getValue($paused)[PHONE_IDX]['finished'] ?? null, 'expire marks old calls');
    $noTs = $newWorker(0);
    $expire->invoke($noTs, time());
    checkCd(false, isset($prop('incompleteAnswered')->getValue($noTs)[PHONE_IDX]['finished']), 'call without answeredAt is not expired');

    // 5. Восстановление: успех сбрасывает паузу и пишет INFO.
    ConnectorDb::$down = false;
    Logger::$lines = [];
    checkCd(true, $runPrepare($worker), 'request succeeds after recovery');
    checkCd(0, $backoff->failures(), 'backoff reset on success');
    $recovered = array_filter(Logger::$lines, static function ($l) {
        return $l[0] === 'info' && strpos($l[1], 'getContactsData recovered after') === 0;
    });
    checkCd(1, count($recovered), 'recovery is logged');

    // 6. Нет номеров — ConnectorDb не вызывается.
    $empty = $class->newInstanceWithoutConstructor();
    $prop('logger')->setValue($empty, new Logger());
    $prop('entitySettings')->setValue($empty, []);
    $before = ConnectorDb::$requests;
    ConnectorDb::$down = true;
    checkCd(true, $runPrepare($empty), 'no phones: success without request');
    checkCd($before, ConnectorDb::$requests, 'no phones: ConnectorDb is not asked');

    echo "OK\n";
}
