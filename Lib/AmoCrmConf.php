<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 12 2019
 */


namespace Modules\ModuleAmoCrm\Lib;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Configs\CronConf;
use MikoPBX\Core\System\PBX;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Modules\PbxExtensionUtils;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
use Modules\ModuleAmoCrm\bin\ConnectorDb;
use Modules\ModuleAmoCrm\bin\SyncDaemon;
use Modules\ModuleAmoCrm\bin\WorkerAmoCrmAMI;
use Modules\ModuleAmoCrm\bin\WorkerAmoHTTP;
use Modules\ModuleAmoCrm\Lib\RestAPI\Controllers\ApiController;
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
                'same => n,Gosub(set-dial-contacts,${EXTEN},1)'.PHP_EOL."\t".
                'same => n,Set(DST_USER_AGENT=${TOUPPER(${PJSIP_CONTACT(${PJSIP_AOR(${EXTEN},contact)},user_agent)})})'.PHP_EOL."\t".
                'same => n,ExecIf($["${INTECEPTION_CNANNEL}x" != "x" && "${STRREPLACE(DST_USER_AGENT,TELEPHONE)}" != "${DST_USER_AGENT}"]?Set(_PT1C_SIP_HEADER=Call-Info:\;answer-after=0))'.PHP_EOL."\t".
                'same => n,ExecIf($["${INTECEPTION_CNANNEL}x" != "x" && "${STRREPLACE(DST_USER_AGENT,MICROSIP)}" != "${DST_USER_AGENT}"]?Set(_PT1C_SIP_HEADER=Call-Info:\;answer-after=0))'.PHP_EOL."\t".
                'same => n,GosubIf($["${INTECEPTION_CNANNEL}x" != "x"]?amo-set-periodic-hook,s,1)'.PHP_EOL."\t".
                'same => n,ExecIf($["${FIELDQTY(CONTACTS,&)}" != "0" && "${ALLOW_MULTY_ANSWER}" != "1"]?Set(_PT1C_SIP_HEADER=${EMPTY_VAR}))'.PHP_EOL."\t".
                'same => n,Dial(${DST_CONTACT},30,b(originate-create-channel,${EXTEN},1)G(amo-orig-leg-2^${CALLERID(num)}^1))'.PHP_EOL.
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
            $syncKeys = ['offsetCdr', 'lastContactsSyncTime', 'lastCompaniesSyncTime', 'lastLeadsSyncTime'];
            if ($changedFields === 1 && in_array($data['changedFields'][0],$syncKeys, true)) {
                return;
            }
            if(in_array('tokenForAmo', $data['changedFields'], true)) {
                $this->makeAuthFiles();
                if($changedFields === 1){
                    return;
                }
            }
            $this->startAllServices(true);
        }
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
        $workersToRestart = $this->getModuleWorkers();
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
                'type'   => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => ConnectorDb::class,
            ],
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker' => WorkerAmoHTTP::class,
            ],
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => SyncDaemon::class,
            ],
        ];
    }

    /**
     * REST API модуля.
     * @return array[]
     */
    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [ApiController::class, 'amoEntityUpdateAction', '/pbxcore/api/amo-crm/v1/entity-update', 'post', '/', true],
            [ApiController::class, 'callAction',     '/pbxcore/api/amo-crm/v1/callback', 'post', '/', true],
            [ApiController::class, 'listenerAction', '/pbxcore/api/amo-crm/v1/listener', 'post', '/', true],
            [ApiController::class, 'listenerAction', '/pbxcore/api/amo-crm/v1/listener', 'get', '/', true],
            [ApiController::class, 'panelIsEnable', '/pbxcore/api/amo-crm/v1/panel-enable', 'get', '/', true],
            [ApiController::class, 'commandAction', '/pbxcore/api/amo-crm/v1/command', 'post', '/', true],
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
            case 'ENTITY-UPDATE':
                $data = [
                    'action' => 'entity-update',
                    'data' => $request['data']??[]
                ];
                $beanstalk = new BeanstalkClient(ConnectorDb::class);
                $beanstalk->publish(json_encode($data));
                break;
            case 'LISTENER':
                // Для Oauth2 авторизации.
                $amo = new RestHandlers();
                $res = $amo->processRequest($request);
                break;
            case 'COMMAND':
            case 'TRANSFER':
            case 'CALLBACK':
                $amo = new RestHandlers();
                $res          = $amo->processCallback($request);
                $res->success = true;
                break;
            case 'CHANGE-SETTINGS':
                $amo          = new RestHandlers();
                $res          = $amo->saveSettings($request);
                $res->success = true;
                break;
            case 'RELOAD':
                $res->success = true;
                break;
            default:
                $res->success    = false;
                $res->messages[] = 'API action not found in moduleRestAPICallback ModuleAmoCrm';
        }

        return $res;
    }

    /**
     * Создает файл для проверки авторизации.
     * @return void
     */
    private function makeAuthFiles():void
    {
        // Wait save settings
        $allSettings = ConnectorDb::invoke('getModuleSettings', [true]);
        if(!$allSettings || !isset($allSettings['ModuleAmoCrm'])){
            return;
        }
        $settings    = (object)$allSettings['ModuleAmoCrm'];
        if(!$settings){
            return;
        }
        $baseDir = '/var/etc/auth';
        if(!file_exists($baseDir)){
            Util::mwMkdir($baseDir, true);
        }
        $authFile  = $baseDir.'/'.basename(trim($settings->tokenForAmo));
        if(!file_exists($authFile)){
            $grepPath  = Util::which('grep');
            $cutPath   = Util::which('cut');
            $xargs     = Util::which('xargs');
            $tokenHash = md5('tokenForAmo');
            Processes::mwExec("$grepPath -Rn '$tokenHash' /var/etc/auth | $cutPath -d ':' -f 1 | $xargs rm -rf ");
            file_put_contents($authFile, $tokenHash);
        }
    }

    /**
     * Добавляет UserEvent InterceptionAMO во входящие маршруты для перехвата на ответственного.
     * @param $rout_number
     * @return string
     */
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
        return "location /pbxcore/api/amo-crm/playback {".PHP_EOL.
            "    root /storage/usbdisk1/mikopbx/astspool/monitor;".PHP_EOL.
            '    set_by_lua $token_exists \''.PHP_EOL.
            '        return "ok";'.PHP_EOL.
            "    ';".PHP_EOL.
            "    set_by_lua \$result_url '".PHP_EOL.
            '        local url = "/pbxcore/api/amo-crm/v2/playback"..ngx.var.arg_view;'.PHP_EOL.
            '        return string.gsub(url,ngx.var.document_root,"");'.PHP_EOL.
            "    ';".PHP_EOL.
            '    try_files "${result_url}" "${result_url}";'.PHP_EOL.
            '}'.PHP_EOL.PHP_EOL.
            "location /pbxcore/api/amo-crm/v2/media {".PHP_EOL.
            "    root /storage/usbdisk1/mikopbx/astspool/monitor;".PHP_EOL.
            '    set_by_lua $token_exists \''.PHP_EOL.
            '        local file = "/var/etc/auth/"..tostring(ngx.var.arg_token);'.PHP_EOL.
            '        local f = io.open(file, "rb")'.PHP_EOL.
            '        local result = "fail";'.PHP_EOL.
            '        if f then'.PHP_EOL.
            '            f:close()'.PHP_EOL.
            '            result = "ok"'.PHP_EOL.
            '        end'.PHP_EOL.
            '        return result;'.PHP_EOL.
            "    ';".PHP_EOL.
            '    if ( $token_exists != \'ok\' ) {'.PHP_EOL.
            '        rewrite ^ /pbxcore/api/nchan/auth last;'.PHP_EOL.
            '    }'.PHP_EOL.
            "    set_by_lua \$result_url '".PHP_EOL.
            '        local url = "/pbxcore/api/amo-crm/v2/playback"..ngx.var.arg_view;'.PHP_EOL.
            '        return string.gsub(url,ngx.var.document_root,"");'.PHP_EOL.
            "    ';".PHP_EOL.
            '    try_files "${result_url}" "${result_url}";'.PHP_EOL.
            '}'.PHP_EOL.PHP_EOL.
            'location /pbxcore/api/amo-crm/v2/playback {'.PHP_EOL.
            "    if ( \$token_exists != 'ok' ) {".PHP_EOL.
            '        rewrite ^ /pbxcore/api/nchan/auth last;'.PHP_EOL.
            '    }'.PHP_EOL.
            '    alias /storage/usbdisk1/mikopbx/astspool/monitor;'.PHP_EOL.
            '    add_header X-debug-message "test" always;'.PHP_EOL.
            '}'.PHP_EOL.PHP_EOL.
            'location ~ /pbxcore/api/amo/pub/(.*)$ {'.PHP_EOL."\t".
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
                'add_header Expires "0";'.PHP_EOL."\t".
                'add_header X-TEST-DATE $time_iso8601;'.PHP_EOL."\t".
                "add_header 'Access-Control-Allow-Origin' '*' always;".PHP_EOL."\t".
                'add_header Cache-Control "no-store, no-cache, must-revalidate, max-age=0";'.PHP_EOL."\t".
                'add_header Pragma "no-cache";'.PHP_EOL.
            "}".
            PHP_EOL.
            PHP_EOL.
            "location /webrtc {".PHP_EOL."\t".
                'proxy_pass http://127.0.0.1:'.PbxSettings::getValueByKey('AJAMPort').'/asterisk/ws;'.PHP_EOL."\t".
                'proxy_http_version 1.1;'.PHP_EOL."\t".
                'proxy_set_header Upgrade $http_upgrade;'.PHP_EOL."\t".
                'proxy_set_header Connection "upgrade";'.PHP_EOL."\t".
                'proxy_read_timeout 86400;'.PHP_EOL.
            '}'.PHP_EOL;
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
     */
    public function onAfterModuleEnable(): void
    {
        $cron = new CronConf();
        $cron->reStart();
        PBX::dialplanReload();
    }

    /**
     * @param array $tasks
     */
    public function createCronTasks(array &$tasks): void
    {
        $tmpDir = $this->di->getShared('config')->path('core.tempDir') . '/ModuleAmoCrm';
        $findPath   = Util::which('find');
        $phpPath    = Util::which('php');
        $tasks[]    = "*/1 * * * * $findPath $tmpDir -mmin +1 -type f -delete> /dev/null 2>&1".PHP_EOL;
        $tasks[]    = "0 1 * * * $phpPath $this->moduleDir/bin/start-init-sync.php > /dev/null 2>&1".PHP_EOL;
    }
}