
{% if !workIsAllowed %}
<div id="WaitSyncMsg" class="ui icon message">
  <i class="notched circle loading icon"></i>
  <div class="content">
    <div class="header">
      {{ t._('mod_amo_NeedWaitSyncTitle') }}
    </div>
    <p>{{ t._('mod_amo_NeedWaitSyncBody') }}</p>
  </div>
</div>
{% endif %}

<div class="ui top attached tabular menu">
  <a class="active item" data-tab="rules">{{ t._('mod_amo_rules') }}<i class="ui icon blue info circle tab-hint" data-content="{{ t._('mod_amo_tabRulesHint') }}"></i></a>
  <a class="item" data-tab="settings">{{ t._('mod_amo_settingsConnection') }}<i class="ui icon blue info circle tab-hint" data-content="{{ t._('mod_amo_tabSettingsHint') }}"></i></a>
  <a class="item" data-tab="calls">{{ t._('mod_amo_settingsCalls') }}<i class="ui icon blue info circle tab-hint" data-content="{{ t._('mod_amo_tabCallsHint') }}"></i></a>
  <a class="item" data-tab="users">{{ t._('mod_amo_tabUsers') }}<i class="ui icon blue info circle tab-hint" data-content="{{ t._('mod_amo_usersHint') }}"></i><i class="ui icon sync link tab-action" id="refresh-users-button" data-content="{{ t._('mod_amo_usersRefresh') }}"></i></a>
</div>

<form class="ui form" id="module-amo-crm-form">

<div class="ui bottom active tab segment disability" data-tab="rules">
    {{ link_to("module-amo-crm/module-amo-crm/modify/", '<i class="add circle icon"></i> '~t._('mod_amo_AddRules'), "class": "ui blue button", "id":"add-new-button") }}
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
            <td class="" data-order="{{ rule['type_order'] }}"><i class="icon {{ rule['type_icon'] }}"></i>{{ t._(rule['type_translate']) }}</td>
            <td class="">
                <i class="icons">
                {% if (rule['create_contact'] === 1) %}
                    <i class="icon checkmark green" data-value="on"></i>
                {% else %}
                    <i class="icon close red" data-value="off"></i>
                {% endif %}
                </i>
            </td>
            <td class="">
                <i class="icons">
                {% if (rule['create_lead'] === 1) %}
                    <i class="icon checkmark green" data-value="on"></i>
                {% else %}
                    <i class="icon close red" data-value="off"></i>
                {% endif %}
                </i>
            </td>
            <td class="">
                <i class="icons">
                {% if (rule['create_task'] === 1) %}
                    <i class="icon checkmark green" data-value="on"></i>
                {% else %}
                    <i class="icon close red" data-value="off"></i>
                {% endif %}
                </i>
            </td>
            <td class="">
                <i class="icons">
                {% if (rule['create_unsorted'] === 1) %}
                    <i class="icon checkmark green" data-value="on"></i>
                {% else %}
                    <i class="icon close red" data-value="off"></i>
                {% endif %}
                </i>
            </td>
            {{ partial("partials/tablesbuttons",
                [
                    'id': rule['id'],
                    'edit' : 'module-amo-crm/module-amo-crm/modify/',
                    'delete': 'module-amo-crm/module-amo-crm/delete/'
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
</div>
<div class="ui bottom attached tab segment" data-tab="settings">
        <div class="ui">
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
            <div class="fields disability">
                <div class="ten wide field">
                    <label>{{ t._('mod_amo_externalHostname') }} <i class="ui icon blue info circle" data-content="{{ t._('mod_amo_externalHostnameHint') }}"></i></label>
                    <div class="disability ui fluid action input">
                        {{ form.render('externalHostname') }}
                        <button class="ui icon button" id="copy-pbx-address" type="button"><i class="copy icon"></i></button>
                    </div>
                </div>
                <div class="four wide field">
                    <label>{{ t._('mod_amo_webhookPort') }} <i class="ui icon blue info circle" data-content="{{ t._('mod_amo_webhookPortHint') }}"></i></label>
                    <div class="disability ui fluid input">
                        {{ form.render('webhookPort') }}
                    </div>
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

            <div class="field disability">
                <div class="ui segment">
                    <div class="ui toggle checkbox">
                        {{ form.render('isPrivateWidget') }}
                        <label>{{ t._('mod_amo_isPrivateWidget') }}</label>
                    </div>
                    <div id="private-fields">
                        <div class="ten wide field disability">
                            <br>
                            <label >{{ t._('mod_amo_privateClientId') }}</label>
                            <div class="disability ui fluid input">
                                 {{ form.render('privateClientId') }}
                            </div>
                        </div>
                        <div class="ten wide field disability">
                            <label >{{ t._('mod_amo_privateClientSecret') }}</label>
                            <div class="disability ui fluid input">
                                 {{ form.render('privateClientSecret') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="warning-message" class="ui visible warning message" style="display: none;">
              <div class="header">ваав</div>
              <div class="body">выава</div>
            </div>
        </div>
        <table id="ModuleAmoPipeLines-table" class="ui small very compact single line table"></table>
</div>

<div class="ui bottom tab segment disability" data-tab="calls">

    <div class="field">
        <div class="ui toggle checkbox">
            {{ form.render('disableDetailedCdr') }}
            <label>{{ t._('mod_amo_disableDetailedCdr') }}</label>
        </div>
    </div>
    <div class="field">
        <div class="ui toggle checkbox">
            {{ form.render('restrictCdrToKnownEmployees') }}
            <label>{{ t._('mod_amo_restrictCdrToKnownEmployees') }}</label>
        </div>
    </div>

    <div class="ui visible message">
        <p>{{ t._('mod_amo_respCommentMessage') }}</p>
    </div>
    <table id="respCallsTable" class="ui selectable compact unstackable table limited-cdr-settings">
        <thead>
        <tr>
            <th class="eight wide">{{ t._('mod_amo_entitySettingsTableType') }}</th>
            <th>{{ t._('mod_amo_respTableResponsible') }}</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><i class="icon green call-incoming"></i>{{ t._('mod_amo_type_INCOMING_KNOWN') }}</td>
            <td>{{ form.render('respCallAnsweredHaveClient') }}</td>
        </tr>
        <tr>
            <td><i class="icon green call-incoming"></i>{{ t._('mod_amo_type_INCOMING_UNKNOWN') }}</td>
            <td>{{ form.render('respCallAnsweredNoClient') }}</td>
        </tr>
        <tr>
            <td><i class="icon red call-incoming"></i>{{ t._('mod_amo_type_MISSING_KNOWN') }}</td>
            <td>{{ form.render('respCallMissedHaveClient') }}</td>
        </tr>
        <tr>
            <td><i class="icon red call-incoming"></i>{{ t._('mod_amo_type_MISSING_UNKNOWN') }}</td>
            <td>{{ form.render('respCallMissedNoClient') }}</td>
        </tr>
        </tbody>
    </table>
</div>

<div class="ui bottom tab segment disability" data-tab="users">
    <div id="users-message" class="ui message" style="display: none;"></div>
    <table class="ui selectable compact unstackable table" id="amo-users-table" style="display: none;">
        <thead>
        <tr>
            <th>{{ t._('mod_amo_usersTableAmoUser') }}</th>
            <th>{{ t._('mod_amo_usersTableEmail') }}</th>
            <th class="six wide">{{ t._('mod_amo_usersTablePbxUser') }}</th>
        </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

{{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>