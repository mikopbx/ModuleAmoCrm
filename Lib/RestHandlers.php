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

use Modules\ModuleAmoCrm\bin\ConnectorDb;
use Modules\ModuleAmoCrm\bin\WorkerAmoHTTP;

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
        // Сохранение настроек виджета в amoCRM: сопоставление пользователей могло измениться.
        // Изменённый номер принимается, если он есть на АТС, иначе сопоставление обнуляется
        // (только локально, настройки виджета не перезаписываются).
        $users = $request['data']['users'] ?? [];
        if (is_string($users)) {
            $decoded = json_decode($users, true);
            $users   = is_array($decoded) ? $decoded : [];
        }
        if (is_array($users) && !empty($users)) {
            $res->data = ConnectorDb::invoke('saveUsersMapping', [$users, (int)($request['data']['portalId'] ?? 0)]);
        }
        // Список пользователей amoCRM мог измениться.
        IntegrationSync::startInBackground(IntegrationSync::MODE_USERS);
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
        if(isset($params['error'])) {
            // Отказ/ошибка авторизации (например, администратор отменил повторную авторизацию в amoCRM).
            // Рабочий токен не очищаем: иначе отмена повторной авторизации отключает интеграцию.
            $result->success = false;
            $result->messages[] = 'OAuth error: ' . substr((string)preg_replace('/[^\w.\- ]/u', '', is_scalar($params['error']) ? (string)$params['error'] : ''), 0, 100);
        }elseif (isset($params['code']) && !empty($params['code'])){
            $apiResult = WorkerAmoHTTP::invokeAmoApi('getAccessTokenByCode', [$params['code']]);
            if ($apiResult instanceof PBXAmoResult) {
                $result = $apiResult;
            } else {
                $result->success = false;
                $result->messages[] = 'Invalid response from WorkerAmoHTTP';
            }
            WorkerAmoHTTP::invokeAmoApi('checkConnection', [true]);
            if ($result->success) {
                // Новая авторизация: webhook, виджет, пользователи. Пауза — на перезапуск воркеров.
                IntegrationSync::startInBackground(IntegrationSync::MODE_ALL, 20);
            }
        }
        $result->processor = __METHOD__;
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
        $allowedActions = ['callback'];
        $actionName = $params['action']??'';
        if(!in_array($actionName, $allowedActions, true)){
            return $res;
        }
        $method = "{$actionName}Action";
        if(method_exists($this, $method)){
            return $this->$method($params);
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
}
