<?php

declare(strict_types=1);

// #56: тип звонка, определённый во время разговора, переносится в CDR-путь
// AmoCdrDaemon::prepareDataCreatingEntities().

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
        /** @var array<string, array> */
        public static array $contacts = [];
        public static function invoke($method, $args, $retVal = true)
        {
            if ($method !== 'getContactsData') {
                throw new \RuntimeException("Unexpected ConnectorDb::$method");
            }
            $result = [];
            foreach ($args[0] as $phone) {
                $result[$phone] = self::$contacts[(string)$phone] ?? null;
            }
            return $result;
        }
        public static function tryInvoke($method, $args, $timeout = 60)
        {
            return self::invoke($method, $args);
        }
    }
    class WorkerAmoHTTP {}
}

namespace {
    use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
    use Modules\ModuleAmoCrm\bin\ConnectorDb;
    use Modules\ModuleAmoCrm\Lib\CallTypeResolver;
    use Modules\ModuleAmoCrm\Lib\Logger;

    $root = dirname(__DIR__);
    $bootstrapDir = sys_get_temp_dir() . '/amocrm-incomplete-' . bin2hex(random_bytes(8));
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

    function checkDaemon($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(
                STDERR,
                "FAIL: {$message}\nExpected: " . var_export($expected, true)
                . "\nActual: " . var_export($actual, true) . "\n"
            );
            exit(1);
        }
    }

    const PHONE     = '+79001234567';
    const PHONE_IDX = '9001234567';
    const LINKED    = '1700000000.10';

    $class = new ReflectionClass(AmoCdrDaemon::class);
    $prepare = $class->getMethod('prepareDataCreatingEntities');
    $prepare->setAccessible(true);
    $set = static function ($worker, string $name, $value) use ($class): void {
        $property = $class->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($worker, $value);
    };
    $get = static function ($worker, string $name) use ($class) {
        $property = $class->getProperty($name);
        $property->setAccessible(true);
        return $property->getValue($worker);
    };

    $rule = static function (array $overrides): array {
        return array_merge([
            'responsible'             => 'first',
            'def_responsible'         => '',
            'create_contact'          => '0',
            'create_lead'             => '0',
            'create_task'             => '0',
            'create_unsorted'         => '0',
            'task_responsible_type'   => 'firstAnswerUser',
            'template_contact_name'   => 'Contact <PhoneNumber>',
            'template_lead_name'      => 'Lead <PhoneNumber>',
            'template_task_text'      => 'Task <PhoneNumber>',
            'deadline_task'           => '1',
            'lead_pipeline_id'        => '10',
            'lead_pipeline_status_id' => '20',
        ], $overrides);
    };
    // Правило для известных отличается ответственным за задачу, чтобы было видно,
    // какое правило применилось.
    $knownRule = $rule([
        'create_lead' => '1', 'create_task' => '1',
        'task_responsible_type' => 'def_responsible', 'def_responsible' => '777',
    ]);
    $contactLeadRule = $rule([
        'create_contact' => '1', 'create_lead' => '1', 'create_task' => '1',
    ]);
    $unsortedRule = $rule(['create_unsorted' => '1']);

    $newWorker = static function (array $unknownRule, ?array $known = null) use ($class, $set, $knownRule) {
        $knownRule = $known ?? $knownRule;
        $worker = $class->newInstanceWithoutConstructor();
        $set($worker, 'logger', new Logger());
        $set($worker, 'entitySettings', [
            AmoCdrDaemon::INCOMING_UNKNOWN => ['' => $unknownRule],
            AmoCdrDaemon::INCOMING_KNOWN   => ['' => $knownRule],
            AmoCdrDaemon::MISSING_KNOWN    => ['' => $knownRule],
        ]);
        return $worker;
    };

    // Фаза 1: звонок ещё идёт, сотрудник 201 ответил, номер неизвестен.
    $duringCall = static function ($worker) use ($set, $prepare): void {
        ConnectorDb::$contacts = [];
        $set($worker, 'incompleteAnswered', [PHONE_IDX => [
            'uniq' => LINKED, 'phone' => PHONE, 'id' => LINKED,
            'did' => '', 'responsible' => '201', 'type' => null,
        ]]);
        $calls = [];
        $counter = [];
        checkDaemon(true, $prepare->invokeArgs($worker, [&$calls, &$counter]), 'in-progress pass succeeds');
        // cdrSyncInternal() удаляет запись до обработки CDR — исходная причина #56.
        $set($worker, 'incompleteAnswered', []);
    };

    // Фаза 2: пришёл CDR. Контакт (и сделка) к этому моменту существуют.
    $cdr = static function ($worker, int $answered, bool $contactExists) use ($set, $get, $prepare): array {
        ConnectorDb::$contacts = $contactExists ? [PHONE_IDX => [
            'contactId' => '555', 'companyId' => '', 'leadId' => '888',
            'responsible_user_id' => '201', 'resp_contact_user_id' => '201',
        ]] : [];
        $set($worker, 'cdrRows', [LINKED => [
            'answered' => $answered, 'did' => '',
            'firstAnswerUser' => '201', 'lastAnswerUser' => '201',
            'firstMissedUser' => '201', 'lastMissedUser' => '201',
        ]]);
        $calls = [PHONE_IDX => [[
            'entity_id' => null, 'note_type' => 'call_in', 'created_at' => 1700000000,
            'request_id' => LINKED, 'id' => LINKED, 'is_app' => '0',
            'params' => ['uniq' => LINKED, 'duration' => $answered ? 30 : 0,
                'source' => 'miko-pbx', 'link' => '', 'phone' => PHONE, 'call_status' => 4],
        ]]];
        $counter = [LINKED => 1];
        checkDaemon(true, $prepare->invokeArgs($worker, [&$calls, &$counter]), 'CDR pass succeeds');
        return [
            'type'     => $get($worker, 'cdrRows')[LINKED]['type'] ?? null,
            'tasks'    => $get($worker, 'newTasks'),
            'leads'    => $get($worker, 'newLeads'),
            'contacts' => $get($worker, 'newContacts'),
            'unsorted' => $get($worker, 'newUnsorted'),
        ];
    };

    // 1. Сценарий #56: контакт и сделка созданы во время разговора.
    $worker = $newWorker($contactLeadRule);
    $duringCall($worker);
    checkDaemon([PHONE_IDX], array_map('strval', array_keys($get($worker, 'newContacts'))), 'contact is queued during the call');
    $result = $cdr($worker, 1, true);
    checkDaemon(AmoCdrDaemon::INCOMING_UNKNOWN, $result['type'], '#56: CDR keeps INCOMING_UNKNOWN');
    checkDaemon(201, $result['tasks'][PHONE_IDX]['responsible_user_id'] ?? null, '#56: task follows the UNKNOWN rule');
    checkDaemon(888, $result['tasks'][PHONE_IDX]['entity_id'] ?? null, '#56: task is attached to the existing lead');
    checkDaemon([], $result['leads'], '#56: no second lead');
    checkDaemon([], $result['contacts'], '#56: no second contact');
    checkDaemon([], $result['unsorted'], '#56: no unsorted');

    // 2. Тот же CDR без фазы разговора (как было до исправления) — KNOWN.
    $worker = $newWorker($contactLeadRule);
    $result = $cdr($worker, 1, true);
    checkDaemon(AmoCdrDaemon::INCOMING_KNOWN, $result['type'], 'without in-progress pass the CDR classification is used');
    checkDaemon(777, $result['tasks'][PHONE_IDX]['responsible_user_id'] ?? null, 'KNOWN rule task responsible');

    // 3. Правило «неразобранное»: во время разговора модуль ничего не создаёт,
    // контакт создан вручную — классификация по CDR, как раньше.
    $worker = $newWorker($unsortedRule);
    $duringCall($worker);
    $result = $cdr($worker, 1, true);
    checkDaemon(AmoCdrDaemon::INCOMING_KNOWN, $result['type'], 'type is not carried when nothing is created mid-call');

    // 4. По CDR звонок пропущен — тип из CDR.
    $worker = $newWorker($contactLeadRule);
    $duringCall($worker);
    $result = $cdr($worker, 0, true);
    checkDaemon(AmoCdrDaemon::MISSING_KNOWN, $result['type'], 'missed CDR ignores the carried type');

    // 5. Номер известен уже во время разговора — тип не запоминается.
    $worker = $newWorker($contactLeadRule);
    ConnectorDb::$contacts = [PHONE_IDX => ['contactId' => '555', 'companyId' => '', 'leadId' => '888']];
    $set($worker, 'incompleteAnswered', [PHONE_IDX => [
        'uniq' => LINKED, 'phone' => PHONE, 'id' => LINKED,
        'did' => '', 'responsible' => '201', 'type' => null,
    ]]);
    $calls = [];
    $counter = [];
    $prepare->invokeArgs($worker, [&$calls, &$counter]);
    $types = $class->getMethod('incompleteTypes');
    $types->setAccessible(true);
    checkDaemon(0, $types->invoke($worker)->count(), 'known caller: nothing is remembered');

    // 6. Тип перенесён, а в правиле (данные в БД в обход UI) включены и контакт,
    // и неразобранное: неразобранное не создаётся, иначе дубль контакта и сделки.
    $worker = $newWorker($rule(['create_contact' => '1', 'create_lead' => '1', 'create_unsorted' => '1']));
    $duringCall($worker);
    $result = $cdr($worker, 1, true);
    checkDaemon(AmoCdrDaemon::INCOMING_UNKNOWN, $result['type'], 'carried type with unsorted rule');
    checkDaemon([], $result['unsorted'], 'carried type: unsorted is not created for the existing contact');

    // 7. Без перенесённого типа поведение неразобранного не меняется: правило для
    // известных с сохранённым create_unsorted=1 создаёт его, как и раньше.
    $worker = $newWorker($contactLeadRule, $rule(['create_unsorted' => '1']));
    $result = $cdr($worker, 1, true);
    checkDaemon(AmoCdrDaemon::INCOMING_KNOWN, $result['type'], 'known caller without carried type');
    checkDaemon([PHONE_IDX], array_map('strval', array_keys($result['unsorted'])), 'no carried type: unsorted behaviour unchanged');

    echo "OK\n";
}
