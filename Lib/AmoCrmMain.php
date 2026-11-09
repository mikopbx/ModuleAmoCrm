<?php

namespace Modules\ModuleAmoCrm\Lib;

use MikoPBX\Core\System\Util;
use MikoPBX\Modules\PbxExtensionUtils;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\bin\ConnectorDb;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\PbxSettings;

/**
 * Объект класса существует только как свойство объекта WorkerAmoHTTP.
 */
class AmoCrmMain extends AmoCrmMainBase
{
    private string $baseDomain = '';
    private AuthToken $token;
    private bool $initDone = false;

    public bool   $isPrivateWidget;
    public string $privateClientId;
    public string $privateClientSecret;

    public const ENTITY_CONTACTS = 'contacts';
    public const ENTITY_COMPANIES = 'companies';
    public const ENTITY_LEADS = 'leads';

    public const EMPTY_HOST_VALUE = 'EMPTY_HOST_VALUE';

    /**
     * Инициализации API клиента.
     * AmoCrmMain constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $allSettings = ConnectorDb::invoke('getModuleSettings', [true]);
        if(!empty($allSettings) && is_array($allSettings)){
            $settings = (object)$allSettings['ModuleAmoCrm'];
        }else{
            exit(3);
        }

        if($settings){
            $this->baseDomain           = ''.$settings->baseDomain;
            $this->token               = new AuthToken((string)$settings->authData);
            $this->isPrivateWidget     = (string)$settings->isPrivateWidget === '1';
            $this->privateClientId     = ''.$settings->privateClientId;
            $this->privateClientSecret = ''.$settings->privateClientSecret;
            $this->refreshToken();
            $this->initDone = true;
        }
        if(empty($this->baseDomain)){
            exit(4);
        }
    }

    /**
     * Инициализирован ли объект?
     * @return bool
     */
    public function isInitDone():bool
    {
        return $this->initDone;
    }

    /**
     * Return client ID.
     * @return string
     */
    private function getClientId():string
    {
        if($this->isPrivateWidget) {
            return $this->privateClientId;
        }
        return self::CLIENT_ID;
    }

    /**
     * Return client secret
     * @return string
     */
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
            $result->success = $this->token->saveToken($result->data);
        }
        return $result;
    }

    /**
     * Обновление токена.
     * @return void
     */
    public function refreshToken():void
    {
        if(!$this->initDone){
            return;
        }
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
    public function checkConnection(bool $updatePortalId = false): PBXAmoResult
    {
        if(!PbxExtensionUtils::isEnabled($this->moduleUniqueId)){
            return new PBXAmoResult();
        }
        $authorization = $this->token->getTokenType().' '.$this->token->getAccessToken();
        if(empty(trim($authorization))){
            return new PBXAmoResult();
        }
        $url = "https://$this->baseDomain/api/v4/account";
        $headers = [
            'Authorization' => $authorization,
        ];

        $connectionData = ClientHTTP::sendHttpGetRequest($url, [], $headers);
        $id = (int)($connectionData->data['id']??0);
        if($updatePortalId && $id > 0){
            $this->token->savePortalId($id);
        }
        return $connectionData;
    }

    public function addCalls($calls, $entity_type):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/$entity_type/notes";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return ClientHTTP::sendHttpPostRequest($url, $calls, $headers);
    }

    /**
     * Синхронизация воронок.
     * @param $portalId
     * @return array
     */
    public function syncPipeLines($portalId):array
    {
        $url = "https://$this->baseDomain/api/v4/leads/pipelines";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        $response = ClientHTTP::sendHttpGetRequest($url, [], $headers);
        if($response->success){
            $data = $response->data['_embedded']['pipelines']??[];
            $pipeLines = ConnectorDb::invoke('updatePipelines', [$data]);
        }else{
            $pipeLines = ConnectorDb::invoke('getPipeLines', []);
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

    /**
     * Получает изменненные контакты / компании.
     * @param string $type
     * @param string $pageLink
     * @return PBXApiResult
     */
    public function getChangedEntity(string $pageLink, string $type):PBXApiResult
    {
        $types = [
            self::ENTITY_COMPANIES  => 'company',
            self::ENTITY_CONTACTS   => 'contact',
            self::ENTITY_LEADS      => self::ENTITY_LEADS,
        ];
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        $url = str_replace(self::EMPTY_HOST_VALUE, $this->baseDomain ,$pageLink);
        $params = [];
        $response = ClientHTTP::sendHttpGetRequest($url, $params, $headers);
        $page     = ChangedEntityPage::parse(
            (bool)$response->success,
            $response->data,
            (array)$response->messages,
            $type,
            $types[$type] ?? $type
        );
        $result          = new PBXApiResult();
        $result->success = $page['success'];
        if ($page['success']) {
            $result->data = [
                $type      => $page['items'],
                'nextPage' => $page['nextPage'],
            ];
        } else {
            // Ошибка HTTP (401, 5xx, таймаут) — не пустая страница: SyncDaemon повторит запрос,
            // а в init не завершит проход и не удалит кэш.
            $result->messages = $page['messages'];
        }
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
     * Заголовки авторизации для API amoCRM.
     * @return array
     */
    private function authHeaders():array
    {
        return [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
    }

    /**
     * UUID OAuth-интеграции, с которой связан виджет (client_uuid в API виджетов).
     * @return string
     */
    public function getIntegrationUuid():string
    {
        return $this->getClientId();
    }

    /**
     * Страница каталога виджетов (code и client_uuid) — для поиска кода приватного виджета.
     * @param int $page
     * @return PBXAmoResult data: ['widgets' => [['code','client_uuid','is_active_in_account'], ...], 'pageCount' => int]
     */
    public function getWidgetsPage(int $page):PBXAmoResult
    {
        $url      = "https://$this->baseDomain/api/v4/widgets";
        $response = ClientHTTP::sendHttpGetRequest($url, ['limit' => 250, 'page' => max(1, $page)], $this->authHeaders());
        $result   = new PBXAmoResult();
        $result->success  = $response->success;
        $result->messages = $response->messages;
        if (!$response->success) {
            return $result;
        }
        $widgets = [];
        foreach ($response->data['_embedded']['widgets'] ?? [] as $widget) {
            $widgets[] = [
                'code'                 => (string)($widget['code'] ?? ''),
                'client_uuid'          => (string)($widget['client_uuid'] ?? ''),
                'is_active_in_account' => !empty($widget['is_active_in_account']),
            ];
        }
        $result->data = [
            'widgets'   => $widgets,
            'pageCount' => (int)($response->data['_page_count'] ?? 0),
            'hasNext'   => isset($response->data['_links']['next']),
        ];
        return $result;
    }

    /**
     * Настройки виджета MikoPBX в аккаунте.
     * Значения настроек amoCRM возвращает только ключу связанной с виджетом интеграции.
     * @param string $widgetCode Код виджета; пусто — код публичного виджета.
     * @return PBXAmoResult data: ['code', 'client_uuid', 'is_active_in_account', 'settings' => [...], 'pbx_users' => [amoUserId => номер]]
     */
    public function getWidgetSettings(string $widgetCode = ''):PBXAmoResult
    {
        if ($widgetCode === '') {
            $widgetCode = IntegrationConfig::PUBLIC_WIDGET_CODE;
        }
        $url      = "https://$this->baseDomain/api/v4/widgets/".rawurlencode($widgetCode);
        $response = ClientHTTP::sendHttpGetRequest($url, [], $this->authHeaders());
        $result   = new PBXAmoResult();
        $result->success  = $response->success;
        $result->messages = $response->messages;
        if (!$response->success) {
            return $result;
        }
        $settings = $response->data['settings'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }
        $result->data = [
            'code'                 => (string)($response->data['code'] ?? $widgetCode),
            'client_uuid'          => (string)($response->data['client_uuid'] ?? ''),
            'is_active_in_account' => !empty($response->data['is_active_in_account']),
            'has_settings'         => isset($response->data['settings']),
            'settings'             => $settings,
            'pbx_users'            => IntegrationConfig::normalizeUsersMap($settings['pbx_users'] ?? []),
        ];
        return $result;
    }

    /**
     * Запись настроек виджета (POST /api/v4/widgets/{code}). Требуются права администратора аккаунта.
     * @param string $widgetCode
     * @param array  $settings Поля шаблона настроек: token, miko_pbx_host, pbx_users.
     * @return PBXAmoResult
     */
    public function saveWidgetSettings(string $widgetCode, array $settings):PBXAmoResult
    {
        $url  = "https://$this->baseDomain/api/v4/widgets/".rawurlencode($widgetCode);
        $body = $settings;
        if (isset($body['pbx_users']) && is_array($body['pbx_users']) && empty($body['pbx_users'])) {
            // Пустой массив сериализуется в [], amoCRM ждёт объект.
            $body['pbx_users'] = new \stdClass();
        }
        $response = ClientHTTP::sendHttpPostRequest($url, $body, $this->authHeaders());
        if ($response->success) {
            // В ответе — токен и адрес АТС, вызывающей стороне они не нужны.
            $response->data = ['code' => (string)($response->data['code'] ?? $widgetCode)];
        }
        return $response;
    }

    /**
     * Все пользователи аккаунта amoCRM.
     * @return PBXAmoResult data: ['users' => [['id','name','email','is_active'], ...]]
     */
    public function getAccountUsers():PBXAmoResult
    {
        $url      = "https://$this->baseDomain/api/v4/users";
        $users    = [];
        $result   = new PBXAmoResult();
        $complete = false;
        for ($page = 1; $page <= 20; $page++) {
            $response = ClientHTTP::sendHttpGetRequest($url, ['limit' => 250, 'page' => $page], $this->authHeaders());
            if (!$response->success) {
                $result->success  = false;
                $result->messages = $response->messages;
                return $result;
            }
            foreach ($response->data['_embedded']['users'] ?? [] as $user) {
                if (!isset($user['id'])) {
                    continue;
                }
                $users[] = [
                    'id'        => (string)$user['id'],
                    'name'      => (string)($user['name'] ?? ''),
                    'email'     => (string)($user['email'] ?? ''),
                    'is_active' => !isset($user['rights']['is_active']) || !empty($user['rights']['is_active']),
                ];
            }
            if (!isset($response->data['_links']['next'])) {
                $complete = true;
                break;
            }
        }
        if (!$complete) {
            // Неполный список нельзя считать актуальным: по нему удаляются пользователи и их сопоставления.
            $result->success  = false;
            $result->messages = ['error-code' => 0, 'error-msg' => 'Too many amoCRM users (more than 20 pages), list is incomplete'];
            return $result;
        }
        $result->success = true;
        $result->data    = ['users' => $users];
        return $result;
    }

    /**
     * Webhook аккаунта.
     * @return PBXAmoResult data: ['webhooks' => [...]]
     */
    public function getWebhooks():PBXAmoResult
    {
        $url      = "https://$this->baseDomain/api/v4/webhooks";
        $response = ClientHTTP::sendHttpGetRequest($url, [], $this->authHeaders());
        if ($response->success) {
            $response->data = ['webhooks' => $response->data['_embedded']['webhooks'] ?? []];
        }
        return $response;
    }

    /**
     * Создание webhook. Для существующего destination amoCRM заменяет список событий.
     * @param string   $destination
     * @param string[] $events
     * @return PBXAmoResult
     */
    public function addWebhook(string $destination, array $events):PBXAmoResult
    {
        $url = "https://$this->baseDomain/api/v4/webhooks";
        return ClientHTTP::sendHttpPostRequest(
            $url,
            ['destination' => $destination, 'settings' => array_values($events)],
            $this->authHeaders()
        );
    }

    /**
     * Удаление webhook по destination.
     * @param string $destination
     * @return PBXAmoResult 404 — webhook уже удалён.
     */
    public function deleteWebhook(string $destination):PBXAmoResult
    {
        $url = "https://$this->baseDomain/api/v4/webhooks";
        return ClientHTTP::sendHttpDeleteRequest($url, ['destination' => $destination], $this->authHeaders());
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
        $number = preg_replace('/\D+/', '', $number);
        if(!is_numeric(str_replace('+', '', $number))){
            return $number;
        }
        return substr($number, -10);
    }

}