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

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleAmoCrm\Lib\Logger;

class WorkerAmoCrmAMI extends WorkerBase
{
    /**
     * @return string
     */
    public static function getChannelUrl(): string
    {
        $port = PbxSettings::getValueByKey('WEBPort');
        return "http://127.0.0.1:{$port}/pbxcore/api/nchan/pub/pbx-events";
    }

    private bool    $useInterception = false;
    private int     $lastUpdateSettings = 0;

    private $beanstalk;
    private Logger $logger;

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
        exit(0);
    }

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->beanstalk = new BeanstalkClient(ConnectorDb::class);
        $this->logger = new Logger('WorkerAmoCrmAMI', 'ModuleAmoCrm');

        $this->am     = Util::getAstManager();
        $this->setFilter();
        $this->checkUpdateSettings();

        $this->am->addEventHandler("userevent", [$this, "callback"]);
        while ($this->needRestart === false) {
            $result = $this->am->waitUserEvent(true);
            if (!$result) {
                // Нужен реконнект.
                usleep(100000);
                $this->am = Util::getAstManager();
                $this->setFilter();
            }
        }
    }

    private function checkUpdateSettings():void
    {
        if(time() - $this->lastUpdateSettings <= 10){
            return;
        }
        $this->lastUpdateSettings = time();
        $allSettings = ConnectorDb::invoke('getModuleSettings', [true]);
        if(!empty($allSettings) && is_array($allSettings)){
            // Флаг приходит из integer-столбца числом (#65).
            $this->useInterception = intval($allSettings['ModuleAmoCrm']['useInterception'] ?? 0) === 1;
        }
    }

    /**
     * Установка фильтра
     *
     */
    private function setFilter():void
    {
        $pingTube = $this->makePingTubeName(self::class);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: '.$pingTube];
        $this->am->sendRequestTimeout('Filter', $params);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: Interception'];
        $this->am->sendRequestTimeout('Filter', $params);
    }

    /**
     * Событие для перехвата на ответственного.
     * @param $data
     * @return void
     */
    private function interception($data):void
    {
        if(!$this->useInterception){
            return;
        }
        $params = [
            'id'      => $data['Linkedid'],
            'date'    => date(\DateTimeInterface::ATOM),
            'phone'   => $data['CALLERID'],
            'channel' => $data['chan1c'],
            'did'     => $data['FROM_DID'],
            'action'  => 'interception'
        ];
        $this->beanstalk->publish(json_encode($params));
    }

    /**
     * Функция обработки оповещений.
     * @param $parameters
     * @return void
     */
    public function callback($parameters):void{

        if ($this->replyOnPingRequest($parameters)){
            return;
        }
        if (stripos($parameters['UserEvent'] ?? '','InterceptionAMO' ) !== false) {
            $this->checkUpdateSettings();
            $this->interception($parameters);
        }
    }
}

WorkerAmoCrmAMI::startWorker($argv??[]);