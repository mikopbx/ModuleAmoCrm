<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 4 2020
 *
 */

namespace Modules\ModuleAmoCrm\Lib\RestAPI\Controllers;

use MikoPBX\Common\Providers\LoggerProvider;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\PBXCoreREST\Controllers\Modules\ModulesControllerBase;
use Modules\ModuleAmoCrm\bin\ConnectorDb;

class ApiController extends ModulesControllerBase
{
    /**
     *  curl -X POST -d '{"action": "call", "number": "74952293042", "user-id": "1", "user-number": "203"}' http://127.0.0.1/pbxcore/api/amo-crm/v1/callback
     *  curl -X POST -d '{"action": "call", "number": "74952293042", "user-id": "1", "user-number": "203"}' http://172.16.156.223/pbxcore/api/amo-crm/v1/callback
        curl 'https://127.0.0.1/pbxcore/api/amo-crm/v1/callback' \
        --data-raw 'action=callback&number=79043332233&user-number=201+&user-id=480711' \
        --compressed

        curl 'http://127.0.0.1/pbxcore/api/amo-crm/v1/change-settings' \
        --data-raw 'users%5B480711%5D=201+&users%5B2794642%5D=202&users%5B7689754%5D=203&action=saveSettings'
    */
    public function callAction():void
    {
        $this->evalFunction('callback');
    }

    public function listenerAction():void
    {
        $this->evalFunction('listener');
    }

    /**
     * Проверка токена виджетом: 200 — токен принят, 403 — нет (ответ даёт checkAuth).
     * Имя эндпоинта (panel-enable) сохранено для совместимости со старыми версиями виджета.
     * @return void
     */
    public function panelIsEnable():void
    {
        if($this->checkAuth() === false){
            return;
        }
        $this->response->setStatusCode(200, 'OK')->sendHeaders();
        $this->response->sendRaw();
    }

    public function changeSettingsAction():void
    {
        $this->evalFunction('change-settings');
    }

    public function amoEntityUpdateAction():void
    {
        $tmpFileName = '/tmp/amo-server-ip';
        $ip = $this->request->getClientAddress(true);
        $oldIp = '';
        if(file_exists($tmpFileName)){
            $oldIp = file_get_contents($tmpFileName);
        }
        if($oldIp !== $ip){
            SystemMessages::sysLogMsg('amoCrm-hook', 'from: '. $ip);
            file_put_contents($tmpFileName, $ip);
        }
        $data = $this->request->getPost();
        ConnectorDb::invoke('entityUpdate', [$data], false);
    }

    /**
     * Проверка авторизации по токену.
     * @return bool
     */
    private function checkAuth():bool
    {
        // Запрос через webhook-порт с токен-URI — авторизация подтверждена nginx.
        // Проверяем заголовок + что реальный remote addr = localhost (nginx proxy).
        if ($this->request->getHeader('X-Amo-Webhook-Auth') === '1'
            && $this->request->getClientAddress(false) === '127.0.0.1') {
            return true;
        }
        $token = $this->request->getPost('token', 'string', '');
        if (empty($token)) {
            $token = $this->request->getQuery('token', 'string', '');
        }
        // Защита от path traversal: токен должен быть только алфавитно-цифровым
        if(empty($token) || preg_match('/[^a-zA-Z0-9]/', $token) || !file_exists("/var/etc/auth/".$token)){
            $remoteAddress = $this->request->getClientAddress(true);
            $userAgent     = $this->request->getUserAgent();
            $loggerAuth    = $this->di->getShared(LoggerProvider::SERVICE_NAME);
            $loggerAuth->warning("From: {$remoteAddress} UserAgent:{$userAgent} Cause: Wrong password");
            $loggerAuth->setLogLevel(LOG_AUTH);

            $this->response->setStatusCode(403, 'OK')->sendHeaders();
            $this->response->sendRaw();
            return false;
        }
        return true;
    }

    private function evalFunction($name):void
    {
        if($this->checkAuth() === false){
            return;
        }

        $this->callActionForModule('ModuleAmoCrm', $name);
        if(!$this->response->isSent()){
            $this->response->sendRaw();
        }
    }

    /**
     * Вывод ответа сервера.
     * @param $result
     * @return void
     */
    private function echoResponse($result):void
    {
        try {
            echo json_encode($result, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
        }catch (\Exception $e){
            echo 'Error json encode: '. print_r($result, true);
        }
    }

    /**
     * Возвращает IP адрес клиента.
     * @return string
     */
    private function getClientIp() {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) && $_SERVER[$key]) {
                // Некоторые заголовки могут содержать несколько IP (например, X-Forwarded-For)
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]); // Берём первый IP — это обычно клиент
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '127.0.0.1';
    }
}