<?php

declare(strict_types=1);

namespace MikoPBX\Core\Workers {
    class WorkerBase
    {
        public static function startWorker($argv): void
        {
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

namespace Modules\ModuleAmoCrm\Lib {
    class ClientHTTP
    {
        public static $calls = [];

        public static function sendHttpPostRequest($url, $params): void
        {
            self::$calls[] = $params;
        }
    }
}

namespace {
    use Modules\ModuleAmoCrm\bin\WorkerAmoCrmAMI;
    use Modules\ModuleAmoCrm\Lib\ClientHTTP;

    // Load the real worker without the PBX bootstrap or starting its event loop.
    $bootstrapDir = sys_get_temp_dir() . '/amocrm-dial-end-' . bin2hex(random_bytes(8));
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

    set_error_handler(static function ($severity, $message, $file, $line): void {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    $workerClass = new ReflectionClass(WorkerAmoCrmAMI::class);
    $worker = $workerClass->newInstanceWithoutConstructor();
    foreach (['users' => ['101' => 42], 'activeChannels' => ['SIP/101-test' => '101']] as $name => $value) {
        $property = $workerClass->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($worker, $value);
    }
    $method = $workerClass->getMethod('actionDialEnd');
    $method->setAccessible(true);
    $base = ['src_chan' => 'SIP/101-test', 'UNIQUEID' => 'leg-1', 'linkedid' => 'call-1'];
    $cases = [
        'start retains priority' => ['start' => '2026-10-20 10:00:00', 'event_time' => '2026-10-20 11:00:00'],
        'missing start uses event time' => ['event_time' => '2026-10-20 11:00:00'],
        'missing timestamps use current time' => [],
        'invalid timestamp uses current time' => ['start' => 'invalid'],
    ];
    foreach ($cases as $message => $timestamps) {
        ClientHTTP::$calls = [];
        $before = time();
        $method->invoke($worker, $base + $timestamps);
        $after = time();
        if (count(ClientHTTP::$calls) !== 1) {
            throw new RuntimeException($message . ': expected one end-dial notification');
        }
        $call = ClientHTTP::$calls[0];
        $timestamp = strtotime($timestamps['start'] ?? $timestamps['event_time'] ?? 'invalid');
        $actual = strtotime($call['date']);
        if ($timestamp === false ? ($actual < $before || $actual > $after) : $actual !== $timestamp) {
            throw new RuntimeException($message . ': unexpected notification date');
        }
        unset($call['date']);
        if ($call !== ['uid' => 'leg-1', 'id' => 'call-1', 'user' => 42, 'src' => '101', 'dst' => '', 'action' => 'end-dial']) {
            throw new RuntimeException($message . ': notification payload changed');
        }
    }

    ClientHTTP::$calls = [];
    $method->invoke($worker, array_merge($base, ['src_chan' => 'SIP/unknown']));
    if (ClientHTTP::$calls !== []) {
        throw new RuntimeException('Unknown source must not publish an end-dial notification');
    }
    restore_error_handler();
    fwrite(STDOUT, "PASS: AMI dial_end timestamp regression tests\n");
}
