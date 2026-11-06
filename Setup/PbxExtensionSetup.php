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

/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2019
 */

namespace Modules\ModuleAmoCrm\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModuleAmoCrm\Lib\CallTypeResolver;
use Modules\ModuleAmoCrm\Lib\EntitySettingsDefaults;
use Modules\ModuleAmoCrm\Models\ModuleAmoEntitySettings;
use Throwable;


/**
 * Class PbxExtensionSetup
 * Module installer and uninstaller
 *
 * @package Modules\ModuleAmoCrm\Setup
 */
class PbxExtensionSetup extends PbxExtensionSetupBase
{

    /**
     * Adds the module to the sidebar menu.
     *
     * @return bool The result of the addition process.
     */
    public function addToSidebar(): bool
    {
        $menuSettingsKey           = "AdditionalMenuItem{$this->moduleUniqueID}";
        $menuSettings              = PbxSettings::findFirstByKey($menuSettingsKey);
        if ($menuSettings === null) {
            $menuSettings      = new PbxSettings();
            $menuSettings->key = $menuSettingsKey;
        }
        $value               = [
            'uniqid'        => $this->moduleUniqueID,
            'group'         => 'integrations',
            'iconClass'     => 'handshake outline',
            'caption'       => "Breadcrumb{$this->moduleUniqueID}",
            'showAtSidebar' => true,
        ];
        $menuSettings->value = json_encode($value);

        return $menuSettings->save();
    }

    /**
     * Создаёт таблицы и добавляет правила для типов звонков, появившихся
     * в новых версиях модуля.
     *
     * @return bool
     */
    public function installDB(): bool
    {
        $result = parent::installDB();
        if ($result) {
            $this->addNewDefaultRules();
        }
        return $result;
    }

    /**
     * #64: на существующих установках правила по умолчанию не создаются
     * (ConnectorDb::fillEntitySettings заполняет только пустую таблицу).
     * Правило OUTGOING_UNKNOWN_FAIL ничего не создаёт и добавляется, чтобы тип
     * был виден в настройках. Выполняется только при установке/обновлении,
     * поэтому удалённое пользователем правило вернётся лишь при следующем обновлении.
     * Ошибка не прерывает установку.
     *
     * @return void
     */
    private function addNewDefaultRules(): void
    {
        try {
            $existing = ModuleAmoEntitySettings::find(['columns' => 'portalId,type,did'])->toArray();
            $rules    = EntitySettingsDefaults::missingRules(
                $existing,
                EntitySettingsDefaults::load(),
                [CallTypeResolver::OUTGOING_UNKNOWN_FAIL]
            );
            foreach ($rules as $rule) {
                $record = new ModuleAmoEntitySettings();
                foreach ($rule as $key => $value) {
                    $record->writeAttribute($key, $value);
                }
                if (!$record->save()) {
                    $this->messages[] = 'ModuleAmoCrm: failed to add rule '
                        . $rule['type'] . ': ' . implode(', ', $record->getMessages());
                }
            }
        } catch (Throwable $e) {
            $this->messages[] = 'ModuleAmoCrm: failed to add new default rules: ' . $e->getMessage();
        }
    }

}