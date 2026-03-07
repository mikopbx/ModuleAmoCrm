<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2025 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

use MikoPBX\Core\System\System;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleAmoCrm\Lib\AmoCrmConf;
require_once 'Globals.php';

$moduleEnable = PbxExtensionUtils::isEnabled('ModuleAmoCrm');
if(!$moduleEnable){
    exit(1);
}
$conf = new AmoCrmConf();
$workers = $conf->getModuleWorkers();
foreach ($workers as $workerData) {
    $WorkerPID = Processes::getPidOfProcess($workerData['worker']);
    print_r($WorkerPID.PHP_EOL);
    if (empty($WorkerPID)) {
        Processes::processPHPWorker($workerData['worker']);
        SystemMessages::sysLogMsg('AMO_SAFE', "Service {$workerData['worker']} started.", LOG_NOTICE);
    }else{
        // Проверка дубликата процесса.
        $allButLast = array_slice(explode(' ', $WorkerPID), 0, -1);
        if(!empty($allButLast)){
            // Завершаем дубликаты процессов.
            $bbPath = Util::which('busybox');
            shell_exec("$bbPath kill -SIGUSR2 ". implode(" ", $allButLast));
        }
    }
}

// Проверка размеров лог-файлов. Если ротация не сработала и файл превысил 2x лимит — обрезаем.
$logDir = System::getLogDir() . '/ModuleAmoCrm';
$maxSize = 20 * 1024 * 1024; // 2x от лимита ротации (10MB)
if (is_dir($logDir)) {
    $files = glob($logDir . '/*.log');
    if (is_array($files)) {
        foreach ($files as $file) {
            $size = filesize($file);
            if ($size !== false && $size > $maxSize) {
                $logName = basename($file);
                SystemMessages::sysLogMsg('AMO_SAFE', "Log $logName exceeds limit ({$size} bytes), truncating.", LOG_WARNING);
                $fh = fopen($file, 'w');
                if ($fh !== false) {
                    fclose($fh);
                }
            }
        }
    }
}
