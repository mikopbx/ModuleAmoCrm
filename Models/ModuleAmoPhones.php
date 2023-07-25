<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 2 2019
 */

namespace Modules\ModuleAmoCrm\Models;
use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Class ModuleAmoUsers
 * @package Modules\ModuleAmoCrm\Models
 * @Indexes(
 *     [name='idEntity', columns=['idEntity'], type=''],
 *     [name='linked_company_id', columns=['linked_company_id'], type=''],
 *     [name='entityType', columns=['entityType'], type=''],
 *     [name='idPhone', columns=['idPhone'], type='']
 * )
 */
class ModuleAmoPhones extends ModulesModelsBase
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
    public $idEntity;

    /**
     * @Column(type="string", nullable=true)
     */
    public $idPhone;

    /**
     * @Column(type="string", nullable=true)
     */
    public $phone;

    /**
     * @Column(type="string", nullable=true)
     */
    public $responsible_user_id;

    /**
     * @Column(type="string", nullable=true)
     */
    public $linked_company_id;

    /**
     * @Column(type="string", nullable=true)
     */
    public $company_name;

    /**
     * @Column(type="string", nullable=true)
     */
    public $name;

    /**
     * @Column(type="string", nullable=true)
     */
    public $entityType;

    /**
     * @param $calledModelObject
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleAmoPhones');
        parent::initialize();
    }
}