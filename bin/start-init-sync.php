<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2024 Alexey Portnov and Nikolay Beketov
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

use Modules\ModuleAmoCrm\bin\ConnectorDb;
use Modules\ModuleAmoCrm\Lib\Logger;
use Modules\ModuleAmoCrm\Lib\SyncState;
require_once 'Globals.php';

// Старт init-синхронизации: фиксируем initTime в настройках, чтобы после перезапуска
// SyncDaemon продолжил тот же проход, а не начал инкремент с нуля.
$logger   = new Logger('SyncDaemon', 'ModuleAmoCrm');
// ConnectorDb может перезапускаться в момент запуска cron — несколько попыток, иначе init пропустит сутки.
$settings = [];
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $settings = ConnectorDb::invoke('getModuleSettings', [true])['ModuleAmoCrm'] ?? [];
    if (!empty($settings) && is_array($settings)) {
        break;
    }
    if ($attempt < 3) {
        sleep(10);
    }
}
if (empty($settings) || !is_array($settings)) {
    $logger->writeError('Scheduled init skipped: module settings are unavailable (ConnectorDb)');
    exit(1);
}
$now = time();
if (!SyncState::canStartScheduledInit($settings, $now)) {
    $logger->writeInfo(
        ['initSyncTime' => $settings['initSyncTime'] ?? null],
        'Scheduled init skipped: previous init is not finished'
    );
    exit(0);
}
$prevInit = (int)($settings['initSyncTime'] ?? 0);
if ($prevInit > 0) {
    $logger->writeError(['initSyncTime' => $prevInit], 'Previous init expired, replaced by scheduled init');
}
$initTime = $now;
// Атомарно: старт только если initSyncTime не изменился с момента чтения (SyncDaemon мог начать/завершить init).
if (ConnectorDb::invoke('saveInitSettings', [$prevInit, SyncState::startSettings($initTime)]) !== true) {
    $logger->writeError(['expectedInitSyncTime' => $prevInit], 'Scheduled init not started: init state changed or ConnectorDb unavailable');
    exit(1);
}
$logger->writeInfo("Scheduled init started, initTime: $initTime");
