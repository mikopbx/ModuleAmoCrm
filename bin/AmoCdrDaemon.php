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

require_once('Globals.php');

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerCdr;
use MikoPBX\Core\System\BeanstalkClient;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use Modules\ModuleAmoCrm\Models\ModuleAmoUsers;
use MikoPBX\Common\Models\Extensions;
use Modules\ModuleAmoCrm\Models\ModuleAmoRequestData;
class AmoCdrDaemon
{
    public const PID_FILE     = "/var/run/amo-cdr-daemon.pid";
    public const DAEMON_TITLE = 'AmoCdrDaemon';
    public const SOURCE_ID    = 'miko-pbx';

    private const LIMIT   = 2;
    private int $offset;
    private array $users = [];
    private int $extensionLength;
    public AmoCrmMain $connector;
    public array $innerNums = [];
    public string $referenceDate='';

    private array $cdrRows = [];

    /**
     * AmoCdrDaemon constructor.
     */
    public function __construct()
    {
        /** @var ModuleAmoCrm $settings */
        $settings = ModuleAmoCrm::findFirst();
        if($settings){
            $this->offset = 1*$settings->offsetCdr;
            $this->offset = max($this->offset,1);
            $this->referenceDate = $settings->referenceDate;
        }
        $this->connector = new AmoCrmMain();
        $this->updateUsers();
    }

    /**
     * Обновление таблицы пользователей AMO.
     */
    private function updateUsers():void
    {
        try {
            /** @var ModuleAmoUsers $user */
            $amoUsers = ModuleAmoUsers::find('enable=1');
            $this->users = [];
            foreach ($amoUsers as $user){
                $this->users[$user->number] = $user->amoUserId;
            }
        }catch (Throwable $e){
            Util::sysLogMsg(__CLASS__, $e->getMessage());
        }
        $this->extensionLength = 1*PbxSettings::getValueByKey('PBXInternalExtensionLength');

        $userList   = [];
        $this->innerNums = [];
        /** @var Extensions $ext */
        $extensions = Extensions::find(['order' => 'type DESC']);
        foreach ($extensions as $ext){
            if($ext->type === Extensions::TYPE_SIP){
                $userList[$ext->userid] = $ext->number;
            }elseif($ext->type === Extensions::TYPE_EXTERNAL && isset($userList[$ext->userid])){
                $innerNum = $userList[$ext->userid];
                if(isset($this->users[$innerNum])){
                    $amoUserId = $this->users[$innerNum];
                    $this->users[$this->getPhoneIndex($ext->number)] = $amoUserId;
                }
            }
            $this->innerNums[] = $this->getPhoneIndex($ext->number);
        }
        unset($userList);
    }

    /**
     * Возвращает усеценный слева номер телефона.
     *
     * @param $number
     *
     * @return bool|string
     */
    public function getPhoneIndex($number)
    {
        return substr($number, -10);
    }

    public static function processExists():bool
    {
        $result = false;
        if(file_exists(self::PID_FILE)){
            $psPath      = Util::which('ps');
            $busyboxPath = Util::which('busybox');
            $pid     = file_get_contents(self::PID_FILE);
            $output  = shell_exec("$psPath -A -o pid | $busyboxPath grep $pid ");
            if(!empty($output)){
                $result = true;
            }
        }
        if(!$result){
            file_put_contents(self::PID_FILE, getmypid());
        }
        return $result;
    }

    /**
     * Получение порции CDR для анализа.
     * @param array $filter
     * @return array
     */
    public function getCdr(array $filter):array
    {
        $rows = [];
        $filter['miko_result_in_file'] = true;
        $result_data = null;
        try {
            $client     = new BeanstalkClient(WorkerCdr::SELECT_CDR_TUBE);
            $result   = $client->request(json_encode($filter), 2);
            $filename = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            if (!file_exists($filename)) {
                return $rows;
            }
            $result_data = json_decode(file_get_contents($filename), true, 512, JSON_THROW_ON_ERROR);
            unlink($filename);
        }catch (Throwable $e){
            Util::sysLogMsg(self::class, $e->getMessage());
        }

        if(is_array($result_data)){
            $rows = $result_data;
        }
        return $rows;
    }

    /**
     * Начало загрузки истории звонков в Amo.
     */
    public function start():void
    {
        $this->cdrRows = [];
        $filter = [
            'id>:id: AND start>:referenceDate:',
            'bind'                => [
                'id' => $this->offset,
                'referenceDate' => $this->referenceDate
            ],
            'order'               => 'id',
            'limit'               => self::LIMIT,
            'miko_result_in_file' => true,
        ];
        $rows = $this->getCdr($filter);

        $extHostname = $this->connector->getExtHostname();
        $calls  = [];
        foreach ($rows as $row){
            $srcNum = $this->getPhoneIndex($row['src_num']);
            $dstNum = $this->getPhoneIndex($row['dst_num']);
            if(isset($this->innerNums[$srcNum], $this->innerNums[$dstNum])){
                // Это внутренний разговор.
                // Не переносим его в AMO.
                continue;
            }
            $phoneCol  = 'src_num';
            if($this->extensionLength < strlen($srcNum)){
                // Это входящий.
                $direction = 'inbound';
                $amoUserId = $this->innerNums[$dstNum]??null;
            }elseif($this->extensionLength < strlen($dstNum)){
                // Исходящий.
                $direction = 'outbound';
                $phoneCol  = 'dst_num';
                $amoUserId = $this->innerNums[$srcNum]??null;
            }else{
                continue;
            }
            if($row['billsec'] < 1){
                // Пропущенный вызов.
                $call_status = 6;
                $link = '';
            }else{
                $link = "https://$extHostname/pbxcore/api/amo-crm/playback?view={$row['recordingfile']}";
                $call_status = 4;
            }
            try {
                $d          = new DateTime($row['start']);
                $created_at = $d->getTimestamp();
            }catch (Throwable $e){
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
                'request_id'          => $row['UNIQUEID']
            ];
            if(isset($amoUserId)){
                $call['created_by'] = $amoUserId;
                $call['responsible_user_id'] = $amoUserId;
            }
            $calls[]      = $call;
            $this->offset = $row['id'];
            $this->cdrRows[$row['UNIQUEID']] = $call;
        }
        unset($rows);
        $this->addCalls($calls);
        $this->updateOffset();
    }

    private function addCalls($calls):void
    {
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
                'request_id' => $call['request_id'],
                'source_name' => self::SOURCE_ID,
                'source_uid' => self::SOURCE_ID,
                'created_at' => $call['created_at'],
                "metadata" => [
                    "is_call_event_needed"  => true,
                    "uniq"                  => $call['uniq'],
                    'duration'              => $call['duration'],
                    "service_code"          => "CkAvbEwPam6sad",
                    "link"                  => $call["link"],
                    "phone"                 => $call["phone"],
                    "called_at"             => $call['created_at'],
                    "from"                  => $call['source']
                ]
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
     */
    private function saveResponse($uid, $request, $response, $isError=0):void
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
        }catch (Throwable $e){
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
    private function updateOffset(){
        /** @var ModuleAmoCrm $settings */
        $settings = ModuleAmoCrm::findFirst();
        if(!$settings){
            return;
        }
        $settings->offsetCdr = $this->offset;
        $settings->save();
    }
}

if(AmoCdrDaemon::processExists()){
    exit(0);
}

cli_set_process_title(AmoCdrDaemon::DAEMON_TITLE);

$daemon = new AmoCdrDaemon();
while (true){
    $daemon->start();
    sleep(3);
}