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
            [CdrGetController::class, 'playbackAction',  '/pbxcore/api/amo-crm/playback', 'get', '/', true],
            [ApiController::class, 'changeSettingsAction', '/pbxcore/api/amo-crm/v1/change-settings', 'post', '/', true],
        ];
    }

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
}