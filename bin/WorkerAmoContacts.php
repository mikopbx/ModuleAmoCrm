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

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use Modules\ModuleAmoCrm\Models\ModuleAmoUsers;

class WorkerAmoContacts extends WorkerBase
{

    public  AmoCrmMain $amoApi;
    private array   $users = [];

    /**
     * Старт работы листнера.
     *
     * @param $params
     */
    public function start($params):void
    {
        $this->amoApi   = new AmoCrmMain();
        $beanstalk      = new BeanstalkClient(self::class);
        $amoUsers       = ModuleAmoUsers::find('enable=1');
        foreach ($amoUsers as $user){
            if(!is_numeric($user->amoUserId)){
                continue;
            }
            $this->users[1*$user->amoUserId] = preg_replace('/[D]/', '', $user->number);
        }

        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while (true) {
            $beanstalk->wait();
        }
    }

    /** Возвращает данные из кэш.
     *
     * @param $cacheKey
     *
     * @return mixed|null
     */
    public function getCache($cacheKey)
    {
        return $this->di->getManagedCache()->get($cacheKey);
    }

    /**
     * Сохраняет даныне в кэш.
     *
     * @param string $cacheKey ключ
     * @param mixed  $resData  данные
     * @param int    $ttl      время жизни кеша
     */
    public function saveCache(string $cacheKey, $resData, int $ttl = 30): void
    {
        $managedCache = $this->di->getManagedCache();
        $managedCache->set($cacheKey, $resData, $ttl);
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
        $clientData = [];
        if($data['action'] === 'findContacts'){
            $clientData = $this->findContact( $data['numbers'] );
        }elseif($data['action'] === 'interception'){
            $clientData = $this->findContact( [$data['phone']] );
            $userId = $clientData[0]['userId']??null;
            if( isset($this->users[$userId])){
                try {
                    $this->startInterception($data['channel'], $data['id'], $this->users[$userId], $data['phone']);
                }catch (\Throwable $e){
                    Util::sysLogMsg(self::class, $e->getMessage());
                }
            }
        }
        if(!empty($clientData)){
            $this->amoApi->sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_CALL_NAME, ['action' => 'findContact', 'data' => $clientData]);
        }
    }

    /** Запуск звонка "Перехват на ответственного".
     * @param $interceptionChannel
     * @param $interceptionLinkedId
     * @param $src
     * @param $dest_number
     * @return void
     * @throws \Phalcon\Exception
     */
    private function startInterception($interceptionChannel, $interceptionLinkedId, $src, $dest_number):void{
        $am = Util::getAstManager('off');
        $variable    = "_DST_CONTEXT=interception-bridge,origCidName=I:{$dest_number},ALLOW_MULTY_ANSWER=1,_INTECEPTION_CNANNEL={$interceptionChannel},_OLD_LINKEDID={$interceptionLinkedId}";
        $channel     = "Local/{$src}@amo-orig-leg-1";
        $am->Originate($channel, null, null, null,  "Wait", "300", null, "$dest_number <$dest_number>", $variable);
    }

    /**
     * Поиск клиента по номеру.
     * @param $numbers
     * @return array
     */
    private function findContact($numbers):array
    {
        $result = [];
        foreach ($numbers as $phone){
            // 1. Проверка кэш.
            $cacheData = $this->getCache(self::class.':'.$phone);
            if(!empty($cacheData)){
                // Контакт найден.
                $result[] = $cacheData;
                continue;
            }
            if($cacheData === []){
                // Контакт не был найден, поиск производился ранее.
                continue;
            }
            // 2. Поиск в Extensions.
            $parameters = [
                'conditions' => 'type IN ({ids:array}) AND number=:phone:',
                'columns' => 'callerid',
                'bind'       => [
                    'ids' => [Extensions::TYPE_SIP, Extensions::TYPE_QUEUE],
                    'phone' => $phone,
                ],
            ];
            $extensionsData = Extensions::findFirst($parameters);
            if($extensionsData){
                $data = [
                    'id'     => '',
                    'name'   => $extensionsData->callerid,
                    'company'=> '',
                    'userId' => '',
                    'number' => $phone
                ];
                $result[] = $data;
                $this->saveCache(self::class.':'.$phone, $data, 120);
                continue;
            }

            if(strlen($phone) <=5){
                // Номеров короче 5 символов нет в amoCRM.
                continue;
            }
            // 3. Поиск в AmoCRM.
            $response = $this->amoApi->getContactDataByPhone($phone);
            // Нельзя слишком часто отправлять запрос.
            usleep(300000);
            if($response->success){
                $result[] = $response->data;
                $this->saveCache(self::class.':'.$phone, $response->data, 60);
            }else{
                $this->saveCache(self::class.':'.$phone, [], 10);
            }
        }
        return $result;
    }
}

WorkerAmoContacts::startWorker($argv??null);