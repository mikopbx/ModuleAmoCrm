define(function (require) {
  let $ = require('jquery'),
      connector = require('./mpbx-connector.js?v=1.0.24');

  return function () {
    let self = this;
    self.findContact = function (notifications_data){
      $.get('//' + window.location.host + '/private/api/contact_search.php?SEARCH=' + notifications_data.number , function(res) {
        notifications_data.element = {};
        notifications_data.element.id = $(res).find('contact > id').eq(0).text();
        notifications_data.element.name = $(res).find('contact > name').eq(0).text();
        notifications_data.element.company = $(res).find('contact > company > name').eq(0).text();
        self.addCallNotify(notifications_data);
      });
    }
    self.addCallNotify = function (data) {
      let n_data = {
        to: data.to,
        date: Math.ceil(Date.now() / 1000),
      };
      if (data.element.id > 0) {
        n_data.text = self.langs.contacts.call_title + ': ' + data.element.name + '. <a href="/contacts/detail/' + data.element.id + '">'+self.langs.contacts.goto_contact+'</a>';
        n_data.header = '' + self.langs.calls[data.type] + ': ' + data.number + ' ';
      } else {
        n_data.text = '<a href="/contacts/add/?phone=' + data.number + '">'+self.langs.contacts.create_contact+'</a>';
        n_data.header = '' + self.langs.calls[data.type] + ': ' + data.number;
      }
      AMOCRM.notifications.add_call(n_data);
    };

    this.callbacks = {
      render: function () {
        return true;
      },
      init: function () {
        self.connector = connector(self);
        return true;
      },
      bind_actions: function () {
        console.log('bind_actions');
        return true;
      },
      settings: function () {
        return true;
      },
      onSave: function () {
        self.connector.onSaveSettings();
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