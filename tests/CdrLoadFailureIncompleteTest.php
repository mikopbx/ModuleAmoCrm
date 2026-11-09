<?php

declare(strict_types=1);

// #58: сбой загрузки страницы CDR не блокирует обработку текущих отвеченных
// звонков. Сквозная проверка защиты от дублей: звонок обрабатывается во время
// разговора один раз, CDR этого же звонка после восстановления не создаёт второй
// контакт и вторую сделку, параллельный экземпляр (flock) ничего не делает.

namespace MikoPBX\Core\Workers {
    class WorkerBase {}
}

namespace MikoPBX\Common\Providers {
    class CDRDatabaseProvider
    {
        public static array $cache = [];
        public static array $cdr = [];
        public static bool $failExpansion = false;
        public static function getCacheCdr(): array
        {
            return self::$cache;
        }
        public static function getCdr(array $filter): array
        {
            if (isset($filter['bind']['linkedid'])) {
                if (self::$failExpansion) {
                    return []; // таймаут WorkerCdr на втором запросе
                }
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
        public static array $errors = [];
        public function writeSummary(...$args): void {}
        public function writeInfo(...$args): void {}
        public function writeError($data, string $title = ''): void { self::$errors[] = $title !== '' ? $title : print_r($data, true); }
    }
    class AmoCrmMain
    {
        public static function getPhoneIndex($number)
        {
            $number = preg_replace('/\D+/', '', (string)$number);
            return substr($number, -10);
        }
    }
    class ClientHTTP
    {
        public static array $sent = [];
        public static function sendHttpPostRequest($url, $data)
        {
            self::$sent[] = $data;
            return true;
        }
    }
}

namespace Modules\ModuleAmoCrm\bin {
    use Modules\ModuleAmoCrm\Lib\AmoCrmMain;

    class WorkerAmoCrmAMI
    {
        public static function getChannelUrl(): string { return 'http://nchan'; }
    }

    // Состояние ConnectorDb: кэш телефонной книги и сделок модуля.
    class ConnectorDb
    {
        /** @var array<string, array> индекс номера => данные контакта */
        public static array $phoneBook = [];
        /** @var array<int, array> ожидающие записи updatePhoneBook/updateLeads (неблокирующие вызовы) */
        public static array $queue = [];
        public static array $settings = [];
        public static int $lookups = 0;
        // Одна очередь Beanstalk, один процесс ConnectorDb, одинаковый приоритет:
        // неблокирующие задания выполняются раньше следующего блокирующего запроса.
        public static bool $fifo = false;

        public static function contacts(array $phones): array
        {
            if (self::$fifo) {
                self::flush();
            }
            self::$lookups++;
            $result = [];
            foreach ($phones as $phone) {
                $result[$phone] = self::$phoneBook[AmoCrmMain::getPhoneIndex($phone)] ?? null;
            }
            return $result;
        }
        public static function flush(): void
        {
            foreach (self::$queue as [$method, $data]) {
                foreach ($data['add'] as $item) {
                    if ($method === 'updatePhoneBook') {
                        $idx = AmoCrmMain::getPhoneIndex($item['custom_fields'][0]['values'][0]['value']);
                        self::$phoneBook[$idx] = array_merge(self::$phoneBook[$idx] ?? ['leadId' => null],
                            ['contactId' => (string)$item['id'], 'companyId' => '']);
                    } else {
                        foreach ($item['_embedded']['contacts'] ?? [] as $c) {
                            foreach (self::$phoneBook as $idx => $row) {
                                if ($row['contactId'] === (string)$c['id']) {
                                    self::$phoneBook[$idx]['leadId'] = (string)$item['id'];
                                }
                            }
                        }
                    }
                }
            }
            self::$queue = [];
        }
        public static function invoke($method, $args, $retVal = true)
        {
            switch ($method) {
                case 'getContactsData':
                    return self::contacts($args[0]);
                case 'updatePhoneBook':
                case 'updateLeads':
                    self::$queue[] = [$method, $args[0]];
                    return true;
                case 'saveNewSettings':
                    self::$settings[] = $args[0];
                    return true;
                default:
                    throw new \RuntimeException("Unexpected ConnectorDb::invoke($method)");
            }
        }
        public static function tryInvoke($method, $args, $timeout = 60)
        {
            if ($method !== 'getContactsData') {
                throw new \RuntimeException("Unexpected ConnectorDb::$method");
            }
            return self::contacts($args[0]);
        }
    }

    // amoCRM: выдаёт id и считает созданные сущности.
    class WorkerAmoHTTP
    {
        public static array $calls = [];
        private static int $nextId = 1000;
        public static function invokeAmoApi($method, $args)
        {
            self::$calls[$method][] = $args;
            $result = new \stdClass();
            $result->success = true;
            $result->messages = [];
            $result->data = [];
            if ($method === 'createContacts') {
                foreach ($args[0] as $contact) {
                    $result->data['_embedded']['contacts'][] = ['id' => self::$nextId++, 'request_id' => $contact['request_id']];
                }
            } elseif ($method === 'addLeads') {
                foreach ($args[0] as $lead) {
                    $result->data['_embedded']['leads'][] = ['id' => self::$nextId++, 'request_id' => $lead['request_id']];
                }
            }
            return $result;
        }
        public static function count(string $method): int
        {
            return count(self::$calls[$method] ?? []);
        }
    }
}

namespace {
    use MikoPBX\Common\Providers\CDRDatabaseProvider;
    use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
    use Modules\ModuleAmoCrm\bin\ConnectorDb;
    use Modules\ModuleAmoCrm\bin\WorkerAmoHTTP;
    use Modules\ModuleAmoCrm\Lib\ClientHTTP;
    use Modules\ModuleAmoCrm\Lib\Logger;

    $root = dirname(__DIR__);
    $bootstrapDir = sys_get_temp_dir() . '/amocrm-cdr-fail-' . bin2hex(random_bytes(8));
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

    function checkFail($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
                . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    const PHONE  = '+79001234567';
    const IDX    = '9001234567';
    const LINKED = '1700000000.10';

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
    $rule = [
        'responsible' => 'first', 'def_responsible' => '', 'create_contact' => '1', 'create_lead' => '1',
        'create_task' => '0', 'create_unsorted' => '0', 'task_responsible_type' => 'firstAnswerUser',
        'template_contact_name' => 'Contact <PhoneNumber>', 'template_lead_name' => 'Lead <PhoneNumber>',
        'template_task_text' => 'Task <PhoneNumber>', 'deadline_task' => '1',
        'lead_pipeline_id' => '10', 'lead_pipeline_status_id' => '20',
    ];
    $newWorker = static function () use ($class, $prop, $rule) {
        $worker = $class->newInstanceWithoutConstructor();
        $prop('logger')->setValue($worker, new Logger());
        $prop('entitySettings')->setValue($worker, [
            AmoCdrDaemon::INCOMING_UNKNOWN => ['' => $rule],
            AmoCdrDaemon::INCOMING_KNOWN   => ['' => $rule],
        ]);
        $prop('users')->setValue($worker, ['201' => '5001']);
        $prop('innerNums')->setValue($worker, ['201']);
        $prop('offset')->setValue($worker, 100);
        $prop('referenceDate')->setValue($worker, '2026-01-01 00:00:00');
        $prop('portalId')->setValue($worker, 1);
        $prop('extHostname')->setValue($worker, 'pbx.example');
        return $worker;
    };
    $reset = static function (): void {
        ConnectorDb::$phoneBook = [];
        ConnectorDb::$queue = [];
        ConnectorDb::$settings = [];
        ConnectorDb::$lookups = 0;
        WorkerAmoHTTP::$calls = [];
        ClientHTTP::$sent = [];
        Logger::$errors = [];
        CDRDatabaseProvider::$cache = [];
        CDRDatabaseProvider::$cdr = [];
        CDRDatabaseProvider::$failExpansion = false;
    };
    $openCards = static function (): array {
        return array_values(array_filter(ClientHTTP::$sent, static function ($d) {
            return ($d['action'] ?? '') === 'open-card';
        }));
    };
    $activeCall = [
        'start' => '2026-11-09 10:00:00', 'answer' => '2026-11-09 10:00:05', 'endtime' => '',
        'src_num' => PHONE, 'dst_num' => '201', 'UNIQUEID' => LINKED . '_aB12cD', 'linkedid' => LINKED,
        'did' => '', 'src_chan' => '', 'dst_chan' => '',
    ];
    $cdrRow = [
        'id' => 101, 'start' => '2026-11-09 10:00:00', 'answer' => '2026-11-09 10:00:05',
        'src_num' => PHONE, 'dst_num' => '201', 'billsec' => 60, 'recordingfile' => '',
        'UNIQUEID' => LINKED . '_aB12cD', 'linkedid' => LINKED, 'disposition' => 'ANSWERED', 'is_app' => '0', 'did' => '',
    ];
    // Одна итерация основного цикла демона (без updateUsers/updateSettings).
    $tick = static function ($worker) use ($method): void {
        $method('updateActiveCalls')->invoke($worker);
        $method('cdrSync')->invoke($worker);
    };

    // 1. #58: страница CDR не загружается, а звонок от неизвестного уже отвечен.
    $reset();
    $worker = $newWorker();
    CDRDatabaseProvider::$cache = [$activeCall];
    CDRDatabaseProvider::$cdr = [$cdrRow]; // CDR другого звонка в очереди; раскрытие падает
    CDRDatabaseProvider::$cdr[0]['linkedid'] = 'other';
    CDRDatabaseProvider::$failExpansion = true;
    $tick($worker);
    checkFail(true, in_array('Failed to load CDR page, offset unchanged', Logger::$errors, true), 'CDR page load failed');
    checkFail(1, count($openCards()), '#58: card is opened despite the CDR failure');
    checkFail(1, WorkerAmoHTTP::count('createContacts'), 'contact created once');
    checkFail(1, WorkerAmoHTTP::count('addLeads'), 'lead created once');
    checkFail(100, $prop('offset')->getValue($worker), 'offset is not advanced');
    checkFail([], ConnectorDb::$settings, 'offset is not saved');

    // 2. Сбой продолжается, звонок идёт: повторных созданий и карточек нет.
    ConnectorDb::flush();
    $lookups = ConnectorDb::$lookups;
    for ($i = 0; $i < 3; $i++) {
        $tick($worker);
    }
    checkFail(1, count($openCards()), 'card is not reopened while the call lasts');
    checkFail(1, WorkerAmoHTTP::count('createContacts'), 'no second contact during the failure');
    checkFail(1, WorkerAmoHTTP::count('addLeads'), 'no second lead during the failure');
    checkFail($lookups, ConnectorDb::$lookups, 'finished call is not looked up again (#60)');

    // 3. Звонок завершён, CDR восстановился и пришёл CDR этого звонка:
    // контакт и сделка уже в БД — дублей нет, звонок прикрепляется к контакту.
    CDRDatabaseProvider::$cache = [];
    CDRDatabaseProvider::$cdr = [$cdrRow];
    CDRDatabaseProvider::$failExpansion = false;
    $tick($worker);
    checkFail(1, WorkerAmoHTTP::count('createContacts'), 'CDR after recovery: no second contact');
    checkFail(1, WorkerAmoHTTP::count('addLeads'), 'CDR after recovery: no second lead');
    checkFail(1, WorkerAmoHTTP::count('addCalls'), 'call note is added');
    checkFail(1000, WorkerAmoHTTP::$calls['addCalls'][0][0][0]['entity_id'] ?? null, 'call is attached to the created contact');
    checkFail(101, $prop('offset')->getValue($worker), 'offset advanced after recovery');
    checkFail(1, count($openCards()), 'no extra card after recovery');

    // 4. CDR пришёл в следующую же секунду (короткий звонок), явного сброса очереди
    // нет: updatePhoneBook/updateLeads выполняются раньше getContactsData по порядку
    // очереди ConnectorDb — второго контакта и второй сделки нет.
    $reset();
    ConnectorDb::$fifo = true;
    $worker = $newWorker();
    CDRDatabaseProvider::$cache = [$activeCall];
    CDRDatabaseProvider::$cdr = [array_merge($cdrRow, ['linkedid' => 'other'])];
    CDRDatabaseProvider::$failExpansion = true;
    $tick($worker);
    CDRDatabaseProvider::$cache = [];
    CDRDatabaseProvider::$cdr = [$cdrRow];
    CDRDatabaseProvider::$failExpansion = false;
    $tick($worker);
    checkFail(1, WorkerAmoHTTP::count('createContacts'), 'next-second CDR: no second contact');
    checkFail(1, WorkerAmoHTTP::count('addLeads'), 'next-second CDR: no second lead');
    checkFail(1, WorkerAmoHTTP::count('addCalls'), 'next-second CDR: call note is added');
    ConnectorDb::$fifo = false;

    // 5. Второй экземпляр демона при занятой блокировке ничего не обрабатывает.
    $reset();
    $worker = $newWorker();
    CDRDatabaseProvider::$cache = [$activeCall];
    CDRDatabaseProvider::$cdr = [array_merge($cdrRow, ['linkedid' => 'other'])];
    CDRDatabaseProvider::$failExpansion = true;
    $lock = fopen('/tmp/amo_cdr_sync.lock', 'w');
    checkFail(true, flock($lock, LOCK_EX | LOCK_NB), 'test holds the lock');
    $tick($worker);
    checkFail(0, count($openCards()), 'locked: no card from the second instance');
    checkFail(0, WorkerAmoHTTP::count('createContacts'), 'locked: no contact from the second instance');
    flock($lock, LOCK_UN);
    fclose($lock);
    $tick($worker);
    checkFail(1, count($openCards()), 'after unlock the call is processed once');

    // 6. UNIQUEID строки CDR равен linkedid (так бывает у импортированных CDR):
    // данные звонка в cdrRows не затираются, отвеченный звонок не считается пропущенным.
    $reset();
    $worker = $newWorker();
    $prop('entitySettings')->setValue($worker, [
        AmoCdrDaemon::INCOMING_UNKNOWN => ['' => $rule],
    ]);
    CDRDatabaseProvider::$cdr = [array_merge($cdrRow, ['UNIQUEID' => LINKED])];
    $tick($worker);
    checkFail(AmoCdrDaemon::INCOMING_UNKNOWN, $prop('cdrRows')->getValue($worker)[LINKED]['type'] ?? null,
        'UNIQUEID == linkedid: answered call keeps its type');
    checkFail(1, WorkerAmoHTTP::count('createContacts'), 'UNIQUEID == linkedid: contact by the INCOMING_UNKNOWN rule');

    echo "OK\n";
}
