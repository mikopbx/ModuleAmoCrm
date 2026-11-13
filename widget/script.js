/* global define, APP */
define(function (require) {
  const connector = require('./js/mpbx-connector.js?v=%WidgetVersion%');
  const $         = require('jquery');

  const ENTITY_ID = /^\d+$/;

  return function () {
    const self = this;

    self.settings = {
      currentUser:  '',
      currentPhone: '',
      pbxHost:      '',
      token:        '',
    };

    /**
     * pbx_users приходит объектом или JSON-строкой: {amoUserId: "внутренний номер"}.
     */
    function parseUsers(raw) {
      if (!raw) {
        return {};
      }
      if (typeof raw === 'string') {
        try {
          raw = JSON.parse(raw);
        } catch (e) {
          return {};
        }
      }
      return (raw && typeof raw === 'object') ? raw : {};
    }

    function showError(code) {
      const errors = (self.langs && self.langs.errors) || {};
      APP.notifications.show_message_error({
        header: errors.alert || 'MikoPBX',
        text:   errors[code] || errors.unknown || code,
      });
    }

    self.api = {
      /**
       * Применить настройки виджета и (пере)подключиться к АТС.
       */
      applySettings: function (pbxHost, token, users) {
        const currentUser = APP.constant('user').id;
        const phone       = parseUsers(users)[currentUser];
        self.settings.currentUser  = currentUser;
        self.settings.currentPhone = (phone === undefined || phone === null) ? '' : String(phone).trim();
        self.settings.pbxHost      = String(pbxHost || '').trim();
        self.settings.token        = String(token || '').trim();
        connector.init(
          {pbxHost: self.settings.pbxHost, token: self.settings.token},
          {onOpenCard: self.api.onOpenCard, onError: showError},
          self.settings.currentPhone !== ''
        );
      },

      /**
       * Событие open-card от АТС: входящий отвечен, контакт / сделка / компания известны.
       * Событие получают все сотрудники, карточку открывает только ответственный.
       */
      onOpenCard: function (data) {
        if (String(data.responsible) !== String(self.settings.currentUser)) {
          return;
        }
        let entity = '';
        let id     = '';
        if (ENTITY_ID.test(String(data.lead || ''))) {
          entity = 'leads';
          id     = String(data.lead);
        } else if (ENTITY_ID.test(String(data.client || ''))) {
          entity = 'contacts';
          id     = String(data.client);
        } else if (ENTITY_ID.test(String(data.company || ''))) {
          entity = 'companies';
          id     = String(data.company);
        } else {
          return;
        }
        const key = String(data.id || data.uniq || `${data.phone}:${data.responsible}`);
        connector.once(key, function () {
          self.api.gotoEntity(entity, id);
        });
      },

      /**
       * Переход через роутер amoCRM (без перезагрузки страницы).
       */
      gotoEntity: function (entity, id) {
        const $link = $('<a></a>')
          .attr('href', `/${entity}/detail/${id}`)
          .addClass('js-navigate-link')
          .css('display', 'none');
        $('body').prepend($link);
        $link.trigger('click');
        $link.remove();
      },

      onClickPhone: function (params) {
        if (!self.settings.pbxHost || !self.settings.token) {
          showError('errorNoSettings');
          return;
        }
        if (self.settings.currentPhone === '') {
          showError('errorNoPhone');
          return;
        }
        connector.callback(params.value, self.settings.currentPhone, self.settings.currentUser);
      },

      /**
       * Ссылки на записи в примечаниях звонков: подставить адрес АТС из настроек.
       */
      bindRecordLinks: function () {
        $(document).off('mousedown.mikoWidget').on('mousedown.mikoWidget', 'div.feed-note__call-content a', function () {
          if ($(this).parent().find('a[data-prepare="miko-pbx"]').length === 0) {
            return;
          }
          const pbxHost = self.settings.pbxHost.replace(/^https?:\/\//, '').replace(/\/+$/, '');
          if (pbxHost === '') {
            return;
          }
          // pbxHost может содержать путь (host:port/token): сравниваем префикс URL, а не URL.host,
          // иначе каждый mousedown добавляет ещё один /token и ссылка ломается.
          const target = 'https://' + pbxHost + '/';
          $(this).each(function () {
            const oldUrl = $(this).attr('href') || '';
            if (oldUrl.indexOf(target) === 0) {
              return;
            }
            const rest = oldUrl.replace(/^https?:\/\/[^/]+\/?/, '');
            if (rest === oldUrl) {
              return;
            }
            $(this).attr('href', target + rest);
          });
        });
      },
    };

    self.callbacks = {
      render: function () {
        return true;
      },
      init: function () {
        const settings = self.get_settings();
        self.api.applySettings(settings.miko_pbx_host, settings.token, settings.pbx_users);
        self.add_action('phone', self.api.onClickPhone);
        self.api.bindRecordLinks();
        return true;
      },
      bind_actions: function () {
        return true;
      },
      settings: function () {
        const wCode = self.params.widget_code;
        const boldStyle = {
          'font-weight': 'bold',
          'color':       '#61a0e1',
        };
        $(`.${wCode} div.widget_settings_block__title_field:not(.widget_settings_block_users__title_field)`).css(boldStyle);
        $(`.${wCode} .widget_settings_block strong`).css(boldStyle);
        $(`.${wCode} .widget_settings_block a`).css({'color': '#61a0e1'});
        return true;
      },
      onSave: function (data) {
        const fields  = (data && data.fields) || {};
        // Поле, не пришедшее в fields, не меняем: иначе пустой токен отключит виджет до перезагрузки.
        // Запасное значение — сохранённые настройки виджета, затем то, что уже применено.
        const stored  = self.get_settings() || {};
        const pick    = function (key, applied) {
          if (fields[key] !== undefined) {
            return fields[key];
          }
          return stored[key] !== undefined && stored[key] !== '' ? stored[key] : applied;
        };
        const pbxUsers = pick('pbx_users', undefined);
        self.api.applySettings(
          pick('miko_pbx_host', self.settings.pbxHost),
          pick('token', self.settings.token),
          pbxUsers
        );
        const users = parseUsers(pbxUsers);
        if (Object.keys(users).length > 0) {
          $.get('/api/v4/account')
            .done(function (account) {
              connector.saveSettings(users, account.id);
            })
            .fail(function () {
              showError('errorSaveSettings');
            });
        }
        return true;
      },
      destroy: function () {
        connector.destroy();
        $(document).off('mousedown.mikoWidget');
      },
    };
    return self;
  };
});
