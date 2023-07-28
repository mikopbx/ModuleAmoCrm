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
 * Class ModuleAmoPipeLines
 * @package Modules\ModuleAmoCrm\Models
 */
class ModuleAmoPipeLines extends ModulesModelsBase
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
    public $amoId;

    /**
     * @Column(type="string", nullable=true)
     */
    public $name;

    /**
     * Toggle
     *
     * @Column(type="string", nullable=true)
     */
    public $did = '';

    /**
     * Toggle
     *
     * @Column(type="string", nullable=true)
     */
    public $statuses = '';

    /**
     * @Column(type="integer", nullable=true)
     */
    public $portalId = 0;

    /**
     * @param $calledModelObject
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleAmoPipeLines');
        parent::initialize();
    }
}