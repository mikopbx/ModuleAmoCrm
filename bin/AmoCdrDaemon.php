<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2021 Alexey Portnov and Nikolay Beketov
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
require_once('Globals.php');

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use MikoPBX\Common\Providers\CDRDatabaseProvider;
use Modules\ModuleAmoCrm\Models\ModuleAmoRequestData;
use DateTime;


class AmoCdrDaemon extends WorkerBase
{
    public const SOURCE_ID    = 'miko-pbx';
    private const   LIMIT   = 50;
    private int     $offset = 1;
    private int $extensionLength;
    public AmoCrmMain $connector;
    public array $innerNums = [];
    private array $users = [];
    public string $referenceDate='';

    private array $cdrRows = [];

    /**
     * Начало загрузки истории звонков в Amo.
     */
    public function start($params):void
    {
        /** @var ModuleAmoCrm $settings */
        $settings = ModuleAmoCrm::findFirst();
        if($settings){
            $this->offset = 1*$settings->offsetCdr;
            $this->offset = max($this->offset,1);
            $this->referenceDate = $settings->referenceDate;
        }else{
            // Настройки не заполенны.
            return;
        }
        $this->connector = new AmoCrmMain();
        [$this->extensionLength, $this->users, $this->innerNums] = AmoCrmMain::updateUsers();
        while (true){
            $this->cdrSync();
            sleep(3);
        }
    }

    private function cdrSync():void
    {
        $this->cdrRows = [];
        $filter = [
            'id>:id: AND start>:referenceDate:',
            'bind'    => [
                'id'  => $this->offset,
                'referenceDate' => $this->referenceDate
            ],
            'order'   => 'id',
            'columns' => 'id,start,src_num,dst_num,billsec,recordingfile,UNIQUEID,linkedid,disposition',
            'limit'   => self::LIMIT,
        ];
        $rows = CDRDatabaseProvider::getCdr($filter);
        $extHostname = $this->connector->getExtHostname();
        $calls    = [];
        foreach ($rows as $row){
            $srcNum = AmoCrmMain::getPhoneIndex($row['src_num']);
            $dstNum = AmoCrmMain::getPhoneIndex($row['dst_num']);
            if( in_array($srcNum, $this->innerNums, true)
                && in_array($dstNum, $this->innerNums, true)){
                // Это внутренний разговор.
                // Не переносим его в AMO.
                continue;
            }
            $phoneCol  = 'src_num';
            if($this->extensionLength < strlen($srcNum)){
                // Это входящий.
                $direction = 'inbound';
                $amoUserId = $this->users[$dstNum]??null;
            }elseif($this->extensionLength < strlen($dstNum)){
                // Исходящий.
                $direction = 'outbound';
                $phoneCol  = 'dst_num';
                $amoUserId = $this->users[$srcNum]??null;
            }else{
                continue;
            }
            if($row['billsec'] < 1){
                // Пропущенный вызов.
                $call_status = 6;
                $link = '';
                $this->cdrRows[$row['linkedid']]['answered'] |= false;
            }else{
                // Это точно не пропущенный вызов.
                $this->cdrRows[$row['linkedid']]['answered'] |= true;
                $link = "https://$extHostname/pbxcore/api/amo-crm/playback?view={$row['recordingfile']}";
                $call_status = 4;
            }
            try {
                $d          = new DateTime($row['start']);
                $created_at = $d->getTimestamp();
            }catch (\Throwable $e){
                Util::sysLogMsg(__CLASS__, $row['UNIQUEID'].' : '.$row['start'].' : '.$e->getMessage());
                continue;
            }
            $call = [
                'direction'           => $direction,
                'uniq'                => $row['UNIQUEID'],
                'duration'            => 1*$row['billsec'],
                'source'              => self::SOURCE_ID,
                'link'                => $link,
                'phone'               => $row[$phoneCol],
                'call_result'         => $row['disposition'],
                'call_status'         => $call_status,
                'created_at'          => $created_at,
                'updated_at'          => $created_at,
                'request_id'          => $row['UNIQUEID'],
                'id'                  => $row['linkedid']
            ];
            if(isset($amoUserId)){
                $call['created_by'] = $amoUserId;
                $call['responsible_user_id'] = $amoUserId;
            }
            $calls[]      = $call;
            $this->offset = $row['id'];
            $this->cdrRows[$row['UNIQUEID']] = $call;
        }

        foreach ($calls as $index => &$call){
            if( $this->cdrRows[$call['id']]['answered'] === 1 && $call['call_status'] === 6){
                // Если вызов отвечен, то не следует загружать информацию о пропущенных.
                unset($calls[$index],$call);
            }else{
                unset($call['id']);
            }
        }
        unset($rows,$call);
        $this->addCalls($calls);
        $this->updateOffset();
    }

    private function addCalls($calls):void
    {
        if(empty($calls)){
            return;
        }
        $result = $this->connector->addCalls($calls);
        $forUnsorted     = [];
        $validationError = [];

        $resultCalls = $result->data['_embedded']['calls']??[];
        foreach ($resultCalls as $row){
            $call = $this->cdrRows[$row['request_id']]??[];
            if(empty($call)){
                continue;
            }
            $this->saveResponse($row['request_id'],$call, $row);
        }
        $errorData = $result->messages['error-data']['errors']??[];
        foreach ($errorData as $err){
            if(!isset($err['title'])){
                continue;
            }
            if($err['status'] === 263){
                // Entity not found
                $forUnsorted[] = $this->cdrRows[$err['request_id']];
            }else{
                $row = $this->cdrRows[$err['request_id']];
                $row['error'] = $err;
                $validationError[] = $row;
            }
        }
        $errorData = $result->messages['error-data']['validation-errors']??[];
        foreach ($errorData as $err){
            $row = $this->cdrRows[$err['request_id']];
            $row['error'] = $err;
            $validationError[] = $row;
        }
        $this->addUnsorted($forUnsorted, $validationError);
        $this->logErrors($validationError);
    }

    /**
     * Добавление неразобранного.
     * @param $forUnsorted
     * @param $validationError
     */
    private function addUnsorted($forUnsorted, &$validationError):void
    {
        $calls = [];
        foreach ($forUnsorted as $call){
            if($call['direction'] !== 'inbound'){
                continue;
            }
            $calls[] = [
                'request_id'  => $call['request_id'],
                'source_name' => self::SOURCE_ID,
                'source_uid'  => self::SOURCE_ID,
                'created_at'  => $call['created_at'],
                "metadata" => [
                    "is_call_event_needed"  => true,
                    "uniq"                  => $call['uniq'],
                    'duration'              => $call['duration'],
                    "service_code"          => "CkAvbEwPam6sad",
                    "link"                  => $call["link"],
                    "phone"                 => $call["phone"],
                    "called_at"             => $call['created_at'],
                    "from"                  => $call['source']
                ],
            ];
        }
        if(empty($calls)){
            return;
        }
        $result = $this->connector->addUnsorted($calls);
        $errorData = $result->messages['error-data']['validation-errors']??[];
        foreach ($errorData as $err){
            $row = $this->cdrRows[$err['request_id']];
            $row['error'] = $err;
            $validationError[] = $row;
        }
        $unsorted = $result->data['_embedded']['unsorted']??[];
        foreach ($unsorted as $row){
            $call = $this->cdrRows[$row['request_id']]??[];
            if(empty($call)){
                continue;
            }
            $this->saveResponse($row['request_id'],$call, $row);
        }
    }

    /**
     * Сохранение результатов запроса.
     * @param $uid
     * @param $request
     * @param $response
     * @param int $isError
     * @return void
     */
    private function saveResponse($uid, $request, $response, int $isError=0):void
    {
        try {
            $resDb = ModuleAmoRequestData::findFirst("UNIQUEID='{$uid}'");
            if(!$resDb){
                $resDb = new ModuleAmoRequestData();
                $resDb->UNIQUEID = $uid;
            }
            $resDb->request  = json_encode($request, JSON_THROW_ON_ERROR);
            $resDb->response = json_encode($response, JSON_THROW_ON_ERROR);
            $resDb->isError  = $isError;
            $resDb->save();
        }catch (\Throwable $e){
            Util::sysLogMsg(__CLASS__, $e->getMessage());
        }
    }

    /**
     * Сохранение информации об ошибке в базу данных.
     * @param $validationError
     */
    private function logErrors($validationError):void
    {
        foreach ($validationError as $row){
            $call     = $row;
            $response = $row['error']??[];
            unset($call['error']);
            $this->saveResponse($row['request_id'],$call, $response, 1);
        }
    }

    /**
     * Обновление текущей позиции CDR.
     */
    private function updateOffset():void
    {
        /** @var ModuleAmoCrm $settings */
        $settings = ModuleAmoCrm::findFirst();
        if(!$settings){
            return;
        }
        $settings->offsetCdr = $this->offset;
        $settings->save();
    }
}

AmoCdrDaemon::startWorker($argv??null);