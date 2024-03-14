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
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleAmoCrm\Lib\ClientHTTP;
use Modules\ModuleAmoCrm\Lib\Logger;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use MikoPBX\Common\Providers\CDRDatabaseProvider;
use DateTime;
use MikoPBX\Common\Models\Extensions;
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

    private array $newContacts = [];
    private array $newLeads = [];
    private array $newUnsorted = [];
    private array $newTasks = [];
    private array $incompleteAnswered = [];

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
        // Оповещение только если изменилось состояние.
        $result = ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_CALL_NAME, ['data' => $result, 'action' => 'USERS']);
        if(!$result->success){
            $this->logger->writeInfo("Update user list. Count: ".count($result));
            try {
                $this->logger->writeInfo("Result: ". json_encode($result, JSON_THROW_ON_ERROR));
            }catch (Throwable $e){
                $this->logger->writeInfo("Result: ". $e->getMessage());
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
            $this->logger->writeInfo("Update active call. Count: ".count($params));
            // Оповещаме только если изменилось состояние.
            $result = ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_CALL_NAME, ['data' => $params, 'action' => 'CDRs']);
            try {
                $this->logger->writeInfo("Result: ". json_encode($result, JSON_THROW_ON_ERROR));
            }catch (Throwable $e){
                $this->logger->writeInfo("Result: ". print_r($e->getMessage(), true));
            }
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
            // Чистим незавершенные вызовы, если необходимо.
            $srcNum = AmoCrmMain::getPhoneIndex($row['src_num']);
            $dstNum = AmoCrmMain::getPhoneIndex($row['dst_num']);
            if(isset($this->incompleteAnswered[$srcNum])){
                $this->cdrRows[$row['linkedid']]['incompleteType'] = $this->incompleteAnswered[$srcNum]['type'];
            }
            unset($this->incompleteAnswered[$srcNum],$this->incompleteAnswered[$dstNum]);

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
            }elseif(empty($dstNum) && strlen($srcNum) > 6){
                // Исходящий.
                $direction = 'inbound';
                $amoUserId = null;
                $userPhone = '';
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
                $this->setMissedData($row['linkedid'], $row['start'], $amoUserId);
            }else{
                // Это точно не пропущенный вызов.
                $this->cdrRows[$row['linkedid']]['answered'] |= true;
                $link = "https://$this->extHostname/pbxcore/api/amo-crm/playback?view={$row['recordingfile']}";
                $call_status = 4;
                $this->setAnswerData($row['linkedid'], $row['answer'], $amoUserId);
            }
            $this->cdrRows[$row['linkedid']]['haveUser'] |= ($row['is_app'] !== '1');

            $created_at = $this->getTimestamp($row['start'], $row['UNIQUEID']);
            if($created_at === 0){
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
                $this->cdrRows[$row['linkedid']]['did'] = $row['did'];
                $call['call_result'] = "dst: $userPhone, did: {$row['did']}";
            }
            if(!isset($callCounter[$row['linkedid']])){
                $callCounter[$row['linkedid']] = 1;
            }else{
                $callCounter[$row['linkedid']]++;
            }

            if(isset($amoUserId)){
                $call['created_by']          = $amoUserId;
                $call['responsible_user_id'] = $amoUserId;
            }
            $calls[]      = $call;
            $this->cdrRows[$row['UNIQUEID']] = $call;
        }
        ////
        // Обработка и создание контактов
        ////
        $this->prepareDataCreatingEntities($calls);

        ////
        // Создание сущностей amoCRM
        ////
        $this->createContacts();
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
        $countCDR = count($calls);
        if($countCDR>0){
            $this->logger->writeInfo("CDR synchronization. Step 1. Count: $countCDR");
        }
        $calls = $this->cleanCalls($calls, $callCounter);
        $countCDR = count($calls);
        if($countCDR>0){
            $this->logger->writeInfo("CDR synchronization. Step 2. Count: $countCDR");
        }
        if(empty($calls)){
            return;
        }
        // Пытаемся добавить вызовы. Это получится, если контакты существуют.
        $result =  WorkerAmoHTTP::invokeAmoApi('addCalls', [$calls]);
        if(!$result->success){
            try {
                $this->logger->writeInfo("Error create calls (REQ):". json_encode($calls, JSON_THROW_ON_ERROR));
                $this->logger->writeInfo("Error create calls (RES):". json_encode($result, JSON_THROW_ON_ERROR));
            }catch (Throwable $e){
                $this->logger->writeInfo("CDR synchronization. " . $e->getMessage());
            }
        }
    }

    /**
     * Очистка звонков на приложения.
     * @param $calls
     * @param $callCounter
     * @return array
     */
    private function cleanCalls($calls, $callCounter):array
    {
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
        return $calls;
    }

    /**
     * Подготавливает данные для создания сделок / контактов / задач.
     * @param array $calls
     */
    private function prepareDataCreatingEntities(array &$calls):void
    {
        $this->newContacts = [];
        $this->newLeads = [];
        $this->newUnsorted = [];
        $this->newTasks = [];

        $phones = array_merge(array_column($this->incompleteAnswered, "phone"), array_column($calls, 'phone'));
        $contactsData = ConnectorDb::invoke('getContactsData', [array_unique($phones)]);
        // Не завершенные вызовы
        foreach ($this->incompleteAnswered as $id => $call){
            if(isset($this->incompleteAnswered[$id]['finished'])){
                continue;
            }
            $contData      = $contactsData[$call['phone']];
            $contactExists = !empty($contData);

            if($contactExists){
                $this->incompleteAnswered[$id]['client']  =  $contData['contactId'];
                $this->incompleteAnswered[$id]['company'] =  $contData['companyId'];
                $this->incompleteAnswered[$id]['lead']    =  $contData['leadId'];
            }

            $type = $this->getCallType(false, $contactExists, true);
            $this->incompleteAnswered[$id]['type']  = $type;

            $settings = $this->entitySettings[$type][$call['did']]??$this->entitySettings[$type]['']??[];
            if(empty($settings) && $settings['responsible'] === 'first'){
                continue;
            }
            // Получим ответственного.
            $indexAction = AmoCrmMain::getPhoneIndex($call['phone']);
            if($settings['create_contact'] === '1' && !$contactExists){
                $this->newContacts[$indexAction] = [
                    'phone'       => $call['phone'],
                    'contactName' => $this->replaceTagTemplate($settings['template_contact_name'], $call),
                    'request_id'  => $indexAction,
                    'responsible_user_id' =>  $call['responsible'],
                ];
            }
            $this->addNewLead($settings, $call, $contData, $call['responsible']);
        }
        // Завершенные вызовы.
        foreach ($calls as $index => $call) {
            if($this->cdrRows[$call['id']]['answered'] === 1 && $call['duration'] === 0){
                unset($calls[$index]);
                continue;
            }
            if (isset($this->cdrRows[$call['id']]['type'])) {
                continue;
            }
            $contData      = $contactsData[$call['phone']];
            $contactExists = !empty($contData);
            $isMissed      = $this->cdrRows[$call['id']]['answered'] === 0;
            $isIncoming    = $call['direction'] === 'inbound';

            $did           =  $this->cdrRows[$call['id']]['did']??'';
            if(isset($this->cdrRows[$call['id']]['incompleteType'])){
                $type = $this->cdrRows[$call['id']]['incompleteType'];
            }else{
                $type = $this->getCallType($isMissed, $contactExists, $isIncoming);
            }
            $this->cdrRows[$call['id']]['type'] = $type;
            $settings = $this->entitySettings[$type][$did]??$this->entitySettings[$type]['']??[];
            if(empty($settings)){
                // Нет настроек для этого типа звонка.
                // Ничего не делаем, не загружаем.
                if(!$contactExists){
                    // Это неизвестный клиент. Некуда прикреплять телефонный звонок.
                    unset($calls[$index],$call);
                }
                continue;
            }
            // Получим ответственного.
            $responsible = 1*($this->cdrRows[$call['id']][$settings['responsible']."AnswerUser"]??$settings['def_responsible']);
            $indexAction = AmoCrmMain::getPhoneIndex($call['phone']);
            if($settings['create_contact'] === '1' && !$contactExists){
                $this->newContacts[$indexAction] = [
                    'phone'       => $call['phone'],
                    'contactName' => $this->replaceTagTemplate($settings['template_contact_name'], $call),
                    'request_id'  => $indexAction,
                    'responsible_user_id' =>  $responsible,
                ];
            }

            $this->addNewLead($settings, $call, $contData, $responsible);
            $this->addNewTask($settings, $call, $contData);
            $this->addNewUnsorted($settings,$calls, $index, $responsible);
        }
    }

    /**
     * Замена тегов в шаблоне.
     * @param string $template
     * @param array  $data
     * @return string
     */
    private function replaceTagTemplate(string $template, array $data):string
    {
        return str_replace(['<НомерТелефона>','<PhoneNumber>'],[$data['phone'],$data['phone']],$template);
    }

    /**
     * @param $settings
     * @param $calls
     * @param $index
     * @param $responsible
     * @return void
     */
    private function addNewUnsorted($settings, &$calls, $index, $responsible):void
    {
        if($settings['create_unsorted'] === '1'){
            $call = $calls[$index];
            $indexAction = AmoCrmMain::getPhoneIndex($call['phone']);
            // Наполняем неразобранное.
            $this->newUnsorted[$indexAction] = [
                'request_id'  => $indexAction,
                'source_name' => self::SOURCE_ID,
                'source_uid'  => self::SOURCE_ID,
                'pipeline_id' =>  (int)$settings['lead_pipeline_id'],
                'created_at'  => $call['created_at'],
                "metadata" => [
                    "is_call_event_needed"  => true,
                    "uniq"                  => $call['uniq'],
                    'duration'              => $call['duration'],
                    "service_code"          => self::SOURCE_ID,
                    "link"                  => $call["link"],
                    "phone"                 => $call["phone"],
                    "called_at"             => $call['created_at'],
                    "from"                  => $call['source']
                ],
                "_embedded" => [
                    'contacts' => [
                        [
                            'name' => $this->replaceTagTemplate($settings['template_contact_name'], $call),
                            'custom_fields_values' => [
                                [
                                    'field_code' => 'PHONE',
                                    'values' => [['value' => $call["phone"]]]
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
            unset($calls[$index]);
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
        $indexAction = AmoCrmMain::getPhoneIndex($call['phone']);
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
        if($settings['create_lead'] !== '1' || !empty($lead)){
            return;
        }
        $indexAction = AmoCrmMain::getPhoneIndex($call['phone']);
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
     * @return void
     */
    private function createContacts():void
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
            try {
                $this->logger->writeInfo("Error create contacts:". json_encode($this->newContacts, JSON_THROW_ON_ERROR));
                $this->logger->writeInfo("Error create contacts:". json_encode($resultCreateContacts, JSON_THROW_ON_ERROR));
            }catch (Throwable $e){
                $this->logger->writeError($e->getMessage());
            }
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
            'add' => []
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
            try {
                $this->logger->writeInfo("Error create tasks (REQ):". json_encode($this->newLeads, JSON_THROW_ON_ERROR));
                $this->logger->writeInfo("Error create leads (RES):". json_encode($resultCreateLeads, JSON_THROW_ON_ERROR));
            }catch (Throwable $e){
                $this->logger->writeError($e->getMessage());
            }
        }
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
            try {
                $this->logger->writeInfo("Error create tasks (REQ):". json_encode($this->newTasks, JSON_THROW_ON_ERROR));
                $this->logger->writeInfo("Error create tasks (RES):". json_encode($resultCreateTasks,JSON_THROW_ON_ERROR));
            }catch (Throwable $e){
                $this->logger->writeError("Error:".$e->getMessage());
            }
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
            try {
                $this->logger->writeInfo("Error create unsorted (REQ):". json_encode($this->newUnsorted, JSON_THROW_ON_ERROR));
                $this->logger->writeInfo("Error create unsorted (RES):". json_encode($resultCreateUnsorted, JSON_THROW_ON_ERROR));
            }catch (Throwable $e){
                $this->logger->writeError("Error:".$e->getMessage());
            }
       }
    }
}

if(isset($argv) && count($argv) !== 1){
    AmoCdrDaemon::startWorker($argv??[]);
}