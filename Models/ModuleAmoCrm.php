<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 2 2019
 */

/*
 * https://docs.phalcon.io/4.0/en/db-models
 *
 */

namespace Modules\ModuleAmoCrm\Models;

use MikoPBX\Common\Models\Providers;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

class ModuleAmoCrm extends ModulesModelsBase
{

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=true)
     */
    public $clientId;

    /**
     * @Column(type="string", nullable=true)
     */
    public $clientSecret;

    /**
     * @Column(type="string", nullable=true)
     */
    public $authData;

    /**
     * @Column(type="string", nullable=true)
     */
    public $baseDomain;

    /**
     * @Column(type="string", nullable=true)
     */
    public $referenceDate;

    /**
     * Toggle
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $offsetCdr = 1;

    /**
     * Returns dynamic relations between module models and common models
     * MikoPBX check it in ModelsBase after every call to keep data consistent
     *
     * There is example to describe the relation between Providers and ModuleAmoCrm models
     *
     * It is important to duplicate the relation alias on message field after Models\ word
     *
     * @param $calledModelObject
     *
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
//        if (is_a($calledModelObject, Providers::class)) {
//            $calledModelObject->belongsTo(
//                'id',
//                ModuleAmoCrm::class,
//                'dropdown_field',
//                [
//                    'alias'      => 'ModuleAmoCrmProvider',
//                    'foreignKey' => [
//                        'allowNulls' => 0,
//                        'message'    => 'Models\ModuleAmoCrmProvider',
//                        'action'     => Relation::ACTION_RESTRICT
//                        // запретить удалять провайдера если есть ссылки в модуле
//                    ],
//                ]
//            );
//        }
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleAmoCrm');
        parent::initialize();
    }

}