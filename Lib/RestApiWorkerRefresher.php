<?php

namespace Modules\ModuleAmoCrm\Lib;

use MikoPBX\Core\System\Processes;
use MikoPBX\PBXCoreREST\Workers\WorkerApiCommands;
use ReflectionClass;

/**
 * Refreshes the long-running REST API worker pool after module state changes.
 *
 * WorkerApiCommands caches the enabled module registry. Without a refresh,
 * requests can intermittently reach a worker which still considers this
 * module disabled until that process is restarted for another reason.
 */
final class RestApiWorkerRefresher
{
    /**
     * Restart the API worker pool using the least disruptive action supported
     * by the installed MikoPBX version.
     */
    public static function restart(): void
    {
        $reflection = new ReflectionClass(Processes::class);
        $validActions = $reflection->getConstant('VALID_ACTIONS');
        $supportsSoftRestart = is_array($validActions)
            && in_array('soft-restart', $validActions, true);

        self::restartUsing(
            [Processes::class, 'processPHPWorker'],
            $supportsSoftRestart,
            WorkerApiCommands::class
        );
    }

    /**
     * Dispatch the version-compatible restart command.
     *
     * Public to keep the compatibility decision independently testable.
     *
     * @param callable $processWorker
     * @param bool $supportsSoftRestart
     * @param string $workerClass
     */
    public static function restartUsing(
        callable $processWorker,
        bool $supportsSoftRestart,
        string $workerClass
    ): void {
        if ($supportsSoftRestart) {
            call_user_func($processWorker, $workerClass, 'start', 'soft-restart');
            return;
        }

        call_user_func($processWorker, $workerClass);
    }
}
