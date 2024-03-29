
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
  <a class="active item" data-tab="rules">{{ t._('mod_amo_rules') }}</a>
  <a class="item" data-tab="settings">{{ t._('mod_amo_settingsConnection') }}</a>
  <a class="item" data-tab="calls">{{ t._('mod_amo_settingsCalls') }}</a>
</div>

<form class="ui large grey form" id="module-amo-crm-form">

<div class="ui bottom active tab segment disability" data-tab="rules">
    {{ link_to("module-amo-crm/modify/", '<i class="add circle icon"></i> '~t._('mod_amo_AddRules'), "class": "ui blue button", "id":"add-new-button") }}
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
                    <div class="ui toggle checkbox ">
                        {{ form.render('panelIsEnable') }}
                        <label>{{ t._('mod_amo_panelIsEnable') }}</label>
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
    <div class="ui visible message">
        <p>{{ t._('mod_amo_respCommentMessage') }}</p>
    </div>
    <br>
    <div class="field limited-cdr-settings">
        <label >{{ t._('mod_amo_type_INCOMING_KNOWN') }}</label>
        <div class="disability ui fluid action input">
             {{ form.render('respCallAnsweredHaveClient') }}
        </div>
    </div>
    <div class="field limited-cdr-settings">
        <label >{{ t._('mod_amo_type_INCOMING_UNKNOWN') }}</label>
        <div class="disability ui fluid action input">
             {{ form.render('respCallAnsweredNoClient') }}
        </div>
    </div>
    <div class="field limited-cdr-settings">
        <label >{{ t._('mod_amo_type_MISSING_KNOWN') }}</label>
        <div class="disability ui fluid action input">
             {{ form.render('respCallMissedHaveClient') }}
        </div>
    </div>
    <div class="field limited-cdr-settings">
        <label >{{ t._('mod_amo_type_MISSING_UNKNOWN') }}</label>
        <div class="disability ui fluid action input">
             {{ form.render('respCallMissedNoClient') }}
        </div>
    </div>
</div>

{{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>