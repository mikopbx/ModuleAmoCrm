<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 12 2019
 */


namespace Modules\ModuleAmoCrm\Lib;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
use Modules\ModuleAmoCrm\bin\WorkerAmoCrmAMI;
use Modules\ModuleAmoCrm\bin\WorkerAmoCrmMain;
use Modules\ModuleAmoCrm\Lib\RestAPI\Controllers\ApiController;
use MikoPBX\PBXCoreREST\Controllers\Cdr\GetController as CdrGetController;

class AmoCrmConf extends ConfigClass
{

    /**
     * Receive information about mikopbx main database changes
     *
     * @param $data
     */
    public function modelsEventChangeData($data): void
    {
        if (
            $data['model'] === PbxSettings::class
            && $data['recordId'] === 'PBXLanguage'
        ) {
            $templateMain = new AmoCrmMain();
            $templateMain->startAllServices(true);
        }
    }

    /**
     * Returns module workers to start it at WorkerSafeScriptCore
     *
     * @return array
     */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker' => WorkerAmoCrmMain::class,
            ],
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_AMI,
                'worker' => WorkerAmoCrmAMI::class,
            ],
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => AmoCdrDaemon::class,
            ],
        ];
    }

    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [ApiController::class, 'callAction',     '/pbxcore/api/amo-crm/v1/callback', 'post', '/', true],
            [ApiController::class, 'listenerAction', '/pbxcore/api/amo-crm/v1/listener', 'post', '/', true],
            [ApiController::class, 'listenerAction', '/pbxcore/api/amo-crm/v1/listener', 'get', '/', true],
            [ApiController::class, 'commandAction', '/pbxcore/api/amo-crm/v1/command', 'post', '/', true],
            [CdrGetController::class, 'playbackAction',  '/pbxcore/api/amo-crm/playback', 'get', '/', true],
            [ApiController::class, 'changeSettingsAction', '/pbxcore/api/amo-crm/v1/change-settings', 'post', '/', true],
        ];
    }

    /**
    curl 'https://127.0.0.1/pbxcore/api/amo-crm/v1/callback' \
    --data-raw 'action=callback&number=79043332233&user-number=201+&user-id=480711' \
    --compressed

    curl 'http://127.0.0.1/pbxcore/api/amo-crm/v1/command' \
    --data-raw 'call-id=mikopbx-1649170490.6_b0691C&user-id=480711&user-phone=201&action=hangup' \
    --compressed

    curl 'http://127.0.0.1/pbxcore/api/amo-crm/v1/change-settings' \
    --data-raw 'users%5B480711%5D=201+&users%5B2794642%5D=202&users%5B7689754%5D=203&action=saveSettings'
    **/
    /**
     *  Process CoreAPI requests under root rights
     *
     * @param array $request
     *
     * @return PBXApiResult
     */
    public function moduleRestAPICallback(array $request): PBXApiResult
    {
        $res    = new PBXApiResult();
        $res->processor = __METHOD__;
        $action = strtoupper($request['action']);
        switch ($action) {
            case 'CHECK':
                $amo = new AmoCrmMain();
                $res          = $amo->checkModuleWorkProperly();
                break;
            case 'LISTENER':
                $amo = new AmoCrmMain();
                $res          = $amo->processRequest($request);
                $res->success = true;
                break;
            case 'COMMAND':
                $amo = new AmoCrmMain();
                $res = $amo->invokeCommand($request);
                break;
            case 'CALLBACK':
                $amo = new AmoCrmMain();
                $res          = $amo->processCallback($request);
                $res->success = true;
                break;
            case 'CHANGE-SETTINGS':
                $amo          = new AmoCrmMain();
                $res          = $amo->saveSettings($request);
                $res->success = true;
                break;
            case 'RELOAD':
                $templateMain = new AmoCrmMain();
                $templateMain->startAllServices(true);
                $res->success = true;
                break;
            default:
                $res->success    = false;
                $res->messages[] = 'API action not found in moduleRestAPICallback ModuleAmoCrm';
        }

        return $res;
    }

    /**
     * Create additional Nginx locations from modules
     *
     */
    public function createNginxLocations(): string
    {
        return 'location ~ /pbxcore/api/amo/pub/(.*)$ {'.PHP_EOL."\t".
                    'nchan_publisher;'.PHP_EOL."\t".
                    'allow  127.0.0.1;'.PHP_EOL."\t".
                    'nchan_channel_id "$1";'.PHP_EOL."\t".
                    'nchan_message_buffer_length 1;'.PHP_EOL."\t".
                    'nchan_message_timeout 300m;'.PHP_EOL.
                '}'.
                PHP_EOL.
                PHP_EOL.
                "location ^~ /webrtc-phone/ {".PHP_EOL."\t".
                    "root {$this->moduleDir}/App/locations/;".PHP_EOL."\t".
                    "index index.html;".PHP_EOL."\t".
                    "access_log off;".PHP_EOL."\t".
                    "expires 3d;".PHP_EOL.
                "}".PHP_EOL;
    }
}