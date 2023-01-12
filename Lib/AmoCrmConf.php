<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 12 2019
 */


namespace Modules\ModuleAmoCrm\Lib;

use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\PBX;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
use Modules\ModuleAmoCrm\bin\WorkerAmoContacts;
use Modules\ModuleAmoCrm\bin\WorkerAmoCrmAMI;
use Modules\ModuleAmoCrm\Lib\RestAPI\Controllers\ApiController;
use MikoPBX\PBXCoreREST\Controllers\Cdr\GetController as CdrGetController;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;

class AmoCrmConf extends ConfigClass
{
    /**
     * Prepares additional contexts sections in the extensions.conf file
     *
     * @return string
     */
    public function extensionGenContexts(): string
    {
        return  '[amo-orig-check-state]'.PHP_EOL.
                'exten => s,1,Set(INTECEPTION_CNANNEL=${IMPORT(${HOOK_CHANNEL},INTECEPTION_CNANNEL)})'.PHP_EOL."\t".
                'same => n,ExecIf($[ "${CHANNEL_EXISTS(${INTECEPTION_CNANNEL})}" == "0" ]?ChannelRedirect(${HOOK_CHANNEL},amo-orig-leg-1,h,1))'.PHP_EOL."\t".
                'same => n,ExecIf($[ "${IMPORT(${INTECEPTION_CNANNEL},M_DIALSTATUS)}" == "ANSWER" ]?ChannelRedirect(${HOOK_CHANNEL},amo-orig-leg-1,h,1))'.PHP_EOL.
                PHP_EOL.
                '[amo-orig-leg-1]'.PHP_EOL.
                'exten => failed,1,Hangup()'.PHP_EOL."\t".
                'exten => _[0-9*#+a-zA-Z][0-9*#+a-zA-Z]!,1,Answer()'.PHP_EOL."\t".
                'same => n,Set(_CALLER=${EXTEN})'.PHP_EOL."\t".
                'same => n,ExecIf($["${origCidName}x" != "x"]?Set(CALLERID(name)=${origCidName}))'.PHP_EOL."\t".
                'same => n,Set(CONTACTS=${PJSIP_DIAL_CONTACTS(${EXTEN})})'.PHP_EOL."\t".
                'same => n,Set(_PT1C_SIP_HEADER=${SIPADDHEADER})'.PHP_EOL."\t".
                'same => n,ExecIf($["${FIELDQTY(CONTACTS,&)}" != "1" && "${ALLOW_MULTY_ANSWER}" != "1"]?Set(__PT1C_SIP_HEADER=${EMPTY_VAR}))'.PHP_EOL."\t".
                'same => n,GosubIf($["${INTECEPTION_CNANNEL}x" != "x"]?amo-set-periodic-hook,s,1)'.PHP_EOL."\t".
                'same => n,Dial(${CONTACTS},30,b(originate-create-channel,${EXTEN},1)G(amo-orig-leg-2^${CALLERID(num)}^1))'.PHP_EOL.
                'exten => h,1,NoOp(=== SPAWN EXTENSION ===)'.PHP_EOL.
                PHP_EOL.
                '[amo-set-periodic-hook]'.PHP_EOL.
                'exten => s,1,Set(BEEPID=${PERIODIC_HOOK(amo-orig-check-state,s,1)})'.PHP_EOL."\t".
                'same => n,return'.PHP_EOL.
                PHP_EOL.
                '[amo-orig-leg-2]'.PHP_EOL.
                'exten => _[0-9*#+a-zA-Z]!,1,goto(caller)'.PHP_EOL.
                'exten => _[0-9*#+a-zA-Z]!,1001(caller),Answer()'.PHP_EOL."\t".
                'same => n,Hangup()'.PHP_EOL.
                'exten => _[0-9*#+a-zA-Z]!,2,goto(callee)'.PHP_EOL.
                'exten => _[0-9*#+a-zA-Z]!,2002(callee),Goto(${DST_CONTEXT},${EXTEN},1)'.PHP_EOL.
                'exten => h,1,NoOp(=== SPAWN EXTENSION ===)'.PHP_EOL;
    }

    /**
     * Receive information about mikopbx main database changes
     *
     * @param $data
     */
    public function modelsEventChangeData($data): void
    {
        if ($data['model'] === ModuleAmoCrm::class) {
            $changedFields = count($data['changedFields']);
            if ($changedFields === 1 && $data['changedFields'][0] === 'offsetCdr') {
                return;
            }
            if(in_array('tokenForAmo', $data['changedFields'], true)) {
                $this->makeAuthFiles();
                if($changedFields === 1){
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
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker' => WorkerAmoContacts::class,
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
            [ApiController::class, 'findContactAction', '/pbxcore/api/amo-crm/v1/find-contact', 'post', '/', true],
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
            case 'FIND-CONTACT':
                $findContactsParams = [
                    'action'  => 'findContacts',
                    'numbers' => [
                        $request['data']['phone'],
                    ]
                ];
                $beanstalk = new BeanstalkClient(WorkerAmoContacts::class);
                $beanstalk->publish(json_encode($findContactsParams));
                break;
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

    public function generateIncomingRoutBeforeDial($rout_number): string
    {
        return "\t" . 'same => n,UserEvent(InterceptionAMO,CALLERID: ${CALLERID(num)},chan1c: ${CHANNEL},FROM_DID: ${FROM_DID})' . "\n\t";
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
                    "root {$this->moduleDir}/sites/;".PHP_EOL."\t".
                    "index index.html;".PHP_EOL."\t".
                    "access_log off;".PHP_EOL."\t".
                    "expires 3d;".PHP_EOL.
                "}".PHP_EOL;
    }

    /**
     * Process after disable action in web interface
     *
     * @return void
     */
    public function onAfterModuleDisable(): void
    {
        PBX::dialplanReload();
    }

    /**
     * Process after enable action in web interface
     *
     * @return void
     * @throws \Exception
     */
    public function onAfterModuleEnable(): void
    {
        PBX::dialplanReload();
    }
}