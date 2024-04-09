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

use MikoPBX\Common\Models\LanInterfaces;
use MikoPBX\Core\System\Storage;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleAmoCrm\Lib\ClientHTTP;
use Modules\ModuleAmoCrm\Lib\Logger;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use MikoPBX\Common\Providers\CDRDatabaseProvider;
use DateTime;
use MikoPBX\Common\Models\Extensions;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Throwable;
use DateTimeInterface;
use Exception;

class AmoCdrDaemon extends WorkerBase
{
    public const  SOURCE_ID    = 'miko-pbx';
    private const LIMIT_CDR   = 50;
    private int   $offset = 1;
    public array  $innerNums = [];
    private array $users = [];
    public string $referenceDate='';
    private array $cdrRows = [];
    private string $lastCacheCdr = '';
    private Logger $logger;
    private string $extHostname = '';
    private int $lastSyncTime = 0;
    private int $portalId = 0;
    private array $entitySettings = [];

    private bool $disableDetailedCdr = false;
    public string $respCallAnsweredHaveClient = '';
    public string $respCallAnsweredNoClient = '';
    public string $respCallMissedNoClient = '';
    public string $respCallMissedHaveClient = '';

    private array $newContacts = [];
    private array $newLeads = [];
    private array $newUnsorted = [];
    private array $newTasks = [];
    private array $incompleteAnswered = [];
    private array $createdLeads = []; // Кэш создана ли сделка для звонка. 1 звонок = 1 сделка

    // ВИДЫ ЗВОНКОВ.
    // Входящие
    public const MISSING_UNKNOWN    = 'MISSING_UNKNOWN';
    public const MISSING_KNOWN      = 'MISSING_KNOWN';
    public const INCOMING_UNKNOWN   = 'INCOMING_UNKNOWN';
    public const INCOMING_KNOWN     = 'INCOMING_KNOWN';
    // Исходящие
    public const OUTGOING_UNKNOWN   = 'OUTGOING_UNKNOWN';
    public const OUTGOING_KNOWN     = 'OUTGOING_KNOWN';
    public const OUTGOING_KNOWN_FAIL= 'OUTGOING_KNOWN_FAIL';

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
     * Начало загрузки истории звонков в Amo.
     */
    public function start($argv):void
    {
        $res = LanInterfaces::findFirst("internet = '1'")->toArray();
        $this->extHostname  = $res['exthostname']??'';
        $this->logger =  new Logger('cdr-daemon', 'ModuleAmoCrm');
        $this->logger->writeInfo('Starting '. basename(__CLASS__).'...');

        $workIsAllowed = false;
        while ($this->needRestart === false){
            if(time() - $this->lastSyncTime > 10){
                ConnectorDb::invoke('updateSettings', [], false);
                WorkerAmoHTTP::invokeAmoApi('syncPipeLines', [$this->portalId]);
                ConnectorDb::invoke('fillEntitySettings', []);
                $workIsAllowed = $this->updateSettings();
            }
            if($workIsAllowed){
                $this->updateActiveCalls();
                $this->updateUsers();
                $this->cdrSync();
            }
            sleep(1);
            $this->logger->rotate();
        }
    }

    /**
     * Получение актуальных настроек.
     * @return void
     */
    private function updateSettings():bool
    {
        $allSettings = ConnectorDb::invoke('getModuleSettings', [false]);
        if(!empty($allSettings) && is_array($allSettings)){
            $oldOffset = $this->offset;
            $this->offset        = max(1*$allSettings['ModuleAmoCrm']['offsetCdr']??1,1);
            $this->referenceDate = $allSettings['ModuleAmoCrm']['referenceDate']??'';
            $this->portalId      = (int)($allSettings['ModuleAmoCrm']['portalId']??0);

            $this->disableDetailedCdr         = ($allSettings['ModuleAmoCrm']['disableDetailedCdr']??'0') === '1';
            $this->respCallAnsweredHaveClient = ($allSettings['ModuleAmoCrm']['respCallAnsweredHaveClient']??'');
            $this->respCallAnsweredNoClient   = ($allSettings['ModuleAmoCrm']['respCallAnsweredNoClient']??'');
            $this->respCallMissedNoClient     = ($allSettings['ModuleAmoCrm']['respCallMissedNoClient']??'');
            $this->respCallMissedHaveClient   = ($allSettings['ModuleAmoCrm']['respCallMissedHaveClient']??'');

            if($oldOffset !== $this->offset){
                $this->logger->writeInfo("Update settings, Reference date: $this->referenceDate, offset: $this->offset");
            }
            $entSettings = $allSettings['ModuleAmoEntitySettings'];
            $this->entitySettings = [];
            foreach ($entSettings as $entSetting){
                $this->entitySettings[$entSetting['type']][$entSetting['did']] = $entSetting;
            }
            $lastContactsSyncTime = (int)($allSettings['ModuleAmoCrm']['lastContactsSyncTime']??0);
            $workIsAllowed = $lastContactsSyncTime > 0;
        }else{
            $this->logger->writeError('Settings not found...');
            return false;
        }
        [, $this->users, $this->innerNums] = AmoCrmMain::updateUsers();
        $this->innerNums[] = 'outworktimes';
        $this->innerNums[] = 'voicemail';

        $this->lastSyncTime = time();
        return $workIsAllowed;
    }

    /**
     * Обновление списка пользователей в nchan.
     * @return void
     */
    private function updateUsers():void
    {
        $usersAmo = ConnectorDb::invoke('getPortalUsers', [1]);
        $amoUsersArray = [];
        foreach ($usersAmo as $user){
            $amoUsersArray[$user['number']] = $user['amoUserId'];
        }
        $extensionFilter = [
            'type IN ({types:array})',
            'bind'    => [
                'types' => [Extensions::TYPE_SIP, Extensions::TYPE_QUEUE]
            ],
            'columns' => 'number,callerid,type'
        ];
        $data = [];
        $extensions = Extensions::find($extensionFilter);
        foreach ($extensions as $extension){
            $data[] = [
                'number' => $extension->number,
                'name' => $extension->callerid,
                'amoId' => $amoUsersArray[$extension->number]??'',
                'type' => $extension->type
            ];
        }
        unset($extensions);
        // Оповещение только если изменилось состояние.
        $result = ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_CALL_NAME, ['data' => $data, 'action' => 'USERS']);
        if(!$result->success){
            $this->logger->writeError("Update user list. Count: ".count($data));
            try {
                $this->logger->writeError("Send data: ". json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                $this->logger->writeError("Result: ". json_encode($result, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            }catch (Throwable $e){
                $this->logger->writeError($e->getMessage());
            }
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
            $dstUser = $this->users[$cdr['dst_num']]??'';
            $srcNum = AmoCrmMain::getPhoneIndex($cdr['src_num']);
            $dstNum = AmoCrmMain::getPhoneIndex($cdr['dst_num']);
            if( !empty($cdr['answer'])
                && !empty($dstUser)
                && !isset($this->incompleteAnswered[$srcNum]['finished'])
                && !in_array($srcNum, $this->innerNums, true)
                && in_array($dstNum, $this->innerNums, true)){
                // Входящий вызов отвечен сотрудником
                $this->incompleteAnswered[$srcNum] = [
                    'uniq'                => $cdr['UNIQUEID'],
                    'phone'               => $cdr['src_num'],
                    'id'                  => $cdr['linkedid'],
                    'did'                 => $cdr['did'],
                    'responsible'         => $dstUser,
                    'type'                => null
                ];
                $this->logger->writeInfo($this->incompleteAnswered[$srcNum], "New incomplete answered");
            }

            $endTime    = '';
            $answerTime = '';
            try {
                $startTime = date(DateTimeInterface::ATOM, strtotime($cdr['start']));
                if(!empty($cdr['answer'])){
                    $answerTime = date(DateTimeInterface::ATOM, strtotime($cdr['answer']));
                }
                if(!empty($cdr['endtime'])){
                    $endTime    = date(DateTimeInterface::ATOM, strtotime($cdr['endtime']));
                }
            }catch (Exception $e){
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
                'user-dst'         => $dstUser,
                'src-chan'         => $cdr['src_chan'],
                'dst-chan'         => $cdr['dst_chan'],
            ];
        }
        $md5Cdr = md5(print_r($params, true));
        if($md5Cdr !== $this->lastCacheCdr){
            // Оповещаме только если изменилось состояние.
            ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_CALL_NAME, ['data' => $params, 'action' => 'CDRs']);
            $this->lastCacheCdr = $md5Cdr;
        }
    }

    /**
     * Начало синхронизации истории звонков.
     * @return void
     */
    private function cdrSync():void
    {
        $oldOffset = $this->offset;
        $this->cdrRows = [];
        $add_query                     = [
            'columns' => 'id,start,answer,src_num,dst_num,billsec,recordingfile,UNIQUEID,linkedid,disposition,is_app,did',
            'linkedid IN ({linkedid:array})',
            'bind'    => [
                'linkedid' => null,
            ],
            'order'   => 'start,answer,id',
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
        }catch (Throwable $e){
            $rows = [];
        }
        $calls    = [];

        $countCDR = count($rows);
        if($countCDR>0){
            $this->logger->writeInfo("Start of CDR synchronization. Count: $countCDR");
        }
        $callCounter = [];
        foreach ($rows as $row){
            $id = $row['linkedid'];
            // Чистим незавершенные вызовы, если необходимо.
            $srcNum = AmoCrmMain::getPhoneIndex($row['src_num']);
            $dstNum = AmoCrmMain::getPhoneIndex($row['dst_num']);
            $this->logger->writeInfo("From $srcNum to $dstNum, linkedid: $id, UNIQUEID:{$row['UNIQUEID']}, id: {$row['id']}");
            if(isset($this->incompleteAnswered[$srcNum])){
                $this->cdrRows[$id]['incompleteType'] = $this->incompleteAnswered[$srcNum]['type'];
            }
            unset($this->incompleteAnswered[$srcNum],$this->incompleteAnswered[$dstNum]);
            if(file_exists($row['recordingfile'])){
                $this->cdrRows[$id]['records'][] = $row['recordingfile'];
                $this->cdrRows[$id]['duration'] += 1*$row['billsec'];
            }
            if( in_array($srcNum, $this->innerNums, true)
                && in_array($dstNum, $this->innerNums, true)){
                // Это внутренний разговор.
                // Не переносим его в AMO.
                $this->offset = max($this->offset,$row['id']);
                $this->logger->writeInfo("Is Inner call... linkedid: $id");
                continue;
            }
            $phoneCol  = 'src_num';
            if(in_array($dstNum, $this->innerNums, true)){
                // Это входящий.
                $direction = 'call_in';
                $amoUserId = $this->users[$dstNum]??null;
                $userPhone = $dstNum;
            }elseif(in_array($srcNum, $this->innerNums, true)){
                // Исходящий.
                $direction = 'call_out';
                $phoneCol  = 'dst_num';
                $amoUserId = $this->users[$srcNum]??null;
                $userPhone = $srcNum;
            }elseif(empty($dstNum) && strlen($srcNum) > 6){
                // Исходящий.
                $direction = 'call_in';
                $amoUserId = null;
                $userPhone = '';
            }else{
                $this->offset = max($this->offset,$row['id']);
                $this->logger->writeInfo("Is unknown call... linkedid: $id");
                continue;
            }
            if(!isset($this->cdrRows[$id])){
                $this->cdrRows[$id]['first']    = $row['UNIQUEID'];
                $this->cdrRows[$id]['haveUser'] = false;
                $this->cdrRows[$id]['duration'] = 0;
            }

            if($row['billsec'] < 1){
                // Пропущенный вызов.
                $call_status = 6;
                $link = '';
                $this->cdrRows[$id]['answered'] |= false;
                $this->setMissedData($id, $row['start'], $amoUserId);
            }else{
                // Это точно не пропущенный вызов.
                $this->cdrRows[$id]['answered'] |= true;
                $link = "https://$this->extHostname/pbxcore/api/amo-crm/playback?view={$row['recordingfile']}";
                $call_status = 4;
                $this->setAnswerData($id, $row['answer'], $amoUserId);
            }
            $this->cdrRows[$id]['haveUser'] |= ($row['is_app'] !== '1');

            $this->offset = max($this->offset,$row['id']);
            $created_at = $this->getTimestamp($row['start'], $row['UNIQUEID']);
            if($created_at === 0){
                $this->logger->writeInfo("Skip it: fail parse date: {$row['start']}, linkedid: $id");
                continue;
            }
            if(strlen($row[$phoneCol])<5){
                $this->logger->writeInfo("Skip it: str len: {$row[$phoneCol]} < 5, linkedid: $id");
                continue;
            }
            $call = [
                'entity_id'  => null,
                'note_type'  => $direction,
                'created_at' => $created_at,
                'request_id' => $row['UNIQUEID'],
                'params' => [
                    'uniq'      => $row['UNIQUEID'],
                    'duration'  => 1*$row['billsec'],
                    'source'    => self::SOURCE_ID,
                    'link'      => $link,
                    'phone'     => $row[$phoneCol],
                    'call_status' => $call_status,
                ],
                'id'                  => $id,
                'is_app'              => $row['is_app'],
            ];
            if(!empty($row['did'])){
                $this->cdrRows[$id]['did'] = $row['did'];
                $call['params']['call_result'] = "dst: $userPhone, did: {$row['did']}";
            }
            if(!isset($callCounter[$id])){
                $callCounter[$id] = 1;
            }else{
                $callCounter[$id]++;
            }

            if(isset($amoUserId)){
                $call['created_by']                 = $amoUserId;
                $call['responsible_user_id']        = $amoUserId;
                $call['params']['call_responsible'] = $amoUserId;
            }
            $phoneId = AmoCrmMain::getPhoneIndex($call['params']['phone']);

            $calls[$phoneId][] = $call;
            $this->cdrRows[$row['UNIQUEID']] = $call;
            $this->logger->writeInfo($call, "Result call data");
        }

        ////
        // Обработка и создание контактов
        ////
        $ok = $this->prepareDataCreatingEntities($calls, $callCounter);
        if($ok === false){
            $this->offset = $oldOffset;
            // Произошел сбой, повторим запрос через некоторое время.
            return;
        }
        ////
        // Создание сущностей amoCRM
        ////
        $this->createContacts($calls);
        $this->createLeads();
        $this->createTasks();
        $this->createUnsorted();

        $this->alertIncompleteAnswered();
        ////
        // Прикрепление звонков к сущностям.
        ////
        $this->addCalls($calls, $callCounter);

        if($oldOffset !== $this->offset){
            ConnectorDb::invoke('saveNewSettings', [['offsetCdr' => $this->offset]]);
        }
    }

    /**
     * Отправить в браузер сотрудника команду открытия карточки.
     * @return void
     */
    private function alertIncompleteAnswered():void
    {
        foreach ($this->incompleteAnswered as $id => $call){
            if(isset($this->incompleteAnswered[$id]['finished'])){
                continue;
            }
            if(!empty($call['lead']) || !empty($call['client']) || !empty($call['company'])){
                $this->logger->writeInfo($call, "alertIncompleteAnswered");
                ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_CALL_NAME, ['data' => $call, 'action' => 'open-card']);
            }
            $this->incompleteAnswered[$id]['finished'] = true;
        }
    }

    /**
     * Преобразует строку в дату.
     * @param $strDate
     * @param $logParam
     * @return int
     */
    private function getTimestamp($strDate, $logParam):int
    {
        try {
            $d          = new DateTime($strDate);
            $time = $d->getTimestamp();
        }catch (Throwable $e){
            Util::sysLogMsg(__CLASS__, $logParam.' : '.$strDate.' : '.$e->getMessage());
            return 0;
        }
        return $time;
    }

    /**
     * Опеределение первого и последнего ответившего на вызов.
     * @param string      $linkedId
     * @param string      $answer
     * @param string|null $amoUserId
     * @return void
     */
    private function setAnswerData(string $linkedId, string $answer, ?string $amoUserId):void
    {
        if(!$amoUserId){
            return;
        }
        $intAnswer = $this->getTimestamp($answer, $linkedId);
        $dataIsSet = isset($this->cdrRows[$linkedId]["lastAnswerData"]);
        if(!$dataIsSet || ($this->cdrRows[$linkedId]["lastAnswerData"] < $intAnswer)){
            $this->cdrRows[$linkedId]["lastAnswerData"]  = $intAnswer;
            $this->cdrRows[$linkedId]["lastAnswerUser"]  = $amoUserId;
        }
        $dataIsSet = isset($this->cdrRows[$linkedId]["firstAnswerData"]);
        if(!$dataIsSet || ($this->cdrRows[$linkedId]["firstAnswerData"] > $intAnswer)){
            $this->cdrRows[$linkedId]["firstAnswerData"]  = $intAnswer;
            $this->cdrRows[$linkedId]["firstAnswerUser"]  = $amoUserId;
        }
    }
    /**
     * Опеределение первого и последнего пропустившего вызов.
     * @param string      $linkedId
     * @param string      $start
     * @param string|null $amoUserId
     * @return void
     */
    private function setMissedData(string $linkedId, string $start, ?string $amoUserId):void
    {
        if(!$amoUserId){
            return;
        }
        $intStart     = $this->getTimestamp($start, $linkedId);
        $dataIsSet = isset($this->cdrRows[$linkedId]["lastMissedData"]);
        if(!$dataIsSet || ($this->cdrRows[$linkedId]["lastMissedData"] < $intStart)){
            $this->cdrRows[$linkedId]["lastMissedData"]  = $intStart;
            $this->cdrRows[$linkedId]["lastMissedUser"]  = $amoUserId;
        }
        $dataIsSet = isset($this->cdrRows[$linkedId]["firstMissedData"]);
        if(!$dataIsSet || ($this->cdrRows[$linkedId]["firstMissedData"] > $intStart)){
            $this->cdrRows[$linkedId]["firstMissedData"]  = $intStart;
            $this->cdrRows[$linkedId]["firstMissedUser"]  = $amoUserId;
        }
    }

    /**
     * Добавление информации о звонках сущностям amoCRM
     * Вызов REST API amoCRM
     * @param $calls
     * @param $callCounter
     * @return void
     */
    private function addCalls($calls, $callCounter):void
    {
        $calls  = array_merge(... array_values($calls));
        if(!empty($calls)){
            $this->logger->writeInfo($calls, "CDR synchronization. Step 1 Count: ".count($calls));
            $calls = $this->cleanCalls($calls, $callCounter);
        }
        if(empty($calls)){
            return;
        }
        $this->logger->writeInfo($calls, "CDR synchronization. Step 2. Count: ".count($calls));
        $ids = '';
        foreach ($calls as &$call) {
            $ids.= $call['id'].'|';
            unset($call['id'],$call['is_app'],$call['did']);
        }
        unset($call);
        // Пытаемся добавить вызовы. Это получится, если контакты существуют.
        $result = WorkerAmoHTTP::invokeAmoApi('addCalls', [$calls]);
        $this->logger->writeInfo($calls, "Create calls (REQ): $ids");
        $this->logger->writeInfo($result, "Create calls (RES): $ids");
    }

    /**
     * Очистка звонков на приложения.
     * @param $calls
     * @param $callCounter
     * @return array
     */
    private function cleanCalls($calls, $callCounter):array
    {
        if($this->disableDetailedCdr){
            $calls = $this->reduceCdr($calls);
        }else{
            foreach ($calls as $index => &$call){
                if($callCounter[$call['id']] === 1){
                    continue;
                }
                $haveUser = $this->cdrRows[$call['id']]['haveUser'] === 1;
                if(!isset($call['responsible_user_id']) && $haveUser){
                    $this->logger->writeError($call, "Unsetted responsible_user_id for {$call['id']}, drop it");
                    unset($calls[$index], $call);
                    continue;
                }
                if($call['is_app'] === '1' && $haveUser) {
                    // Этот вызов был направлен на сотрудника.
                    // Все вызовы на приложения чистим.
                    $this->logger->writeInfo($call, "Is app {$call['id']}, drop it");
                    unset($calls[$index], $call);
                }elseif($this->cdrRows[$call['id']]['first'] !== $call['params']['uniq'] && $haveUser === false){
                    // Этот вызов не попал на сотрудников, только приложения
                    // Оставляем только вызов на первое приложение
                    $this->logger->writeInfo($call, "Is app only {$call['id']}, drop it");
                    unset($calls[$index], $call);
                }elseif( $this->cdrRows[$call['id']]['answered'] === 1 && $call['params']['call_status'] === 6 && $haveUser){
                    // Если вызов отвечен, то не следует загружать информацию о пропущенных.
                    $this->logger->writeInfo($call, "The Cdr was missed, the call was answered {$call['id']}, drop it");
                    unset($calls[$index],$call);
                }
            }
            unset($call);
        }
        return $calls;
    }

    private function reduceCdr($calls):array
    {
        $resCalls = [];
        // Объединение нескольких файлов в один.
        //  sox -m f1.mp3 f2.mp3 out.mp3
        $responsibleMap = [
            self::MISSING_UNKNOWN => [
                'settingName' => 'respCallMissedNoClient',
                ModuleAmoCrm::RESP_TYPE_RULE    => 'responsibleRule',
                ModuleAmoCrm::RESP_TYPE_LAST    => 'lastMissedUser',
                ModuleAmoCrm::RESP_TYPE_FIRST   => 'firstMissedUser',
            ],
            self::MISSING_KNOWN => [
                'settingName' => 'respCallMissedHaveClient',
                ModuleAmoCrm::RESP_TYPE_RULE    => 'responsibleRule',
                ModuleAmoCrm::RESP_TYPE_LAST    => 'lastMissedUser',
                ModuleAmoCrm::RESP_TYPE_FIRST   => 'firstMissedUser',
                ModuleAmoCrm::RESP_TYPE_CONTACT => 'resp_contact_user_id',
            ],
            self::INCOMING_KNOWN => [
                'settingName' => 'respCallAnsweredHaveClient',
                ModuleAmoCrm::RESP_TYPE_RULE    => 'responsibleRule',
                ModuleAmoCrm::RESP_TYPE_LAST    => 'lastAnswerUser',
                ModuleAmoCrm::RESP_TYPE_FIRST   => 'firstAnswerUser',
                ModuleAmoCrm::RESP_TYPE_CONTACT => 'resp_contact_user_id',
            ],
            self::INCOMING_UNKNOWN => [
                'settingName' => 'respCallAnsweredNoClient',
                ModuleAmoCrm::RESP_TYPE_RULE    => 'responsibleRule',
                ModuleAmoCrm::RESP_TYPE_LAST    => 'lastAnswerUser',
                ModuleAmoCrm::RESP_TYPE_FIRST   => 'firstAnswerUser',
            ]
        ];

        foreach ($calls as $call) {
            if(isset($resCalls[$call['id']])){
                $this->logger->writeError($call, "A call with this ID has been processed {$call['id']}, drop it");
                continue;
            }
            $typeCall = $this->cdrRows[$call['id']]['type']??'';
            if(empty($typeCall) ){
                $this->logger->writeError($call, "the type of call is not defined {$call['id']}, drop it");
                continue;
            }
            $this->logger->writeInfo($call, "Successful cdr verification {$call['id']}");
            $settingName = $responsibleMap[$typeCall]['settingName']??'';
            $this->logger->writeInfo($settingName, "Responsible map {$call['id']}");
            if(!empty($settingName)){
                $responsibleSettingName = $responsibleMap[$typeCall][$this->$settingName]??'';
                $this->logger->writeInfo($responsibleMap[$typeCall], "Responsible map settings {$call['id']}");
                $responsible            = $this->cdrRows[$call['id']][$responsibleSettingName]??0;
                if (!empty($responsible)){
                    $call['created_by']          = 1*$responsible;
                    $call['responsible_user_id'] = 1*$responsible;
                }
            }
            $call['params']['link']       = $this->getCreateFileAndLink($call['id'], $call['created_at']);
            $call['params']['duration']   = $this->cdrRows[$call['id']]['duration'];
            if($this->cdrRows[$call['id']]['answered'] === 1 ){
                $call['params']['call_status'] = 4;
            }else{
                $call['params']['call_status'] = 6;
            }
            $resCalls[$call['id']] = $call;
            $this->logger->writeInfo($settingName, "Result cdr {$call['id']}");

        }
        return array_values($resCalls);
    }

    /**
     * Объединяет несколько файлов в один и возвращает ссылку на скачивание файла.
     * @param string $id
     * @param int    $created_at
     * @return string
     */
    private function getCreateFileAndLink(string $id, int $created_at):string
    {
        $link = '';
        if(isset($this->cdrRows[$id]['records'])){
            if(count($this->cdrRows[$id]['records']) === 1){
                $fileName = $this->cdrRows[$id]['records'][0];
            }else{
                $monitor_dir = Storage::getMonitorDir();
                $sub_dir = date('Y/m/d/H', $created_at);
                $fileName = "$monitor_dir/amo/$sub_dir/$id.mp3";
                Util::mwMkdir(dirname($fileName));

                $pathSox = Util::which('sox');
                $records = array_reverse($this->cdrRows[$id]['records']);
                $cmd = '';
                foreach ($records as $key => $value){
                    if($key === array_key_first($records)){
                        $cmd.= "$pathSox $value -p pad 3 0 | ";
                    }elseif ($key === array_key_last($records)){
                        $cmd.= "$pathSox - -m $value $fileName";
                    }else{
                        $cmd.= "$pathSox - -m $value -p pad 3 0 | ";
                    }
                }
                shell_exec($cmd);
            }
            $link = "https://$this->extHostname/pbxcore/api/amo-crm/playback?view=$fileName";
        }
        return $link;
    }


    /**
     * Подготавливает данные для создания сделок / контактов / задач.
     * @param array $calls
     * @param array $callCounter
     * @return void
     */
    private function prepareDataCreatingEntities(array &$calls, array &$callCounter):bool
    {
        $this->newContacts = [];
        $this->newLeads = [];
        $this->newUnsorted = [];
        $this->newTasks = [];

        $phones       = array_unique(array_merge(array_keys($this->incompleteAnswered), array_keys($calls)));
        $contactsData = ConnectorDb::invoke('getContactsData', [$phones]);
        if(count($phones) !== count($contactsData)){
            // getContactsData - должен вернуть столько элементов, сколько передано уникальных номеров телевонов.
            $this->logger->writeError($contactsData, "An incorrect response was received when requesting getContactsData");
            return false;
        }
        // Не завершенные вызовы
        foreach ($this->incompleteAnswered as $id => $call){
            if(isset($this->incompleteAnswered[$id]['finished'])){
                if(!isset($this->incompleteAnswered[$id]['skip'])){
                    $this->logger->writeInfo($this->incompleteAnswered[$id], "Call was finished (incompleteAnswered), skip it)");
                }
                $this->incompleteAnswered[$id]['skip'] = true;
                continue;
            }
            $this->logger->writeInfo($call, "Check incomplete answered call");

            $indexAction   = AmoCrmMain::getPhoneIndex($call['phone']);
            $contData      = $contactsData[$indexAction];
            $contactId     = $contData['contactId']??null;
            $contactExists = !empty($contactId);

            $this->logger->writeInfo($contData, "Contact data for id: {$call['id']}");

            if($contactExists){
                $this->incompleteAnswered[$id]['client']  =  $contData['contactId'];
                $this->incompleteAnswered[$id]['company'] =  $contData['companyId'];
                $this->incompleteAnswered[$id]['lead']    =  $contData['leadId'];
            }

            $type = $this->getCallType(false, $contactExists, true);
            $this->incompleteAnswered[$id]['type']  = $type;

            $settings = $this->entitySettings[$type][$call['did']]??$this->entitySettings[$type]['']??[];
            if(empty($settings) || $settings['responsible'] !== 'first'){
                $this->logger->writeInfo($settings, "Automatic card opening for incoming calls only: {$call['id']}");
                continue;
            }
            $params = ['id' => $call['id'], 'params' => ['phone' => $call['phone']]];
            if($settings['create_contact'] === '1' && !$contactExists){
                $this->newContacts[$indexAction] = [
                    'phone'               => $call['phone'],
                    'contactName'         => $this->replaceTagTemplate($settings['template_contact_name'], $params),
                    'request_id'          => $indexAction,
                    'responsible_user_id' =>  $call['responsible'],
                ];
                $this->logger->writeInfo("Need add contact: {$call['id']}");
            }
            $this->addNewLead($settings, $params, $contData, $call['responsible']);
            unset($params);
        }
        // Завершенные вызовы.
        foreach ($calls as $phoneId => $subCalls){
            foreach ($subCalls as $index => $call) {
                $this->logger->writeInfo($call, "Complete call: {$call['id']}");
                if($this->cdrRows[$call['id']]['answered'] === 1 && $call['params']['duration'] === 0){
                    $this->logger->writeInfo("Сdr not answered the call was generally answered: {$call['id']}. skip it");
                    $callCounter[$call['id']]--;
                    unset($calls[$phoneId][$index],$call);
                    continue;
                }
                if (isset($this->cdrRows[$call['id']]['type'])) {
                    $this->logger->writeInfo("The type of call has already been determined earlier: {$call['id']}. skip it");
                    continue;
                }
                $phone         = $call['params']['phone'];
                $contData      = $contactsData[$phoneId];
                $this->logger->writeInfo($contData, "Contact data for id: {$call['id']}");

                $contactId     = $contData['contactId']??null;
                $contactExists = !empty($contactId);

                $isMissed      = $this->cdrRows[$call['id']]['answered'] === 0;
                $isIncoming    = $call['note_type'] === 'call_in';

                $did           =  $this->cdrRows[$call['id']]['did']??'';
                if(isset($this->cdrRows[$call['id']]['incompleteType'])){
                    $type = $this->cdrRows[$call['id']]['incompleteType'];
                }else{
                    $type = $this->getCallType($isMissed, $contactExists, $isIncoming);
                }
                $this->cdrRows[$call['id']]['type'] = $type;
                $settings = $this->entitySettings[$type][$did]??$this->entitySettings[$type]['']??[];

                $this->logger->writeInfo($settings, "the type of call: $type, id: {$call['id']}");
                if(empty($settings)){
                    $this->logger->writeInfo('skip it', "the type of call: $type, id: {$call['id']}");
                    // Нет настроек для этого типа звонка.
                    // Ничего не делаем, не загружаем.
                    if(!$contactExists){
                        // Это неизвестный клиент. Некуда прикреплять телефонный звонок.
                        $callCounter[$call['id']]--;
                        unset($calls[$phoneId][$index],$call);
                    }
                    continue;
                }
                if($this->cdrRows[$call['id']]['answered'] === 1){
                    $responsibleField = $settings['responsible']."AnswerUser";
                }else{
                    $responsibleField = $settings['responsible']."MissedUser";
                }
                // Получим ответственного.
                $responsible = 1*($this->cdrRows[$call['id']][$responsibleField]??$settings['def_responsible']);
                $this->cdrRows[$call['id']]['responsibleRule']      = $responsible;
                $this->cdrRows[$call['id']]['resp_contact_user_id'] = 1*($contactsData[$phone]['resp_contact_user_id']??'');

                $indexAction = AmoCrmMain::getPhoneIndex($phone);
                if($contactExists){
                    $calls[$phoneId][$index]['entity_id'] = 1*$contData['contactId'];
                }elseif($settings['create_contact'] === '1'){
                    $this->newContacts[$indexAction] = [
                        'phone'               => $phone,
                        'contactName'         => $this->replaceTagTemplate($settings['template_contact_name'], $call),
                        'request_id'          => $indexAction,
                        'responsible_user_id' => $responsible,
                    ];
                    $this->logger->writeInfo("Need add contact: {$call['id']}");
                }

                $this->addNewLead($settings, $call, $contData, $responsible);
                $this->addNewTask($settings, $call, $contData);
                $this->addNewUnsorted($settings,$calls, $call, $responsible);
            }
        }

        // Чистим кэш.
        $tmpCalls = array_merge(... array_values($calls));
        foreach ($tmpCalls as $tmpCall){
            unset($this->createdLeads[$tmpCall['id']]);
        }

        return true;
    }

    /**
     * Замена тегов в шаблоне.
     * @param string $template
     * @param array  $data
     * @return string
     */
    private function replaceTagTemplate(string $template, array $data):string
    {
        $phone = $data['params']['phone'];
        return str_replace(['<НомерТелефона>','<PhoneNumber>'],[$phone,$phone],$template);
    }

    /**
     * @param $settings
     * @param $calls
     * @param $call
     * @param $responsible
     * @return void
     */
    private function addNewUnsorted($settings, &$calls, $call, $responsible):void
    {
        if($settings['create_unsorted'] === '1'){
            $indexAction = AmoCrmMain::getPhoneIndex($call['params']['phone']);
            // Наполняем неразобранное.
            $this->newUnsorted[$indexAction] = [
                'request_id'  => $indexAction,
                'source_name' => self::SOURCE_ID,
                'source_uid'  => self::SOURCE_ID,
                'pipeline_id' =>  (int)$settings['lead_pipeline_id'],
                'created_at'  => $call['created_at'],
                "metadata" => [
                    "is_call_event_needed"  => true,
                    "uniq"                  => $call['params']['uniq'],
                    'duration'              => $call['params']['duration'],
                    "service_code"          => self::SOURCE_ID,
                    "link"                  => $call['params']["link"],
                    "phone"                 => $call['params']["phone"],
                    "called_at"             => $call['created_at'],
                    "from"                  => $call['params']['source']
                ],
                "_embedded" => [
                    'contacts' => [
                        [
                            'name' => $this->replaceTagTemplate($settings['template_contact_name'], $call),
                            'custom_fields_values' => [
                                [
                                    'field_code' => 'PHONE',
                                    'values' => [['value' => $call['params']["phone"]]]
                                ]
                            ]
                        ]
                    ],
                    'leads' => [[
                        'name' => $this->replaceTagTemplate($settings['template_lead_name'], $call)
                    ]],
                ]
            ];
            if($this->cdrRows[$call['id']]['answered'] === 1){
                $this->newUnsorted[$indexAction]['metadata']['call_responsible'] = $responsible;
            }
            // Звонок будет добавлен через неразобранное.
            // Удаляем его из списка звонков.
            $index = array_search($call, $calls[AmoCrmMain::getPhoneIndex($call['params']['phone'])], true);
            unset($calls[$indexAction][$index]);
        }

    }

    /**
     * Добавление новой задачи в пулл для отправки на сервер amo.
     * @param $settings
     * @param $call
     * @param $contData
     * @return void
     */
    private function addNewTask($settings, $call, $contData):void
    {
        if($settings['create_task'] !== '1'){
            return;
        }
        $indexAction = AmoCrmMain::getPhoneIndex($call['params']['phone']);
        $contactExists = !empty($contData);
        $lead          = $contData['leadId']??'';
        $responsibleArray = [
            'lastMissedUser'    => $this->cdrRows[$call['id']]['lastMissedUser']??'',
            'firstMissedUser'   => $this->cdrRows[$call['id']]['firstMissedUser']??'',
            'lastAnswerUser'    => $this->cdrRows[$call['id']]['lastAnswerUser']??'',
            'firstAnswerUser'   => $this->cdrRows[$call['id']]['firstAnswerUser']??'',
            'clientResponsible' => $contData['responsible_user_id']??'',
            'def_responsible'   => $settings['def_responsible'],
        ];

        $taskResponsible = (int)($responsibleArray[$settings['task_responsible_type']]??0);
        if(!empty($taskResponsible)){
            $this->newTasks[$indexAction] = [
                'text'                =>  $this->replaceTagTemplate($settings['template_task_text'], $call),
                'complete_till'       =>  time()+3600*(int)$settings['deadline_task'],
                'task_type_id'        =>  1,
                'responsible_user_id' =>  $taskResponsible,
            ];
            if(!empty($lead)){
                $this->newTasks[$indexAction]['entity_type'] = 'leads';
                $this->newTasks[$indexAction]['entity_id'] = (int) $lead;
            }elseif ($contactExists){
                if(!empty($contData['contactId'])){
                    $this->newTasks[$indexAction]['entity_type'] = 'contact';
                    $this->newTasks[$indexAction]['entity_id']    = (int) $contData['contactId'];
                }else{
                    $this->newTasks[$indexAction]['entity_type'] = 'companies';
                    $this->newTasks[$indexAction]['entity_id']    = (int) $contData['companyId'];
                }
            }
        }
    }

    /**
     * Создание структуры контакта по шаблону.
     * @param $call
     * @param $contData
     * @param $settings
     * @param $responsible
     * @return void
     */
    private function addNewLead($settings, $call, $contData, $responsible):void
    {
        $lead = $contData['leadId']??'';
        if($settings['create_lead'] !== '1' || !empty($lead) || isset($this->createdLeads[$call['id']])){
            // Лид уже был создан ранее
            // Или Лид не должен быть создан.
            return;
        }
        $this->logger->writeInfo("Need add Lead: {$call['id']}");

        $this->createdLeads[$call['id']] = true;

        $indexAction = AmoCrmMain::getPhoneIndex($call['params']['phone']);
        $leadData = [
            'name'        =>  $this->replaceTagTemplate($settings['template_lead_name'], $call),
            'status_id'   => (int) $settings['lead_pipeline_status_id'],
            'pipeline_id' =>  (int)$settings['lead_pipeline_id'],
            'price'       =>  0,

        ];
        if($contData !== false){
            if(!empty($contData['contactId'])){
                $leadData['_embedded']['contacts'][]=[
                    'id' => (int)$contData['contactId'],
                    'is_main' => true
                ];
            }
            if(!empty($contData['companyId'])){
                $leadData['_embedded']['companies'][]=[
                    'id' => $contData['contactId'],
                ];
            }
        }

        $leadData['responsible_user_id'] = $responsible;
        $leadData['request_id']          = $indexAction;
        $this->newLeads[$indexAction]    = $leadData;
    }

    /**
     * Получаем тип телефонного звонка. Классификация вызова.
     * @param $isMissed
     * @param $contactExists
     * @param $isIncoming
     * @return string
     */
    private function getCallType($isMissed, $contactExists, $isIncoming):string
    {
        if ($isMissed && !$contactExists) {
            $type = self::MISSING_UNKNOWN;
        } elseif ($isIncoming && $isMissed) {
            $type = self::MISSING_KNOWN;
        } elseif ($isIncoming && !$contactExists) {
            $type = self::INCOMING_UNKNOWN;
        } elseif ($isIncoming) {
            $type = self::INCOMING_KNOWN;
        } elseif ($isMissed) {
            $type = self::OUTGOING_KNOWN_FAIL;
        } elseif ($contactExists) {
            $type = self::OUTGOING_KNOWN;
        } else {
            $type = self::OUTGOING_UNKNOWN;
        }
        return $type;
    }

    /**
     * Создание контактов на оснве подготовленных данных.
     * @param $calls
     * @return void
     */
    private function createContacts(&$calls):void
    {
        if(empty($this->newContacts)){
            return;
        }
        $contactsData = [
            'add' => []
        ];
        $resultCreateContacts = WorkerAmoHTTP::invokeAmoApi('createContacts', [$this->newContacts]);
        $contacts = $resultCreateContacts->data['_embedded']['contacts']??[];
        foreach ($contacts as $contact){
            if( isset($this->newLeads[$contact['request_id']]) ){
                $this->newLeads[$contact['request_id']]['_embedded']['contacts'][] = [
                    'id' => $contact['id'],
                    'is_main' => true
                ];
            }
            if( isset($this->newUnsorted[$contact['request_id']]) ){
                $this->newUnsorted[$contact['request_id']]['_embedded']['contacts'][] = [
                    'id' => $contact['id'],
                ];
            }
            if( isset($this->newTasks[$contact['request_id']]) ){
                $this->newTasks[$contact['request_id']]['entity_id'] = $contact['id'];
                $this->newTasks[$contact['request_id']]['entity_type'] = 'contact';
            }
            if( isset($this->incompleteAnswered[$contact['request_id']]) ){
                $this->incompleteAnswered[$contact['request_id']]['client'] = $contact['id'];
            }

            if(isset($calls[$contact['request_id']])){
                foreach ($calls[$contact['request_id']] as &$call){
                    $call['entity_id'] = $contact['id'];
                }
                unset($call);
            }

            $contactsData['add'][] = [
                'type'                  => 'contact',
                'id'                    => $contact['id'],
                'name'                  =>  $this->newContacts[$contact['request_id']]['contactName'],
                'responsible_user_id'   => $this->newContacts[$contact['request_id']]['responsible_user_id'],
                'company_name'          => '',
                'linked_company_id'     => '',
                'custom_fields'         => [
                    [
                        'code'   => 'PHONE', 
                        'values' => [
                            ['value' => $this->newContacts[$contact['request_id']]['phone']]
                        ]
                    ]
                ]
            ];
            
        }
        if(!$resultCreateContacts->success){
            $this->logger->writeError($this->newContacts, "Error create contacts");
            $this->logger->writeError($resultCreateContacts, "Error create contacts");
        }
        // Сохраним данные о контакте в базе.
        ConnectorDb::invoke('updatePhoneBook', [$contactsData], false);
    }

    /**
     * Создание сделок.
     * @return void
     */
    private function createLeads():void
    {
        if(empty($this->newLeads)){
            return;
        }
        $leadData = [
            'add' => [],
            'source' => self::class
        ];
        $resultCreateLeads    = WorkerAmoHTTP::invokeAmoApi('addLeads', [array_values($this->newLeads)]);
        $leads = $resultCreateLeads->data['_embedded']['leads']??[];
        foreach ($leads as $lead){
            if( isset($this->newTasks[$lead['request_id']]) ){
                $this->newTasks[$lead['request_id']]['entity_id'] = $lead['id'];
                $this->newTasks[$lead['request_id']]['entity_type'] = 'leads';
            }
            if( isset($this->incompleteAnswered[$lead['request_id']]) ){
                $this->incompleteAnswered[$lead['request_id']]['lead'] = $lead['id'];
            }
            $leadData['add'][] = [
                'id'                    => $lead['id'],
                'name'                  => $this->newLeads[$lead['request_id']]['name'],
                'responsible_user_id'   => $this->newLeads[$lead['request_id']]['responsible_user_id'],
                'status_id'             => $this->newLeads[$lead['request_id']]['status_id'],
                'pipeline_id'           => $this->newLeads[$lead['request_id']]['pipeline_id'],
                '_embedded'             => $this->newLeads[$lead['request_id']]['_embedded']
            ];
        }
        if(!$resultCreateLeads->success) {
            $this->logger->writeError($this->newLeads, "Error create tasks (REQ)");
            $this->logger->writeError($resultCreateLeads, "Error create leads (RES):");
        }
        $this->logger->writeInfo($leadData, "Send task 'updateLeads'");
        ConnectorDb::invoke('updateLeads', [$leadData], false);
    }

    /**
     * Создание задач.
     * @return void
     */
    private function createTasks():void
    {
        if(empty($this->newTasks)){
            return;
        }
        $resultCreateTasks    = WorkerAmoHTTP::invokeAmoApi('addTasks', [array_values($this->newTasks)]);
        if(!$resultCreateTasks->success) {
            $this->logger->writeError($this->newTasks, "Error create tasks (REQ)");
            $this->logger->writeError($resultCreateTasks, "Error create tasks (RES)");
        }
    }

    /**
     * Создание неразобранного.
     * @return void
     */
    private function createUnsorted():void
    {
        if(empty($this->newUnsorted)){
            return;
        }
        $resultCreateUnsorted    = WorkerAmoHTTP::invokeAmoApi('addUnsorted', [array_values($this->newUnsorted)]);
        if($resultCreateUnsorted->success) {
            $phone     = $resultCreateUnsorted->data["_embedded"]["unsorted"][0]["request_id"]??'';
            $contactId = $resultCreateUnsorted->data["_embedded"]["unsorted"][0]["_embedded"]["contacts"][0]["id"]??'';
            $leadId    = $resultCreateUnsorted->data["_embedded"]["unsorted"][0]["_embedded"]["leads"][0]["id"]??'';
            if(!empty($phone)){
                ConnectorDb::invoke('addContactLeadFromUnsorted', [$phone, $contactId, $leadId]);
            }
        }else{
            $this->logger->writeError($this->newUnsorted, "Error create unsorted (REQ)");
            $this->logger->writeError($resultCreateUnsorted, "Error create unsorted (RES)");
       }
    }
}

if(isset($argv) && count($argv) !== 1){
    AmoCdrDaemon::startWorker($argv??[]);
}