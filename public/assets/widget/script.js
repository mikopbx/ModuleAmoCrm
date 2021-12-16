define(['jquery', 'underscore', 'twigjs'], function ($, _, Twig) {
  return function () {
    let self = this;

    this.getTemplate = _.bind(function (template, params, callback) {
      params = (typeof params == 'object') ? params : {};
      template = template || '';

      return this.render({
        href: '/templates/' + template + '.twig',
        base_path: this.params.path,
        v: this.get_version(),
        load: callback
      }, params);
    }, this);

    this.callbacks = {
      render: function () {
        return true;
      },
      init: _.bind(function () {
        this.add_action("phone", function (params) {
          let settings     = self.get_settings();
          let current_user = AMOCRM.constant('user').id;
          let phones       = settings.pbx_users || false;
          let host         = settings.miko_pbx_host || false;

          if (typeof phones == 'string') {
            phones = phones ? $.parseJSON(phones) : false;
          }
          if (host && phones && typeof phones[current_user] !== 'undefined') {
            let postParams = {
              'action': 'call',
              'number': params.value,
              'user-number': phones[current_user],
              'user-id': current_user
            };
            $.post(`https://${host}/pbxcore/api/amo-crm/v1/callback`, postParams, function( data ) {
              console.log('result', data);
            });
          }else {
            // Вывести сообщение ош ошибке. self.langs
          }
        });

        return true;
      }, this),
      bind_actions: function () {
        console.log('bind_actions');
        window.AMOCRM.player_prepare[self.params.widget_code] = function ($el) {
          console.log($el)
          this.play($el, $el.attr('href'));
        };
        return true;
      },
      settings: function () {
        return true;
      },
      onSave: function () {
        let settings     = self.get_settings();
        let phones       = settings.pbx_users || false;
        let host         = settings.miko_pbx_host || false;
        if (typeof phones == 'string') {
          phones = phones ? $.parseJSON(phones) : false;
        }
        if (host && phones) {
          let postParams = {
            'users': phones,
          };
          $.post(`https://${host}/pbxcore/api/amo-crm/v1/change-settings`, postParams, function( data ) {
            console.log('result', data);
          });
        }else {
          // Вывести сообщение ош ошибке. self.langs
        }
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
      advancedSettings: _.bind(function () {
        var $work_area = $('#work-area-' + self.get_settings().widget_code),
            $save_button = $(
                Twig({ref: '/tmpl/controls/button.twig'}).render({
                  text: 'Сохранить',
                  class_name: 'button-input_blue button-input-disabled js-button-save-' + self.get_settings().widget_code,
                  additional_data: ''
                })
            ),
            $cancel_button = $(
                Twig({ref: '/tmpl/controls/cancel_button.twig'}).render({
                  text: 'Отмена',
                  class_name: 'button-input-disabled js-button-cancel-' + self.get_settings().widget_code,
                  additional_data: ''
                })
            );

        console.log('advancedSettings');

        $save_button.prop('disabled', true);
        $('.content__top__preset').css({float: 'left'});

        $('.list__body-right__top').css({display: 'block'})
            .append('<div class="list__body-right__top__buttons"></div>');
        $('.list__body-right__top__buttons').css({float: 'right'})
            .append($cancel_button)
            .append($save_button);

        self.getTemplate('advanced_settings', {}, function (template) {
          var $page = $(
              template.render({title: self.i18n('advanced').title, widget_code: self.get_settings().widget_code})
          );

          $work_area.append($page);
        });
      }, self),

      /**
       * Метод срабатывает, когда пользователь в конструкторе Salesbot размещает один из хендлеров виджета.
       * Мы должны вернуть JSON код salesbot'а
       *
       * @param handler_code - Код хендлера, который мы предоставляем. Описан в manifest.json, в примере равен handler_code
       * @param params - Передаются настройки виджета. Формат такой:
       * {
       *   button_title: "TEST",
       *   button_caption: "TEST",
       *   text: "{{lead.cf.10929}}",
       *   number: "{{lead.price}}",
       *   url: "{{contact.cf.10368}}"
       * }
       *
       * @return {{}}
       */
      onSalesbotDesignerSave: function (handler_code, params) {
        var salesbot_source = {
              question: [],
              require: []
            },
            button_caption = params.button_caption || "",
            button_title = params.button_title || "",
            text = params.text || "",
            number = params.number || 0,
            handler_template = {
              handler: "show",
              params: {
                type: "buttons",
                value: text + ' ' + number,
                buttons: [
                  button_title + ' ' + button_caption,
                ]
              }
            };

        console.log(params);

        salesbot_source.question.push(handler_template);

        return JSON.stringify([salesbot_source]);
      },
    };
    return this;
  };
});