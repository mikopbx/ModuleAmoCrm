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
 *     [name='UNIQUEID', columns=['UNIQUEID'], type='']
 * )
 */
class ModuleAmoRequestData extends ModulesModelsBase
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
    public $UNIQUEID;

    /**
     * @Column(type="string", nullable=true)
     */
    public $request;

    /**
     * @Column(type="string", nullable=true)
     */
    public $response;

    /**
     * Toggle
     *
     * @Column(type="integer", default="0", nullable=true)
     */
    public $isError = 0;

    /**
     * @param $calledModelObject
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleAmoRequestData');
        parent::initialize();
    }
}