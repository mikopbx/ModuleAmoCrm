<?php

namespace Modules\ModuleAmoCrm\Lib;

use MikoPBX\Core\System\Util;
use MikoPBX\Modules\PbxExtensionUtils;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\bin\ConnectorDb;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Modules\ModuleAmoCrm\Models\ModuleAmoPipeLines;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\PbxSettings;

class AmoCrmMain extends AmoCrmMainBase
{
    private string $baseDomain = '';
    private AuthToken $token;

    public bool   $isPrivateWidget;
    public string $privateClientId;
    public string $privateClientSecret;

    public const ENTITY_CONTACTS = 'contacts';
    public const ENTITY_COMPANIES = 'companies';

    /**
     * Инициализации API клиента.
     * AmoCrmMain constructor.
     */
    public function __construct()
    {
        parent::__construct();
        /** @var ModuleAmoCrm $settings */
        $settings = ModuleAmoCrm::findFirst();
        if($settings){
            $this->baseDomain   = $settings->baseDomain;
            $this->token = new AuthToken((string)$settings->authData);
            $this->isPrivateWidget     = (string)$settings->isPrivateWidget === '1';
            $this->privateClientId     = ''.$settings->privateClientId;
            $this->privateClientSecret = ''.$settings->privateClientSecret;
            $this->refreshToken();
        }
    }

    private function getClientId():string
    {
        if($this->isPrivateWidget) {
            return $this->privateClientId;
        }
        return self::CLIENT_ID;
    }
    private function getClientSecret():string
    {
        if($this->isPrivateWidget){
            return $this->privateClientSecret;
        }
        return self::CLIENT_SECRET;
    }

    /**
     * Получение токена из code.
     * @param $code
     * @return PBXAmoResult
     */
    public function getAccessTokenByCode($code):PBXAmoResult
    {
        $params  = [
            'client_id'     => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::REDIRECT_URL,
        ];
        $url = "https://$this->baseDomain/oauth2/access_token";
        $result = ClientHTTP::sendHttpPostRequest($url, $params);
        if($result->success){
            $connectionData = $this->checkConnection();
            $id = $connectionData->data['id']??0;
            $result->success = $this->token->saveToken($result->data, $id);
        }
        return $result;
    }

    /**
     * Обновление токена.
     * @return void
     */
    private function refreshToken():void
    {
        $refreshToken = $this->token->getRefreshToken();
        if(empty($refreshToken)){
            return;
        }
        if(!$this->token->isExpired()){
            // Обновлять токен не требуется.
            return;
        }
        $url = "https://$this->baseDomain/oauth2/access_token";
        $params = [
            'client_id'     => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'redirect_uri'  => self::REDIRECT_URL,
        ];
        $result = ClientHTTP::sendHttpPostRequest($url, $params);
        if($result->success){
            $this->token->saveToken($result->data);
        }
    }

    /**
     * Инициация телефонного звонка.
     *
     * @param $peer_number
     * @param $peer_mobile
     * @param $dest_number
     *
     * @return array
     * @throws \Exception
     */
    public static function amiOriginate($peer_number, $peer_mobile, $dest_number): array
    {
        return Util::getAstManager('off')->Originate(
            'Local/' . $peer_number . '@amo-orig-leg-1',
            null,
            null,
            null,
            "Wait",
            "300",
            null,
            "$dest_number <$dest_number>",
            "_DST_CONTEXT=all_peers,__peer_mobile={$peer_mobile}",
        );
    }

    /**
     * Получение данных аккаунта.
     */
    public function checkConnection(): PBXAmoResult
    {
        if(!PbxExtensionUtils::isEnabled($this->moduleUniqueId)){
            return new PBXAmoResult();
        }
        $authorization = $this->token->getTokenType().' '.$this->token->getAccessToken();
        if(empty(trim($authorization))){
            return new PBXAmoResult();
        }
        $url = "https://$this->baseDomain/api/v2/account";
        $headers = [
            'Authorization' => $authorization,
        ];
        return ClientHTTP::sendHttpGetRequest($url, [], $headers);
    }

    public function addCalls($calls):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/calls";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return ClientHTTP::sendHttpPostRequest($url, $calls, $headers);
    }

    /**
     * Синхронизация воронок.
     * @param $portalId
     * @return array
     * @throws \JsonException
     */
    public function syncPipeLines($portalId):array
    {
        if(!isset($this->token)){
            return [];
        }
        $url = "https://$this->baseDomain/api/v4/leads/pipelines";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        $response = ClientHTTP::sendHttpGetRequest($url, [], $headers);
        if($response->success){
            $data = $response->data['_embedded']['pipelines']??[];
            if(is_array($data)){
                $lineIds = [];
                $dbData = ModuleAmoPipeLines::find(["'$portalId'=portalId",'columns' => 'amoId,name']);
                foreach ($dbData as $lineData){
                    $lineIds[$lineData->amoId] = $lineData->name;
                }
                foreach ($data as $line){
                    if(isset($lineIds[$line['id']])){
                        $dbData = ModuleAmoPipeLines::findFirst("'$portalId'=portalId AND amoId='{$line['id']}'");
                    }else{
                        // Такой линии нет в базе данных.
                        $dbData = new ModuleAmoPipeLines();
                        $dbData->amoId    = $line['id'];
                        $dbData->portalId = $portalId;
                    }
                    $dbData->name  = $line['name'];
                    $dbData->statuses = json_encode($line['_embedded']['statuses'], JSON_THROW_ON_ERROR);
                    $dbData->save();
                    unset($lineIds[$line['id']]);
                }
                foreach ($lineIds as $id => $name){
                    /** @var ModuleAmoPipeLines $dbData */
                    $dbData = ModuleAmoPipeLines::findFirst("'$portalId'=portalId AND amoId='{$id}'");
                    if($dbData){
                        // Такой воронки больше нет. удаляем.
                        $dbData->delete();
                    }
                }
            }
        }
        $pipeLines = [];
        $dbData = ModuleAmoPipeLines::find(["'$portalId'=portalId", 'columns' => 'amoId,did,name']);
        foreach ($dbData as $line){
            $pipeLines[] = [
                'id'    => $line->amoId,
                'name'  => $line->name
            ];
        }
        return $pipeLines;
    }

    /**
     * Запрос в amoCRM сведений о контакте.
     * @param $phone
     * @return PBXApiResult
     */
    public function getContactDataByPhone($phone):PBXApiResult
    {
        $url = "https://$this->baseDomain/private/api/v2/json/contacts/list";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        $params = [
            'query' => $phone
        ];
        $response = ClientHTTP::sendHttpGetRequest($url, $params, $headers);
        $result   = new PBXApiResult();

        $contact = $response->data['response']['contacts'][0]??false;
        if($response->success && $contact){
            $result->data = [
                'id'     => $contact['id']??'',
                'name'   => $contact['name']??'',
                'company'=> $contact['company_name']??'',
                'userId' => $contact['responsible_user_id']??'',
                'number' => $phone,
                'entity' => 'contact'

            ];
            $result->success = !empty($result->data['id']);
        }
        return $result;
    }

    public function addUnsorted($calls):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/leads/unsorted/sip";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return ClientHTTP::sendHttpPostRequest($url, $calls, $headers);
    }

    /**
     * Пакетное добавление сделок.
     * @param $calls
     * @return PBXApiResult
     */
    public function addLeads($calls):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/leads";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return ClientHTTP::sendHttpPostRequest($url, $calls, $headers);
    }

    /**
     * Пакетное добавление сделок.
     * @param $calls
     * @return PBXApiResult
     */
    public function addTasks($calls):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/tasks";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return ClientHTTP::sendHttpPostRequest($url, $calls, $headers);
    }

    public function getNotes($entity_type, $entity_id):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/$entity_type/$entity_id/notes";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return ClientHTTP::sendHttpGetRequest($url, [], $headers);
    }

    public function getSources():PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/sources";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return ClientHTTP::sendHttpGetRequest($url, [], $headers);
    }

    public function patchNote($entity_type, $entity_id, $id, $newRecFile):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/$entity_type/$entity_id/notes/$id";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        $params = [
            'note_type' => 'call_in',
            'params' => [
                'link'=>$newRecFile,
                'uniq'=>"test-uid",
                'duration'=>60,
                'source'=>"miko-pbx",
                'phone' => "74992243333",
                'call_status' => 4,
                'call_result' => 'ANSWERED'
            ],
        ];
        return ClientHTTP::sendHttpPatchRequest($url, $params, $headers);
    }

    public function notifyUsers(string $phone, array $users):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v2/events/";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        $notify = [
            'add' => [
                [
                'type' => "phone_call",
                'phone_number' => $phone,
                'users' => $users
                ],
            ]
        ];
        return ClientHTTP::sendHttpPostRequest($url, $notify, $headers);
    }

    public function addSources($data):PBXApiResult
    {
        $sources = [];
        foreach ($data as $row){
            $name       = $row['name']??'';
            $externalID = $row['external_id']??'';
            if(empty($name) || empty($externalID)){
                continue;
            }
            $source = [
                'name' => $name,
                'external_id' => $externalID
            ];
            if(isset($row['pipeline_id'])){
                $source['pipeline_id'] =  $row['pipeline_id'];
            }
            if(isset($row['default'])){
                $source['default'] =  $row['default'];
            }
            $sources[] = $source;
        }
        $url = "https://$this->baseDomain/api/v4/sources";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return ClientHTTP::sendHttpPostRequest($url, $sources, $headers);
    }

    /**
     * Получает изменненные контакты / компании.
     * @param int    $fromTime
     * @param int    $toTime
     * @param string $type
     * @param string $page
     * @return PBXApiResult
     */
    public function getChangedContacts(int $fromTime, int $toTime, string $type, string $page = ''):PBXApiResult
    {
        $types = [
            self::ENTITY_COMPANIES => 'company',
            self::ENTITY_CONTACTS  => 'contact',
        ];
        $url = "https://$this->baseDomain/api/v4/$type";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        $params = [
            'order[updated_at]' => 'desc',
            'filter[updated_at][from]' => $fromTime,
            'filter[updated_at][to]' => $toTime,
        ];

        if(!empty($page)){
            $params['page'] = $page;
        }
        $response = ClientHTTP::sendHttpGetRequest($url, $params, $headers);
        $result   = new PBXApiResult();
        $nextUrl = parse_url($response->data['_links']['next']['href']??'');
        parse_str($nextUrl['query']??'', $queryArray);

        $result->data = [
            $type => [],
            'nextPage' => $queryArray['page']??'',
        ];
        foreach ($response->data['_embedded'][$type]??[] as $contact){
            $contact['type'] = $types[$type];
            $result->data[$type][]  = $contact;
        }
        return $result;
    }

    /**
     * Получает измененные сделки
     * @param int    $fromTime
     * @param int    $toTime
     * @param string $page
     * @return PBXApiResult
     */
    public function getChangedLeads(int $fromTime, int $toTime, string $page = ''):PBXApiResult{
        $url = "https://$this->baseDomain/api/v4/leads";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        $params = [
            'with' => 'contacts',
            'order[updated_at]' => 'desc',
            'filter[updated_at][from]' => $fromTime,
            'filter[updated_at][to]' => $toTime,
        ];
        if(!empty($page)){
            $params['page'] = $page;
        }
        $response = ClientHTTP::sendHttpGetRequest($url, $params, $headers);
        $result   = new PBXApiResult();
        $nextUrl = parse_url($response->data['_links']['next']['href']??'');
        parse_str($nextUrl['query']??'', $queryArray);

        $result->data = [
            'leads' => [],
            'nextPage' => $queryArray['page']??'',
        ];
        $result->data['leads'] = $response->data['_embedded']['leads']??[];
        return $result;
    }

    /**
     * Создание контактов в amoCRM
     * @param array $data
     * @return PBXApiResult
     */
    public function createContacts(array $data):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/contacts";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        $params = [];
        foreach ($data as $contactData){
            $phone = $contactData['phone']??'';
            if(empty($phone)){
                continue;
            }
            $params[$contactData['phone']] = [
                'name' => $contactData['contactName']??'-- '.$contactData['phone'].' --',
                'custom_fields_values' => [
                    [
                        'field_code' => 'PHONE',
                        'values' => [['value' => $contactData['phone']]]
                    ]
                ]
            ];
            if(isset($contactData['request_id'])){
                $params[$contactData['phone']]['request_id'] = $contactData['request_id'];
            }
            if(isset($contactData['responsible_user_id'])) {
                $params[$contactData['phone']]['responsible_user_id'] = $contactData['responsible_user_id'];
            }
        }
        if(empty($params)){
            $result = new PBXAmoResult();
            $result->success = true;
        }else{
            $params = array_values($params);
            $result = ClientHTTP::sendHttpPostRequest($url, $params, $headers);
        }
        return $result;
    }

    /**
     * Обновление таблицы пользователей AMO.
     */
    public static function updateUsers():array
    {
        $users = [];
        $amoUsers = ConnectorDb::invoke('getPortalUsers', [1]);
        foreach ($amoUsers as $user){
            if(!is_numeric($user['amoUserId'])){
                continue;
            }
            $users[$user['number']] = 1*$user['amoUserId'];
        }
        $extensionLength = 1*PbxSettings::getValueByKey('PBXInternalExtensionLength');
        $userList   = [];
        $innerNums = [];
        /** @var Extensions $ext */
        $extensions = Extensions::find(['order' => 'type DESC']);
        foreach ($extensions as $ext){
            if($ext->type === Extensions::TYPE_SIP){
                $userList[$ext->userid] = $ext->number;
            }elseif($ext->type === Extensions::TYPE_EXTERNAL && isset($userList[$ext->userid])){
                $innerNum = $userList[$ext->userid];
                if(isset($users[$innerNum])){
                    $amoUserId = $users[$innerNum];
                    $users[self::getPhoneIndex($ext->number)] = 1*$amoUserId;
                }
            }
            $innerNums[] = self::getPhoneIndex($ext->number);
        }
        unset($userList);
        return [$extensionLength, $users, $innerNums];
    }

    /**
     * Возвращает усеценный слева номер телефона.
     *
     * @param $number
     *
     * @return bool|string
     */
    public static function getPhoneIndex($number)
    {
        if(!is_numeric(str_replace('+', '', $number))){
            return $number;
        }
        return substr($number, -10);
    }

}