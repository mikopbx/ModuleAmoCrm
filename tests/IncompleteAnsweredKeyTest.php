<?php

declare(strict_types=1);

// #59: incompleteAnswered индексируется по linkedid — CDR предыдущего звонка
// не удаляет запись повторного звонка с того же номера.
// #60: обработанные (finished) записи не попадают в запрос getContactsData.

namespace MikoPBX\Core\Workers {
    class WorkerBase {}
}

namespace MikoPBX\Common\Providers {
    class CDRDatabaseProvider
    {
        /** @var array<int, array> */
        public static array $cache = [];
        /** @var array<int, array> */
        public static array $cdr = [];
        public static function getCacheCdr(): array
        {
            return self::$cache;
        }
        public static function getCdr(array $filter): array
        {
            if (isset($filter['bind']['linkedid'])) {
                return array_values(array_filter(self::$cdr, static function ($row) use ($filter) {
                    return in_array($row['linkedid'], $filter['bind']['linkedid'], true);
                }));
            }
            return array_values(array_filter(self::$cdr, static function ($row) use ($filter) {
                return $row['id'] > $filter['bind']['id'];
            }));
        }
    }
}

namespace Modules\ModuleAmoCrm\Lib {
    class Logger
    {
        public function writeSummary(...$args): void {}
        public function writeInfo(...$args): void {}
        public function writeError(...$args): void {}
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
        /** @var array<int, array> */
        public static array $requested = [];
        /** @var array<int, array> */
        public static array $settings = [];
        public static function invoke($method, $args, $retVal = true)
        {
            if ($method === 'saveNewSettings') {
                self::$settings[] = $args[0];
                return true;
            }
            throw new \RuntimeException("Unexpected ConnectorDb::invoke($method)");
        }
        public static function tryInvoke($method, $args, $timeout = 60)
        {
            if ($method !== 'getContactsData') {
                throw new \RuntimeException("Unexpected ConnectorDb::$method");
            }
            self::$requested[] = $args[0];
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
    use MikoPBX\Common\Providers\CDRDatabaseProvider;
    use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
    use Modules\ModuleAmoCrm\bin\ConnectorDb;
    use Modules\ModuleAmoCrm\Lib\Logger;

    $root = dirname(__DIR__);
    $bootstrapDir = sys_get_temp_dir() . '/amocrm-incomplete-key-' . bin2hex(random_bytes(8));
    mkdir($bootstrapDir);
    file_put_contents($bootstrapDir . '/Globals.php', '<?php');
    $includePath = get_include_path();
    set_include_path($bootstrapDir . PATH_SEPARATOR . $includePath);
    try {
        require_once $root . '/Lib/ResponsibleResolver.php';
        require_once $root . '/Lib/CallTypeResolver.php';
        require_once $root . '/Lib/IncompleteCallTypes.php';
        require_once $root . '/Lib/RetryBackoff.php';
        require_once $root . '/Lib/CdrBatch.php';
        require_once $root . '/bin/AmoCdrDaemon.php';
    } finally {
        set_include_path($includePath);
        unlink($bootstrapDir . '/Globals.php');
        rmdir($bootstrapDir);
    }
    set_error_handler(static function ($severity, $message, $file, $line): void {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    function checkKey($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
                . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    const PHONE   = '+79001234567';
    const PHONE_B = '+79007654321';
    const IDX     = '9001234567';
    const IDX_B   = '9007654321';
    const FIRST   = '1700000000.10';
    const SECOND  = '1700000100.20';

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
    $entry = static function (string $linkedId, string $phone, int $answeredAt, bool $finished): array {
        $e = [
            'uniq' => $linkedId, 'phone' => $phone, 'id' => $linkedId, 'did' => '',
            'responsible' => '201', 'type' => null, 'answeredAt' => $answeredAt,
        ];
        if ($finished) {
            $e['finished'] = true;
        }
        return $e;
    };
    $newWorker = static function (array $incomplete) use ($class, $prop) {
        $worker = $class->newInstanceWithoutConstructor();
        $prop('logger')->setValue($worker, new Logger());
        $prop('entitySettings')->setValue($worker, []);
        $prop('users')->setValue($worker, ['201' => 5001]);
        $prop('innerNums')->setValue($worker, ['201', '202']);
        $prop('incompleteAnswered')->setValue($worker, $incomplete);
        return $worker;
    };
    $state = static function ($worker) use ($prop): array {
        return $prop('incompleteAnswered')->getValue($worker);
    };
    $activeCall = static function (string $linkedId, string $phone): array {
        return [
            'start' => '2026-11-09 10:00:00', 'answer' => '2026-11-09 10:00:05', 'endtime' => '',
            'src_num' => $phone, 'dst_num' => '201', 'UNIQUEID' => $linkedId, 'linkedid' => $linkedId,
            'did' => '', 'src_chan' => '', 'dst_chan' => '',
        ];
    };
    $now = time();

    // 1. #59: первый звонок обработан (finished), клиент перезвонил с того же номера —
    // запись второго звонка создаётся сразу, а не после CDR первого.
    $worker = $newWorker([FIRST => $entry(FIRST, PHONE, $now - 30, true)]);
    CDRDatabaseProvider::$cache = [$activeCall(SECOND, PHONE)];
    $method('updateActiveCalls')->invoke($worker);
    checkKey([FIRST, SECOND], array_keys($state($worker)), 'repeat call gets its own entry');
    checkKey(false, isset($state($worker)[SECOND]['finished']), 'repeat call is pending');

    // 2. #59: CDR первого звонка удаляет только его запись.
    CDRDatabaseProvider::$cache = [];
    CDRDatabaseProvider::$cdr = [[
        'id' => 101, 'start' => '2026-11-09 09:59:00', 'answer' => '2026-11-09 09:59:05',
        'src_num' => '202', 'dst_num' => '201', 'billsec' => 30, 'recordingfile' => '',
        'UNIQUEID' => FIRST, 'linkedid' => FIRST, 'disposition' => 'ANSWERED', 'is_app' => '0', 'did' => '',
    ]];
    $prop('offset')->setValue($worker, 100);
    $prop('referenceDate')->setValue($worker, '2026-01-01 00:00:00');
    $prop('portalId')->setValue($worker, 1);
    ConnectorDb::$requested = [];
    $method('cdrSyncInternal')->invoke($worker);
    checkKey([SECOND], array_keys($state($worker)), 'CDR of the first call keeps the repeat call');
    checkKey([[IDX]], ConnectorDb::$requested, 'repeat call is looked up in the same pass');
    checkKey(true, $state($worker)[SECOND]['finished'] ?? null, 'repeat call is processed');
    checkKey(101, $prop('offset')->getValue($worker), 'offset advanced');

    // 3. #60: обработанные записи не попадают в запрос контактов.
    $worker = $newWorker([
        FIRST  => $entry(FIRST, PHONE, $now - 30, true),
        SECOND => $entry(SECOND, PHONE_B, $now - 5, false),
    ]);
    ConnectorDb::$requested = [];
    $calls = [];
    $counter = [];
    checkKey(true, $method('prepareDataCreatingEntities')->invokeArgs($worker, [&$calls, &$counter]), 'prepare succeeds');
    checkKey([[IDX_B]], ConnectorDb::$requested, 'finished entry is not looked up');

    // Только обработанные — запроса нет.
    $worker = $newWorker([FIRST => $entry(FIRST, PHONE, $now - 30, true)]);
    ConnectorDb::$requested = [];
    checkKey(true, $method('prepareDataCreatingEntities')->invokeArgs($worker, [&$calls, &$counter]), 'prepare without pending');
    checkKey([], ConnectorDb::$requested, 'no request for finished entries only');

    // Два идущих звонка с одного номера — один номер в запросе.
    $worker = $newWorker([
        FIRST  => $entry(FIRST, PHONE, $now - 30, false),
        SECOND => $entry(SECOND, PHONE, $now - 5, false),
    ]);
    ConnectorDb::$requested = [];
    $method('prepareDataCreatingEntities')->invokeArgs($worker, [&$calls, &$counter]);
    checkKey([[IDX]], ConnectorDb::$requested, 'one phone for two calls from the same number');
    checkKey([FIRST, SECOND], $method('incompleteIdsByPhone')->invoke($worker, IDX), 'both calls are found by phone');

    // 4. Обработанная запись без CDR удаляется по TTL; необработанная, свежая и
    // ещё идущая (есть в кэше) остаются.
    $worker = $newWorker([
        FIRST   => $entry(FIRST, PHONE, $now - 43201, true),
        SECOND  => $entry(SECOND, PHONE_B, $now - 43201, false),
        'fresh' => $entry('fresh', PHONE, $now - 10, true),
        'long'  => $entry('long', PHONE, $now - 43201, true),
    ]);
    $method('purgeIncompleteAnswered')->invoke($worker, $now, ['long' => true]);
    checkKey([SECOND, 'fresh', 'long'], array_keys($state($worker)), 'only stale finished entry is purged');

    // 5. Строка кэша уже с endtime (CDR записан, строка ещё не удалена) — запись не создаётся.
    $worker = $newWorker([]);
    $ended = $activeCall(FIRST, PHONE);
    $ended['endtime'] = '2026-11-09 10:01:00';
    CDRDatabaseProvider::$cache = [$ended];
    $method('updateActiveCalls')->invoke($worker);
    checkKey([], $state($worker), 'ended cache row does not create an entry');

    // 6. CDR внешнего первого звонка и идущий повторный с того же номера в одном проходе:
    // повторный откладывается, сделка создаётся одна — по CDR, без перезаписи.
    $unknownRule = [
        'responsible' => 'first', 'def_responsible' => '', 'create_contact' => '0', 'create_lead' => '1',
        'create_task' => '0', 'create_unsorted' => '0', 'task_responsible_type' => 'firstAnswerUser',
        'template_contact_name' => 'Contact <PhoneNumber>', 'template_lead_name' => 'Lead <PhoneNumber>',
        'template_task_text' => 'Task <PhoneNumber>', 'deadline_task' => '1',
        'lead_pipeline_id' => '10', 'lead_pipeline_status_id' => '20',
    ];
    $missedRule = array_merge($unknownRule, ['lead_pipeline_id' => '30', 'lead_pipeline_status_id' => '40']);
    $worker = $newWorker([SECOND => $entry(SECOND, PHONE, $now - 5, false)]);
    $prop('entitySettings')->setValue($worker, [
        AmoCdrDaemon::INCOMING_UNKNOWN => ['' => $unknownRule],
        AmoCdrDaemon::MISSING_UNKNOWN  => ['' => $missedRule],
    ]);
    $prop('cdrRows')->setValue($worker, [FIRST => [
        'answered' => 0, 'did' => '', 'firstAnswerUser' => '', 'lastAnswerUser' => '',
        'firstMissedUser' => '201', 'lastMissedUser' => '201',
    ]]);
    $calls = [IDX => [[
        'entity_id' => null, 'note_type' => 'call_in', 'created_at' => 1700000000,
        'request_id' => FIRST, 'id' => FIRST, 'is_app' => '0',
        'params' => ['uniq' => FIRST, 'duration' => 0, 'source' => 'miko-pbx', 'link' => '',
            'phone' => PHONE, 'call_status' => 6],
    ]]];
    $counter = [FIRST => 1];
    checkKey(true, $method('prepareDataCreatingEntities')->invokeArgs($worker, [&$calls, &$counter]), 'same-pass prepare succeeds');
    $leads = $prop('newLeads')->getValue($worker);
    checkKey(30, $leads[IDX]['pipeline_id'] ?? null, 'lead of the CDR call is not overwritten');
    checkKey(false, isset($prop('createdLeads')->getValue($worker)[SECOND]), 'repeat call keeps its own lead decision');
    checkKey([], $method('incompleteIdsByPhone')->invoke($worker, IDX), 'deferred call gets no entities of the CDR call');
    $method('alertIncompleteAnswered')->invoke($worker);
    checkKey(false, isset($state($worker)[SECOND]['finished']), 'deferred call stays pending for the next pass');

    echo "OK\n";
}
