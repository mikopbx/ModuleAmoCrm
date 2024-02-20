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
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use Modules\ModuleAmoCrm\Lib\ClientHTTP;
use Modules\ModuleAmoCrm\Lib\Logger;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Modules\ModuleAmoCrm\Models\ModuleAmoEntitySettings;
use Modules\ModuleAmoCrm\Models\ModuleAmoLeads;
use Modules\ModuleAmoCrm\Models\ModuleAmoPhones;
use Modules\ModuleAmoCrm\Models\ModuleAmoPipeLines;
use Modules\ModuleAmoCrm\Models\ModuleAmoUsers;
use Phalcon\Di;
use Throwable;
use Phalcon\Mvc\Model\Manager;

class ConnectorDb extends WorkerBase
{
    private array   $users = [];
    private int     $portalId = 0;
    private int     $initTime = 0;
    private Logger  $logger;

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
     * Callback for the ping to keep the connection alive.
     *
     * @param BeanstalkClient $message The received message.
     *
     * @return void
     */
    public function pingCallBack(BeanstalkClient $message): void
    {
        $this->logger->writeInfo(getmypid().': pingCallBack ...');
        $this->logger->rotate();
        parent::pingCallBack($message);
    }

    /**
     * Обновление настроек по данным db.
     * @return void
     */
    public function updateSettings():void
    {
        $settings = ModuleAmoCrm::findFirst();
        if($settings){
            $this->portalId              = (int)$settings->portalId;
        }else{
            $settings = new ModuleAmoCrm();
            $settings->lastContactsSyncTime  = 0;
            $settings->lastCompaniesSyncTime = 0;
            $settings->lastLeadsSyncTime     = 0;
            $settings->save();
        }
    }

    /**
     * Возвращает настройки
     * @param bool $mainOnly
     * @return array
     */
    public function getModuleSettings(bool $mainOnly = false):array
    {
        $settings = [
            'startTime' => time(),
        ];
        $dbData = ModuleAmoCrm::findFirst();
        if(!$dbData){
            return [];
        }
        $settings['ModuleAmoCrm'] = $dbData->toArray();
        if(!$mainOnly){
            $settings['ModuleAmoEntitySettings'] = ModuleAmoEntitySettings::find("portalId='{$dbData->portalId}'")->toArray();
        }
        $settings['endTime'] = time();
        return $settings;
    }

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->updateSettings();
        $this->logger =  new Logger('ConnectorDb', 'ModuleAmoCrm');
        $this->logger->writeInfo('Starting '. basename(__CLASS__).'...');
        $this->logger->writeInfo($argv);

        $beanstalk      = new BeanstalkClient(self::class);
        $amoUsers       = ModuleAmoUsers::find('enable=1');
        foreach ($amoUsers as $user){
            if(!is_numeric($user->amoUserId)){
                continue;
            }
            $this->users[1*$user->amoUserId] = preg_replace('/[D]/', '', $user->number);
        }

        $this->logger->writeInfo($this->users);

        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while ($this->needRestart === false) {
            $beanstalk->wait();
        }
    }


    /**
     * Возвращает данные из кэш.
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
            $data = json_decode($tube->getBody(), true, 512, JSON_THROW_ON_ERROR);
        }catch (\Throwable $e){
            $tube->reply(false);
            return;
        }
        $this->logger->writeInfo($data);
        $res_data = [];
        if($data['action'] === 'entity-update'){
            $this->updatePhoneBook($data['data']['contacts']??[]);
            $this->updateLeads($data['data']['leads']??[]);
        }elseif($data['action'] === 'invoke'){
            $funcName = $data['function']??'';
            if(method_exists($this, $funcName)){
                if(count($data['args']) === 0){
                    $res_data = $this->$funcName();
                }else{
                    $res_data = $this->$funcName(...$data['args']??[]);
                }
                $res_data = $this->saveResultInTmpFile($res_data);
            }
        }elseif($data['action'] === 'interception'){
            $clientData = $this->findContacts( [$data['phone']] );
            $userId = $clientData[0]['userId']??null;
            if( isset($this->users[$userId])){
                try {
                    $this->startInterception($data['channel'], $data['id'], $this->users[$userId], $data['phone']);
                }catch (Throwable $e){
                    Util::sysLogMsg(self::class, $e->getMessage());
                }
            }
        }
        $tube->reply($res_data);

    }

    /**
     * Сериализует данные и сохраняет их во временный файл.
     * @param $data
     * @return string
     */
    private function saveResultInTmpFile($data):string
    {
        try {
            $res_data = json_encode($data, JSON_THROW_ON_ERROR);
        }catch (\JsonException $e){
            return '';
        }
        $downloadCacheDir = '/tmp/';
        $tmpDir = '/tmp/';
        $di = Di::getDefault();
        if ($di) {
            $dirsConfig = $di->getShared('config');
            $tmoDirName = $dirsConfig->path('core.tempDir') . '/ModuleAmoCrm';
            Util::mwMkdir($tmoDirName);
            chown($tmoDirName, 'www');
            if (file_exists($tmoDirName)) {
                $tmpDir = $tmoDirName;
            }

            $downloadCacheDir = $dirsConfig->path('www.downloadCacheDir');
            if (!file_exists($downloadCacheDir)) {
                $downloadCacheDir = '';
            }
        }
        $fileBaseName = md5(microtime(true));
        // "temp-" in the filename is necessary for the file to be automatically deleted after 5 minutes.
        $filename = $tmpDir . '/temp-' . $fileBaseName;
        file_put_contents($filename, $res_data);
        if (!empty($downloadCacheDir)) {
            $linkName = $downloadCacheDir . '/' . $fileBaseName;
            // For automatic file deletion.
            // A file with such a symlink will be deleted after 5 minutes by cron.
            Util::createUpdateSymlink($filename, $linkName, true);
        }
        chown($filename, 'www');
        return $filename;
    }

    /**
     * Старт новой синхронизации контактов.
     * @param int $initTime
     * @return void
     */
    public function updateInitTime(int $initTime):void
    {
        $this->initTime = $initTime;
    }

    /**
     * Удаляет все записи, что не соответствуют $initTime
     * Используется при синхронизации / актуализации контактов
     * @param int $initTime
     * @return void
     */
    public function deleteWithFailTime(int $initTime):void
    {
        $this->initTime = $initTime;
        ModuleAmoLeads::find("initTime<>'$initTime'")->delete();
        ModuleAmoPhones::find("initTime<>'$initTime'")->delete();
    }

    /**
     * Сохранение изменных данных контактов. Наполнение телефонной книги.
     * @param array $updates
     * @return void
     */
    public function updatePhoneBook(array $updates):void{
        $idEntityFields = [
            'contact' => 'idEntity',
            'company' => 'linked_company_id',
        ];

        $actions = ['update', 'add', 'delete'];
        foreach ($actions as $action){
            $entities = $updates[$action]??[];
            foreach ($entities as $entity){
                $idEntity = $idEntityFields[$entity['type']];
                ModuleAmoPhones::find("$idEntity='${entity['id']}'")->delete();
                if($action === 'delete'){
                    continue;
                }
                $custom_fields = $entity['custom_fields']??$entity['custom_fields_values']??'';
                foreach ($custom_fields as $field){
                    $fCode = $field['code']??$field['field_code']??'';
                    if($fCode !== 'PHONE'){
                        continue;
                    }
                    foreach ($field['values'] as $value){
                        /** @var ModuleAmoPhones $newRecord */
                        $newRecord = new ModuleAmoPhones();
                        $newRecord->portalId            = $this->portalId;
                        $newRecord->entityType          = $entity['type'];
                        $newRecord->responsible_user_id = $entity['responsible_user_id'];
                        $newRecord->phone               = $value['value'];
                        $newRecord->idPhone             = AmoCrmMain::getPhoneIndex($value['value']);
                        $newRecord->name                = $entity['name']??'';
                        $newRecord->company_name        = $entity['company_name']??'';
                        $newRecord->linked_company_id   = $entity['linked_company_id']??'';
                        $newRecord->initTime            = $this->initTime;
                        $newRecord->writeAttribute($idEntity,$entity['id']);
                        if(!$newRecord->save()){
                            $this->logger->writeError(['error' => 'Fail save contact', 'msg' => $newRecord->getMessages(), 'data' => $entity]);
                        }
                    }
                }
            }
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
    public function findContacts($numbers):array
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

            // Поиск в Extensions.
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
                    'number' => $phone,
                    'entity' => ''
                ];
                $result[] = $data;
                $this->saveCache(self::class.':'.$phone, $data, 120);
                continue;
            }
            if(strlen($phone) <=5){
                continue;
            }
            // Поиск в локальной базе данных.
            $filter =  [
                'conditions' => 'idPhone = :phone:',
                'columns'    => ['idEntity as id,name,company_name as company,responsible_user_id as userId,phone as number,entityType as entity'],
                'bind'       => [
                    'phone' => AmoCrmMain::getPhoneIndex($phone)
                ],
                'order' => 'id'
            ];
            $res = ModuleAmoPhones::findFirst($filter);
            if($res){
                $result[]= $res->toArray();
                $this->saveCache(self::class.':'.$phone, $res->toArray(), 60);
            }else{
                $this->saveCache(self::class.':'.$phone, [], 10);
            }
        }
        ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_CALL_NAME, ['action' => 'findContact', 'data' => $result]);
        return $result;
    }

    /**
     * Заполняет настройки по умолчанию для создания сущностей.
     * @return void
     */
    public function fillEntitySettings():void
    {
        $filter     = "'$this->portalId'=portalId";
        $dbSettings = ModuleAmoEntitySettings::find($filter);
        $haveEmptyDefUser  = false;
        $haveEmptyPipeline = false;
        if(count($dbSettings->toArray()) === 0){
            $defSettingsFile = dirname(__DIR__).'/db/default-entity-settings.json';
            try {
                $defSettings = json_decode(file_get_contents($defSettingsFile), true, 512, JSON_THROW_ON_ERROR);
            }catch (\Exception $e){
                $defSettings = [];
            }
            foreach ($defSettings as $defSetting){
                $setting = new ModuleAmoEntitySettings();
                foreach ($defSetting as $key => $value){
                    $setting->writeAttribute($key, $value);
                }
                $setting->portalId = $this->portalId;
                $setting->save();
            }

            $haveEmptyDefUser  = true;
            $haveEmptyPipeline = true;
        }else{
            foreach ($dbSettings as $dbSetting){
                if(empty($dbSetting->def_responsible)){
                    $haveEmptyDefUser = true;
                }
                if(empty($dbSetting->lead_pipeline_id) || empty($dbSetting->lead_pipeline_status_id)){
                    $haveEmptyPipeline = true;
                }
            }
        }

        if($haveEmptyDefUser){
            $dbSettings = ModuleAmoEntitySettings::find($filter);
            $user = ModuleAmoUsers::findFirst($filter);
            if($user){
                foreach ($dbSettings as $dbSetting){
                    if(empty($dbSetting->def_responsible)){
                        $dbSetting->def_responsible  = $user->amoUserId;
                        $dbSetting->save();
                    }
                }
            }
        }

        if($haveEmptyPipeline){
            $dbSettings = ModuleAmoEntitySettings::find($filter);
            $pipeline   = ModuleAmoPipeLines::findFirst($filter);
            if($pipeline){
                $statuses = json_decode($pipeline->statuses, true, 512, JSON_THROW_ON_ERROR);
                $status = '';
                foreach ($statuses as $statusData){
                    if($statusData['is_editable']){
                        $status = $statusData['id'];
                        break;
                    }
                }
                if(!empty($status)){
                    foreach ($dbSettings as $dbSetting){
                        if(empty($dbSetting->lead_pipeline_id) || empty($dbSetting->lead_pipeline_status_id)){
                            $dbSetting->lead_pipeline_id  = $pipeline->amoId;
                            $dbSetting->lead_pipeline_status_id = $status;
                            $dbSetting->save();
                        }
                    }
                }
            }
        }

    }

    /**
     * Обновление сделки.
     * @param array $updates
     * @return void
     */
    public function updateLeads(array $updates):void
    {
        $actions = ['update', 'add', 'delete'];
        foreach ($actions as $action){
            $leads = $updates[$action]??[];
            foreach ($leads as $lead){
                $oldData = ModuleAmoLeads::find("idAmo='{$lead['id']}'");
                foreach ($oldData as $oldRecord){
                    $oldRecord->delete();
                }
                unset($oldData);
                if($action === 'delete'){
                    continue;
                }
                $contacts = $lead['_embedded']['contacts']??[];
                $company  = $lead['_embedded']['companies'][0]['id']??'';
                foreach ($contacts as $contact){
                    $newRecord = new ModuleAmoLeads();
                    $newRecord->portalId            = $this->portalId;
                    $newRecord->idAmo               = $lead['id'];
                    $newRecord->name                = $lead['name'];
                    $newRecord->responsible_user_id = $lead['responsible_user_id'];
                    $newRecord->status_id           = $lead['status_id'];
                    $newRecord->pipeline_id         = $lead['pipeline_id'];
                    $newRecord->contactId           = $contact['id'];
                    $newRecord->companyId           = $company;
                    $newRecord->isMainContact       = $contact['is_main']?'1':'0';
                    $newRecord->closed_at           = $lead['closed_at']??0;
                    $newRecord->initTime            = $this->initTime;
                    if(!$newRecord->save()){
                        $this->logger->writeError(['error' => 'Fail save contact', 'msg' => $newRecord->getMessages(), 'data' => $contact]);
                    }
                }
                if(!empty($company) && count($contacts) === 0){
                    $newRecord = new ModuleAmoLeads();
                    $newRecord->portalId            = $this->portalId;
                    $newRecord->idAmo               = $lead['id'];
                    $newRecord->name                = $lead['name'];
                    $newRecord->responsible_user_id = $lead['responsible_user_id'];
                    $newRecord->status_id           = $lead['status_id'];
                    $newRecord->pipeline_id         = $lead['pipeline_id'];
                    $newRecord->contactId           = '';
                    $newRecord->companyId           = $company;
                    $newRecord->isMainContact       = '0';
                    $newRecord->closed_at           = $lead['closed_at']??0;
                    $newRecord->initTime            = $this->initTime;
                    if(!$newRecord->save()){
                        $this->logger->writeError(['error' => 'Fail save contact', 'msg' => $newRecord->getMessages(), 'data' => $lead]);
                    }
                }
            }
        }
    }

    /**
     * Заполнение вспомогательных таблиц после добавления неразобранного.
     * @param $phone
     * @param $contactId
     * @param $leadId
     * @return void
     */
    public function addContactLeadFromUnsorted($phone, $contactId, $leadId):bool
    {
        $oldData = ModuleAmoLeads::findFirst("idAmo='{$leadId}'");
        if(!$oldData){
            $newRecord = new ModuleAmoLeads();
            $newRecord->portalId            = $this->portalId;
            $newRecord->idAmo               = $leadId;
            $newRecord->contactId           = $contactId;
            $newRecord->isMainContact       = '1';
            $newRecord->closed_at           = 0;
            $newRecord->save();
        }

        $oldData = ModuleAmoPhones::findFirst("idEntity='{$phone}'");
        if(!$oldData){
            /** @var ModuleAmoPhones $newRecord */
            $newRecord = new ModuleAmoPhones();
            $newRecord->portalId            = $this->portalId;
            $newRecord->entityType          = 'contact';
            $newRecord->idPhone             = $phone;
            $newRecord->idEntity            = $contactId;
            $newRecord->initTime            = $this->initTime;
            $newRecord->save();
        }
        return true;
    }

    /**
     * Получение информации по наличию сделки для номера телефона.
     * @param array $numbers
     * @return array
     */
    public function getContactsData(array $numbers):array
    {
        if(empty($numbers)){
            return [];
        }
        /** @var Manager $manager */
        $manager = $this->di->get('modelsManager');
        $phones = [];
        foreach ($numbers as $number){
            $phones[(string)$number] = AmoCrmMain::getPhoneIndex($number);
        }
        $parameters = [
            'models'     => [
                'ModuleAmoPhones' => ModuleAmoPhones::class,
            ],
            'conditions' => 'ModuleAmoPhones.portalId = :portalId: AND ModuleAmoPhones.idPhone IN ({idPhone:array})',
            'bind'  => [
                'idPhone' => array_values($phones),
                'portalId' => $this->portalId
            ],
            'columns'    => [
                'idPhone'            => 'ModuleAmoPhones.idPhone',
                'responsible_user_id'=> 'MAX(ModuleAmoPhones.responsible_user_id)',
                'contactId'          => 'MAX(ModuleAmoPhones.idEntity)',
                'companyId'          => 'MAX(ModuleAmoPhones.linked_company_id)',
                'leadId'             => 'MAX(ModuleAmoLeads.idAmo)',
            ],
            'group'      => [
                'ModuleAmoPhones.idPhone'
            ],
            'order'      => 'ModuleAmoPhones.entityType DESC',
            'joins'      => [
                'ModuleAmoLeads' => [
                    0 => ModuleAmoLeads::class,
                    1 => '((ModuleAmoPhones.idEntity <> "" AND ModuleAmoPhones.idEntity = ModuleAmoLeads.contactId) OR (ModuleAmoLeads.companyId <> "" AND ModuleAmoLeads.companyId = ModuleAmoPhones.linked_company_id)) AND ModuleAmoLeads.closed_at = 0 AND ModuleAmoLeads.portalId = :portalId:',
                    2 => 'ModuleAmoLeads',
                    3 => 'LEFT',
                ]
            ],
        ];

        $query  = $manager->createBuilder($parameters)->getQuery();
        $result = $query->execute()->toArray();

        $data = [];
        foreach ($result as $row){
            $keys = array_keys($phones, $row['idPhone'], true);
            foreach ($keys as $key){
                $data[$key] = $row;
            }
        }
        foreach ($numbers as $phone){
            if(!isset($data[$phone])){
                $data[$phone] = null;
            }
        }
        return $data;
    }

    /**
     * @param int $enable
     * @return array
     */
    public function getPortalUsers(int $enable = 1):array
    {
        /** @var Manager $manager */
        $manager = $this->di->get('modelsManager');
        $parameters = [
            'models'     => [
                'ModuleAmoCrm'   => ModuleAmoCrm::class,
            ],
            'bind'  => [
                'enable' => $enable
             ],
            'columns'    => [
                'portalId'  => 'ModuleAmoCrm.portalId',
                'amoUserId' => 'ModuleAmoUsers.amoUserId',
                'number'    => 'ModuleAmoUsers.number',
                'id'        => 'ModuleAmoUsers.id',
                'enable'    => 'ModuleAmoUsers.enable',
            ],
            'order'      => 'ModuleAmoUsers.amoUserId',
            'joins'      => [
                'ModuleAmoUsers' => [
                    0 => ModuleAmoUsers::class,
                    1 => 'ModuleAmoUsers.portalId = ModuleAmoCrm.portalId AND ModuleAmoUsers.enable = :enable:',
                    2 => 'ModuleAmoUsers',
                    3 => 'INNER',
                ]
            ],
        ];
        $query  = $manager->createBuilder($parameters)->getQuery();
        return $query->execute()->toArray();
    }

    /**
     * Синхронизация воронок и статусов.
     * @param array $data
     * @return array
     */
    public function updatePipelines(array $data):array
    {
        $lineIds = [];
        $dbData = ModuleAmoPipeLines::find(["'$this->portalId'=portalId",'columns' => 'amoId,name']);
        foreach ($dbData as $lineData){
            $lineIds[$lineData->amoId] = $lineData->name;
        }
        foreach ($data as $line){
            if(isset($lineIds[$line['id']])){
                $dbData = ModuleAmoPipeLines::findFirst("'$this->portalId'=portalId AND amoId='{$line['id']}'");
            }else{
                // Такой линии нет в базе данных.
                $dbData = new ModuleAmoPipeLines();
                $dbData->amoId    = $line['id'];
                $dbData->portalId = $this->portalId;
            }
            $dbData->name  = $line['name'];
            try {
                $dbData->statuses = json_encode($line['_embedded']['statuses'], JSON_THROW_ON_ERROR);
            }catch (\JsonException $e){
                $dbData->statuses = [];
            }
            $dbData->save();
            unset($lineIds[$line['id']]);
        }
        foreach ($lineIds as $id => $name){
            /** @var ModuleAmoPipeLines $dbData */
            $dbData = ModuleAmoPipeLines::findFirst("'$this->portalId'=portalId AND amoId='{$id}'");
            if($dbData){
                // Такой воронки больше нет. удаляем.
                $dbData->delete();
            }
        }

        return $this->getPipeLines();
    }

    /**
     * Возвращает все воронки.
     * @param bool $source
     * @return array
     */
    public function getPipeLines(bool $source = false):array
    {
        if($source){
            return ModuleAmoPipeLines::find(["'$this->portalId'=portalId"])->toArray();
        }
        $pipeLines = [];
        $dbData = ModuleAmoPipeLines::find(["'$this->portalId'=portalId", 'columns' => 'amoId,did,name']);
        foreach ($dbData as $line){
            $pipeLines[] = [
                'id'    => $line->amoId,
                'name'  => $line->name
            ];
        }
        return $pipeLines;
    }

    /**
     * Сохраняет новые настройки в базу данных.
     * @param array $data
     * @return bool
     */
    public function saveNewSettings(array $data):bool
    {
        /** @var ModuleAmoCrm $settings */
        $settings = ModuleAmoCrm::findFirst();
        if(!$settings){
            $settings = new ModuleAmoCrm();
        }
        if(isset($data['portalId']) && (int)$data['portalId'] === 0){
            // Не сохраняем пустое значение portalId.
            unset($data['portalId']);
        }
        foreach ($settings->toArray() as $key => $value){
            if(isset($data[$key])){
                $settings->writeAttribute($key, $data[$key]);
            }
        }
        return $settings->save();
    }

    /**
     * Сохраняем список сотрудников портала.
     * @param $users
     * @param $portalId
     * @return bool
     */
    public function saveAmoUsers($users, $portalId):bool
    {
        $result = true;
        foreach ($users as $amoUserId => $number){
            $dbData = ModuleAmoUsers::findFirst("amoUserId='$amoUserId' AND portalId='$portalId'");
            if(!$dbData){
                $dbData = new ModuleAmoUsers();
                $dbData->amoUserId = $amoUserId;
                $dbData->portalId  = $portalId;
            }
            $dbData->number = trim($number);
            $result = min($dbData->save(), $result);
        }
        return $result;
    }

    /**
     * Метод следует вызывать при работе с API из прочих процессов.
     * @param string $function
     * @param array $args
     * @param bool $retVal
     * @return array|bool|mixed
     */
    public static function invoke(string $function, array $args = [], bool $retVal = true){
        $req = [
            'action'   => 'invoke',
            'function' => $function,
            'args'     => $args
        ];
        $client = new BeanstalkClient(self::class);
        $object = [];
        try {
            if($retVal){
                $req['need-ret'] = true;
                $result = $client->request(json_encode($req, JSON_THROW_ON_ERROR), 20);
            }else{
                $client->publish(json_encode($req, JSON_THROW_ON_ERROR));
                return true;
            }
            if(file_exists($result)){
                $object = json_decode(file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
                unlink($result);
            }
        } catch (\Throwable $e) {
            $object = [];
        }
        return $object;
    }

    /**
     * Действия контроллера с базой данных
     */

    /**
     * Delete record
     */
    public function deleteEntitySettings($id): array
    {
        $result = new PBXApiResult();
        $record = ModuleAmoEntitySettings::findFirstById($id);
        if ($record !== null && ! $record->delete()) {
            $result->messages[] = implode('<br>', $record->getMessages());
            $result->success    = false;
        }else{
            $result->data['id'] = $id;
            $result->success    = true;
        }
        return $result->getResult();
    }

    /**
     * Get record
     */
    public function getEntitySettings($id): array
    {
        $result = new PBXApiResult();
        $rule = null;
        if($id){
            $rule = ModuleAmoEntitySettings::findFirst("id='$id'");
        }
        if(!$rule){
            $rule = new ModuleAmoEntitySettings();
            $settings = ModuleAmoCrm::findFirst();
            if ($settings) {
                $rule->portalId = $settings->portalId;
            }
        }
        $result->data = $rule->toArray();
        $result->data['represent'] = $rule->getRepresent();
        return $result->getResult();
    }

    /**
     * Save settings entity settings action
     */
    public function saveEntitySettingsAction($data):array
    {
        $result = new PBXApiResult();

        $did = trim($data['did']);
        $filter = [
            "did=:did: AND type=:type: AND id<>:id: AND portalId=:portalId:",
            'bind'    => [
                'did' => $did,
                'type'=> $data['type']??'',
                'portalId'=> $data['portalId']??'',
                'id'  => $data['id']
            ]
        ];
        $record = ModuleAmoEntitySettings::findFirst($filter);
        if($record){
            $result->messages[] = Util::translate('mod_amo_rule_for_type_did_exists', false);
            $result->success = false;
            return $result->getResult();
        }

        $record = null;
        if(!empty($data['id'])){
            $record = ModuleAmoEntitySettings::findFirstById($data['id']);
        }
        if ($record === null) {
            $record = new ModuleAmoEntitySettings();
        }
        $this->db->begin();
        foreach ($record as $key => $value) {
            if($key === 'id'){
                continue;
            }
            if(in_array($key,['create_contact', 'create_lead', 'create_unsorted', 'create_task'])){
                $record->$key = ($data[$key] === 'on' || $data[$key] === true) ? '1' : '0';
            } elseif (array_key_exists($key, $data)) {
                $record->$key = trim($data[$key]);
            } else {
                $record->$key = '';
            }
        }

        if (FALSE === $record->save()) {
            $result->messages[] = $record->getMessages();
            $result->success = false;
            $this->db->rollback();
            return $result->getResult();
        }
        $result->success = true;
        $result->data['id'] = $record->id;
        $this->db->commit();

        return $result->getResult();
    }


}

if(isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(ConnectorDb::class) === $argv[0]){
    ConnectorDb::startWorker($argv??[]);
}
