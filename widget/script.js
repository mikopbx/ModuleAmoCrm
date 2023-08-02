/* global define, APP */
define(function (require) {
  const connector = require('./js/mpbx-connector.js?v=%WidgetVersion%');
  const $         = require('jquery');
  const PubSub    = require('pubsub');
  const Modal     = require('lib/components/base/modal');

  return function () {
    // mikopbx_w = this;
    let self = this;

    self.stickers = [];
    self.settings = {
      currentUser:  '',
      currentPhone: '',
      pbxHost:      '',
      users:        [],
      token:        '',
      time_unit:    '',
      ns: '',
    };
    self.callbacks = {
      render: function () {
        return true;
      },
      init: function () {
        // Инициализация виджета. Сбор настроек для коннектора.
        self.api.init();
        self.settings.heightWindow = $(window).height();
        // Подключение коннектора к АТС.
        connector.init(self.settings);
        return true;
      },
      bind_actions: function () {
        return true;
      },
      settings: function () {
        let wCode = self.params.widget_code;
        let boldStyle = {
          "font-weight": "bold",
          "color": "#61a0e1"
        };
        $(`.${wCode} div.widget_settings_block__title_field:not(.widget_settings_block_users__title_field)`).css(boldStyle);
        $(`.${wCode} strong`).css(boldStyle);
        $(`.${wCode} a`).css({"color": "#61a0e1"});
        return true;
      },
      onSave: function (data) {
        let phones = data.fields.pbx_users || false;
        if (phones) {
          $.get( "/api/v2/account", function( data ) {
            PubSub.publish(self.ns + ':connector', {'users': phones , 'portalId': data.id, action: 'saveSettings'});
          });
        }
        return true;
      },
      destroy: function () {
      },
    };
    self.api = {
      init: function (){
        let globalSettings = self.get_settings();
        // Connect Semantic-ui
        let pathSemantic = globalSettings.path + '/css/semantic.css?v=' + globalSettings.version;
        if ($('link[href="' + pathSemantic +'"').length < 1) {
          $("head").prepend('<link href="'  +pathSemantic + '" type="text/css" rel="stylesheet">');
        }
        let currentPhone = '';
        let user   = APP.constant('user').id;
        let phones = globalSettings.pbx_users || false;
        if (typeof phones == 'string') {
          phones = phones ? $.parseJSON(phones) : false;
        }
        if (phones && typeof phones[user] !== 'undefined') {
          currentPhone = phones[user].trim();
        }
        let newSettings = {
          currentUser:  user,
          currentPhone: currentPhone,
          pbxHost:      globalSettings.miko_pbx_host,
          users:        globalSettings.pbx_users,
          token:        globalSettings.token,
          time_unit:    self.i18n('settings.time_unit'),
          ns:           self.ns,
        };
        $.each(newSettings, function (key, value){
          if(typeof self.settings[key] === 'undefined'){
            return;
          }
          self.settings[key] = value;
        });

        self.add_action("phone", self.api.onClickPhone);
        PubSub.subscribe(self.ns + ':main', self.api.onMessage);

        $(document).on('mousedown',"div.feed-note__call-content a",function() {
          if($(this).parent().find('a[data-prepare="miko-pbx"]').length === 0){
            return;
          }
          $(this).each(() => {
            let oldUrl  = $(this).attr('href');
            let oldHost = (new URL(oldUrl)).hostname;
            if(oldHost === self.settings.pbxHost){
              return;
            }
            $(this).attr('href', oldUrl.replace(oldHost, self.settings.pbxHost));
          })
        });
      },
      onMessage: function (msg, message) {
        if(message.action === 'findContact'){
          self.api.findContact(message, function (result){
            result.action = 'resultFindContact';
            PubSub.publish(self.ns + ':connector', result);
          });
        }else if(message.action === 'error'){
          let error_params = {
            header: self.langs.errors['alert'],
            text: self.langs.errors[message.code]
          };
          APP.notifications.show_message_error(error_params);
        }else if(message.action === 'openCard'){
          self.api.findContact(message.data, function(data){
            if(data.element.id === ''){
              // Контакт НЕ найден.
              self.call_result(data.number);
            }else{
              // контакт найден.
              self.api.gotoContact(data.element.id);
            }
          })
        }
      },
      gotoContact: function (id){
          let elId = `mikopbx-a-${id}`;
          let selector = `#${elId}`;
          if ($(selector).length < 1) {
              $("body").prepend(`<a href="/contacts/detail/${id}" class="js-navigate-link" id="${elId}"></a>`);
          }
          $(selector).trigger('click');
          $(selector).remove();
      },
      createContact: function (notifications_data){
        let custom_fields_values = [];

        if(notifications_data.number !== undefined && notifications_data.number.trim() !== ''){
          custom_fields_values.push({
            'field_code': 'PHONE',
            'values': [
              {'value': notifications_data.number}
            ]
          });
        }
        let name = notifications_data.number;
        if(notifications_data.clientName !== undefined && notifications_data.clientName.trim() !== ''){
          name = notifications_data.clientName;
        }
        if(notifications_data.email !== undefined && notifications_data.email.trim() !== ''){
          custom_fields_values.push({
            'field_code': 'EMAIL',
            'values': [
              {'value': notifications_data.email}
            ]
          });
        }

        let params = [
          {
            "name": name,
            "created_by": APP.constant('user').id,
            "custom_fields_values": custom_fields_values
          }
        ];
        $.ajax({
          url:'//' + window.location.host + "/api/v4/contacts",
          type:"POST",
          data:JSON.stringify(params),
          contentType:'application/json;charset=utf-8',
          success: function(result){
            if(typeof result._embedded.contacts[0] !== 'undefined'){
              self.api.gotoContact(result._embedded.contacts[0].id);
              self.api.addNotesContact(result._embedded.contacts[0].id, notifications_data.comment);
            }
          }
        });
      },
      addNotesContact: function (id, comment){
        if(comment === undefined || comment.trim() === ''){
          return;
        }
        comment = comment.trim();
        let data = [{
          entity_id: 1*id,
          "created_by": APP.constant('user').id,
          note_type: 'common',
          "params": {
            "text": comment
          }
        }];
        $.ajax({
          url:'//' + window.location.host + `/api/v4/contacts/notes`,
          type:"POST",
          data:JSON.stringify(data),
          contentType:'application/json;charset=utf-8',
          error: (jqXHR, textStatus, errorThrown) => {
            console.debug(jqXHR, textStatus, errorThrown);
          }
        });

      },
      findContact: function (contactData, callback){
        let query = '';
        if(contactData.email !== undefined && contactData.email.trim() !== '' ){
          query += '&SEARCH='+contactData.email;
        }
        if(contactData.number !== undefined && contactData.number.trim() !== '' ){
          query += '&SEARCH='+contactData.number;
        }
        if(query === ''){
          contactData.element = {};
          contactData.element.id     = '';
          contactData.element.name   = '';
          contactData.element.company= '';
          callback(contactData)
          return;
        }
        $.get(`//${window.location.host}/private/api/contact_search.php?${query}`).done(function(res) {
          contactData.element = {};
          const selectorContact = 'contact';
          const selectorCompany = 'company';
          contactData.element.id = $(res).find(`${selectorContact} > id`).eq(0).text();
          contactData.element.company= $(res).find(`${selectorContact} > ${selectorCompany} > name`).eq(0).text();
          if(contactData.element.id === ''){
            contactData.element.id     = '';
            contactData.element.name   = '';
            callback(contactData)
          }else{
            $.get(`//${window.location.host}/api/v4/contacts/${contactData.element.id}`, function( data ) {
              contactData.element.id     = data.id;
              contactData.element.name   = data.name;
              contactData.custom_fields_values   = data.custom_fields_values;
              callback(contactData)
            });
          }

        });
      },
      onClickPhone: function (params) {
        if ( self.settings.pbxHost && self.settings.currentPhone !== '') {
          let postParams = {
            'action':       'callback',
            'number':       params.value,
            'user-number':  self.settings.currentPhone,
            'user-id':      self.settings.currentUser
          };
          PubSub.publish(self.ns + ':connector', postParams);
        }
      },
    };
    self.call_result = function (phone) {
      let intPhone    = phone.match(/\d+/g).join('');
      if(intPhone.length < 5){
        return;
      }
      let phonePretty = phone;
      let params = {
        phone: phone,
        phonePretty: phonePretty,
        intPhone: intPhone,
        lang: self.langs
      };
      let callback = function (template) {
        if(self.stickers[intPhone] !== undefined){
          return;
        }
        let markup = template.render(params);

        self.stickers[intPhone] = new Modal({
          class_name: 'modal-window', init: function ($modal_body) {
            $modal_body.trigger('modal:loaded')
                .html(markup)
                .trigger('modal:centrify');
          }, destroy: function () {
            delete self.stickers[phone];
          }
        });
        let funcClose = function (){
          $(`#miko-sticker-${intPhone} button.miko-close-btn`).trigger("click");
        }

        $(`#miko-sticker-${intPhone} button.miko-save-btn`).click(function (e) {
              e.preventDefault();
              let form = $(this).parent('form');
              let data = {
                clientName: form.find('#client-name').val(),
                number: form.find('#phone').val(),
                email: form.find('#el-adr').val(),
                comment: form.find('#comment').val(),
              }
              self.api.findContact(data, (result)=>{
                  if(result.element.id !== ''){
                    // Такой контакт уже существует.
                    form.find('#contactId').val(result.element.id);
                    form.find('div.ui.message.red p').html(`<a href="/contacts/detail/${result.element.id}" class="js-navigate-link">${result.element.name}</a>`);
                    form.find('div.ui.message.red').removeClass('hidden');
                    form.find('button.miko-save-btn').addClass('hidden');
                    form.find('button.miko-update-btn').removeClass('hidden');
                  }else{
                    self.api.createContact(data);
                    setTimeout(funcClose, 1000);
                    delete self.stickers[intPhone];
                  }
              });
            }
        );
        $(`#miko-sticker-${intPhone} button.miko-close-btn`).click(function (e) {
              e.preventDefault();
              $(this).parents('.modal.modal-window').remove();
              delete self.stickers[intPhone];
            }
        );
        $(`#miko-sticker-${intPhone} button.miko-update-btn`).click(function (e) {
              e.preventDefault();
              let form =  $(this).parent('form');
              let id = form.find('#contactId').val();

              $.get(`//${window.location.host}/api/v4/contacts/${id}`, function( data ) {
                let number = form.find('#phone').val().match(/\d+/g).join('');
                let email = form.find('#el-adr').val();
                let needPhone=(number !== '');
                let needEmail=(email !== '');
                $.each(data.custom_fields_values, function(index, fData){
                  if(needPhone!==false && fData.field_code === 'PHONE'){
                      $.each(fData.values, function(fIndex, rowData){
                        if(number === rowData.value.match(/\d+/g).join('')){
                          needPhone = false;
                        }
                      });
                  }
                  if(needEmail!==false && fData.field_code === "EMAIL"){
                    $.each(fData.values, function(fIndex, rowData){
                      if(email === rowData.value){
                        needEmail = false;
                      }
                    });
                  }
                });

                $.each(data.custom_fields_values, function(index, fData){
                  if(needPhone!==false && fData.field_code === 'PHONE'){
                    fData.values.push({'value': number});
                  }
                  if(needEmail!==false && fData.field_code === "EMAIL"){
                    fData.values.push({'value': email});
                  }
                });

                let comment = form.find('#comment').val().trim();
                if(comment !== ''){
                  self.api.addNotesContact(id, comment);
                }
                if(needEmail === false && needPhone === false){
                  self.api.gotoContact(id);
                  setTimeout(funcClose, 500);
                  delete self.stickers[intPhone];
                  return;
                }

                $.ajax({
                  url:'//' + window.location.host + "/api/v4/contacts",
                  type:"PATCH",
                  data:JSON.stringify([data]),
                  contentType:'application/json;charset=utf-8',
                  success: function(result){
                    self.api.gotoContact(id);
                    setTimeout(funcClose, 500);
                    delete self.stickers[intPhone];
                  },
                  error: (jqXHR, textStatus, errorThrown) => {
                    console.debug(jqXHR, textStatus, errorThrown);
                    delete self.stickers[intPhone];
                  }
                });

              });
            }
        );
      };
      self.render({
            href: '/templates/sticker.twig',
            base_path: self.params.path,
            load: callback
          },
          params
      );
    }
    return self;
  };
});