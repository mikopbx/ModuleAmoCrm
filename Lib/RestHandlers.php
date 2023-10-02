<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleAmoCrm\Lib;

use MikoPBX\Common\Providers\CDRDatabaseProvider;
use MikoPBX\Core\System\Util;
use Modules\ModuleAmoCrm\bin\WorkerAmoHTTP;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Modules\ModuleAmoCrm\Models\ModuleAmoUsers;

class RestHandlers extends AmoCrmMainBase
{

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
                $dbData = ModuleAmoUsers::findFirst("amoUserId='$amoUserId' AND portalId='{$request['data']['portalId']}'");
                if(!$dbData){
                    $dbData = new ModuleAmoUsers();
                    $dbData->amoUserId = $amoUserId;
                    $dbData->portalId  = $request['data']['portalId'];
                }
                $dbData->number = trim($number);
                $dbData->save();
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
            $result = WorkerAmoHTTP::invokeAmoApi('getAccessTokenByCode', [$params['code']]);
            WorkerAmoHTTP::invokeAmoApi('checkConnection', [true]);
        }
        $result->processor = __METHOD__;
        if(!isset($params['save-only']) && isset($params['code'])){
            $result->setHtml($this->moduleDir.'/public/assets/html/auth-ok.html');
        }
        return $result;
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
     * Обработка команды завершения вызова.
     * @param array $request
     * @return PBXAmoResult
     */
    public function hangupAction(array $request):PBXAmoResult
    {
        $res = new PBXAmoResult();
        $cdrData = CDRDatabaseProvider::getCacheCdr();
        try {
            $am = Util::getAstManager('off');
        }catch (\Exception $e){
            return $res;
        }
        foreach ($cdrData as $cdr){
            if($cdr['UNIQUEID'] !== $request['call-id']){
                continue;
            }
            if($request['user-phone'] === $cdr['src_num']){
                $channel = $cdr['src_chan'];
            }else{
                $channel = $cdr['dst_chan'];
            }
            $am->Hangup($channel);
            $res->success = true;
        }

        return $res;
    }

    /**
     * Запуск Originate.
     * @param $params
     * @return PBXAmoResult
     * @throws \Exception
     */
    public function callbackAction($params):PBXAmoResult
    {
        $res = new PBXAmoResult();
        $dst = preg_replace("/[^0-9+]/", '', $params['number']);
        AmoCrmMain::amiOriginate($params['user-number'], '', $dst);
        $this->logger->writeInfo(
            "ONEXTERNALCALLSTART: originate from user {$params['user-id']} <{$params['user-number']}> to {$dst})"
        );
        $res->success = true;
        return $res;
    }

    /**
     * Переадресация вызова.
     * @param $params
     * @return PBXAmoResult
     * @throws \Phalcon\Exception
     */
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

}