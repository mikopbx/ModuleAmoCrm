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
 *     [name='number', columns=['number'], type='']
 * )
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