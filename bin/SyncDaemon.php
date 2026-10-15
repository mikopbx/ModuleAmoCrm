<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2022 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleAmoCrm\bin;
require_once 'Globals.php';

use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use Modules\ModuleAmoCrm\Lib\Logger;
use Modules\ModuleAmoCrm\Lib\SyncPageRetrier;

class SyncDaemon extends WorkerBase
{
    public const LIMIT_PART = 249;
    public const COLUMN_UPDATE_NAME = "updated_at";
    public const COLUMN_CREATE_NAME = "created_at";

    private Logger $logger;

    private int $initTime = 0;
    private int $portalId = 0;

    public int $lastContactsSyncTime = 0;
    public int $lastCompaniesSyncTime = 0;
    public int $lastLeadsSyncTime = 0;

    /**
     * Handles the received signal.
     *
     * @param int $signal The signal to handle.
     *
     * @return void
     */
    public function signalHandler(int $signal): void
    {
        parent::signalHandler($signal);
        cli_set_process_title('SHUTDOWN_'.cli_get_process_title());
    }

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger =  new Logger('SyncDaemon', 'ModuleAmoCrm');
        $this->logger->writeInfo('Starting '. basename(__CLASS__).'...');

        // Защита от параллельных экземпляров SyncDaemon
        $lockFile = '/tmp/amo_sync_daemon.lock';
        $lockFp = fopen($lockFile, 'w');
        if ($lockFp === false) {
            $this->logger->writeError('Failed to open SyncDaemon lock file');
            return;
        }
        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            $this->logger->writeInfo('Another SyncDaemon instance is running, exiting');
            fclose($lockFp);
            return;
        }

        try {
            while ($this->needRestart === false) {
                $this->checkInitMode();
                $this->syncContacts(AmoCrmMain::ENTITY_CONTACTS);
                $this->syncContacts(AmoCrmMain::ENTITY_LEADS);
                $this->syncContacts(AmoCrmMain::ENTITY_COMPANIES);
                if($this->initTime > 0){
                    // Очищаем все записи, что не были обновлены при init.
                    ConnectorDb::invoke('deleteWithFailTime', [$this->initTime]);
                    // Init завершён — ставим текущее время, чтобы инкрементальная синхронизация
                    // начала с этого момента, а не качала всё заново (from=1).
                    $now = time();
                    ConnectorDb::invoke('saveNewSettings', [['lastContactsSyncTime' => $now, 'lastCompaniesSyncTime' => $now, 'lastLeadsSyncTime' => $now]]);
                    $this->logger->writeInfo('End init mode...');
                    sleep(1);
                }else{
                    sleep(10);
                }
            }
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Проверка на режим инициализации.
     * @return void
     */
    private function checkInitMode():void
    {
        $settings = ConnectorDb::invoke('getModuleSettings', [true])['ModuleAmoCrm']??[];
        $this->portalId              = (int)($settings['portalId']??0);
        $this->lastContactsSyncTime  = (int)($settings['lastContactsSyncTime']??0);
        $this->lastCompaniesSyncTime = (int)($settings['lastCompaniesSyncTime']??0);
        $this->lastLeadsSyncTime     = (int)($settings['lastLeadsSyncTime']??0);

        $syncTime = $this->lastContactsSyncTime + $this->lastCompaniesSyncTime + $this->lastLeadsSyncTime;
        if($syncTime === 0){
            // Это INIT режим, активируется раз в сутки по cron.
            // Контакты и лиды синхронизируются с начала.
            $this->initTime = time();
            $this->logger->writeInfo('Start init mode...');
        }else{
            $this->initTime = 0;
        }
    }

    /**
     * Синхронизация контактов.
     * @param string $entityType
     * @return void
     */
    private function syncContacts(string $entityType):void
    {
        if($this->portalId <=0 ){
            // Нет подключения к порталу.
            return;
        }
        $endTime = time();
        $fieldName = "last".ucfirst($entityType)."SyncTime";
        $updateFunc = [
            AmoCrmMain::ENTITY_CONTACTS  => 'updatePhoneBook',
            AmoCrmMain::ENTITY_COMPANIES => 'updatePhoneBook',
            AmoCrmMain::ENTITY_LEADS     => 'updateLeads'
        ];
        if($this->initTime === 0){
            $params = [
                'order['.self::COLUMN_UPDATE_NAME.']' => 'desc',
                'filter['.self::COLUMN_UPDATE_NAME.'][from]'  => $this->$fieldName,
                'filter['.self::COLUMN_UPDATE_NAME.'][to]'    => $endTime,
                'limit' => self::LIMIT_PART
            ];
        }else{
            $params = [
                'order[id]' => 'desc',
                'limit' => self::LIMIT_PART
            ];
        }
        $url = "https://".AmoCrmMain::EMPTY_HOST_VALUE."/api/v4/$entityType";
        if($entityType === AmoCrmMain::ENTITY_LEADS){
            $params['with'] = 'contacts';
        }
        $nextPage = $url."?".http_build_query($params);
        $count = 0;
        while(!empty($nextPage)){
            $pageUrl = $nextPage;
            $logUrl = parse_url($nextPage, PHP_URL_PATH);
            $logQuery = parse_url($nextPage, PHP_URL_QUERY);
            $this->logger->writeInfo("SYNC: $count, GET '$entityType' ".$logUrl.($logQuery ? "?$logQuery" : ''));
            $retryResult = SyncPageRetrier::run(
                $pageUrl,
                function (string $retryUrl) use ($entityType): array {
                    $result = WorkerAmoHTTP::invokeAmoApi('getChangedEntity', [$retryUrl, $entityType]);
                    $success = $result !== false
                        && empty($result->messages)
                        && isset($result->data[$entityType]);
                    if (!$success) {
                        $errCtx = [
                            'messages' => isset($result->messages) ? $result->messages : null,
                            'data' => isset($result->data) ? $result->data : null,
                            'url' => $retryUrl,
                        ];
                        $this->logger->writeError($errCtx, 'Fail getChangedEntity attempt');
                    }
                    return ['success' => $success, 'result' => $result];
                },
                static function (): void {
                    sleep(10);
                }
            );
            $result = $retryResult['result'];

            if (!$retryResult['success']) {
                $errCtx = [
                    'entityType' => $entityType,
                    'url' => $pageUrl,
                    'from' => $this->$fieldName,
                    'to' => $endTime,
                    'attempts' => $retryResult['attempts'],
                    'messages' => isset($result->messages) ? $result->messages : null,
                    'data' => isset($result->data) ? $result->data : null,
                ];
                $this->logger->writeError($errCtx, 'Fail getChangedEntity after retries, advance sync interval');
                break;
            }

            $nextPage = $result->data['nextPage'];
            $count += count($result->data[$entityType]);
            $chunks   = array_chunk($result->data[$entityType], 25, false);
            foreach ($chunks as $chunk){
                $data = [
                    'update' => $chunk,
                    'source' => self::class
                ];
                if($this->initTime > 0){
                    $data['initTime'] = $this->initTime;
                }
                $resultSave = ConnectorDb::invoke($updateFunc[$entityType], [$data]);
                if(!isset($resultSave['update-all']) && $entityType !== AmoCrmMain::ENTITY_LEADS){
                    $this->logger->writeError($resultSave, 'Fail save contact data...');
                }
                usleep(100000);
            }
            $this->logger->rotate();
        }
        ConnectorDb::invoke('saveNewSettings', [[$fieldName => $endTime]],false);
    }
}

if(isset($argv) && count($argv) !== 1){
    SyncDaemon::startWorker($argv??[]);
}
