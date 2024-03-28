<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 9 2018
 *
 */
namespace Modules\ModuleAmoCrm\App\Forms;

use MikoPBX\Core\System\Util;
use Modules\ModuleAmoCrm\Lib\AmoCrmMainBase;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\Select;
use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Hidden;


class ModuleAmoCrmForm extends Form
{
    public function initialize($entity = null, $options = null) :void
    {
        $this->add(new Hidden('id',             ['value' => $entity->id]));
        $this->add(new Hidden('referenceDate',  ['value' => $entity->id]));
        $this->add(new Hidden('clientId',       ['value' => AmoCrmMainBase::CLIENT_ID]));
        $this->add(new Hidden('redirectUri',    ['value' => AmoCrmMainBase::REDIRECT_URL]));
        $this->add(new Text('baseDomain'));
        $this->add(new Text('tokenForAmo'));

        $useInterception = ['value' => null];
        if ($entity->useInterception === '1') {
            $useInterception = ['checked' => 'checked', 'value' => null];
        }
        $this->add(new Check('useInterception', $useInterception));

        $panelIsEnable = ['value' => null];
        if ($entity->panelIsEnable === '1') {
            $panelIsEnable = ['checked' => 'checked', 'value' => null];
        }
        $this->add(new Check('panelIsEnable', $panelIsEnable));

        $isPrivateWidget = ['value' => null];
        if ($entity->isPrivateWidget === '1') {
            $isPrivateWidget = ['checked' => 'checked', 'value' => null];
        }
        $this->add(new Check('isPrivateWidget', $isPrivateWidget));
        $this->add(new Text('privateClientId'));
        $this->add(new Text('privateClientSecret'));


        $values = ['value' => null];
        if ($entity->disableDetailedCdr === '1') {
            $values = ['checked' => 'checked', 'value' => null];
        }
        $this->add(new Check('disableDetailedCdr', $values));

        $variants = [
            ModuleAmoCrm::RESP_TYPE_FIRST      => Util::translate('mod_amo_type_responsible_answered_first', false),
            ModuleAmoCrm::RESP_TYPE_LAST       => Util::translate('mod_amo_type_responsible_answered_last',  false),
            ModuleAmoCrm::RESP_TYPE_CONTACT    => Util::translate('mod_amo_type_responsible_contact',  false),
            ModuleAmoCrm::RESP_TYPE_RULE       => Util::translate('mod_amo_type_responsible_rule',  false),
        ];
        $type = new Select(
            'respCallAnsweredHaveClient', $variants, [
                             'useEmpty' => false,
                             'class' => 'ui selection dropdown search',
                         ]
        );
        $this->add($type);

        $variants = [
            ModuleAmoCrm::RESP_TYPE_FIRST      => Util::translate('mod_amo_type_responsible_answered_first', false),
            ModuleAmoCrm::RESP_TYPE_LAST       => Util::translate('mod_amo_type_responsible_answered_last',  false),
            ModuleAmoCrm::RESP_TYPE_RULE       => Util::translate('mod_amo_type_responsible_rule',  false),
        ];
        $type = new Select(
            'respCallAnsweredNoClient', $variants, [
                                            'useEmpty' => false,
                                            'class' => 'ui selection dropdown search',
                                        ]
        );
        $this->add($type);

        $variants = [
            ModuleAmoCrm::RESP_TYPE_FIRST      => Util::translate('mod_amo_type_responsible_missed_first', false),
            ModuleAmoCrm::RESP_TYPE_LAST       => Util::translate('mod_amo_type_responsible_missed_last',  false),
            ModuleAmoCrm::RESP_TYPE_RULE       => Util::translate('mod_amo_type_responsible_rule',  false),
        ];
        $type = new Select(
            'respCallMissedNoClient', $variants, [
                                            'useEmpty' => false,
                                            'class' => 'ui selection dropdown search',
                                        ]
        );
        $this->add($type);

        $variants = [
            ModuleAmoCrm::RESP_TYPE_FIRST      => Util::translate('mod_amo_type_responsible_missed_first', false),
            ModuleAmoCrm::RESP_TYPE_LAST       => Util::translate('mod_amo_type_responsible_missed_last',  false),
            ModuleAmoCrm::RESP_TYPE_CONTACT    => Util::translate('mod_amo_type_responsible_contact',  false),
            ModuleAmoCrm::RESP_TYPE_RULE       => Util::translate('mod_amo_type_responsible_rule',  false),
        ];
        $type = new Select(
            'respCallMissedHaveClient', $variants, [
                                            'useEmpty' => false,
                                            'class' => 'ui selection dropdown search',
                                        ]
        );
        $this->add($type);
    }
}