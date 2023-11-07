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

class SyncDaemon extends WorkerBase
{
    public const LIMIT_PART = 249;
    public const COLUMN_UPDATE_NAME = "updated_at";
    public const COLUMN_CREATE_NAME = "created_at";

    private string $columnName = self::COLUMN_UPDATE_NAME;

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
        $type = $argv[2]??'';
        if($type === 'init'){
            $this->columnName = self::COLUMN_CREATE_NAME;
        }
        while ($this->needRestart === false) {
            $this->syncContacts(AmoCrmMain::ENTITY_CONTACTS);
            $this->syncContacts(AmoCrmMain::ENTITY_LEADS);
            $this->syncContacts(AmoCrmMain::ENTITY_COMPANIES);
            if($type === 'init'){
                exit();
            }
            sleep(10);
        }
    }

    /**
     * Синхронизация контактов.
     * @param string $entityType
     * @return void
     */
    private function syncContacts(string $entityType):void
    {
        $allSettings = ConnectorDb::invoke('getModuleSettings', [true]);
        $fieldName = "last".ucfirst($entityType)."SyncTime";
        $updateFunc = [
            AmoCrmMain::ENTITY_CONTACTS => 'updatePhoneBook',
            AmoCrmMain::ENTITY_COMPANIES => 'updatePhoneBook',
            AmoCrmMain::ENTITY_LEADS => 'updateLeads'
        ];
        if(!empty($allSettings) && is_array($allSettings)){
            $settings = (object)$allSettings['ModuleAmoCrm'];
            if($this->columnName === self::COLUMN_CREATE_NAME){
                $settings->$fieldName = 0;
            }
        }else{
            return;
        }
        if((int)$settings->portalId <=0 ){
            // Нет подключения к порталу.
            return;
        }
        $endTime = time();
        $url = "https://".AmoCrmMain::EMPTY_HOST_VALUE."/api/v4/$entityType";
        $params = [
            'order['.$this->columnName.']' => 'desc',
            'filter['.$this->columnName.'][from]'  => $settings->$fieldName,
            'filter['.$this->columnName.'][to]'    => $endTime,
            'limit' => self::LIMIT_PART
        ];
        if($entityType === AmoCrmMain::ENTITY_LEADS){
            $params['with'] = 'contacts';
        }
        $nextPage = $url."?".http_build_query($params);
        while(!empty($nextPage)){
            $result = WorkerAmoHTTP::invokeAmoApi('getChangedEntity', [$nextPage, $entityType]);
            $nextPage = $result->data['nextPage'];
            $chunks = array_chunk($result->data[$entityType], 50, false);
            foreach ($chunks as $chunk){
                ConnectorDb::invoke($updateFunc[$entityType], [[ 'update' => $chunk]]);
                sleep(1);
            }
        }
        ConnectorDb::invoke('saveNewSettings', [[$fieldName => $endTime]]);
    }
}

if(isset($argv) && count($argv) !== 1){
    SyncDaemon::startWorker($argv??[]);
}