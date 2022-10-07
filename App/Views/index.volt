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
        <div id="warning-message" class="ui visible warning message" style="display: none;">
          <div class="header">ваав</div>
          <div class="body">выава</div>
        </div>
    </div>
    <table id="ModuleAmoPipeLines-table" class="ui small very compact single line table"></table>
    <br>
    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>

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

