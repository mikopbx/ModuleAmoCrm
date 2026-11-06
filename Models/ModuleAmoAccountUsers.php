<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleAmoCrm\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Пользователи аккаунта amoCRM и их сопоставление с сотрудниками MikoPBX.
 * Источник сопоставления: по нему пересобирается рабочая таблица m_ModuleAmoUsers
 * и настройка виджета pbx_users.
 *
 * @Indexes(
 *     [name='amoUserId', columns=['amoUserId'], type=''],
 *     [name='portalId', columns=['portalId'], type='']
 * )
 */
class ModuleAmoAccountUsers extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * ID аккаунта amoCRM.
     * @Column(type="integer", default="0", nullable=true)
     */
    public $portalId = 0;

    /**
     * ID пользователя amoCRM.
     * @Column(type="string", nullable=true)
     */
    public $amoUserId;

    /**
     * @Column(type="string", nullable=true)
     */
    public $name = '';

    /**
     * @Column(type="string", nullable=true)
     */
    public $email = '';

    /**
     * Пользователь активен в amoCRM.
     * @Column(type="integer", default="1", nullable=true)
     */
    public $isActive = 1;

    /**
     * Внутренний номер сотрудника MikoPBX (пусто — не сопоставлен).
     * @Column(type="string", nullable=true)
     */
    public $number = '';

    /**
     * Пользователь получен из списка пользователей amoCRM (0 — известен только из настроек виджета).
     * @Column(type="integer", default="0", nullable=true)
     */
    public $inAccount = 0;

    /**
     * @param $calledModelObject
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleAmoAccountUsers');
        parent::initialize();
    }
}
