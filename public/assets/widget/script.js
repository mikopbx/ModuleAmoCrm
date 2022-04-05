define(function (require) {
  const connector = require('./mpbx-connector.js?v=1.0.39');
  const $         = require('jquery');
  const PubSub    = require('pubsub');
  let   self;

  return function () {
    self = this;
    self.createContact = function (notifications_data){
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
            window.location = '/contacts/detail/'+ result._embedded.contacts[0].id;
          }
        }
      });
    };
    self.findContact = function (notifications_data, callback){
      $.get('//' + window.location.host + '/private/api/contact_search.php?SEARCH=' + notifications_data.number).done(function(res) {
        notifications_data.element = {};
        notifications_data.element.id = $(res).find('contact > id').eq(0).text();
        notifications_data.element.name = $(res).find('contact > name').eq(0).text();
        notifications_data.element.company = $(res).find('contact > company > name').eq(0).text();
        callback(notifications_data)
      });
    }
    self.addCallNotify = function (data) {
      let n_data = {
        to: data.to,
        date: Math.ceil(Date.now() / 1000),
      };
      n_data.header = '' + self.langs.calls[data.type];
      if (data.element.id > 0) {
        n_data.text = data.element.name + '<br>'+
                      '<a data-phone="'+data.number+'" href="/contacts/detail/' + data.element.id + '">'+self.langs.contacts.goto_contact+'</a>';
      } else {
        n_data.text = '' + data.number + '<br>' +
                      '<a data-phone="'+data.number+'" class="miko-pbx-create-contact-link">'+self.langs.contacts.create_contact+'</a>';
      }
      self.removeOldNotify(data.number);
      AMOCRM.notifications.add_call(n_data);
    };
    self.removeOldNotify = function (phone) {
      $('.miko-pbx-create-contact-link[data-phone="'+phone+'"]').each(function( index ) {
        let id = $( this ).parents(".notification__item.notification-inner").attr('data-id');
        $.ajax({
          url:'//' + window.location.host + "/v3/inbox/delete",
          type:"POST",
          data:JSON.stringify({'id': [id],"all":false}),
          contentType:'application/json;charset=utf-8',
          success: function(){
            console.log("Data Loaded: ", this);
          }
        })
      });
    };

    self.getUserPhone = function (user){
      let phone        = '';
      let settings     = self.get_settings();
      let phones       = settings.pbx_users || false;
      if (typeof phones == 'string') {
        phones = phones ? $.parseJSON(phones) : false;
      }
      if (phones && typeof phones[user] !== 'undefined') {
        phone = phones[user];
      }
      return phone;
    };

    self.onClickPhone = function (params) {
      let settings     = self.get_settings();
      let current_user = AMOCRM.constant('user').id;
      let host         = settings.miko_pbx_host || false;
      let userPhone    = self.getUserPhone(current_user);
      if (host && userPhone !== '') {
        let postParams = {
          'action':       'callback',
          'number':       params.value,
          'user-number':  userPhone,
          'user-id':      current_user
        };
        PubSub.publish(self.ns + ':connector', postParams);
      }
    };

    this.callbacks = {
      render: function () {
        return true;
      },
      init: function () {
        self.connector = connector(self);
        self.add_action("phone", self.onClickPhone);
        return true;
      },
      bind_actions: function () {
        $(document).on(AMOCRM.click_event + self.ns, '.miko-pbx-create-contact-link', function(){
          self.findContact({'number': $(this).attr('data-phone')}, function(data){
            if(data.element.id === ''){
              // Контакт НЕ найден.
              self.createContact(data);
            }else{
              // контакт найден.
              window.location = '/contacts/detail/'+data.element.id;
            }
          })
        });
        return true;
      },
      settings: function () {
        return true;
      },
      onSave: function () {
        let phones = self.get_settings().pbx_users || false;
        if (typeof phones == 'string') {
          phones = phones ? $.parseJSON(phones) : false;
        }
        PubSub.publish(self.ns + ':connector', {'users': phones, action: 'saveSettings'});
        return true;
      },
      destroy: function () {
        console.log('destroy func');
      },
      contacts: {
        //select contacts in list and clicked on widget name
        selected: function () {
          console.log('contacts');
        }
      },
      leads: {
        //select leads in list and clicked on widget name
        selected: function () {
          console.log('leads');
        }
      },
      tasks: {
        //select taks in list and clicked on widget name
        selected: function () {
          console.log('tasks');
        }
      },
    };
    return this;
  };
});