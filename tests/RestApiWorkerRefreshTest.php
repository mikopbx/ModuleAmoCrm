<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Config {
    class ConfigClass
    {
    }
}

namespace MikoPBX\Core\System {
    class Processes
    {
        private const VALID_ACTIONS = ['start', 'stop', 'restart', 'soft-restart'];

        public static $calls = [];

        public static function processPHPWorker($worker, $action = null, $restartMode = null): void
        {
            self::$calls[] = func_get_args();
        }
    }

    class PBX
    {
        public static function dialplanReload(): void
        {
        }
    }
}

namespace MikoPBX\Core\System\Configs {
    class CronConf
    {
        public function reStart(): void
        {
        }
    }

    class NginxConf
    {
        public function generateConf(): void
        {
        }

        public function reStart(): void
        {
        }
    }
}

namespace MikoPBX\PBXCoreREST\Workers {
    class WorkerApiCommands
    {
    }
}

namespace {
    use MikoPBX\Core\System\Processes;
    use MikoPBX\PBXCoreREST\Workers\WorkerApiCommands;
    use Modules\ModuleAmoCrm\Lib\AmoCrmConf;
    use Modules\ModuleAmoCrm\Lib\RestApiWorkerRefresher;

    function failTest(string $message): void
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function assertCalls(array $expected, array $actual, string $message): void
    {
        if ($expected !== $actual) {
            failTest(
                $message
                . "\nExpected: " . var_export($expected, true)
                . "\nActual: " . var_export($actual, true)
            );
        }
    }

    $root = dirname(__DIR__);
    $refresherFile = $root . '/Lib/RestApiWorkerRefresher.php';
    if (!file_exists($refresherFile)) {
        failTest("missing production file {$refresherFile}");
    }

    require_once $refresherFile;
    require_once $root . '/Lib/AmoCrmConf.php';

    $config = new AmoCrmConf();

    Processes::$calls = [];
    $config->onAfterModuleEnable();
    assertCalls(
        [[WorkerApiCommands::class, 'start', 'soft-restart']],
        Processes::$calls,
        'enabling the module must refresh every REST API worker gracefully'
    );

    Processes::$calls = [];
    $config->onAfterModuleDisable();
    assertCalls(
        [[WorkerApiCommands::class, 'start', 'soft-restart']],
        Processes::$calls,
        'disabling the module must remove stale module routes from every REST API worker'
    );

    $legacyCalls = [];
    RestApiWorkerRefresher::restartUsing(
        static function () use (&$legacyCalls): void {
            $legacyCalls[] = func_get_args();
        },
        false,
        WorkerApiCommands::class
    );
    assertCalls(
        [[WorkerApiCommands::class]],
        $legacyCalls,
        'older MikoPBX releases must use the one-argument processPHPWorker signature'
    );

    fwrite(STDOUT, "OK: REST API workers are refreshed after module state changes\n");
}
