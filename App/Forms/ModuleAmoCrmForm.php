<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 9 2018
 *
 */
namespace Modules\ModuleAmoCrm\App\Forms;

use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Password;
use Phalcon\Forms\Element\Hidden;


class ModuleAmoCrmForm extends Form
{

    public function initialize($entity = null, $options = null) :void
    {
        $this->add(new Hidden('id', ['value' => $entity->id]));
        $this->add(new Hidden('referenceDate', ['value' => $entity->id]));
        $this->add(new Text('baseDomain'));
        $this->add(new Text('clientId'));
        $this->add(new Password('clientSecret'));
        $this->add(new Text('tokenForAmo'));
    }
}