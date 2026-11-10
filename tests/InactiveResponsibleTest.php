<?php

declare(strict_types=1);

namespace MikoPBX\Core\Workers {
    class WorkerBase {}
}

namespace Modules\ModuleAmoCrm\Lib {
    class Logger
    {
        public static array $errors = [];
        public static array $infos = [];
        public function writeSummary(...$args): void {}
        public function writeInfo($data, ...$args): void { self::$infos[] = is_string($data) ? $data : ''; }
        public function writeError($data, ...$args): void { self::$errors[] = is_string($data) ? $data : ''; }
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
    use Modules\ModuleAmoCrm\Lib\ResponsibleResolver;

    $bootstrapDir = sys_get_temp_dir() . '/amocrm-inactive-' . bin2hex(random_bytes(8));
    mkdir($bootstrapDir);
    file_put_contents($bootstrapDir . '/Globals.php', '<?php');
    $includePath = get_include_path();
    set_include_path($bootstrapDir . PATH_SEPARATOR . $includePath);
    try {
        require_once dirname(__DIR__) . '/Lib/CallTypeResolver.php';
        require_once dirname(__DIR__) . '/Lib/IncompleteCallTypes.php';
        require_once dirname(__DIR__) . '/Lib/ResponsibleResolver.php';
        require_once dirname(__DIR__) . '/Lib/ValidationErrors.php';
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
    // Ответ amoCRM на неактивного пользователя (NotSupportedChoice).
    function userRejected(array $ids, bool $withCreatedBy = true): object
    {
        $errors = [['code' => 'NotSupportedChoice', 'path' => 'responsible_user_id', 'detail' => 'The value you selected is not a valid choice.']];
        if ($withCreatedBy) {
            $errors[] = ['code' => 'NotSupportedChoice', 'path' => 'created_by', 'detail' => 'The value you selected is not a valid choice.'];
        }
        return response(false, ['error-code' => 400, 'error-data' => ['validation-errors' =>
            array_map(static fn($id) => ['request_id' => $id, 'errors' => $errors], $ids),
        ]]);
    }

    // --- ResponsibleResolver ---
    check(ResponsibleResolver::pick(['1000001', '1000002'], [1000001]) === 1000002, 'pick skips excluded');
    check(ResponsibleResolver::pick(['', 0, '1000004']) === 1000004, 'pick skips empty values');
    check(ResponsibleResolver::pick(['1000001'], ['1000001']) === null, 'pick returns null when nothing is left');
    check(ResponsibleResolver::isUserRejection([['code' => 'NotSupportedChoice', 'path' => 'created_by']]), 'created_by rejection');
    check(!ResponsibleResolver::isUserRejection([]), 'empty errors are not a user rejection');
    check(!ResponsibleResolver::isUserRejection([['code' => 'InvalidType', 'path' => 'responsible_user_id']]), 'other codes are not a user rejection');
    check(!ResponsibleResolver::isUserRejection([
        ['code' => 'NotSupportedChoice', 'path' => 'responsible_user_id'],
        ['code' => 'NotSupportedChoice', 'path' => 'entity_id'],
    ]), 'mixed errors are not a user rejection');

    $class = new ReflectionClass(AmoCdrDaemon::class);
    $invoke = static function ($worker, string $name, ...$args) use ($class) {
        $m = $class->getMethod($name);
        $m->setAccessible(true);
        return $m->invoke($worker, ...$args);
    };
    $set = static function ($worker, $name, $value) use ($class): void {
        $p = $class->getProperty($name);
        $p->setAccessible(true);
        $p->setValue($worker, $value);
    };
    $get = static function ($worker, $name) use ($class) {
        $p = $class->getProperty($name);
        $p->setAccessible(true);
        return $p->getValue($worker);
    };
    $newWorker = static function (array $cdrRows) use ($class, $set) {
        WorkerAmoHTTP::$calls = [];
        WorkerAmoHTTP::$responses = [];
        ConnectorDb::$calls = [];
        ConnectorDb::$saveResult = true;
        Logger::$errors = [];
        Logger::$infos = [];
        $worker = $class->newInstanceWithoutConstructor();
        $set($worker, 'logger', new Logger());
        $set($worker, 'cdrRows', $cdrRows);
        return $worker;
    };
    $makeCall = static fn($linked, $request, $user) => ['id' => $linked, 'request_id' => $request,
        'entity_id' => 123, 'is_app' => '1', 'params' => ['uniq' => $request],
        'created_by' => $user, 'responsible_user_id' => $user];

    // --- addCalls: звонок с неактивным ответственным контакта уходит с ответственным по правилу ---
    $worker = $newWorker(['linked-a' => ['entity_type' => 'contacts', 'responsibleRule' => 1000002],
        'linked-b' => ['entity_type' => 'contacts', 'responsibleRule' => 1000004]]);
    $a = $makeCall('linked-a', 'leg-a', 1000001);
    $b = $makeCall('linked-b', 'leg-b', 1000003);
    WorkerAmoHTTP::$responses = [userRejected(['leg-a']), response(true)];
    check($invoke($worker, 'addCalls', [[$a, $b]], ['linked-a' => 1, 'linked-b' => 1]), 'Rejected user is retried, page succeeds');
    $resent = WorkerAmoHTTP::$calls[1][0];
    check(array_column($resent, 'request_id') === ['leg-a', 'leg-b'], 'Both calls are resent');
    check($resent[0]['responsible_user_id'] === 1000002 && $resent[0]['created_by'] === 1000002, 'Rule responsible replaces rejected user');
    check($resent[1]['responsible_user_id'] === 1000003, 'Other calls keep their responsible');
    check(ConnectorDb::$calls === [], 'Recovered call is not quarantined');
    check(isset($get($worker, 'rejectedUserIds')[1000001]), 'Rejected user is remembered');

    // --- addCalls: нет ответственного по правилу — поля удаляются ---
    $worker = $newWorker(['linked-a' => ['entity_type' => 'contacts']]);
    WorkerAmoHTTP::$responses = [userRejected(['leg-a']), response(true)];
    check($invoke($worker, 'addCalls', [[$a]], ['linked-a' => 1]), 'Retry without user succeeds');
    $resent = WorkerAmoHTTP::$calls[1][0][0];
    check(!array_key_exists('responsible_user_id', $resent) && !array_key_exists('created_by', $resent), 'User fields are removed');

    // --- addCalls: правило даёт того же неактивного пользователя — поля удаляются ---
    $worker = $newWorker(['linked-a' => ['entity_type' => 'contacts', 'responsibleRule' => 1000001]]);
    WorkerAmoHTTP::$responses = [userRejected(['leg-a']), response(true)];
    check($invoke($worker, 'addCalls', [[$a]], ['linked-a' => 1]), 'Same rejected rule user is not reused');
    check(!array_key_exists('responsible_user_id', WorkerAmoHTTP::$calls[1][0][0]), 'Rejected rule user is not sent again');

    // --- addCalls: запасной тоже неактивен — повтор без пользователя, оба ID запомнены (ревью п.1) ---
    $worker = $newWorker(['linked-a' => ['entity_type' => 'contacts', 'responsibleRule' => 1000002]]);
    WorkerAmoHTTP::$responses = [userRejected(['leg-a']), userRejected(['leg-a']), response(true)];
    check($invoke($worker, 'addCalls', [[$a]], ['linked-a' => 1]), 'Chain ends with a call without user');
    check(count(WorkerAmoHTTP::$calls) === 3, 'Original, fallback, without user');
    check(WorkerAmoHTTP::$calls[1][0][0]['responsible_user_id'] === 1000002, 'Fallback is tried first');
    check(!array_key_exists('responsible_user_id', WorkerAmoHTTP::$calls[2][0][0])
        && !array_key_exists('created_by', WorkerAmoHTTP::$calls[2][0][0]), 'Then without user');
    check(ConnectorDb::$calls === [], 'Recovered call is not quarantined');
    $rejectedIds = $get($worker, 'rejectedUserIds');
    check(isset($rejectedIds[1000001], $rejectedIds[1000002]), 'Both rejected users are remembered');

    // --- addCalls: отказ и без пользователя — карантин, без бесконечного цикла ---
    $worker = $newWorker(['linked-a' => ['entity_type' => 'contacts']]);
    WorkerAmoHTTP::$responses = [userRejected(['leg-a']), userRejected(['leg-a'])];
    check($invoke($worker, 'addCalls', [[$a]], ['linked-a' => 1]), 'Unrecoverable call is quarantined');
    check(count(WorkerAmoHTTP::$calls) === 2, 'No resend when nothing can change');
    check(ConnectorDb::$calls[0][0] === 'saveFailedCdr' && ConnectorDb::$calls[0][1][0] === ['linked-a'], 'Quarantined');

    // --- addCalls: отклонён только created_by — responsible_user_id не трогается (ревью п.4) ---
    $worker = $newWorker(['linked-a' => ['entity_type' => 'contacts', 'responsibleRule' => 1000002]]);
    $mixed = $makeCall('linked-a', 'leg-a', 1000003);
    $mixed['created_by'] = 1000001;
    $createdByOnly = response(false, ['error-code' => 400, 'error-data' => ['validation-errors' => [
        ['request_id' => 'leg-a', 'errors' => [['code' => 'NotSupportedChoice', 'path' => 'created_by', 'detail' => '']]],
    ]]]);
    WorkerAmoHTTP::$responses = [$createdByOnly, response(true)];
    check($invoke($worker, 'addCalls', [[$mixed]], ['linked-a' => 1]), 'created_by rejection recovers');
    $resent = WorkerAmoHTTP::$calls[1][0][0];
    check($resent['responsible_user_id'] === 1000003 && $resent['created_by'] === 1000002, 'Only created_by is replaced');
    $rejectedIds = $get($worker, 'rejectedUserIds');
    check(isset($rejectedIds[1000001]) && !isset($rejectedIds[1000003]), 'Valid responsible is not marked rejected');

    // --- replaceRejectedUser и TTL (ревью п.5) ---
    $worker = $newWorker([]);
    $set($worker, 'rejectedUserIds', [1000001 => time()]);
    check($invoke($worker, 'replaceRejectedUser', '1000001', ['1000002'], 'ctx') === 1000002, 'Known rejected user is replaced');
    check($invoke($worker, 'replaceRejectedUser', '1000001', ['1000001', ''], 'ctx') === null, 'No usable fallback gives null');
    check($invoke($worker, 'replaceRejectedUser', '1000003', ['1000002'], 'ctx') === '1000003', 'Other users are kept as is');
    $set($worker, 'rejectedUserIds', [1000001 => time() - 3601]);
    check($invoke($worker, 'replaceRejectedUser', '1000001', ['1000002'], 'ctx') === '1000001', 'Expired rejection is retried');
    check($get($worker, 'rejectedUserIds') === [], 'Expired rejection is purged');

    // --- createTasks: из пачки исправляется только названная задача, цепочка до def (ревью п.2, п.6) ---
    $taskRejected = static fn(array $ids) => response(false, ['error-code' => 400, 'error-data' => ['validation-errors' =>
        array_map(static fn($id) => ['request_id' => (string)$id, 'errors' => [
            ['code' => 'NotSupportedChoice', 'path' => 'responsible_user_id', 'detail' => ''],
        ]], $ids),
    ]]);
    $worker = $newWorker([]);
    $set($worker, 'newTasks', [
        9001110001 => ['text' => 't1', 'responsible_user_id' => 1000001, 'entity_type' => 'leads', 'entity_id' => 1],
        9001110002 => ['text' => 't2', 'responsible_user_id' => 1000002, 'entity_type' => 'leads', 'entity_id' => 2],
    ]);
    $set($worker, 'newTaskFallbacks', [9001110001 => ['1000003', '1000004'], 9001110002 => ['1000002', '1000004']]);
    WorkerAmoHTTP::$responses = [$taskRejected([9001110001]), $taskRejected([9001110001]), response(true)];
    $invoke($worker, 'createTasks');
    check(count(WorkerAmoHTTP::$calls) === 3, 'Batch is resent, not split');
    $sent = WorkerAmoHTTP::$calls[0][0];
    check(array_column($sent, 'request_id') === ['9001110001', '9001110002'], 'Tasks carry request_id');
    check(array_column(WorkerAmoHTTP::$calls[1][0], 'responsible_user_id') === [1000003, 1000002], 'First fallback, other task untouched');
    check(array_column(WorkerAmoHTTP::$calls[2][0], 'responsible_user_id') === [1000004, 1000002], 'Then default responsible');
    check(Logger::$errors === [], 'Recovered tasks are not errors');

    // --- createTasks: все запасные неактивны — задача без ответственного (ревью п.3) ---
    $worker = $newWorker([]);
    $set($worker, 'newTasks', [5 => ['text' => 'c', 'responsible_user_id' => 1000001]]);
    $set($worker, 'newTaskFallbacks', [5 => [1000001]]);
    WorkerAmoHTTP::$responses = [$taskRejected([5]), response(true)];
    $invoke($worker, 'createTasks');
    check(count(WorkerAmoHTTP::$calls) === 2 && !array_key_exists('responsible_user_id', WorkerAmoHTTP::$calls[1][0][0]), 'Task without user');
    check(Logger::$errors === [], 'Task is created, no error');

    // --- createTasks: невосстановимая задача убирается, остальные уходят одной пачкой ---
    $worker = $newWorker([]);
    $set($worker, 'newTasks', [1 => ['text' => 'a', 'responsible_user_id' => 1], 2 => ['text' => 'b', 'responsible_user_id' => 2]]);
    $badEntity = response(false, ['error-code' => 400, 'error-data' => ['validation-errors' => [
        ['request_id' => '1', 'errors' => [['code' => 'NotFound', 'path' => 'entity_id', 'detail' => '']]],
    ]]]);
    WorkerAmoHTTP::$responses = [$badEntity, response(true)];
    $invoke($worker, 'createTasks');
    check(count(WorkerAmoHTTP::$calls) === 2 && array_column(WorkerAmoHTTP::$calls[1][0], 'text') === ['b'], 'Only the bad task is removed');
    check(count(Logger::$errors) === 2, 'Bad task is logged');

    // --- createTasks: сетевая ошибка / неразобранный 400 — без повторов ---
    foreach ([response(false, ['error-code' => 500]), response(false, ['error-code' => 400])] as $error) {
        $worker = $newWorker([]);
        $set($worker, 'newTasks', [1 => ['text' => 'a', 'responsible_user_id' => 1], 2 => ['text' => 'b', 'responsible_user_id' => 2]]);
        WorkerAmoHTTP::$responses = [$error];
        $invoke($worker, 'createTasks');
        check(count(WorkerAmoHTTP::$calls) === 1, 'Unparsed error is not resent');
        check(count(Logger::$errors) === 2, 'Unparsed error is logged');
    }

    echo "PASS: inactive amoCRM responsible fallback tests\n";
}
