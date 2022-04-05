<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 4 2020
 *
 */

namespace Modules\ModuleAmoCrm\Lib\RestAPI\Controllers;

use MikoPBX\PBXCoreREST\Controllers\Modules\ModulesControllerBase;

class ApiController extends ModulesControllerBase
{
    /**
     * curl -X POST -d '{"action": "call", "number": "74952293042", "user-id": "1", "user-number": "203"}' http://127.0.0.1/pbxcore/api/amo-crm/v1/callback
     * curl -X POST -d '{"action": "call", "number": "74952293042", "user-id": "1", "user-number": "203"}' http://172.16.156.223/pbxcore/api/amo-crm/v1/callback
     */
    public function callAction():void
    {
        $this->evalFunction('callback');
    }

    public function listenerAction():void
    {
        $this->evalFunction('listener');
    }

    public function commandAction():void
    {
        $this->evalFunction('command');
    }

    public function changeSettingsAction():void
    {
        $this->evalFunction('change-settings');
    }

    private function evalFunction($name):void
    {
        $this->callActionForModule('ModuleAmoCrm', $name);
        if(!$this->response->isSent()){
            $this->response->sendRaw();
        }
    }
}