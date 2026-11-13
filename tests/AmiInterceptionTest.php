<?php

declare(strict_types=1);

/*
 * WorkerAmoCrmAMI после удаления панели управления вызовами:
 * обрабатывает только перехват на ответственного, события CdrConnector игнорирует.
 */

namespace MikoPBX\Core\Workers {
    class WorkerBase
    {
        public $am;
        public bool $needRestart = false;

        public static function startWorker($argv): void
        {
        }

        public function replyOnPingRequest($parameters): bool
        {
            return ($parameters['UserEvent'] ?? '') === 'ping';
        }
    }
}

namespace MikoPBX\Common\Models {
    class PbxSettings
    {
        public static function getValueByKey($key): string
        {
            return '80';
        }
    }
}

namespace MikoPBX\Core\System {
    class BeanstalkClient
    {
        public array $published = [];

        public function publish($data): void
        {
            $this->published[] = json_decode($data, true);
        }
    }

    class Util
    {
    }
}

namespace Modules\ModuleAmoCrm\Lib {
    class Logger
    {
    }
}

namespace Modules\ModuleAmoCrm\bin {
    class ConnectorDb
    {
        public static int $calls = 0;
        public static int $useInterception = 1;

        public static function invoke($name, $args = [], $wait = true)
        {
            self::$calls++;
            return ['ModuleAmoCrm' => ['useInterception' => self::$useInterception]];
        }
    }
}

namespace {
    use MikoPBX\Core\System\BeanstalkClient;
    use Modules\ModuleAmoCrm\bin\ConnectorDb;
    use Modules\ModuleAmoCrm\bin\WorkerAmoCrmAMI;

    $bootstrapDir = sys_get_temp_dir() . '/amocrm-ami-' . bin2hex(random_bytes(8));
    mkdir($bootstrapDir);
    file_put_contents($bootstrapDir . '/Globals.php', '<?php');
    $includePath = get_include_path();
    set_include_path($bootstrapDir . PATH_SEPARATOR . $includePath);
    try {
        require_once dirname(__DIR__) . '/bin/WorkerAmoCrmAMI.php';
    } finally {
        set_include_path($includePath);
        unlink($bootstrapDir . '/Globals.php');
        rmdir($bootstrapDir);
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $workerClass = new ReflectionClass(WorkerAmoCrmAMI::class);
    $worker      = $workerClass->newInstanceWithoutConstructor();
    $beanstalk   = new BeanstalkClient();
    $property    = $workerClass->getProperty('beanstalk');
    $property->setAccessible(true);
    $property->setValue($worker, $beanstalk);

    $interception = [
        'UserEvent' => 'InterceptionAMO',
        'Linkedid'  => 'call-1',
        'CALLERID'  => '79001234567',
        'chan1c'    => 'SIP/trunk-1',
        'FROM_DID'  => '74950000000',
    ];

    // Перехват включён: событие уходит в ConnectorDb, настройки прочитаны перед обработкой.
    $worker->callback($interception);
    check(ConnectorDb::$calls === 1, 'settings must be loaded before the first interception');
    check(count($beanstalk->published) === 1, 'interception must be published');
    $event = $beanstalk->published[0];
    check($event['action'] === 'interception' && $event['id'] === 'call-1'
        && $event['phone'] === '79001234567' && $event['channel'] === 'SIP/trunk-1'
        && $event['did'] === '74950000000', 'interception payload changed');

    // Настройки перечитываются не чаще раза в 10 секунд.
    $worker->callback($interception);
    check(ConnectorDb::$calls === 1, 'settings must be cached for 10 seconds');
    check(count($beanstalk->published) === 2, 'second interception must be published');

    // Перехват выключен в настройках — событие не публикуется.
    $last = $workerClass->getProperty('lastUpdateSettings');
    $last->setAccessible(true);
    $last->setValue($worker, 0);
    ConnectorDb::$useInterception = 0;
    $worker->callback($interception);
    check(count($beanstalk->published) === 2, 'disabled interception must not be published');

    // CdrConnector (события панели) и ping больше ничего не делают.
    $calls = ConnectorDb::$calls;
    $worker->callback(['UserEvent' => 'CdrConnector', 'AgiData' => base64_encode('{"action":"dial","linkedid":"x"}')]);
    $worker->callback(['UserEvent' => 'ping']);
    check(count($beanstalk->published) === 2 && ConnectorDb::$calls === $calls, 'CdrConnector/ping must be ignored');

    fwrite(STDOUT, "PASS: AMI interception tests\n");
}
