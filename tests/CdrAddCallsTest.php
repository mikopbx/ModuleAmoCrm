<?php

declare(strict_types=1);

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
}

namespace Modules\ModuleAmoCrm\bin {
    class WorkerAmoHTTP
    {
        public static array $responses = [];
        public static array $calls = [];
        public static function invokeAmoApi($method, $args)
        {
            self::$calls[] = $args;
            if (self::$responses === []) {
                throw new \RuntimeException('Unexpected HTTP request');
            }
            return array_shift(self::$responses);
        }
    }
    class ConnectorDb
    {
        public static array $calls = [];
        public static bool $saveResult = true;
        public static function invoke($method, $args, $retVal = true)
        {
            self::$calls[] = [$method, $args, $retVal];
            return self::$saveResult;
        }
    }
}

namespace {
    use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
    use Modules\ModuleAmoCrm\bin\ConnectorDb;
    use Modules\ModuleAmoCrm\bin\WorkerAmoHTTP;
    use Modules\ModuleAmoCrm\Lib\Logger;

    $bootstrapDir = sys_get_temp_dir() . '/amocrm-add-calls-' . bin2hex(random_bytes(8));
    mkdir($bootstrapDir);
    file_put_contents($bootstrapDir . '/Globals.php', '<?php');
    $includePath = get_include_path();
    set_include_path($bootstrapDir . PATH_SEPARATOR . $includePath);
    try {
        require_once dirname(__DIR__) . '/bin/AmoCdrDaemon.php';
    } finally {
        set_include_path($includePath);
        unlink($bootstrapDir . '/Globals.php');
        rmdir($bootstrapDir);
    }
    set_error_handler(static function ($severity, $message, $file, $line): void {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
    function response(bool $success, array $messages = []): object
    {
        return (object)['success' => $success, 'messages' => $messages];
    }
    function validation(array $ids): object
    {
        return response(false, ['error-code' => 400, 'error-data' => ['validation-errors' =>
            array_map(static fn($id) => ['request_id' => $id, 'errors' => [
                ['code' => 'InvalidType', 'path' => 'responsible_user_id', 'detail' => 'Must be an integer'],
            ]], $ids),
        ]]);
    }
    $class = new ReflectionClass(AmoCdrDaemon::class);
    $method = $class->getMethod('addCalls');
    $method->setAccessible(true);
    $set = static function ($worker, $name, $value) use ($class): void {
        $property = $class->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($worker, $value);
    };
    $get = static function ($worker, $name) use ($class) {
        $property = $class->getProperty($name);
        $property->setAccessible(true);
        return $property->getValue($worker);
    };
    $newWorker = static function () use ($class, $set) {
        WorkerAmoHTTP::$calls = [];
        ConnectorDb::$calls = [];
        ConnectorDb::$saveResult = true;
        $worker = $class->newInstanceWithoutConstructor();
        $set($worker, 'logger', new Logger());
        $set($worker, 'cdrRows', ['linked-a' => ['entity_type' => 'contacts'],
            'linked-b' => ['entity_type' => 'contacts'], 'linked-c' => ['entity_type' => 'companies']]);
        return $worker;
    };
    $makeCall = static fn($linked, $request) => ['id' => $linked, 'request_id' => $request,
        'entity_id' => 123, 'is_app' => '0', 'params' => ['uniq' => $request]];
    $a = $makeCall('linked-a', 'leg-a');
    $b = $makeCall('linked-b', 'leg-b');
    $c = $makeCall('linked-c', 'leg-c');
    $counter = ['linked-a' => 1, 'linked-b' => 1, 'linked-c' => 1];

    $worker = $newWorker();
    WorkerAmoHTTP::$responses = [validation(['leg-a']), response(true), response(true)];
    check($method->invoke($worker, [[$a, $b, $c]], $counter), 'Isolated validation must not fail the page');
    check(array_column(WorkerAmoHTTP::$calls[1][0], 'request_id') === ['leg-b'], 'Only remaining contacts must be resent');
    check(WorkerAmoHTTP::$calls[2][1] === 'companies', 'Companies must still be sent');
    check(ConnectorDb::$calls[0][1][0] === ['linked-a'], 'Persist linkedid, not request_id');
    $reason = ConnectorDb::$calls[0][1][1];
    foreach (['HTTP 400', 'validation-errors', 'leg-a', 'responsible_user_id', 'InvalidType'] as $part) {
        check(strpos($reason, $part) !== false, 'Reason must include ' . $part);
    }
    check(ConnectorDb::$calls[0][2] === true, 'Failure persistence must be acknowledged');

    $worker = $newWorker();
    WorkerAmoHTTP::$responses = [response(true), response(false)];
    check(!$method->invoke($worker, [[$a, $c]], $counter), 'Network error remains a failure');
    check($get($worker, 'pendingCallLinkedIds') === ['linked-c'], 'Exhaustion must track only pending linkedids');
    WorkerAmoHTTP::$responses = [response(true)];
    check($method->invoke($worker, [[$a, $c]], $counter), 'Retry succeeds');
    check(count(WorkerAmoHTTP::$calls) === 3 && WorkerAmoHTTP::$calls[2][1] === 'companies', 'Successful entity group is not resent');
    check(ConnectorDb::$calls === [], 'Network error must not be quarantined');

    $worker = $newWorker();
    WorkerAmoHTTP::$responses = [validation(['leg-a']), response(false)];
    check(!$method->invoke($worker, [[$a, $b]], $counter), 'Error after isolation remains visible');
    WorkerAmoHTTP::$responses = [response(true)];
    check($method->invoke($worker, [[$a, $b]], $counter), 'Remaining record retries');
    check(array_column(WorkerAmoHTTP::$calls[2][0], 'request_id') === ['leg-b'], 'Quarantined request is not resent');
    check(count(ConnectorDb::$calls) === 1, 'Quarantine is not saved twice on retry');

    foreach ([validation(['unknown']), validation(['leg-a', 'unknown']),
        response(false, ['error-code' => 500, 'error-data' => ['validation-errors' => [['request_id' => 'leg-a', 'errors' => [[]]]]]]),
        response(false, ['error-code' => 400]),
        response(false, ['error-code' => 400, 'error-data' => ['validation-errors' => [['request_id' => 'leg-a']]]]),
    ] as $error) {
        $worker = $newWorker();
        WorkerAmoHTTP::$responses = [$error];
        check(!$method->invoke($worker, [[$a, $b]], $counter), 'Unknown/malformed error must fail closed');
        check(ConnectorDb::$calls === [], 'Unknown/malformed errors must not quarantine calls');
        check(count(WorkerAmoHTTP::$calls) === 1, 'Unknown error must not trigger an immediate resend');
    }

    $worker = $newWorker();
    WorkerAmoHTTP::$responses = [validation(['leg-a', 'leg-b'])];
    check($method->invoke($worker, [[$a, $b]], $counter), 'All explicitly invalid calls can be quarantined');
    check(count(ConnectorDb::$calls) === 2 && count(WorkerAmoHTTP::$calls) === 1, 'No empty payload is sent');

    $worker = $newWorker();
    WorkerAmoHTTP::$responses = [validation(['leg-a']), validation(['leg-b']), response(true)];
    $d = $makeCall('linked-b', 'leg-d');
    // Two legs share a linkedid: isolate the rejected request, not its valid sibling.
    check($method->invoke($worker, [[$a, $b, $d]], ['linked-a' => 1, 'linked-b' => 1]), 'Successive validation responses must make bounded progress');
    check(array_column(WorkerAmoHTTP::$calls[2][0], 'request_id') === ['leg-d'], 'Valid sibling of a rejected linkedid is still sent');

    $worker = $newWorker();
    WorkerAmoHTTP::$responses = [validation(['leg-a'])];
    check(!$method->invoke($worker, [[$a, $a]], $counter), 'Ambiguous duplicate request_id must fail closed');
    check(ConnectorDb::$calls === [], 'Duplicate request_id must not be quarantined');

    $worker = $newWorker();
    WorkerAmoHTTP::$responses = [];
    check($method->invoke($worker, [], []), 'Empty page is successful without an HTTP request');

    $worker = $newWorker();
    ConnectorDb::$saveResult = false;
    WorkerAmoHTTP::$responses = [validation(['leg-a'])];
    check(!$method->invoke($worker, [[$a, $b]], $counter), 'Failed persistence must block page advancement');
    check($get($worker, 'pendingCallLinkedIds') === ['linked-a', 'linked-b'], 'Unsaved rejected request stays pending');
    check(count(WorkerAmoHTTP::$calls) === 1, 'Do not silently discard rejected call if persistence failed');

    echo "PASS: CDR addCalls validation isolation and retry regression tests\n";
}
