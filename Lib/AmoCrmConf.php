<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 12 2019
 */


namespace Modules\ModuleAmoCrm\Lib;

use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
use Modules\ModuleAmoCrm\bin\WorkerAmoCrmAMI;
use Modules\ModuleAmoCrm\Lib\RestAPI\Controllers\ApiController;
use MikoPBX\PBXCoreREST\Controllers\Cdr\GetController as CdrGetController;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;

class AmoCrmConf extends ConfigClass
{

    /**
     * Receive information about mikopbx main database changes
     *
     * @param $data
     */
    public function modelsEventChangeData($data): void
    {
        if ($data['model'] === ModuleAmoCrm::class) {
            if (count($data['changedFields']) === 1 && $data['changedFields'][0] === 'offsetCdr') {
                return;
            }
            if(in_array('tokenForAmo', $data['changedFields'], true)) {
                $this->makeAuthFiles();
                if(count($data['changedFields']) === 1){
                    return;
                }
            }
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
            case 'LISTENER':
                // Для Oauth2 авторизации.
                $amo = new AmoCrmMain();
                $res = $amo->processRequest($request);
                break;
            case 'COMMAND':
            case 'TRANSFER':
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
     * @return void
     */
    private function makeAuthFiles():void
    {
        /** @var ModuleAmoCrm $settings */
        $settings = ModuleAmoCrm::findFirst();
        if(!$settings){
            return;
        }
        $baseDir = '/var/etc/auth';
        if(!file_exists($baseDir)){
            Util::mwMkdir($baseDir, true);
        }
        $authFile = $baseDir.'/'.basename($settings->tokenForAmo);
        if(!file_exists($authFile)){
            $grepPath = Util::which('grep');
            $cutPath = Util::which('cut');
            $xargs = Util::which('xargs');
            $tokenHash = md5('tokenForAmo');
            Processes::mwExec("$grepPath -Rn '$tokenHash' /var/etc/auth | $cutPath -d ':' -f 1 | $xargs rm -rf ");
            file_put_contents($baseDir."/".basename($settings->tokenForAmo), $tokenHash);
        }
    }

    /**
     * Create additional Nginx locations from modules
     *
     */
    public function createNginxLocations(): string
    {
        $this->makeAuthFiles();
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