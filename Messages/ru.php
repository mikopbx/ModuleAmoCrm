<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 6 2018
 *
 */

return [
    'BreadcrumbModuleAmoCrm'        => 'Модуль интеграции с amoCRM',
    'BreadcrumbModuleAmoCrmModify'  => 'Модуль интеграции с amoCRM. Редактирование правила создания сущностей',
    'SubHeaderModuleAmoCrm'         => 'Интеграция с amoCRM',
    'mod_amo_AddRules'          => 'Добавить правило',
    'module_amo_crmSimpleLogin'     => 'Ввести код авторизации',
    'mod_amo_authCodeText'          => 'Введите код авторизации',
    'mod_amo_authCodeSave'          => 'Сохранить',
    'mod_amo_NeedWaitSyncTitle'     => 'Синхронизация контактов',
    'mod_amo_NeedWaitSyncBody'      => 'Выполняется синхронизация контактов и сделок. Дождитесь завершения процесса. Загрузка звонков на портал остановлена.',

    'repModuleAmoCrm'               => 'Модуль шаблон - %repesent%',
    'mo_ModuleModuleAmoCrm'         => 'Модуль шаблон',
    'module_amo_crm_connect_ok'     => 'Авторизован',
    'module_amo_crm_connect_fail'   => 'Нажмите для авторизации',
    'module_amo_crm_connect_refresh'=> 'Запрос статуса...',
    'mod_amo_baseDomain'            => 'Адрес портала (пример: example.amocrm.ru)',
    'mod_amo_Error'                 => 'Ошибка',
    'mod_amo_isPrivateWidget'       => 'Это приватная интеграция',
    'mod_amo_panelIsEnable'         => 'Использовать панель управления вызовами',
    'mod_amo_privateClientId'       => 'ID интеграции',
    'mod_amo_privateClientSecret'   => 'Секретный ключ интеграции',

    'mod_amo_entitySettingsTableDid'            => 'DID',
    'mod_amo_entitySettingsTableType'           => 'Тип звонка',
    'mod_amo_entitySettingsTableCreateContact'  => 'Контакт',
    'mod_amo_entitySettingsTableCreateLead'     => 'Сделка',
    'mod_amo_entitySettingsTableCreateUnsorted' => 'Неразобранное',
    'mod_amo_entitySettingsTableCreateTask'     => 'Задача',

    'mod_amo_entitySettingsCreateContactField'  => 'Создавать новый контакт',
    'mod_amo_entitySettingsCreateLeadField'     => 'Создавать сделку, если нет открытой',
    'mod_amo_entitySettingsCreateUnsortedField' => 'Создавать неразобранное',
    'mod_amo_entitySettingsResponsibleField'    => 'Назначить ответственным',
    'mod_amo_entitySettingsDefResponsibleField' => 'Ответственный по умолчанию',
    'mod_amo_entitySettingsCreateTaskField'     => 'Создавать задачу',
    'mod_amo_entitySettingsCreateTypeField'     => 'Тип звонка',
    'mod_amo_entitySettingsLeadPipelineIdField' => 'Воронка',
    'mod_amo_entitySettingsLeadPipelineStatusIdField' => 'Этап воронки',
    'mod_amo_entitySettingsTemplateContactNameField' => 'Шаблон имени контакта, пример: "Новый контакт &lt;PhoneNumber&gt;"',
    'mod_amo_entitySettingsTemplateLeadNameField' => 'Шаблон наименования сделки, пример: "Новая сделка &lt;PhoneNumber&gt;"',
    'mod_amo_entitySettingsTemplateTaskNameField' => 'Шаблон наименования задачи, пример: "Перезвонить по номеру &lt;PhoneNumber&gt;"',
    'mod_amo_entitySettingsTemplateTaskDeadlineField' => 'Крайний срок выполнения задачи в часах',

    'mod_amo_type_responsible_answered_first'   => 'Первый, кто ответил на вызов',
    'mod_amo_type_responsible_answered_last'    => 'Последний, кто ответил на вызов',
    'mod_amo_type_responsible_missed_first'     => 'Первый, кто пропустил вызов',
    'mod_amo_type_responsible_missed_last'      => 'Последний, кто пропустил вызов',
    'mod_amo_type_responsible_contact'          => 'Ответственный за контакт',
    'mod_amo_type_responsible_rule'             => 'По правилу создания сущности',
    'mod_amo_settingsCalls'                     => 'Загрузка звонков',
    'mod_amo_disableDetailedCdr'                => 'Отключить загрузку подробных CDR',
    'mod_amo_respCallAnsweredHaveClient'        => 'Вызов отвечен, есть клиент, ответственный',
    'mod_amo_respCallAnsweredNoClient'          => 'Вызов отвечен, новый клиент, ответственный',
    'mod_amo_respCallMissedHaveClient'          => 'Вызов пропущен, есть клиент, ответственный',
    'mod_amo_respCallMissedNoClient'          => 'Вызов пропущен, новый клиент, ответственный',
    'mod_amo_respCommentMessage'          => 'В этом разделе выполняется настройка правил назначения ответственного за <b>входящие звонки</b> при отключенном режиме подробных CDR',

    'mod_amo_type_INCOMING_UNKNOWN'             => 'Отвеченный входящий с неизвестного номера',
    'mod_amo_type_INCOMING_KNOWN'               => 'Отвеченный входящий с известного номера',
    'mod_amo_type_MISSING_UNKNOWN'              => 'Пропущенный с неизвестного номера',
    'mod_amo_type_MISSING_KNOWN'                => 'Пропущенный с известного номера',
    'mod_amo_type_OUTGOING_UNKNOWN'             => 'Отвеченный исходящий на неизвестный номер',
    'mod_amo_type_OUTGOING_KNOWN'               => 'Отвеченный исходящий на известный номер',
    'mod_amo_type_OUTGOING_KNOWN_FAIL'          => 'Неудачный исходящий на известный номер',

    'mod_amo_task_responsible_type'                  => 'Ответственный по задаче',
    'mod_amo_task_responsible_type_'                 => 'Не выбран',
    'mod_amo_task_responsible_type_firstMissedUser'  => 'Первый, на кого пропустил вызов',
    'mod_amo_task_responsible_type_lastMissedUser'   => 'Последний, кто пропустил вызов',
    'mod_amo_task_responsible_type_lastAnswerUser'   => 'Последний, кто ответил на вызов',
    'mod_amo_task_responsible_type_firstAnswerUser'  => 'Первый, кто ответил на вызов',
    'mod_amo_task_responsible_type_clientResponsible'=> 'Ответственный по клиенту',
    'mod_amo_task_responsible_type_def_responsible'  => 'Ответственный по умолчанию',

    'mod_amo_entitySettingsEntityActionField'   => 'Действие со сделками и контактами',
    'mod_amo_action_none'                       => 'Ничего не делать',
    'mod_amo_action_unsorted'                   => 'Создавать запись в "Неразобранное"',
    'mod_amo_action_contact'                    => 'Создавать контакт',
    'mod_amo_action_contact_lead'               => 'Создавать контакт и сделку',
    'mod_amo_action_lead'                       => 'Создавать сделку, если нет открытой',

    'mod_amo_type_responsibleVarFirst'          => 'Первого, кто ответил на вызов',
    'mod_amo_type_responsibleVarLast'           => 'Последнего, кто ответил на вызов',

    'mod_amo_rule_for_type_did_exists'          => 'Правило для этого внешнего номера (DID) и такого типа звонка уже описано ранее. Создание дубликата запрещено.',

    'module_amo_crmTextAreaFieldLabel'    => 'Пример многостраничного поля',
    'module_amo_crmPasswordFieldLabel'    => 'Пример поля с паролем',
    'module_amo_crmIntegerFieldLabel'     => 'Пример числового поля',
    'module_amo_crmCheckBoxFieldLabel'    => 'Чекбокс',
    'module_amo_crmToggleFieldLabel'      => 'Переключатель',
    'module_amo_crmDropDownFieldLabel'    => 'Выпадающее меню',
    'module_amo_crmValidateValueIsEmpty'  => 'Проверьте поле, оно не заполнено',
    'module_amo_crmConnected'             => 'Модуль подключен',
    'module_amo_crmDisconnected'          => 'Модуль отключен',
    'module_amo_crmUpdateStatus'          => 'Обновление статуса',
    'mod_amo_tokenForAmo'                 => 'Ключ доступа к API MikoPBX',
    'mod_amo_useInterception'             => 'Использовать перехват на ответственного',

    'mod_amo_SaveSettingsError'           => 'Возникла ошибка сохранения настроек. Возможно не запущен сервис ConnectorDB.',
    'mod_amo_rules'                       => 'Создание сущностей',
    'mod_amo_settingsConnection'          => 'Настройки подключения',

];