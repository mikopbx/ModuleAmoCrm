<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 9 2018
 *
 */
namespace Modules\ModuleAmoCrm\App\Forms;

use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Hidden;


class ModuleAmoCrmForm extends Form
{
    public function initialize($entity = null, $options = null) :void
    {
        $this->add(new Hidden('id',             ['value' => $entity->id]));
        $this->add(new Hidden('referenceDate',  ['value' => $entity->id]));
        $this->add(new Hidden('clientId',       ['value' => AmoCrmMain::CLIENT_ID]));
        $this->add(new Hidden('redirectUri',    ['value' => AmoCrmMain::REDIRECT_URL]));
        $this->add(new Text('baseDomain'));
        $this->add(new Text('tokenForAmo'));

        // Export cdr
        $useInterception = ['value' => null];
        if ($entity->useInterception === '1') {
            $useInterception = ['checked' => 'checked', 'value' => null];
        }
        $this->add(new Check('useInterception', $useInterception));
    }
}