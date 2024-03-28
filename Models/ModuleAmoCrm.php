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

use MikoPBX\Modules\Models\ModulesModelsBase;

class ModuleAmoCrm extends ModulesModelsBase
{
    public const RESP_TYPE_FIRST    = 'first';
    public const RESP_TYPE_LAST     = 'last';
    public const RESP_TYPE_CONTACT  = 'contact';
    public const RESP_TYPE_RULE     = 'rule';

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

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
     * @Column(type="string", nullable=true)
     */
    public $tokenForAmo;

    /**
     * Toggle
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $offsetCdr = 1;

    /**
     * Toggle
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $useInterception = 1;

    /**
     * Toggle
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $panelIsEnable = 1;

    /**
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $lastContactsSyncTime = 0;

    /**
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $lastCompaniesSyncTime = 0;

    /**
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $lastLeadsSyncTime = 0;

    /**
     *
     * @Column(type="integer", default="0", nullable=true)
     */
    public $isPrivateWidget = 0;

    /**
     * @Column(type="string", nullable=true)
     */
    public $privateClientId;

    /**
     * @Column(type="string", nullable=true)
     */
    public $privateClientSecret;

    /**
     *
     * @Column(type="integer", default="0", nullable=true)
     */
    public $portalId = 0;

    /**
     * Disable detailed CDRs
     * @Column(type="integer", default="0", nullable=true)
     */
    public $disableDetailedCdr = 0;


    /**
     * first last fromContact fromRule
     * @Column(type="string", nullable=true)
     */
    public $respCallAnsweredHaveClient = '';

    /**
     * first last fromRule
     * @Column(type="string", nullable=true)
     */
    public $respCallAnsweredNoClient = '';

    /**
     * first last fromRule
     * @Column(type="string", nullable=true)
     */
    public $respCallMissedNoClient = '';

    /**
     * first last fromContact fromRule
     * @Column(type="string", nullable=true)
     */
    public $respCallMissedHaveClient = '';

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
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleAmoCrm');
        parent::initialize();
    }

}