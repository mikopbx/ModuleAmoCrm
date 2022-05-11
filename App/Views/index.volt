

<form class="ui large grey segment form" id="module-amo-crm-form">
    <div class="ui segment">
        <a style="display: none;" class="ui green right ribbon label" id="status-ok">{{ t._("module_amo_crm_connect_ok") }}</a>
        <a style="display: none;" class="ui red right ribbon label" id="status-fail">{{ t._("module_amo_crm_connect_fail") }}</a>

        <br>
        {{ form.render('id') }}
        {{ form.render('referenceDate') }}
        <button class="ui positive basic button" id="simple-login-button">{{ t._("module_amo_crmSimpleLogin") }}</button>
<!--         <button class="ui positive basic button" id="login-button">{{ t._("module_amo_crmLogin") }}</button> -->
        <br>
        <br>
        <div class="ten wide field disability">
            <label >{{ t._('mod_amo_baseDomain') }}</label>
            {{ form.render('baseDomain') }}
        </div>

        <div class="ten wide field disability">
            <label >{{ t._('mod_amo_clientId') }}</label>
            {{ form.render('clientId') }}
        </div>
        <div class="ten wide field disability">
            <label >{{ t._('mod_amo_clientSecret') }}</label>
            {{ form.render('clientSecret') }}
        </div>

        <div class="ten wide field disability">
            <label >{{ t._('mod_amo_tokenForAmo') }}</label>
            {{ form.render('tokenForAmo') }}
        </div>

        <div id="warning-message" class="ui visible warning message" style="display: none;">
          <div class="header">ваав</div>
          <div class="body">выава</div>
        </div>
    </div>

    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>

<div class="ui long modal" id="modal-auth-iframe" style="height: 80%;">
    <iframe id="auth-iframe" src="" sandbox="allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-same-origin" style="height: 100%;width: 100%;">
    Ваш браузер не поддерживает плавающие фреймы!
    </iframe>
</div>

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