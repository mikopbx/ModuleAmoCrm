<?php

/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2024 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleAmoCrm\App\Forms;

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\System\Util;
use Modules\ModuleAmoCrm\bin\AmoCdrDaemon;
use Modules\ModuleAmoCrm\bin\ConnectorDb;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Hidden;
use Phalcon\Forms\Element\Select;

class ModuleAmoCrmEntitySettingsModifyForm extends ModuleBaseForm
{
    public function initialize($entity = null, $options = null): void
    {
        $this->add(new Hidden('id', ['value' => $entity->id]));
        $this->add(new Hidden('portalId', ['value' => $entity->portalId]));
        $this->add(new Text('did'));

        $this->add(new Text('template_contact_name'));
        $this->add(new Text('template_lead_name'));
        $this->add(new Text('template_task_text'));
        $this->add(new Numeric('deadline_task', ["maxlength" => 3, "style" => "width: 80px;"]));

        $this->addCheckBox('create_contact', intval($entity->create_contact) === 1);
        $this->addCheckBox('create_lead', intval($entity->create_lead) === 1);
        $this->addCheckBox('create_unsorted', intval($entity->create_unsorted) === 1);
        $this->addCheckBox('create_task', intval($entity->create_task) === 1);

        $types = [
            AmoCdrDaemon::INCOMING_UNKNOWN      => Util::translate('mod_amo_type_' . AmoCdrDaemon::INCOMING_UNKNOWN, false),
            AmoCdrDaemon::INCOMING_KNOWN        => Util::translate('mod_amo_type_' . AmoCdrDaemon::INCOMING_KNOWN, false),
            AmoCdrDaemon::MISSING_UNKNOWN       => Util::translate('mod_amo_type_' . AmoCdrDaemon::MISSING_UNKNOWN, false),
            AmoCdrDaemon::MISSING_KNOWN         => Util::translate('mod_amo_type_' . AmoCdrDaemon::MISSING_KNOWN, false),
            AmoCdrDaemon::OUTGOING_UNKNOWN      => Util::translate('mod_amo_type_' . AmoCdrDaemon::OUTGOING_UNKNOWN, false),
            AmoCdrDaemon::OUTGOING_KNOWN_FAIL   => Util::translate('mod_amo_type_' . AmoCdrDaemon::OUTGOING_KNOWN_FAIL, false),
            AmoCdrDaemon::OUTGOING_KNOWN        => Util::translate('mod_amo_type_' . AmoCdrDaemon::OUTGOING_KNOWN, false),
        ];
        $type = new Select(
            'type',
            $types,
            [
                  'useEmpty' => false,
                  'class' => 'ui selection dropdown search',
                  'using' => [
                      'id',
                      'name',
                  ],
                ]
        );
        $this->add($type);

        $responsibleVar = [
            'first'      => Util::translate('mod_amo_type_responsibleVarFirst', false),
            'last'       => Util::translate('mod_amo_type_responsibleVarLast', false),
        ];
        $type = new Select(
            'responsible',
            $responsibleVar,
            [
                  'useEmpty' => false,
                  'class' => 'ui selection dropdown search',
                ]
        );
        $this->add($type);

        $pipeLinesData    = ConnectorDb::invoke('getPipeLines', [true]);
        $pipeLineStatuses = [];
        $pipeLinesList    = [];
        foreach ($pipeLinesData as $pipeLine) {
            $pipeLinesList[$pipeLine['amoId']] = $pipeLine['name'];
            try {
                $statuses = json_decode($pipeLine['statuses'], true, 512, JSON_THROW_ON_ERROR);
                foreach ($statuses as $index => $status) {
                    if (in_array($status['id'], [142,143,51569353], true)) {
                        continue;
                    }
                    if ($index === 0) {
                        continue;
                    }
                    if ($pipeLine['amoId'] === $entity->lead_pipeline_id) {
                        $selected = $entity->lead_pipeline_status_id === (string)$status['id'];
                    } else {
                        $selected = $index === 1;
                    }
                    $pipeLineStatuses[$pipeLine['amoId']][] = [
                        'name'  => $status['name'],
                        'value' => $status['id'],
                        'selected' => $selected,
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        $this->add(new Hidden('pipeLineStatuses', ['value' => json_encode($pipeLineStatuses)]));
        $lead_pipeline_id = new Select(
            'lead_pipeline_id',
            $pipeLinesList,
            [
                             'useEmpty' => false,
                             'class' => 'ui selection dropdown search',
                         ]
        );
        $this->add($lead_pipeline_id);

        $lead_pipeline_status_id = new Select(
            'lead_pipeline_status_id',
            [],
            [
                             'useEmpty' => false,
                             'class' => 'ui selection dropdown search',
                         ]
        );
        $this->add($lead_pipeline_status_id);


        $extensions = Extensions::find("type='" . Extensions::TYPE_SIP . "'");
        $pbxUsers = [];
        foreach ($extensions as $extension) {
            $pbxUsers[$extension->number] = $extension->callerid;
        }
        unset($extensions);

        $usersAmo = ConnectorDb::invoke('getPortalUsers', [1]);
        $users    = [];
        foreach ($usersAmo as $user) {
            $users[$user['amoUserId']] = ($pbxUsers[$user['number']] ?? '') . " <{$user['number']}>";
        }

        $def_responsible = new Select(
            'def_responsible',
            $users,
            [
                                         'useEmpty' => false,
                                         'class' => 'ui selection dropdown search',
                                     ]
        );
        $this->add($def_responsible);
        $entityAction = new Select(
            'entityAction',
            [],
            [
                                 'useEmpty' => false,
                                 'class' => 'ui selection dropdown search',
                             ]
        );
        $this->add($entityAction);

        $taskResponsibleType = [
            $entity->task_responsible_type => Util::translate('mod_amo_task_responsible_type_' . $entity->task_responsible_type)
        ];
        $taskResponsibleTypeAction = new Select(
            'task_responsible_type',
            $taskResponsibleType,
            [
                                 'useEmpty' => false,
                                 'class' => 'ui selection dropdown search',
                             ]
        );
        $this->add($taskResponsibleTypeAction);
    }
}
