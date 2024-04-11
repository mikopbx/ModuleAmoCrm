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
use MikoPBX\PBXCoreREST\Controllers\Modules\ModulesControllerBase;
use Modules\ModuleAmoCrm\bin\ConnectorDb;
use Modules\ModuleAmoCrm\bin\WorkerAmoCrmAMI;
use Modules\ModuleAmoCrm\Lib\ClientHTTP;

class ApiController extends ModulesControllerBase
{
    /**
     *  curl -X POST -d '{"action": "call", "number": "74952293042", "user-id": "1", "user-number": "203"}' http://127.0.0.1/pbxcore/api/amo-crm/v1/callback
     *  curl -X POST -d '{"action": "call", "number": "74952293042", "user-id": "1", "user-number": "203"}' http://172.16.156.223/pbxcore/api/amo-crm/v1/callback
        curl 'https://127.0.0.1/pbxcore/api/amo-crm/v1/callback' \
        --data-raw 'action=callback&number=79043332233&user-number=201+&user-id=480711' \
        --compressed

        curl 'http://127.0.0.1/pbxcore/api/amo-crm/v1/command' \
        --data-raw 'call-id=mikopbx-1649170490.6_b0691C&user-id=480711&user-phone=201&action=hangup' \
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
     * curl 'https://127.0.0.1/pbxcore/api/amo-crm/v1/find-contact' -H 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8' --data-raw 'phone=79257183047&action=findContact&token=uFJ6v6DfwBfjNVaTL1zKn6KUbSL2xsBWsm3PZ7yG6kRQMWgqu'
     * @return void
     */
    public function findContactAction():void
    {
        if($this->checkAuth() === false){
            return;
        }
        $data   =  $this->request->getPost();
        $result = ConnectorDb::invoke('findContacts', [[$data['phone']]]);
        $this->echoResponse($result);
        $this->response->sendRaw();
    }

    public function commandAction():void
    {
        $this->evalFunction('command');
    }

    public function panelIsEnable():void
    {
        if($this->checkAuth() === false){
            return;
        }
        $allSettings   = ConnectorDb::invoke('getModuleSettings', [true]);
        $panelIsEnable = $allSettings['ModuleAmoCrm']['panelIsEnable']??'0';
        if($panelIsEnable === '1'){
            $code = 200;
        }else{
            $code = 201;
        }
        $this->response->setStatusCode($code, 'OK')->sendHeaders();
        $this->response->sendRaw();
    }

    public function changeSettingsAction():void
    {
        $this->evalFunction('change-settings');
    }

    public function amoEntityUpdateAction():void
    {
        ConnectorDb::invoke('entityUpdate', [$_REQUEST]);
    }

    /**
     * Проверка авторизации по токену.
     * @return bool
     */
    private function checkAuth():bool
    {
        if(!file_exists("/var/etc/auth/".$_REQUEST['token'])){
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
}