/* global define, AMOCRM */
define(function (require) {
  const connector = require('./mpbx-connector.js?v=%WidgetVersion%');
  const $         = require('jquery');
  const PubSub    = require('pubsub');
  const _         = require('underscore');

  return function () {
    self = this;
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
      initMenuPage: _.bind(function (params) {
      }),
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
          PubSub.publish(self.ns + ':connector', {'users': phones , action: 'saveSettings'});
        }
        return true;
      },
      destroy: function () {
      },
      contacts: {
        //select contacts in list and clicked on widget name
        selected: function () {
        }
      },
      leads: {
        //select leads in list and clicked on widget name
        selected: function () {
        }
      },
      tasks: {
        //select taks in list and clicked on widget name
        selected: function () {
        }
      },
    };
    self.api = {
      init: function (){
        let globalSettings = self.get_settings();

        let currentPhone = '';
        let user   = AMOCRM.constant('user').id;
        let phones = globalSettings.pbx_users || false;
        if (typeof phones == 'string') {
          phones = phones ? $.parseJSON(phones) : false;
        }
        if (phones && typeof phones[user] !== 'undefined') {
          currentPhone = phones[user];
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
        }else if(message.action === 'openCard'){
          self.api.findContact(message.data, function(data){
            if(data.element.id === ''){
              // Контакт НЕ найден.
              self.api.createContact(data);
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
        let params = [
          {
            "name": notifications_data.number,
            "created_by": AMOCRM.constant('user').id,
            "custom_fields_values": [
              {
                'field_code': 'PHONE',
                'values': [
                  {'value': notifications_data.number}
                ]
              }
            ]
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
            }
          }
        });
      },
      findContact: function (notifications_data, callback){
        $.get(`//${window.location.host}/private/api/contact_search.php?SEARCH=${notifications_data.number}`).done(function(res) {
          notifications_data.element = {};
          const selectorContact = 'contact';
          const selectorCompany = 'company';
          notifications_data.element.id = $(res).find(`${selectorContact} > id`).eq(0).text();
          notifications_data.element.name = $(res).find(`${selectorContact} > name`).eq(0).text();
          notifications_data.element.company = $(res).find(`${selectorContact} > ${selectorCompany} > name`).eq(0).text();
          callback(notifications_data)
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
    return self;
  };
});