<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2021 Alexey Portnov and Nikolay Beketov
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


use MikoPBX\Core\System\Util;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Throwable;

class AuthToken
{
    private string $type;
    private string $accessToken;
    private string $refreshToken;
    private int    $expired = 0;

    public function __construct(string $authData)
    {
        $this->updateToken($authData);
    }

    private function updateToken($authData, bool $isNew = false):void
    {
        if(!is_array($authData) && !empty($authData)){
            try {
                $authData = json_decode($authData, true, 512, JSON_THROW_ON_ERROR);
            }catch (\Throwable $e){
                Util::sysLogMsg(self::class, $e->getMessage());
            }
        }

        $this->refreshToken = $authData['refresh_token']??'';
        $this->accessToken  = $authData['access_token']??'';
        $this->type         = $authData['token_type']??'';

        if(!isset($authData['expires_in']) || !is_numeric($authData['expires_in'])){
            return;
        }
        if($isNew){
            $this->expired = time() + 1*$authData['expires_in']??0;
        }else{
            $this->expired = 1*$authData['expires_in']??0;
        }
    }

    /**
     * Истек ли скрок годности токена.
     * @return bool
     */
    public function isExpired():bool
    {
        return (($this->expired - time()) < 3600);
    }

    public function getRefreshToken():string
    {
        return $this->refreshToken;
    }

    public function getTokenType():string
    {
        return $this->type;
    }

    public function getAccessToken():string
    {
        return $this->accessToken;
    }

    public function saveToken($authData, $portalId = 0): bool
    {
        $this->updateToken($authData, true);
        $settings = ModuleAmoCrm::findFirst();
        $authData = [
            'token_type'    => $this->type,
            'expires_in'    => $this->expired,
            'access_token'  => $this->accessToken,
            'refresh_token' => $this->refreshToken,
        ];
        try {
            $settings->authData = json_encode($authData, JSON_THROW_ON_ERROR);
        }catch (Throwable $e){
            $settings->authData = '';
        }
        if($portalId > 0){
            $settings->portalId = $portalId;
        }
        return $settings->save();
    }

    /**
     * Обновление значения ID портала.
     * @param $portalId
     * @return bool
     */
    public function savePortalId($portalId): bool
    {
        $settings = ModuleAmoCrm::findFirst();
        $settings->portalId = $portalId;
        return $settings->save();
    }
}