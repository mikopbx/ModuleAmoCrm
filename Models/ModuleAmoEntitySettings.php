<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 2 2019
 */

namespace Modules\ModuleAmoCrm\Models;
use MikoPBX\Modules\Models\ModulesModelsBase;

class ModuleAmoEntitySettings extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="string", nullable=true)
     */
    public $id;

    /**
     * DID звонка, может быть пустым - тогда это "маршрут по умолчанию" для данного типа звонка
     * @Column(type="string", nullable=true)
     */
    public $did;

    /**
     * Тип звонка
     * @Column(type="string", nullable=true)
     */
    public $type;

    /**
     * "Сотрудник по умолчанию" - для случаев, когда вызов не попал на сотрудника
     * @Column(type="string", nullable=true)
     */
    public $def_responsible;

    /**
     * Ответственный по задаче / сделке / контакту - первый сотрудник / последний сотрудник / ответственный по клиенту
     * @Column(type="string", nullable=true)
     */
    public $responsible;

    /**
     * Шаблон имени контакта
     * @Column(type="string", nullable=true)
     */
    public $template_contact_name;

    /**
     * Шаблон имени сделки
     * @Column(type="string", nullable=true)
     */
    public $template_lead_name;

    /**
     * Шаблон имени сделки
     * @Column(type="string", nullable=true)
     */
    public $template_lead_text;

    /**
     * Воронка для сделки
     * @Column(type="string", nullable=true)
     */
    public $lead_pipeline_id;

    /**
     * Этап воронки для сделки
     * @Column(type="string", nullable=true)
     */
    public $lead_pipeline_status_id;

    /**
     * Свойство "Создавать задачу" - да / нет
     * @Column(type="integer", nullable=true)
     */
    public $create_task = 0;

    /**
     * Свойство "Создавать контакт"
     * @Column(type="integer", nullable=true)
     */
    public $create_contact = 0;

    /**
     * Свойство "Создавать сделку"
     * @Column(type="integer", nullable=true)
     */
    public $create_lead = 0;

    /**
     * Свойство "Создавать неразобранное"
     * @Column(type="integer", nullable=true)
     */
    public $create_unsorted = 0;

    /**
     * Шаблон Заполнения задачи
     * @Column(type="string", nullable=true)
     */
    public $template_task_text;

    /**
     * Срок выполнения задачи в часах.
     * @Column(type="integer", nullable=true)
     */
    public $deadline_task = 1;

    public function initialize(): void
    {
        $this->setSource('m_ModuleAmoEntitySettings');
        parent::initialize();
    }
}