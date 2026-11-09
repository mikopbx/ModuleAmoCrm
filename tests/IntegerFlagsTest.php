<?php

declare(strict_types=1);

// #65: флаги правил приходят из integer-столбцов числом (PHP 8.1+) или строкой
// (PHP 7.4) — неразобранное создаётся в обоих случаях.

namespace MikoPBX\Core\Workers {
    class WorkerBase {}
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
        public static function invoke($method, $args, $retVal = true)
        {
            throw new \RuntimeException("Unexpected ConnectorDb::invoke($method)");
        }
        public static function tryInvoke($method, $args, $timeout = 60)
        {
            if ($method !== 'getContactsData') {
                throw new \RuntimeException("Unexpected ConnectorDb::$method");
            }
            $result = [];
            foreach ($args[0] as $phone) {
                $result[$phone] = null; // номер неизвестен
            }
            return $result;
        }
    }
    class WorkerAmoHTTP {}
}

namespace {
    use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
    use Modules\ModuleAmoCrm\Lib\Logger;

    $root = dirname(__DIR__);
    $bootstrapDir = sys_get_temp_dir() . '/amocrm-intflags-' . bin2hex(random_bytes(8));
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

    function checkFlag($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
                . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    const PHONE     = '89001234567';
    const PHONE_IDX = '9001234567';
    const LINKED    = '1700000000.20';

    $class = new ReflectionClass(AmoCdrDaemon::class);
    $prop = static function (string $name) use ($class): ReflectionProperty {
        $p = $class->getProperty($name);
        $p->setAccessible(true);
        return $p;
    };
    $prepare = $class->getMethod('prepareDataCreatingEntities');
    $prepare->setAccessible(true);

    $rule = static function (array $overrides): array {
        return array_merge([
            'responsible'             => 'first',
            'def_responsible'         => '777',
            'create_contact'          => '0',
            'create_lead'             => '0',
            'create_task'             => '0',
            'create_unsorted'         => '0',
            'task_responsible_type'   => 'firstMissedUser',
            'template_contact_name'   => 'Contact <PhoneNumber>',
            'template_lead_name'      => 'Lead <PhoneNumber>',
            'template_task_text'      => 'Task <PhoneNumber>',
            'deadline_task'           => '1',
            'lead_pipeline_id'        => '10',
            'lead_pipeline_status_id' => '20',
        ], $overrides);
    };
    // Правило пропущенного входящего создаёт контакт — как у клиента в #64.
    $missingRule = $rule(['create_contact' => '1']);

    $run = static function (array $entitySettings, string $noteType) use ($class, $prop, $prepare): array {
        $worker = $class->newInstanceWithoutConstructor();
        $prop('logger')->setValue($worker, new Logger());
        $prop('entitySettings')->setValue($worker, $entitySettings);
        $prop('cdrRows')->setValue($worker, [LINKED => [
            'answered' => 0, 'did' => '',
            'firstMissedUser' => '201', 'lastMissedUser' => '202',
        ]]);
        $calls = [PHONE_IDX => [[
            'entity_id' => null, 'note_type' => $noteType, 'created_at' => 1700000000,
            'request_id' => LINKED, 'id' => LINKED, 'is_app' => '',
            'params' => ['uniq' => LINKED, 'duration' => 0, 'source' => 'miko-pbx',
                'link' => '', 'phone' => PHONE, 'call_status' => 6],
        ]]];
        $counter = [LINKED => 1];
        checkFlag(true, $prepare->invokeArgs($worker, [&$calls, &$counter]), 'CDR pass succeeds');
        $rows = $prop('cdrRows')->getValue($worker);
        $contacts = $prop('newContacts')->getValue($worker);
        return [
            'type'        => $rows[LINKED]['type'] ?? null,
            'contacts'    => array_map('strval', array_keys($contacts)),
            'contactResp' => $contacts[PHONE_IDX]['responsible_user_id'] ?? null,
            'unsorted'    => array_map('strval', array_keys($prop('newUnsorted')->getValue($worker))),
            'noEntity'    => $rows[LINKED]['noEntityExpected'] ?? false,
            'calls'       => count($calls[PHONE_IDX] ?? []),
        ];
    };

    foreach ([1, '1'] as $on) {
        $label = var_export($on, true);
        // Действие «Неразобранное»: контакт и сделка выключены.
        $unsortedRule = $rule(['create_unsorted' => $on, 'create_contact' => 0, 'create_lead' => 0, 'create_task' => 0]);
        $r = $run([AmoCdrDaemon::MISSING_UNKNOWN => ['' => $unsortedRule]], 'call_in');
        checkFlag(AmoCdrDaemon::MISSING_UNKNOWN, $r['type'], "missed incoming ($label)");
        checkFlag([PHONE_IDX], $r['unsorted'], "unsorted is created when create_unsorted=$label");
        checkFlag(0, $r['calls'], "call goes through unsorted, not attached separately ($label)");
        checkFlag(false, $r['noEntity'], "unsorted rule: missing entity is not expected ($label)");
        checkFlag([], $r['contacts'], "no separate contact for unsorted ($label)");
    }

    foreach ([0, '0'] as $off) {
        $label = var_export($off, true);
        $r = $run([AmoCdrDaemon::MISSING_UNKNOWN => ['' => $rule(['create_unsorted' => $off])]], 'call_in');
        checkFlag([], $r['unsorted'], "unsorted is not created when create_unsorted=$label");
    }

    echo "OK\n";
}
