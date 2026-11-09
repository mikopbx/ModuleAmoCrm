<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
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

use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleAmoCrm\Lib\IntegrationSync;
require_once 'Globals.php';

// Настройка интеграции в amoCRM: пользователи, настройки виджета, webhook.
// Использование: sync-integration.php [all|users|widget] [задержка, с]
$mode  = (string)($argv[1] ?? IntegrationSync::MODE_ALL);
$delay = max(0, min(300, (int)($argv[2] ?? 0)));

if (!PbxExtensionUtils::isEnabled('ModuleAmoCrm')) {
    exit(0);
}
if ($delay > 0) {
    sleep($delay);
}

// Запуски выполняются по очереди: изменения настроек во время синхронизации
// подхватит следующий запуск.
$lockFile = '/tmp/amo_integration_sync.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    exit(1);
}
try {
    (new IntegrationSync())->run($mode);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
