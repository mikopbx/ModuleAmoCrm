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

use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleAmoCrm\Lib\Logger;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use MikoPBX\Common\Providers\CDRDatabaseProvider;
use Modules\ModuleAmoCrm\Models\ModuleAmoRequestData;
use DateTime;
use Modules\ModuleAmoCrm\Models\ModuleAmoUsers;
use MikoPBX\Common\Models\Extensions;

class AmoCdrDaemon extends WorkerBase
{
    public const  SOURCE_ID    = 'miko-pbx';
    private const LIMIT_CDR   = 50;
    private int   $offset = 1;
    public array  $innerNums = [];
    private array $users = [];
    public string $referenceDate='';

    private AmoCrmMain $amoApi;

    private array $cdrRows = [];
    private string $lastCacheCdr = '';
    private string $lastCacheUsers = '';
    private Logger $logger;
    private array $pipeLines = [];

    /**
     * Начало загрузки истории звонков в Amo.
     */
    public function start($params):void
    {
        $this->logger =  new Logger('cdr-daemon', 'ModuleAmoCrm');
        $this->logger->writeInfo('Starting '. basename(__CLASS__).'...');
        $this->updateSettings();
        while (true){
            $this->pipeLines = $this->amoApi->synchPipeLines();
            $this->updateActiveCalls();
            $this->updateUsers();
            $this->cdrSync();
            sleep(3);
            $this->logger->rotate();
        }
    }

    /**
     * @return void
     */
    private function updateSettings():void
    {
        $this->amoApi = new AmoCrmMain();
        /** @var ModuleAmoCrm $settings */
        $settings = ModuleAmoCrm::findFirst();
        if($settings){
            $this->offset       = 1*$settings->offsetCdr;
            $this->offset        = max($this->offset,1);
            $this->referenceDate = $settings->referenceDate;
            $this->logger->writeInfo("Update settings, Reference date: {$this->referenceDate}, offset: {$this->offset}");
        }else{
            $this->logger->writeError('Settings not found...');
            // Настройки не заполенны.
            return;
        }
        [, $this->users, $this->innerNums] = AmoCrmMain::updateUsers();
        $this->logger->writeInfo("Count users: ".count($this->users)."");

        $this->innerNums[] = 'outworktimes';
        $this->innerNums[] = 'voicemail';
    }

    /**
     * @return void
     */
    private function updateUsers():void
    {
        $filterAmoUsers = ['columns' => 'amoUserId,number'];
        $amoUsers = ModuleAmoUsers::find($filterAmoUsers);
        $amoUsersArray = [];
        foreach ($amoUsers as $user){
            $amoUsersArray[$user->number] = $user->amoUserId;
        }
        unset($amoUsers);
        $extensionFilter = [
            'type IN ({types:array})',
            'bind'    => [
                'types' => [Extensions::TYPE_SIP, Extensions::TYPE_QUEUE]
            ],
            'columns' => 'number,callerid,type'
        ];
        $result = [];
        $extensions = Extensions::find($extensionFilter);
        foreach ($extensions as $extension){
            $result[] = [
                'number' => $extension->number,
                'name' => $extension->callerid,
                'amoId' => $amoUsersArray[$extension->number]??'',
                'type' => $extension->type
            ];
        }
        unset($extensions);
        $md5Cdr = md5(print_r($result, true));
        if($md5Cdr !== $this->lastCacheUsers){
            // Оповещение только если изменилось состояние.
            $this->logger->writeInfo("Update user list. Count: ".count($result));
            $result = $this->amoApi->sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_USERS_NAME, ['data' => $result, 'action' => 'USERS']);
            $this->logger->writeInfo("Result: ". json_encode($result, JSON_THROW_ON_ERROR));
            $this->lastCacheUsers = $md5Cdr;
        }
    }

    /**
     * Обновление информации по текущим звонкам в nchan.
     * @return void
     */
    private function updateActiveCalls():void
    {
        $params  = [];
        $cdrData = CDRDatabaseProvider::getCacheCdr();
        foreach ($cdrData as $cdr){
            $endTime    = '';
            $answerTime = '';
            try {
                $startTime = date(\DateTimeInterface::ATOM, strtotime($cdr['start']));
                if(!empty($cdr['answer'])){
                    $answerTime = date(\DateTimeInterface::ATOM, strtotime($cdr['answer']));
                }
                if(!empty($cdr['endtime'])){
                    $endTime    = date(\DateTimeInterface::ATOM, strtotime($cdr['endtime']));
                }
            }catch (\Exception $e){
                continue;
            }
            $params[] = [
                'start'            => $startTime,
                'answer'           => $answerTime,
                'end'              => $endTime,
                'src'              => $cdr['src_num'],
                'dst'              => $cdr['dst_num'],
                'uid'              => $cdr['UNIQUEID'],
                'id'               => $cdr['linkedid'],
                'user-src'         => $this->users[$cdr['src_num']]??'',
                'user-dst'         => $this->users[$cdr['dst_num']]??'',
                'src-chan'         => $cdr['src_chan'],
                'dst-chan'         => $cdr['dst_chan'],
            ];
        }
        $md5Cdr = md5(print_r($params, true));
        if($md5Cdr !== $this->lastCacheCdr){
            $this->logger->writeInfo("Update active call. Count: ".count($params));
            // Оповещаме только если изменилось состояние.
            $result = $this->amoApi->sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_CDR_NAME, ['data' => $params, 'action' => 'CDRs']);
            $this->logger->writeInfo("Result: ". json_encode($result, JSON_THROW_ON_ERROR));
            $this->lastCacheCdr = $md5Cdr;
        }
    }

    private function cdrSync():void
    {
        $oldOffset = $this->offset;
        $this->cdrRows = [];
        $add_query                     = [
            'columns' => 'id,start,src_num,dst_num,billsec,recordingfile,UNIQUEID,linkedid,disposition,is_app,did',
            'linkedid IN ({linkedid:array})',
            'bind'    => [
                'linkedid' => null,
            ],
            'order'   => 'id',
        ];
        $filter                        = [
            'id>:id: AND start>:referenceDate:',
            'bind'    => [
                'id'  => $this->offset,
                'referenceDate' => $this->referenceDate
            ],
            'group'   => 'linkedid',
            'columns' => 'linkedid',
            'limit'   => self::LIMIT_CDR,
            'add_pack_query' => $add_query
        ];

        try {
            $rows = CDRDatabaseProvider::getCdr($filter);
        }catch (\Throwable $e){
            $rows = [];
        }
        $extHostname = $this->amoApi->getExtHostname();
        $calls    = [];

        $countCDR = count($rows);
        if($countCDR>0){
            $this->logger->writeInfo("Start of CDR synchronization. Count: $countCDR");
        }
        $callCounter = [];
        $pipeline = [];
        foreach ($rows as $row){
            $srcNum = AmoCrmMain::getPhoneIndex($row['src_num']);
            $dstNum = AmoCrmMain::getPhoneIndex($row['dst_num']);
            if( in_array($srcNum, $this->innerNums, true)
                && in_array($dstNum, $this->innerNums, true)){
                // Это внутренний разговор.
                // Не переносим его в AMO.
                $this->offset = $row['id'];
                continue;
            }
            $phoneCol  = 'src_num';
            if(in_array($dstNum, $this->innerNums, true)){
                // Это входящий.
                $direction = 'inbound';
                $amoUserId = $this->users[$dstNum]??null;
                $userPhone = $dstNum;
            }elseif(in_array($srcNum, $this->innerNums, true)){
                // Исходящий.
                $direction = 'outbound';
                $phoneCol  = 'dst_num';
                $amoUserId = $this->users[$srcNum]??null;
                $userPhone = $srcNum;
            }else{
                $this->offset = $row['id'];
                continue;
            }
            if(!isset($this->cdrRows[$row['linkedid']])){
                $this->cdrRows[$row['linkedid']]['first'] = $row['UNIQUEID'];
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

            $this->cdrRows[$row['linkedid']]['haveUser'] |= ($row['is_app'] !== '1');

            try {
                $d          = new DateTime($row['start']);
                $created_at = $d->getTimestamp();
            }catch (\Throwable $e){
                Util::sysLogMsg(__CLASS__, $row['UNIQUEID'].' : '.$row['start'].' : '.$e->getMessage());
                continue;
            }

            $this->offset = $row['id'];
            if(strlen($row[$phoneCol])<5){
                continue;
            }
            $call = [
                'direction'           => $direction,
                'uniq'                => $row['UNIQUEID'],
                'duration'            => 1*$row['billsec'],
                'source'              => self::SOURCE_ID,
                'link'                => $link,
                'phone'               => $row[$phoneCol],
                'call_status'         => $call_status,
                'created_at'          => $created_at,
                'updated_at'          => $created_at,
                'request_id'          => $row['UNIQUEID'],
                'id'                  => $row['linkedid'],
                'is_app'              => $row['is_app']
            ];
            if(!empty($row['did'])){
                $pipelineName = $this->pipeLines[$row['did']]['name']??'-';
                $pipeline[$row['linkedid']] = [
                    'id' => $this->pipeLines[$row['did']]['id']??'',
                    'name' => $pipelineName,
                    'did' => $row['did'],
                    'dst' => $userPhone
                ];
                $call['call_result'] = "| dst: {$userPhone} | {$pipelineName} | did: {$row['did']} |";
            }

            if(!isset($callCounter[$row['linkedid']])){
                $callCounter[$row['linkedid']] = 1;
            }else{
                $callCounter[$row['linkedid']]++;
            }

            if(isset($amoUserId)){
                $call['created_by'] = $amoUserId;
                $call['responsible_user_id'] = $amoUserId;
            }
            $calls[]      = $call;
            $this->cdrRows[$row['UNIQUEID']] = $call;
        }

        $countCDR = count($calls);
        if($countCDR>0){
            $this->logger->writeInfo("CDR synchronization. Step 1. Count: $countCDR");
        }
        foreach ($calls as $index => &$call){
            if($callCounter[$call['id']] === 1){
                unset($call['id'],$call['is_app'],$call['did']);
                continue;
            }
            if($this->cdrRows[$call['id']]['haveUser'] === 1 && $call['is_app'] === '1') {
                // Этот вызов был направлен на сотрудника.
                // Все вызовы на приложения чистим.
                unset($calls[$index], $call);
            }elseif($this->cdrRows[$call['id']]['haveUser'] === 0 && $this->cdrRows[$call['id']]['first'] !== $call['uniq']){
                // Этот вызов не попал на сотрудников, только приложения
                // Оставляем только вызов на первое приложение
                unset($calls[$index], $call);
            }elseif( $this->cdrRows[$call['id']]['answered'] === 1 && $call['call_status'] === 6){
                // Если вызов отвечен, то не следует загружать информацию о пропущенных.
                unset($calls[$index],$call);
            }else{
                unset($call['id'],$call['is_app'],$call['did']);
            }
        }
        unset($rows,$call,$callCounter);

        $countCDR = count($calls);
        if($countCDR>0){
            $this->logger->writeInfo("CDR synchronization. Step 2. Count: $countCDR");
        }
        $result = $this->addCalls($calls, false, $pipeline);

        if($result && $oldOffset !== $this->offset){
            $this->updateOffset();
        }else{
            $this->offset = $oldOffset;
        }
    }

    private function addCalls($calls, bool $mainOnly = false, array $pipeline = []):bool
    {
        if(empty($calls)){
            return true;
        }
        $result = $this->amoApi->addCalls($calls);
        usleep(200000);
        if(($result->messages['error-code']??0) === 401){
            // Ошибка авторизации.
            $this->logger->writeError("Auth: ". json_encode($result->messages));
            return false;
        }
        $this->logResultAddCalls($result);
        $forUnsorted     = [];
        $errorData = $result->messages['error-data']['errors']??[];
        foreach ($errorData as $err){
            if(!isset($err['title'])){
                continue;
            }
            if($err['status'] === 263){
                // Entity not found
                $forUnsorted[] = $this->cdrRows[$err['request_id']];
                $this->logger->writeInfo("Contact not found: ". json_encode($this->cdrRows[$err['request_id']]));
            }else{
                $row = $this->cdrRows[$err['request_id']];
                $row['error'] = $err;
                $this->logger->writeError("Validation: ". json_encode($row));
            }
        }
        if($mainOnly === false){
            $this->addUnsorted($forUnsorted, $pipeline);
        }
        return true;
    }

    /**
     * Запись результата запроса добавления звонка.
     * @param $result
     * @return void
     */
    private function logResultAddCalls($result):void
    {
        $resultCalls = $result->data['_embedded']['calls']??[];
        foreach ($resultCalls as $row){
            $call = $this->cdrRows[$row['request_id']]??[];
            if(empty($call)){
                continue;
            }
            $this->saveResponse($row['request_id'],$call, $row);
        }
    }

    /**
     * Добавление неразобранного.
     * @param $forUnsorted
     * @param $pipeline
     * @param $validationError
     */
    private function addUnsorted($forUnsorted, array $pipeline):void
    {
        // Создаем "неразобранное". На каждый из номеров телефонов.
        $calls = [];
        // При создании неразобранного формируется сделка и контакт,
        // дополнительные звонки по телефонам регистрируем обычным способом.
        $secondaryCalls = []; $uniqPhones = [];
        $outCalls = [];
        foreach ($forUnsorted as $call){
            if($call['direction'] !== 'inbound'){
                unset($call['id'], $call['is_app']);
                $outCalls[] = $call;
                continue;
            }
            if(in_array($call["phone"], $uniqPhones, true)){
                unset($call['id']);
                $secondaryCalls[] = $call;
                continue;
            }
            $uniqPhones[] = $call["phone"];
            $callData = [
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
                "_embedded" => [
                    'contacts' => [
                        [
                            'name' => $call["phone"],
                            'custom_fields_values' => [
                                [
                                    'field_code' => 'PHONE',
                                    'values' => [['value' => $call["phone"]]]
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            $pipelineID = $pipeline[$call['id']]['id']??'';
            if(!empty($pipelineID)){
                $callData['pipeline_id'] =  1*$pipelineID;
            }
            $calls[] = $callData;
        }
        $result = $this->amoApi->createContacts($outCalls);
        $errorData = $result->messages['error-data']??[];
        if(!empty($errorData)){
            $this->logger->writeError("Add contacts: ". json_encode($errorData));
        }
        usleep(200000);
        $this->addCalls($outCalls, true);
        usleep(200000);
        if(empty($calls)){
            return;
        }
        $result = $this->amoApi->addUnsorted($calls);
        usleep(200000);
        $errorData = $result->messages['error-data']['validation-errors']??[];
        foreach ($errorData as $err){
            $row = $this->cdrRows[$err['request_id']];
            $row['error'] = $err;
            $this->logger->writeError("Validation: ". json_encode($row));
        }
        $unsorted = $result->data['_embedded']['unsorted']??[];
        foreach ($unsorted as $row){
            $call = $this->cdrRows[$row['request_id']]??[];
            if(empty($call)){
                continue;
            }
            $this->saveResponse($row['request_id'],$call, $row);
        }

        /**
         * Подгружаем оставшиеся записи.
         */
        $this->addCalls($secondaryCalls, true);
        usleep(200000);
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

AmoCdrDaemon::startWorker($argv??[]);