<form class="ui large grey segment form" id="module-amo-crm-form">
    <div class="ui segment">
        {{ form.render('id') }}
        {{ form.render('referenceDate') }}
        {{ form.render('clientId') }}
        {{ form.render('redirectUri') }}
        <div class="ten wide field disability">
            <label >{{ t._('mod_amo_baseDomain') }}</label>
            <div class="disability ui fluid action input">
                {{ form.render('baseDomain') }}
                <button class="ui basic button" id="login-button">{{ t._("module_amo_crm_connect_refresh") }}</button>
            </div>
        </div>
        <div class="ten wide field disability">
            <label >{{ t._('mod_amo_tokenForAmo') }}</label>
            <div class="disability ui fluid action input">
                 {{ form.render('tokenForAmo') }}
                <button class="ui basic compact icon button green" id="createPassword"><i class="sync icon"></i></button>
            </div>
        </div>
        <div class="field disability">
            <div class="ui segment">
                <div class="ui toggle checkbox ">
                    {{ form.render('useInterception') }}
                    <label>{{ t._('mod_amo_useInterception') }}</label>
                </div>
            </div>
        </div>
        <div id="warning-message" class="ui visible warning message" style="display: none;">
          <div class="header">ваав</div>
          <div class="body">выава</div>
        </div>
    </div>
    <table id="ModuleAmoPipeLines-table" class="ui small very compact single line table"></table>
    <br>
    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>


<!-- 'id', 'did', 'type', 'create_lead', 'create_contact', 'create_unsorted', 'create_task' -->
{% for rule in entitySettings %}
    {% if loop.first %}
        <table class="ui selectable compact unstackable table" id="entitySettingsTable">
        <thead>
        <tr>
            <th>{{ t._('mod_amo_entitySettingsTableDid') }}</th>
            <th>{{ t._('mod_amo_entitySettingsTableType') }}</th>
            <th>{{ t._('mod_amo_entitySettingsTableCreateContact') }}</th>
            <th>{{ t._('mod_amo_entitySettingsTableCreateLead') }}</th>
            <th>{{ t._('mod_amo_entitySettingsTableCreateTask') }}</th>
            <th>{{ t._('mod_amo_entitySettingsTableCreateUnsorted') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
    {% endif %}

    <tr class="rule-row " id="{{ rule['id'] }}">
        <td class="">{{ rule['did'] }}</td>
        <td class="">{{ t._(rule['type_translate']) }}</td>
        <td class="">
            <i class="icons">
            {% if (rule['create_contact'] === '1') %}
                <i class="icon checkmark green" data-value="on"></i>
            {% else %}
                <i class="icon close red" data-value="off"></i>
            {% endif %}
            </i>
        </td>
        <td class="">
            <i class="icons">
            {% if (rule['create_lead'] === '1') %}
                <i class="icon checkmark green" data-value="on"></i>
            {% else %}
                <i class="icon close red" data-value="off"></i>
            {% endif %}
            </i>
        </td>
        <td class="">
            <i class="icons">
            {% if (rule['create_task'] === '1') %}
                <i class="icon checkmark green" data-value="on"></i>
            {% else %}
                <i class="icon close red" data-value="off"></i>
            {% endif %}
            </i>
        </td>
        <td class="">
            <i class="icons">
            {% if (rule['create_unsorted'] === '1') %}
                <i class="icon checkmark green" data-value="on"></i>
            {% else %}
                <i class="icon close red" data-value="off"></i>
            {% endif %}
            </i>
        </td>
        {{ partial("partials/tablesbuttons",
            [
                'id': rule['id'],
                'edit' : 'module-amo-crm/modify/',
                'delete': 'module-amo-crm/delete/'
            ])
        }}
    </tr>

    {% if loop.last %}
        </tbody>
        </table>
    {% endif %}
{% endfor %}
<div class="ui long modal"  id="modal-auth-simple">
    <div class="header"> {{ t._('mod_amo_authCodeText') }} </div>
    <div class="content">
        <div class="ui form">
          <div class="field">
            <textarea id="authCode" rows="3"></textarea>
          </div>
        </div>
    </div>

    <div class="actions">
        <div class="ui approve positive button"> {{ t._('mod_amo_authCodeSave') }}  </div>
    </div>
</div>

