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
 *     [name='idAmo', columns=['idAmo'], type=''],
 *     [name='contactId', columns=['contactId'], type=''],
 *     [name='companyId', columns=['companyId'], type=''],
 *     [name='closed_at', columns=['closed_at'], type='']
 * )
 */
class ModuleAmoLeads extends ModulesModelsBase
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
    public $idAmo;

    /**
     * @Column(type="string", nullable=true)
     */
    public $name;

    /**
     * @Column(type="string", nullable=true)
     */
    public $responsible_user_id;

    /**
     * @Column(type="string", nullable=true)
     */
    public $contactId;

    /**
     * @Column(type="string", nullable=true)
     */
    public $companyId;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $status_id= '';

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $pipeline_id= '';

    /**
     * @Column(type="integer", nullable=true)
     */
    public $isMainContact = 0;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $closed_at = 0;

    /**
     * @param $calledModelObject
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleAmoLeads');
        parent::initialize();
    }
}