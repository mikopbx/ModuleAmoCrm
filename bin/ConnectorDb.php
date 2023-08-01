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
use Modules\ModuleAmoCrm\Lib\PBXAmoResult;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Modules\ModuleAmoCrm\Models\ModuleAmoEntitySettings;
use Modules\ModuleAmoCrm\Models\ModuleAmoLeads;
use Modules\ModuleAmoCrm\Models\ModuleAmoPhones;
use Modules\ModuleAmoCrm\Models\ModuleAmoUsers;
use Throwable;
use Phalcon\Mvc\Model\Manager;

class ConnectorDb extends WorkerBase
{
    private array   $users = [];
    private int     $lastContactsSyncTime;
    private int     $lastCompaniesSyncTime;
    private int     $lastLeadsSyncTime;
    private int     $portalId = 0;

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
     * Обновление настроек по данным db.
     * @return void
     */
    public function updateSettings():void
    {
        if(isset($this->lastContactsSyncTime, $this->lastCompaniesSyncTime)){
            return;
        }
        $settings = ModuleAmoCrm::findFirst();
        if($settings){
            $this->lastContactsSyncTime  = (int)$settings->lastContactsSyncTime;
            $this->lastCompaniesSyncTime = (int)$settings->lastCompaniesSyncTime;
            $this->lastLeadsSyncTime     = (int)$settings->lastLeadsSyncTime;
            $this->portalId              = (int)$settings->portalId;
        }else{
            $this->lastContactsSyncTime  = 0;
            $this->lastCompaniesSyncTime = 0;
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
            'ModuleAmoCrm' => ModuleAmoCrm::findFirst()->toArray(),
        ];
        if(!$mainOnly){
            $settings['ModuleAmoEntitySettings'] = ModuleAmoEntitySettings::find("portalId='{$settings['ModuleAmoCrm']['portalId']}'")->toArray();
        }
        return $settings;
    }

    /**
     * Обновление текущей позиции CDR.
     */
    public function updateOffset($offset):bool
    {
        $settings = ModuleAmoCrm::findFirst();
        if(!$settings){
            return false;
        }
        $settings->offsetCdr = $offset;
        return $settings->save();
    }


    /**
     * Старт работы листнера.
     *
     * @param $params
     */
    public function start($params):void
    {
        $this->updateSettings();

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
        while ($this->needRestart === false) {
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
            $data = json_decode($tube->getBody(), true, 512, JSON_THROW_ON_ERROR);
        }catch (\Throwable $e){
            return;
        }
        $clientData = [];
        if($data['action'] === 'findContacts') {
            $clientData = $this->findContact($data['numbers']);
        }elseif($data['action'] === 'sync-сontacts'){
            $this->syncContacts(AmoCrmMain::ENTITY_COMPANIES);
            $this->syncContacts(AmoCrmMain::ENTITY_CONTACTS);
            $this->syncLeads();
        }elseif($data['action'] === 'entity-update'){
            $this->updatePhoneBook($data['data']['contacts']??[]);
            $this->updateLeads($data['data']['leads']??[]);
        }elseif($data['action'] === 'invoke'){
            $res_data = [];
            $funcName = $data['function']??'';
            if(method_exists($this, $funcName)){
                if(count($data['args']) === 0){
                    $res_data = $this->$funcName();
                }else{
                    $res_data = $this->$funcName(...$data['args']??[]);
                }
                $res_data = serialize($res_data);
            }
            $tube->reply($res_data);
        }elseif($data['action'] === 'interception'){
            $clientData = $this->findContact( [$data['phone']] );
            $userId = $clientData[0]['userId']??null;
            if( isset($this->users[$userId])){
                try {
                    $this->startInterception($data['channel'], $data['id'], $this->users[$userId], $data['phone']);
                }catch (Throwable $e){
                    Util::sysLogMsg(self::class, $e->getMessage());
                }
            }
        }
        if(!empty($clientData)){
            ClientHTTP::sendHttpPostRequest(WorkerAmoCrmAMI::CHANNEL_CALL_NAME, ['action' => 'findContact', 'data' => $clientData]);
        }
    }

    /**
     * Сохранение изменных данных контактов. Наполнение телефонной книги.
     * @param array $updates
     * @return void
     */
    private function updatePhoneBook(array $updates):void{
        $idEntityFields = [
            'contact' => 'idEntity',
            'company' => 'linked_company_id',
        ];

        $actions = ['update', 'add', 'delete'];
        foreach ($actions as $action){
            $entities = $updates[$action]??[];
            foreach ($entities as $entity){
                $idEntity = $idEntityFields[$entity['type']];
                /** @var ModuleAmoPhones $oldRecord */
                $oldData = ModuleAmoPhones::find("$idEntity='${entity['id']}'");
                foreach ($oldData as $oldRecord){
                    $oldRecord->delete();
                }
                unset($oldData);
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
                        $newRecord->writeAttribute($idEntity,$entity['id']);
                        $newRecord->save();
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
                ]
            ];
            $res = ModuleAmoPhones::findFirst($filter);
            if($res){
                $result[]= $res->toArray();
                $this->saveCache(self::class.':'.$phone, $res->toArray(), 60);
            }else{
                $this->saveCache(self::class.':'.$phone, [], 10);
            }
        }
        return $result;
    }

    /**
     * Синхронизация контактов.
     * @param $entityType
     * @return void
     */
    public function syncContacts($entityType):void
    {
        $this->updateSettings();

        $endTime = time();
        $result = WorkerAmoHTTP::invokeAmoApi('getChangedContacts', [$this->lastContactsSyncTime, $endTime, $entityType]);
        if(empty($result->data[$entityType])){
            return;
        }
        $this->updatePhoneBook(['update' => $result->data[$entityType]]);
        while(!empty($result->data['nextPage'])){
            $result = WorkerAmoHTTP::invokeAmoApi('getChangedContacts', [$this->lastContactsSyncTime, $endTime, $entityType, $result->data['nextPage']]);
            $this->updatePhoneBook(['update' => $result->data[$entityType]]);
        }

        $fieldName = "last".ucfirst($entityType)."SyncTime";
        $settings = ModuleAmoCrm::findFirst();
        if($settings){
            $settings->$fieldName = $endTime;
        }
        try {
            $res = $settings->save();
        }catch (Throwable $e){
            $res = false;
        }
        if($res){
            $this->$fieldName = $endTime;
        }
    }

    /**
     * Синхронизация сделок.
     * @return void
     */
    public function syncLeads():void
    {
        $this->updateSettings();
        $entityType = 'leads';
        $endTime = time();
        $result = WorkerAmoHTTP::invokeAmoApi('getChangedLeads', [$this->lastLeadsSyncTime, $endTime]);
        if(empty($result->data[$entityType])){
            return;
        }
        $this->updateLeads([ 'update' => $result->data[$entityType] ]);
        while(!empty($result->data['nextPage'])){
            $result = WorkerAmoHTTP::invokeAmoApi('getChangedLeads', [$this->lastLeadsSyncTime, $endTime, $result->data['nextPage']]);
            $this->updateLeads([ 'update' => $result->data[$entityType]]);
        }

        $settings = ModuleAmoCrm::findFirst();
        if($settings){
            $settings->lastLeadsSyncTime = $endTime;
        }
        try {
            $res = $settings->save();
        }catch (Throwable $e){
            $res = false;
        }
        if($res){
            $this->lastLeadsSyncTime = $endTime;
        }
    }

    /**
     * Обновление сделки.
     * @param array $updates
     * @return void
     */
    private function updateLeads(array $updates):void
    {
        $actions = ['update', 'add', 'delete'];
        foreach ($actions as $action){
            $leads = $updates[$action]??[];
            foreach ($leads as $lead){
                /** @var ModuleAmoPhones $oldRecord */
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
                    $newRecord->save();
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
                    $newRecord->save();
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
     * Выполнение метода API через свойство worker $this->AmoCrmMain
     * Метод следует вызывать при работе с API из прочих процессов.
     * @param $function
     * @param $args
     */
    public static function invoke($function, $args){
        $req = [
            'action'   => 'invoke',
            'function' => $function,
            'args'     => $args
        ];
        $client = new BeanstalkClient(ConnectorDb::class);
        try {
            $result = $client->request(json_encode($req, JSON_THROW_ON_ERROR), 20);
            $object = unserialize($result, ['allowed_classes' => [PBXAmoResult::class, PBXApiResult::class]]);
        } catch (\Throwable $e) {
            $object = [];
        }
        return $object;
    }
}

if(isset($argv) && count($argv) !== 1){
    ConnectorDb::startWorker($argv??[]);
}
