<?php

namespace Modules\ModuleAmoCrm\Lib;

use GuzzleHttp;
use GuzzleHttp\Exception\GuzzleException;
use MikoPBX\Common\Models\LanInterfaces;
use MikoPBX\Common\Providers\CDRDatabaseProvider;
use MikoPBX\Core\Asterisk\AsteriskManager;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\PbxExtensionBase;
use MikoPBX\Modules\PbxExtensionUtils;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Modules\ModuleAmoCrm\Models\ModuleAmoUsers;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\PbxSettings;
use Phabel\Exception;
use Throwable;

class AmoCrmMain extends PbxExtensionBase
{
    private string $clientId = '';
    private string $clientSecret = '';
    private string $baseDomain = '';
    private string $extHostname = '';
    private AuthToken $token;

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
            $this->clientId     = $settings->clientId;
            $this->clientSecret = $settings->clientSecret;
            $res = LanInterfaces::findFirst("internet = '1'")->toArray();
            $this->extHostname  = $res['exthostname']??'';

            $this->token = new AuthToken($settings->authData);
            $this->refreshToken();
        }
    }

    public function getExtHostname():string
    {
        return $this->extHostname;
    }

    /**
     * Получение токена из code.
     * @param $code
     * @return PBXAmoResult
     */
    public function getAccessTokenByCode($code):PBXAmoResult
    {
        $params  = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => "https://miko.ru",
        ];
        $url = "https://$this->baseDomain/oauth2/access_token";
        $result = $this->sendHttpPostRequest($url, $params);
        if($result->success){
            $result->success = $this->token->saveToken($result->data);
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
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'redirect_uri'  => "https://$this->extHostname/pbxcore/api/amo-crm/v1/listener",
        ];
        $result = $this->sendHttpPostRequest($url, $params);
        if($result->success){
            $this->token->saveToken($result->data);
        }
    }

    /**
     * Обработка запроса от AmoCRM, к примеру call.
     * @param array $request
     * @return PBXAmoResult
     */
    public function processCallback(array $request): PBXAmoResult
    {
        $res = new PBXAmoResult();
        $params = $request['data'];
        if(!is_array($params)){
            return $res;
        }
        $action = "{$params['action']}Action";
        if(method_exists($this, $action)){
            return $this->$action($params);
        }
        return $res;
    }

    /**
     * Сохранение настроек, измененных в AMO CRM.
     * @param array $request
     * @return PBXAmoResult
     */
    public function saveSettings(array $request):PBXAmoResult{
        $res = new PBXAmoResult();
        $res->success = true;
        $users = $request['data']['users']??[];
        if(is_array($users)){
            foreach ($users as $amoUserId => $number){
                $dbData = ModuleAmoUsers::findFirst("amoUserId='$amoUserId'");
                if(!$dbData){
                    $dbData = new ModuleAmoUsers();
                    $dbData->amoUserId = $amoUserId;
                }
                $dbData->number = trim($number);
                $dbData->save();
            }
        }
        return $res;
    }

    public function invokeCommand(array $request):PBXAmoResult
    {
        $res = new PBXAmoResult();
        $action = $request['data']['action']??'';
        if($action === 'hangup'){
            $cdrData = CDRDatabaseProvider::getCacheCdr();
            try {
                $am = Util::getAstManager('off');
            }catch (\Exception $e){
                return $res;
            }
            foreach ($cdrData as $cdr){
                if($cdr['UNIQUEID'] !== $request['data']['call-id']){
                    continue;
                }
                if($request['data']['user-phone'] === $cdr['src_num']){
                    $channel = $cdr['src_chan'];
                }else{
                    $channel = $cdr['dst_chan'];
                }
                $am->Hangup($channel);
                $res->success = true;
            }
        }
        return $res;
    }

    /**
     * Обработка второго этапа авторизации, ответ от 3ей стороны.
     * @param array $request
     * @return PBXAmoResult
     */
    public function processRequest(array $request): PBXAmoResult
    {
        $result = new PBXAmoResult();
        $params = $request['data']??[];
        /** @var ModuleAmoCrm $settings */
        if(isset($params['error'])) {
            $settings = ModuleAmoCrm::findFirst();
            $settings->authData = '';
            $settings->save();
        }elseif (isset($params['code']) && !empty($params['code'])){
            $result = $this->getAccessTokenByCode($params['code']);
        }
        $result->processor = __METHOD__;
        if(!isset($params['save-only']) && isset($params['code'])){
            $result->setHtml($this->moduleDir.'/public/assets/html/auth-ok.html');
        }
        return $result;
    }

    /**
     * Отправка POST запроса к API.
     * @param string $url
     * @param array $params
     * @param array $headers
     * @return PBXAmoResult
     */
    public function sendHttpPostRequest(string $url, array $params, array $headers=[]):PBXAmoResult{
        $client  = new GuzzleHttp\Client();
        $options = [
            'timeout'       => 5,
            'http_errors'   => false,
            'headers'       => $headers,
            'json'          => $params,
        ];
        $message = '';
        $resultHttp = null;
        try {
            $resultHttp = $client->request('POST', $url, $options);
            $code       = $resultHttp->getStatusCode();
        }catch (GuzzleHttp\Exception\ConnectException $e ){
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "ConnectException");
            $code = 0;
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "GuzzleException");
            $code = 0;
        }
        return $this->parseResponse($resultHttp, $message, $code);
    }

    /**
     * Отправка POST запроса к API.
     * @param string $url
     * @param array $params
     * @param array $headers
     * @return PBXAmoResult
     */
    public function sendHttpPatchRequest(string $url, array $params, array $headers=[]):PBXAmoResult{
        $client  = new GuzzleHttp\Client();
        $options = [
            'timeout'       => 5,
            'http_errors'   => false,
            'headers'       => $headers,
            'json'          => $params,
        ];
        $message = '';
        $resultHttp = null;
        try {
            $resultHttp = $client->request('PATCH', $url, $options);
            $code       = $resultHttp->getStatusCode();
        }catch (GuzzleHttp\Exception\ConnectException $e ){
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "ConnectException");
            $code = 0;
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "GuzzleException");
            $code = 0;
        }
        return $this->parseResponse($resultHttp, $message, $code);
    }

    /**
     * Преобразование http ответа в массив.
     * @param $resultHttp
     * @param $message
     * @param $code
     * @return PBXAmoResult
     */
    private function parseResponse($resultHttp, $message, $code):PBXAmoResult
    {
        $res = new PBXAmoResult();
        if( isset($resultHttp) && ($code === 200 || $resultHttp->getReasonPhrase() === 'Accepted')){
            $content = $resultHttp->getBody()->getContents();
            $data    = [];
            try {
                $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            }catch (Throwable $e){
                $message = $e->getMessage();
            }
            $res->success = is_array($data);
            if($res->success){
                $res->data = $data;
            }else{
                $res->messages[] = $content;
                $res->messages[] = $message;
            }
        }else{
            $res->success = false;
            $res->messages['error-code'] = $code;
            $res->messages['error-msg']  = $message;
            if($resultHttp){
                try {
                    $res->messages['error-string']  = $resultHttp->getBody()->getContents();
                    $res->messages['error-data']    = json_decode($res->messages['error-string'], true);
                }catch (Throwable $e){
                    $res->messages['error-data']    = [];
                }
            }
        }
        return $res;
    }

    /**
     * Отправка http GET запроса.
     * @param string $url
     * @param array  $params
     * @param array  $headers
     * @return PBXAmoResult
     */
    private function sendHttpGetRequest(string $url, array $params, array $headers=[]):PBXAmoResult{
        if(!empty($params)){
            $url .= "?".http_build_query($params);
        }
        $client  = new GuzzleHttp\Client();
        $options = [
            'timeout'       => 5,
            'http_errors'   => false,
            'headers'       => $headers,
        ];
        $message = '';
        $resultHttp = null;
        try {
            $resultHttp = $client->request('GET', $url, $options);
            $code       = $resultHttp->getStatusCode();
        }catch (GuzzleHttp\Exception\ConnectException $e ){
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "ConnectException");
            $code = 0;
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "GuzzleException");
            $code = 0;
        }
        return $this->parseResponse($resultHttp, $message, $code);
    }

    /**
     * @param $params
     * @return PBXAmoResult
     * @throws \Exception
     */
    public function callbackAction($params):PBXAmoResult
    {
        $res = new PBXAmoResult();
        $dst = preg_replace("/[^0-9+]/", '', $params['number']);
        Util::amiOriginate($params['user-number'], '', $dst);
        $this->logger->writeInfo(
            "ONEXTERNALCALLSTART: originate from user {$params['user-id']} <{$params['user-number']}> to {$dst})"
        );
        $res->success = true;
        return $res;
    }

    public function transferAction($params):PBXAmoResult
    {
        $res     = new PBXAmoResult();
        $action  = 'Redirect';
        $cdrData = CDRDatabaseProvider::getCacheCdr();
        foreach ($cdrData as $cdr){
            if(!in_array($params['user-number'], [$cdr['src_num'], $cdr['dst_num']], true)){
                continue;
            }
            if( !empty($cdr['endtime']) ){
                continue;
            }
            if(!empty($cdr['answer'])){
                $action = 'Atxfer';
                if($cdr['src_num'] === $params['user-number']){
                    $chanForRedirect = $cdr['src_chan'];
                }else{
                    $chanForRedirect = $cdr['dst_chan'];
                }
            }elseif($cdr['src_num'] === $params['user-number']){
                $chanForRedirect = $cdr['dst_chan'];
            }else{
                $chanForRedirect = $cdr['src_chan'];
            }
        }

        if(empty($chanForRedirect)){
            return $res;
        }
        $am = Util::getAstManager('off');
        $command = [
            'Channel'   => $chanForRedirect,
            'Exten'     => $params['number'],
            'Context'   => 'internal-transfer',
            'Priority'  => '1'
        ];
        $am->sendRequestTimeout($action, $command);
        $res->success = true;
        return $res;
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
        return $this->sendHttpGetRequest($url, [], $headers);
    }

    public function addCalls($calls):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/calls";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return $this->sendHttpPostRequest($url, $calls, $headers);
    }

    public function addUnsorted($calls):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/leads/unsorted/sip";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return $this->sendHttpPostRequest($url, $calls, $headers);
    }

    public function getNotes($entity_type, $entity_id):PBXApiResult
    {
        $url = "https://$this->baseDomain/api/v4/$entity_type/$entity_id/notes";
        $headers = [
            'Authorization' => $this->token->getTokenType().' '.$this->token->getAccessToken(),
        ];
        return $this->sendHttpGetRequest($url, [], $headers);
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
        return $this->sendHttpPatchRequest($url, $params, $headers);
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
        return $this->sendHttpPostRequest($url, $notify, $headers);

    }

    /**
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
        $phones = [];
        foreach ($data as $contactData){
            if(in_array($contactData['phone'], $phones, true)){
                continue;
            }
            $phones[] = $contactData['phone'];
            $params[] = [
                'name' => $contactData['phone'],
                'custom_fields_values' => [
                    [
                        'field_code' => 'PHONE',
                        'values' => [['value' => $contactData['phone']]]
                    ]
                ]
            ];
        }
        unset($phones);
        return $this->sendHttpPostRequest($url, $params, $headers);
    }

    /**
     * Process something received over AsteriskAMI
     *
     * @param array $parameters
     */
    public function processAmiMessage(array $parameters): void
    {
        $message = implode(' ', $parameters);
        $this->logger->writeInfo($message);
    }

    /**
     * Process something received over Beanstalk queue
     *
     * @param array $parameters
     */
    public function processBeanstalkMessage(array $parameters): void
    {
        $message = implode(' ', $parameters);
        $this->logger->writeInfo($message);
    }

    /**
     * Start or restart module workers
     *
     * @param bool $restart
     */
    public function startAllServices(bool $restart = false): void
    {
        $moduleEnabled = PbxExtensionUtils::isEnabled($this->moduleUniqueId);
        if ( ! $moduleEnabled) {
            return;
        }
        $configClass      = new AmoCrmConf();
        $workersToRestart = $configClass->getModuleWorkers();

        if ($restart) {
            foreach ($workersToRestart as $moduleWorker) {
                Processes::processPHPWorker($moduleWorker['worker']);
            }
        } else {
            $safeScript = new WorkerSafeScriptsCore();
            foreach ($workersToRestart as $moduleWorker) {
                if ($moduleWorker['type'] === WorkerSafeScriptsCore::CHECK_BY_AMI) {
                    $safeScript->checkWorkerAMI($moduleWorker['worker']);
                } else {
                    $safeScript->checkWorkerBeanstalk($moduleWorker['worker']);
                }
            }
        }
    }

    /**
     * Обновление таблицы пользователей AMO.
     */
    public static function updateUsers():array
    {
        $users = [];
        try {
            /** @var ModuleAmoUsers $user */
            $amoUsers = ModuleAmoUsers::find('enable=1');
            foreach ($amoUsers as $user){
                if(!is_numeric($user->amoUserId)){
                    continue;
                }
                $users[$user->number] = 1*$user->amoUserId;
            }
        }catch (Throwable $e){
            Util::sysLogMsg(__CLASS__, $e->getMessage());
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
        return substr($number, -10);
    }

}