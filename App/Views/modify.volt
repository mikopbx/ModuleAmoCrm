<form class="ui large grey form" id="module-amo-crm-entity-settings-form">
    {{ form.render('id') }}
    {{ form.render('pipeLineStatuses') }}
    <div class="one field">
        <label for="did">{{ t._('mod_amo_entitySettingsTableDid') }}</label>
        {{ form.render('did') }}
    </div>
    <div class="fields">
        <div class="six wide field">
            <label>{{ t._('mod_amo_entitySettingsCreateTypeField') }}</label>
            {{ form.render('type') }}
        </div>
         <div class="six wide field">
            <label>{{ t._('mod_amo_entitySettingsEntityActionField') }}</label>
            {{ form.render('entityAction') }}
        </div>
    </div>
   <div class="fields">
        <div class="six wide field">
            <label>{{ t._('mod_amo_entitySettingsLeadPipelineIdField') }}</label>
            {{ form.render('lead_pipeline_id') }}
        </div>
        <div class="six wide field">
            <label>{{ t._('mod_amo_entitySettingsLeadPipelineStatusIdField') }}</label>
            {{ form.render('lead_pipeline_status_id') }}
        </div>
    </div>

    <div class="fields">
        <div class="six wide field">
            <label>{{ t._('mod_amo_entitySettingsResponsibleField') }}</label>
            {{ form.render('responsible') }}
        </div>
        <div class="six wide field">
            <label>{{ t._('mod_amo_entitySettingsDefResponsibleField') }}</label>
            {{ form.render('def_responsible') }}
        </div>
    </div>
    <div class="ui">
        <div class="ui toggle checkbox" style="display: none;">
            <label for="create_contact">{{ t._('mod_amo_entitySettingsCreateContactField') }}</label>
            {{ form.render('create_contact') }}
        </div>
        <div class="field ten wide">
            <br>
            <label>{{ t._('mod_amo_entitySettingsTemplateContactNameField') }}</label>
            {{ form.render('template_contact_name') }}
        </div>
    </div>
    <div class="ui">
        <div class="ui toggle checkbox" style="display: none;">
            <label for="create_lead">{{ t._('mod_amo_entitySettingsCreateLeadField') }}</label>
            {{ form.render('create_lead') }}
        </div>
        <div class="field ten wide">
            <br>
            <label>{{ t._('mod_amo_entitySettingsTemplateLeadNameField') }}</label>
            {{ form.render('template_lead_name') }}
        </div>
    </div>
    <div class="ui toggle checkbox hidden" style="display: none;">
        <label for="create_unsorted">{{ t._('mod_amo_entitySettingsCreateUnsortedField') }}</label>
        {{ form.render('create_unsorted') }}
    </div>
    <div class="ui segment">
        <div class="ui toggle checkbox hidden">
            <label for="create_task">{{ t._('mod_amo_entitySettingsCreateTaskField') }}</label>
            {{ form.render('create_task') }}
        </div>
        <div class="field ten wide">
            <br>
            <label>{{ t._('mod_amo_entitySettingsTemplateTaskNameField') }}</label>
            {{ form.render('template_task_text') }}
        </div>
        <div class="field ten wide">
            <label>{{ t._('mod_amo_entitySettingsTemplateTaskDeadlineField') }}</label>
            {{ form.render('deadline_task') }}
        </div>
    </div>
    {{ partial("partials/submitbutton",['indexurl':'module-amo-crm/index/']) }}
</form>
