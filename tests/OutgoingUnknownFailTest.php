<?php

declare(strict_types=1);

// #64: неотвеченный исходящий на неизвестный номер обрабатывается правилом
// OUTGOING_UNKNOWN_FAIL, а не правилом пропущенного входящего MISSING_UNKNOWN.

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
    $bootstrapDir = sys_get_temp_dir() . '/amocrm-outfail-' . bin2hex(random_bytes(8));
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

    function checkOuf($expected, $actual, string $message): void
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
        checkOuf(true, $prepare->invokeArgs($worker, [&$calls, &$counter]), 'CDR pass succeeds');
        $rows = $prop('cdrRows')->getValue($worker);
        $contacts = $prop('newContacts')->getValue($worker);
        return [
            'type'        => $rows[LINKED]['type'] ?? null,
            'contacts'    => array_map('strval', array_keys($contacts)),
            'contactResp' => $contacts[PHONE_IDX]['responsible_user_id'] ?? null,
            'noEntity'    => $rows[LINKED]['noEntityExpected'] ?? false,
            'calls'       => count($calls[PHONE_IDX] ?? []),
        ];
    };

    // 1. Правила OUTGOING_UNKNOWN_FAIL нет (установка до #64): правило пропущенного
    //    входящего не применяется, контакт не создаётся, звонок некуда прикрепить.
    $r = $run([AmoCdrDaemon::MISSING_UNKNOWN => ['' => $missingRule]], 'call_out');
    checkOuf(AmoCdrDaemon::OUTGOING_UNKNOWN_FAIL, $r['type'], 'failed outgoing to unknown gets its own type');
    checkOuf([], $r['contacts'], 'no rule: MISSING_UNKNOWN rule is not applied to outgoing');
    checkOuf(0, $r['calls'], 'no rule: call to unknown number is dropped');

    // 2. Правило по умолчанию (ничего не создаёт).
    $r = $run([
        AmoCdrDaemon::MISSING_UNKNOWN       => ['' => $missingRule],
        AmoCdrDaemon::OUTGOING_UNKNOWN_FAIL => ['' => $rule([])],
    ], 'call_out');
    checkOuf([], $r['contacts'], 'default rule creates nothing');
    checkOuf(true, $r['noEntity'], 'default rule: missing entity is expected, not an error');
    checkOuf(1, $r['calls'], 'default rule: call stays in the batch (another call may create the contact)');

    // 3. Правило с созданием контакта — контакт создаётся по нему.
    $r = $run([AmoCdrDaemon::OUTGOING_UNKNOWN_FAIL => ['' => $rule(['create_contact' => '1'])]], 'call_out');
    checkOuf([PHONE_IDX], $r['contacts'], 'OUTGOING_UNKNOWN_FAIL rule with contact creation is applied');
    checkOuf(false, $r['noEntity'], 'contact is created: missing entity is not expected');
    // Ответственный — звонивший (firstMissedUser=201), даже если в правиле выбран «последний».
    $r = $run([AmoCdrDaemon::OUTGOING_UNKNOWN_FAIL => ['' => $rule(['create_contact' => '1', 'responsible' => 'last'])]], 'call_out');
    checkOuf(201, $r['contactResp'], 'responsible is the employee who made the call');

    // 4. Пропущенный входящий с неизвестного — по-прежнему MISSING_UNKNOWN.
    $r = $run([AmoCdrDaemon::MISSING_UNKNOWN => ['' => $missingRule]], 'call_in');
    checkOuf(AmoCdrDaemon::MISSING_UNKNOWN, $r['type'], 'missed incoming keeps MISSING_UNKNOWN');
    checkOuf([PHONE_IDX], $r['contacts'], 'missed incoming still creates the contact');

    // 5. Правило по умолчанию для новых установок.
    $defaults = json_decode(file_get_contents($root . '/Setup/default-entity-settings.json'), true, 512, JSON_THROW_ON_ERROR);
    $found = array_values(array_filter($defaults, static function ($d) {
        return $d['type'] === AmoCdrDaemon::OUTGOING_UNKNOWN_FAIL;
    }));
    checkOuf(1, count($found), 'default rule exists');
    foreach (['create_contact', 'create_lead', 'create_task', 'create_unsorted'] as $k) {
        checkOuf('0', $found[0][$k], "default rule: $k is off");
    }

    echo "OK\n";
}
