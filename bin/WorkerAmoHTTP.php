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
use stdClass;

class WorkerAmoHTTP extends WorkerBase
{
    public  AmoCrmMain $amoApi;
    public int $countReq = 0;
    public float $counterStartTime = 0;

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
        $this->amoApi   = new AmoCrmMain();
        $beanstalk      = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while ($this->needRestart === false) {
            $beanstalk->wait();
        }
    }

    /**
     * Получение запросов на идентификацию номера телефона.
     * @param $tube
     * @return void
     */
    public function onEvents($tube): void
    {
        try {
            $data = json_decode($tube->getBody(), true);
        }catch (\Throwable $e){
            return;
        }
        $res_data = '';
        $funcName = $data['function']??'';
        if(method_exists($this->amoApi, $funcName) && $this->amoApi->isInitDone()){
            $this->needSleep();
            $this->amoApi->refreshToken();
            if(count($data['args']) === 0){
                $res_data = $this->amoApi->$funcName();
            }else{
                $res_data = $this->amoApi->$funcName(...$data['args']??[]);
            }
            $res_data = serialize($res_data);
        }
        $tube->reply($res_data);
    }

    /**
     * Выполнение метода API через свойство worker $this->AmoCrmMain
     * Метод следует вызывать при работе с API из прочих процессов.
     * @param $function
     * @param $args
     */
    public static function invokeAmoApi($function, $args){
        $req = [
            'function' => $function,
            'args' => $args
        ];
        $client = new BeanstalkClient(self::class);
        try {
            $result = $client->request(json_encode($req, JSON_THROW_ON_ERROR), 20);
            $object = unserialize($result, ['allowed_classes' => [PBXAmoResult::class, PBXApiResult::class]]);
        } catch (\Throwable $e) {
            $object = new PBXAmoResult();
            $object->success = false;
            $object->messages[] = $e->getMessage();
        }
        return $object;
    }

    /**
     * Разерешо только 7 запросов в секунду. Принудительное ожидание.
     * @return void
     */
    public function needSleep():void{
        $nowTime   = microtime(true);
        $deltaTime = $nowTime - $this->counterStartTime;
        if( $deltaTime > 1 ){
            $this->countReq = 0;
            $deltaTime = 0;
            $this->counterStartTime = $nowTime;
        }
        $this->countReq++;
        if($deltaTime>0 && $this->countReq>7){
            usleep($deltaTime * 1000000);
            $this->countReq = 0;
        }
    }

}

if(isset($argv) && count($argv) !== 1){
    WorkerAmoHTTP::startWorker($argv??[]);
}