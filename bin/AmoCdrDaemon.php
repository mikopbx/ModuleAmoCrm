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
use Modules\ModuleAmoCrm\Lib\CdrBatch;
use Modules\ModuleAmoCrm\Lib\Logger;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use Modules\ModuleAmoCrm\Lib\ResponsibleResolver;
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
    private bool  $panelIsEnable = false;
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
    private bool $restrictCdrToKnownEmployees = false;
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
    private int $addCallsFailCount = 0; // Счётчик последовательных неудач addCalls
    private const MAX_ADD_CALLS_FAILURES = 5; // Максимум попыток перед пропуском батча

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
     * Компактное представление звонка для логирования.
     * @param array $call
     * @return string
     */
    private function callSummary(array $call): string
    {
        $parts = [];
        foreach (['id', 'entity_id', 'responsible_user_id', 'created_by'] as $k) {
            if (isset($call[$k])) {
                $parts[] = "$k={$call[$k]}";
            }
        }
        foreach (['uniq', 'phone', 'call_status', 'duration'] as $k) {
            if (isset($call['params'][$k])) {
                $parts[] = "$k={$call['params'][$k]}";
            }
        }
        return implode(', ', $parts);
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
            $this->panelIsEnable = intval($allSettings['ModuleAmoCrm']['panelIsEnable']??0) ===0;
            $this->offset        = max(1*$allSettings['ModuleAmoCrm']['offsetCdr']??1,1);
            $this->referenceDate = $allSettings['ModuleAmoCrm']['referenceDate']??'';
            $this->portalId      = intval($allSettings['ModuleAmoCrm']['portalId']??0);

            $this->disableDetailedCdr         = (intval($allSettings['ModuleAmoCrm']['disableDetailedCdr']??'0')) === 1;
            $this->restrictCdrToKnownEmployees= (intval($allSettings['ModuleAmoCrm']['restrictCdrToKnownEmployees']??'0')) === 1;
            $this->respCallAnsweredHaveClient = ($allSettings['ModuleAmoCrm']['respCallAnsweredHaveClient']??'');
            $this->respCallAnsweredNoClient   = ($allSettings['ModuleAmoCrm']['respCallAnsweredNoClient']??'');
            $this->respCallMissedNoClient     = ($allSettings['ModuleAmoCrm']['respCallMissedNoClient']??'');
            $this->respCallMissedHaveClient   = ($allSettings['ModuleAmoCrm']['respCallMissedHaveClient']??'');

            // Внешний адрес АТС из настроек модуля — приоритет над LanInterfaces
            $moduleHostname = trim($allSettings['ModuleAmoCrm']['externalHostname']??'');
            if (!empty($moduleHostname)) {
                $this->extHostname = $moduleHostname;
            }

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
            $amoId = $amoUsersArray[$extension->number]??'';
            if($this->restrictCdrToKnownEmployees && empty($amoId)){
                continue;
            }
            $data[] = [
                'number' => $extension->number,
                'name' => $extension->callerid,
                'amoId' => $amoUsersArray[$extension->number]??'',
                'type' => $extension->type
            ];
        }
        unset($extensions);
        $result = ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::getChannelUrl(), ['data' => $data, 'action' => 'USERS']);
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
        if($this->panelIsEnable && $md5Cdr !== $this->lastCacheCdr){
            // Оповещаме только если изменилось состояние.
            ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::getChannelUrl(), ['data' => $params, 'action' => 'CDRs']);
            $this->lastCacheCdr = $md5Cdr;
        }
    }

    /**
     * Начало синхронизации истории звонков.
     * @return void
     */
    private function cdrSync():void
    {
        // Защита от параллельной обработки CDR двумя экземплярами AmoCdrDaemon
        $lockFile = '/tmp/amo_cdr_sync.lock';
        $lockFp = fopen($lockFile, 'w');
        if ($lockFp === false) {
            $this->logger->writeError('cdrSync: failed to open lock file');
            return;
        }
        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            $this->logger->writeInfo('cdrSync is locked by another process, skip this iteration');
            fclose($lockFp);
            return;
        }
        try {
            $this->cdrSyncInternal();
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Внутренняя логика синхронизации CDR (защищена file lock в cdrSync).
     * @return void
     */
    private function cdrSyncInternal():void
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
            unset($this->incompleteAnswered[$srcNum],$this->incompleteAnswered[$dstNum]);
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
                $this->cdrRows[$id] = [
                    'first'    => $row['UNIQUEID'],
                    'haveUser' => false,
                    'duration' => 0,
                    'answered' => false,
                    'records'  => []
                ];
            }
            if(isset($this->incompleteAnswered[$srcNum])){
                $this->cdrRows[$id]['incompleteType'] = $this->incompleteAnswered[$srcNum]['type'];
            }
            if(file_exists($row['recordingfile'])){
                $this->cdrRows[$id]['records'][] = $row['recordingfile'];
                $this->cdrRows[$id]['duration'] += 1*$row['billsec'];
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
            }elseif ($this->restrictCdrToKnownEmployees){
                $this->logger->writeInfo($this->callSummary($call), "The amoCRM user is not identified, the call will not be uploaded (restrictCdrToKnownEmployees = true)");
                continue;
            }
            $phoneId = AmoCrmMain::getPhoneIndex($call['params']['phone']);

            $calls[$phoneId][] = $call;
            $this->cdrRows[$row['UNIQUEID']] = $call;
            $this->logger->writeInfo($this->callSummary($call), "Result call data");
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
        $callsOk = $this->addCalls($calls, $callCounter);
        if(!$callsOk){
            $this->addCallsFailCount++;
            if($this->addCallsFailCount >= self::MAX_ADD_CALLS_FAILURES){
                // Превышен лимит попыток — пропускаем батч, фиксируем в БД и в лог.
                $failedIds = array_unique(array_keys($this->cdrRows));
                $this->logger->writeError("addCalls failed $this->addCallsFailCount times in a row, skipping batch. Lost linkedids: " . implode(', ', $failedIds));
                ConnectorDb::invoke('saveFailedCdr', [$failedIds, 'addCalls timeout after ' . self::MAX_ADD_CALLS_FAILURES . ' attempts'], false);
                $this->addCallsFailCount = 0;
                // offset НЕ откатываем — пропускаем батч
            }else{
                $this->offset = $oldOffset;
                $this->logger->writeError("addCalls failed (attempt $this->addCallsFailCount/" . self::MAX_ADD_CALLS_FAILURES . "), offset rolled back to $oldOffset");
                return;
            }
        }else{
            $this->addCallsFailCount = 0;
        }

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
                $this->logger->writeInfo($this->callSummary($call), "alertIncompleteAnswered");
                ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::getChannelUrl(), ['data' => $call, 'action' => 'open-card']);
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
    private function addCalls($calls, $callCounter):bool
    {
        $calls  = array_merge(... array_values($calls));
        if(!empty($calls)){
            $this->logger->writeSummary($calls, "CDR synchronization. Step 1 Count: ".count($calls), false, ['id']);
            $calls = $this->cleanCalls($calls, $callCounter);
        }
        if(empty($calls)){
            return true;
        }
        $this->logger->writeSummary($calls, "CDR synchronization. Step 2. Count: ".count($calls), false, ['id']);
        $ids = '';

        $callsArray = [
            'contacts' => [],
            'companies' => []
        ];
        $skippedIds = '';
        foreach ($calls as &$call) {
            if ($call['entity_id'] === null) {
                $skippedIds .= $call['id'] . '|';
                unset($call);
                continue;
            }
            $ids.= $call['id'].'|';
            $entity_type = $this->cdrRows[$call['id']]['entity_type']??'contacts';
            unset($call['id'],$call['is_app'],$call['did']);
            $callsArray[$entity_type][] = $call;
        }
        if (!empty($skippedIds)) {
            $this->logger->writeError("Skipped calls with null entity_id: $skippedIds");
        }
        unset($call, $calls);
        $allSuccess = true;
        foreach ($callsArray as $entity_type => $callsData){
            if(empty($callsData)){
                continue;
            }
            // Пытаемся добавить вызовы. Это получится, если контакты существуют.
            $result = WorkerAmoHTTP::invokeAmoApi('addCalls', [$callsData, $entity_type]);
            $this->logger->writeSummary($callsData, "Create calls (REQ): $ids", true, ['request_id']);
            $this->logger->writeInfo($result, "Create calls (RES): $ids");
            if(!$result->success){
                $this->logger->writeError("Failed to add calls for $entity_type, offset will be rolled back");
                $allSuccess = false;
            }
        }
        return $allSuccess;
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
                $haveUser = intval($this->cdrRows[$call['id']]['haveUser']) === 1;
                if(!isset($call['responsible_user_id']) && $haveUser){
                    $this->logger->writeError($call, "Unsetted responsible_user_id for {$call['id']}, drop it");
                    unset($calls[$index], $call);
                    continue;
                }
                if($call['is_app'] === '1' && $haveUser) {
                    // Этот вызов был направлен на сотрудника.
                    // Все вызовы на приложения чистим.
                    $this->logger->writeInfo($this->callSummary($call), "Is app {$call['id']}, drop it");
                    unset($calls[$index], $call);
                }elseif($this->cdrRows[$call['id']]['first'] !== $call['params']['uniq'] && $haveUser === false){
                    // Этот вызов не попал на сотрудников, только приложения
                    // Оставляем только вызов на первое приложение
                    $this->logger->writeInfo($this->callSummary($call), "Is app only {$call['id']}, drop it");
                    unset($calls[$index], $call);
                }elseif( $this->cdrRows[$call['id']]['answered'] === 1 && $call['params']['call_status'] === 6 && $haveUser){
                    // Если вызов отвечен, то не следует загружать информацию о пропущенных.
                    $this->logger->writeInfo($this->callSummary($call), "The Cdr was missed, the call was answered {$call['id']}, drop it");
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
                // Подхватываем ссылку на запись из другого leg, если у текущего результата её нет.
                if(empty($resCalls[$call['id']]['params']['link']) && !empty($call['params']['link'])){
                    $resCalls[$call['id']]['params']['link'] = $call['params']['link'];
                    if($call['params']['duration'] > 0){
                        $resCalls[$call['id']]['params']['duration'] = $call['params']['duration'];
                    }
                    $this->logger->writeInfo($this->callSummary($call), "Updated link/duration from another leg {$call['id']}");
                }
                continue;
            }
            $typeCall = $this->cdrRows[$call['id']]['type']??'';
            if(empty($typeCall) ){
                $this->logger->writeError($call, "the type of call is not defined {$call['id']}, drop it");
                continue;
            }
            $this->logger->writeInfo($this->callSummary($call), "Successful cdr verification {$call['id']}");
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
            $newLink = $this->getCreateFileAndLink($call['id'], $call['created_at']);
            if(!empty($newLink)){
                $call['params']['link'] = $newLink;
            }
            $newDuration = $this->cdrRows[$call['id']]['duration']??0;
            if($newDuration > 0){
                $call['params']['duration'] = $newDuration;
            }

            $answered = $this->cdrRows[$call['id']]['answered']??0;
            if($answered === 1){
                $call['params']['call_status'] = 4;
            }else{
                $call['params']['call_status'] = 6;
            }

            $resCalls[$call['id']] = $call;
            $this->logger->writeInfo($this->callSummary($call), "Result cdr {$call['id']}");

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
        if(!empty($this->cdrRows[$id]['records'])){
            if(count($this->cdrRows[$id]['records']) === 1){
                $fileName = $this->cdrRows[$id]['records'][0];
            }else{
                $monitor_dir = Storage::getMonitorDir();
                $sub_dir = date('Y/m/d/H', $created_at);
                $ext = pathinfo($this->cdrRows[$id]['records'][0], PATHINFO_EXTENSION);
                if(empty($ext)){
                    $ext = 'mp3';
                }
                $fileName = "$monitor_dir/amo/$sub_dir/$id.$ext";
                Util::mwMkdir(dirname($fileName));

                $records = array_reverse($this->cdrRows[$id]['records']);
                if(!empty($records)){
                    if($ext === 'webm'){
                        $this->mergeWithFfmpeg($records, $fileName);
                    }else{
                        $this->mergeWithSox($records, $fileName);
                    }
                }
            }
            $link = "https://$this->extHostname/pbxcore/api/amo-crm/playback?view=$fileName";
        }
        return $link;
    }


    /**
     * Склейка файлов через sox (mp3 и другие форматы, поддерживаемые sox).
     * @param array  $records
     * @param string $fileName
     * @return void
     */
    private function mergeWithSox(array $records, string $fileName):void
    {
        $pathSox = Util::which('sox');
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

    /**
     * Склейка файлов через ffmpeg (webm и другие форматы, не поддерживаемые sox).
     * @param array  $records
     * @param string $fileName
     * @return void
     */
    private function mergeWithFfmpeg(array $records, string $fileName):void
    {
        $pathFfmpeg = Util::which('ffmpeg');
        $inputs = '';
        $count = count($records);
        foreach ($records as $value){
            $inputs .= "-i $value ";
        }
        if($count === 2){
            $cmd = "$pathFfmpeg $inputs-filter_complex '[0:a][1:a]concat=n=2:v=0:a=1[out]' -map '[out]' -y $fileName 2>/dev/null";
        }else{
            $filterParts = '';
            for($i = 0; $i < $count; $i++){
                $filterParts .= "[$i:a]";
            }
            $cmd = "$pathFfmpeg $inputs-filter_complex '{$filterParts}concat=n=$count:v=0:a=1[out]' -map '[out]' -y $fileName 2>/dev/null";
        }
        shell_exec($cmd);
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
            $this->logger->writeInfo($this->callSummary($call), "Check incomplete answered call");

            $indexAction   = AmoCrmMain::getPhoneIndex($call['phone']);
            $contData      = $contactsData[$indexAction];
            $contactId     = $contData['contactId']??null;
            $companyId     = $contData['companyId']??null;

            $contactExists = false;
            if(!empty($contactId)){
                $this->cdrRows[$call['id']]['entity_type'] = 'contacts';
                $contactExists = true;
            }elseif(!empty($companyId)){
                $contactExists = true;
                $this->cdrRows[$call['id']]['entity_type'] = 'companies';
            }

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
            if(intval($settings['create_contact']) === 1 && !$contactExists){
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
                $this->logger->writeInfo($this->callSummary($call), "Complete call: {$call['id']}");
                $answered = $this->cdrRows[$call['id']]['answered']??0;
                if($answered === 1 && $call['params']['duration'] === 0){
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
                $companyId     = $contData['companyId']??null;

                $contactExists = false;
                if(!empty($contactId)){
                    $this->cdrRows[$call['id']]['entity_type'] = 'contacts';
                    $contactExists = true;
                    $calls[$phoneId][$index]['entity_id'] = intval($contactId);
                }elseif(!empty($companyId)){
                    $contactExists = true;
                    $this->cdrRows[$call['id']]['entity_type'] = 'companies';
                    $calls[$phoneId][$index]['entity_id'] = intval($companyId);
                }

                $isMissed      = $answered === 0;
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
                if($answered === 1){
                    $responsibleField = $settings['responsible']."AnswerUser";
                }else{
                    $responsibleField = $settings['responsible']."MissedUser";
                }
                // Получим ответственного. Пустые и нечисловые значения нельзя приводить
                // арифметикой: в PHP 8 это завершает весь воркер с TypeError.
                $callResponsible = $this->cdrRows[$call['id']][$responsibleField] ?? null;
                $responsible = ResponsibleResolver::resolve(
                    $callResponsible,
                    $settings['def_responsible'] ?? null
                );
                if ($responsible === null && ResponsibleResolver::requiresDefault($settings)) {
                    $reason = "Responsible amoCRM user is not configured for call type {$type}";
                    $this->logger->writeError(
                        [
                            'linkedid' => $call['id'],
                            'type' => $type,
                            'responsibleField' => $responsibleField,
                            'callResponsible' => $callResponsible,
                            'defaultResponsible' => $settings['def_responsible'] ?? null,
                        ],
                        $reason
                    );
                    ConnectorDb::invoke('saveFailedCdr', [[$call['id']], $reason], false);
                    $calls = CdrBatch::removeLinkedId($calls, $call['id']);
                    unset($callCounter[$call['id']]);
                    continue;
                }
                $this->cdrRows[$call['id']]['responsibleRule']      = $responsible ?? 0;
                $this->cdrRows[$call['id']]['resp_contact_user_id'] = intval($contactsData[$phoneId]['resp_contact_user_id']??'');

                $indexAction = AmoCrmMain::getPhoneIndex($phone);
                if(!$contactExists && intval($settings['create_contact']) === 1){
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
        if(intval($settings['create_task']) !== 1){
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
        if(intval($settings['create_lead']) !== 1 || !empty($lead) || isset($this->createdLeads[$call['id']])){
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
                    'id' => intval($contData['contactId']),
                    'is_main' => true
                ];
            }
            if(!empty($contData['companyId'])){
                $leadData['_embedded']['companies'][]=[
                    'id' => intval($contData['companyId']),
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

        // Повторная проверка БД: контакт мог быть создан другим процессом между
        // prepareDataCreatingEntities() и этим моментом
        $phonesToCheck = array_keys($this->newContacts);
        $freshData = ConnectorDb::invoke('getContactsData', [$phonesToCheck]);
        if (is_array($freshData)) {
            foreach ($freshData as $phoneId => $data) {
                if (!empty($data['contactId'])) {
                    $this->logger->writeInfo("Contact already exists for $phoneId (id:{$data['contactId']}), skip create");
                    $contactId = intval($data['contactId']);
                    // Привязываем существующий контакт к связанным сущностям
                    if (isset($this->newLeads[$phoneId])) {
                        $this->newLeads[$phoneId]['_embedded']['contacts'][] = [
                            'id' => $contactId,
                            'is_main' => true
                        ];
                    }
                    if (isset($this->newUnsorted[$phoneId])) {
                        $this->newUnsorted[$phoneId]['_embedded']['contacts'][] = [
                            'id' => $contactId,
                        ];
                    }
                    if (isset($this->newTasks[$phoneId])) {
                        $this->newTasks[$phoneId]['entity_id'] = $contactId;
                        $this->newTasks[$phoneId]['entity_type'] = 'contact';
                    }
                    if (isset($this->incompleteAnswered[$phoneId])) {
                        $this->incompleteAnswered[$phoneId]['client'] = $contactId;
                    }
                    if (isset($calls[$phoneId])) {
                        foreach ($calls[$phoneId] as &$call) {
                            $call['entity_id'] = $contactId;
                        }
                        unset($call);
                    }
                    unset($this->newContacts[$phoneId]);
                }
            }
        }

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
