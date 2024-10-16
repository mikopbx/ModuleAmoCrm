<?php

/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2024 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleAmoCrm\App\Forms;

use MikoPBX\Core\System\Util;
use Modules\ModuleAmoCrm\Lib\AmoCrmMainBase;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Phalcon\Forms\Element\Select;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Hidden;

class ModuleAmoCrmForm extends ModuleBaseForm
{
    public function initialize($entity = null, $options = null): void
    {
        $this->add(new Hidden('id', ['value' => $entity->id]));
        $this->add(new Hidden('referenceDate', ['value' => $entity->id]));
        $this->add(new Hidden('clientId', ['value' => AmoCrmMainBase::CLIENT_ID]));
        $this->add(new Hidden('redirectUri', ['value' => AmoCrmMainBase::REDIRECT_URL]));
        $this->add(new Text('baseDomain'));
        $this->add(new Text('tokenForAmo'));
        $this->addCheckBox('useInterception', intval($entity->useInterception) === 1);
        $this->addCheckBox('panelIsEnable', intval($entity->panelIsEnable) === 1);
        $this->addCheckBox('isPrivateWidget', intval($entity->isPrivateWidget) === 1);
        $this->addCheckBox('disableDetailedCdr', intval($entity->disableDetailedCdr) === 1);
        $this->add(new Text('privateClientId'));
        $this->add(new Text('privateClientSecret'));
        $variants = [
            ModuleAmoCrm::RESP_TYPE_FIRST      => Util::translate('mod_amo_type_responsible_answered_first', false),
            ModuleAmoCrm::RESP_TYPE_LAST       => Util::translate('mod_amo_type_responsible_answered_last', false),
            ModuleAmoCrm::RESP_TYPE_CONTACT    => Util::translate('mod_amo_type_responsible_contact', false),
            ModuleAmoCrm::RESP_TYPE_RULE       => Util::translate('mod_amo_type_responsible_rule', false),
        ];
        $type = new Select(
            'respCallAnsweredHaveClient',
            $variants,
            [
                             'useEmpty' => false,
                             'class' => 'ui selection dropdown search',
                         ]
        );
        $this->add($type);

        $variants = [
            ModuleAmoCrm::RESP_TYPE_FIRST      => Util::translate('mod_amo_type_responsible_answered_first', false),
            ModuleAmoCrm::RESP_TYPE_LAST       => Util::translate('mod_amo_type_responsible_answered_last', false),
            ModuleAmoCrm::RESP_TYPE_RULE       => Util::translate('mod_amo_type_responsible_rule', false),
        ];
        $type = new Select(
            'respCallAnsweredNoClient',
            $variants,
            [
                                            'useEmpty' => false,
                                            'class' => 'ui selection dropdown search',
                                        ]
        );
        $this->add($type);

        $variants = [
            ModuleAmoCrm::RESP_TYPE_FIRST      => Util::translate('mod_amo_type_responsible_missed_first', false),
            ModuleAmoCrm::RESP_TYPE_LAST       => Util::translate('mod_amo_type_responsible_missed_last', false),
            ModuleAmoCrm::RESP_TYPE_RULE       => Util::translate('mod_amo_type_responsible_rule', false),
        ];
        $type = new Select(
            'respCallMissedNoClient',
            $variants,
            [
                                            'useEmpty' => false,
                                            'class' => 'ui selection dropdown search',
                                        ]
        );
        $this->add($type);

        $variants = [
            ModuleAmoCrm::RESP_TYPE_FIRST      => Util::translate('mod_amo_type_responsible_missed_first', false),
            ModuleAmoCrm::RESP_TYPE_LAST       => Util::translate('mod_amo_type_responsible_missed_last', false),
            ModuleAmoCrm::RESP_TYPE_CONTACT    => Util::translate('mod_amo_type_responsible_contact', false),
            ModuleAmoCrm::RESP_TYPE_RULE       => Util::translate('mod_amo_type_responsible_rule', false),
        ];
        $type = new Select(
            'respCallMissedHaveClient',
            $variants,
            [
                                            'useEmpty' => false,
                                            'class' => 'ui selection dropdown search',
                                        ]
        );
        $this->add($type);
    }

}
