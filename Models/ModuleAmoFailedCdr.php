<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 2 2026
 */

namespace Modules\ModuleAmoCrm\Models;
use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Хранение провальных CDR, которые не удалось отправить в AmoCRM.
 *
 * @Indexes(
 *     [name='linkedid', columns=['linkedid'], type=''],
 *     [name='failedAt', columns=['failedAt'], type='']
 * )
 */
class ModuleAmoFailedCdr extends ModulesModelsBase
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
    public $linkedid;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $failedAt = 0;

    /**
     * @Column(type="string", nullable=true)
     */
    public $reason = '';

    /**
     * @param $calledModelObject
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleAmoFailedCdr');
        parent::initialize();
    }
}
