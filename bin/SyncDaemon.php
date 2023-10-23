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

use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use Modules\ModuleAmoCrm\Lib\PBXAmoResult;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use stdClass;

class SyncDaemon extends WorkerBase
{

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
     * @param $params
     */
    public function start($params):void
    {
        while ($this->needRestart === false) {
            $this->syncLeads();
            $this->syncContacts(AmoCrmMain::ENTITY_COMPANIES);
            $this->syncContacts(AmoCrmMain::ENTITY_CONTACTS);
            sleep(10);
        }
    }

    /**
     * Синхронизация сделок.
     * @return void
     */
    private function syncLeads():void
    {
        $allSettings = ConnectorDb::invoke('getModuleSettings', [true]);
        if(!empty($allSettings) && is_array($allSettings)){
            $settings = (object)$allSettings['ModuleAmoCrm'];
        }else{
            return;
        }
        $entityType = 'leads';
        $endTime = time();
        $result = WorkerAmoHTTP::invokeAmoApi('getChangedLeads', [$settings->lastLeadsSyncTime, $endTime]);
        if(empty($result->data[$entityType])){
            return;
        }
        $data = [ 'update' => $result->data[$entityType]];
        ConnectorDb::invoke('updateLeads', [$data]);
        while(!empty($result->data['nextPage'])){
            $result = WorkerAmoHTTP::invokeAmoApi('getChangedLeads', [$settings->lastLeadsSyncTime, $endTime, $result->data['nextPage']]);
            $data = [ 'update' => $result->data[$entityType]];
            ConnectorDb::invoke('updateLeads', [$data]);
        }
        ConnectorDb::invoke('saveNewSettings', [['lastLeadsSyncTime' => $endTime]]);
    }

    /**
     * Синхронизация контактов.
     * @param $entityType
     * @return void
     */
    private function syncContacts($entityType):void
    {
        $allSettings = ConnectorDb::invoke('getModuleSettings', [true]);
        if(!empty($allSettings) && is_array($allSettings)){
            $settings = (object)$allSettings['ModuleAmoCrm'];
        }else{
            return;
        }
        $endTime = time();
        $result = WorkerAmoHTTP::invokeAmoApi('getChangedContacts', [$settings->lastContactsSyncTime, $endTime, $entityType]);
        if(empty($result->data[$entityType])){
            return;
        }
        $data = ['update' => $result->data[$entityType]];
        ConnectorDb::invoke('updatePhoneBook', [$data]);
        while(!empty($result->data['nextPage'])){
            $result = WorkerAmoHTTP::invokeAmoApi('getChangedContacts', [$settings->lastContactsSyncTime, $endTime, $entityType, $result->data['nextPage']]);
            $data = ['update' => $result->data[$entityType]];
            ConnectorDb::invoke('updatePhoneBook', [$data]);
        }
        $fieldName = "last".ucfirst($entityType)."SyncTime";
        ConnectorDb::invoke('saveNewSettings', [[$fieldName => $endTime]]);
    }
}

if(isset($argv) && count($argv) !== 1){
    SyncDaemon::startWorker($argv??[]);
}