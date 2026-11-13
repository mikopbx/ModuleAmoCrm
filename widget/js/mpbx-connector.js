/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

/* global define */

/**
 * Связь виджета с MikoPBX:
 *  - WebSocket nchan `pbx-events`, из событий используется только `open-card`;
 *  - REST: /callback (звонок из карточки), /change-settings (сопоставление пользователей),
 *    /panel-enable (проверка токена: 403 — токен не принят).
 */
define(['jquery'], function ($) {
    const RECONNECT_MIN   = 1000;
    const RECONNECT_MAX   = 60000;
    const DOWN_ALERT_AFTER= 60000;   // Сообщать о потере связи, если её нет дольше минуты.
    const ALERT_INTERVAL  = 300000;  // Фоновые ошибки — не чаще раза в 5 минут.
    const CARD_KEY_PREFIX = 'mikopbx:open-card:'; // Тот же префикс в sites/webrtc-phone/index.html.
    const CARD_KEY_TTL    = 60000;
    const CARD_KEY_PURGE  = 300000;

    const state = {
        host:       '',
        token:      '',
        handlers:   {},
        ws:         null,
        timer:      null,
        delay:      RECONNECT_MIN,
        downSince:  0,
        authFailed: false,
        lastAlert:  0,
        stopped:    true,
    };

    /**
     * host[:port][/token] без схемы и завершающих слешей.
     */
    function normalizeHost(host) {
        return String(host || '').trim().replace(/^[a-z]+:\/\//i, '').replace(/\/+$/, '');
    }

    function httpProtocol() {
        return window.location.protocol === 'http:' ? 'http:' : 'https:';
    }

    function apiUrl(path) {
        return `${httpProtocol()}//${state.host}/pbxcore/api/amo-crm/v1/${path}`;
    }

    /**
     * @param {string} code ключ из i18n errors
     * @param {boolean} force true — ответ на действие пользователя, без ограничения частоты
     */
    function notify(code, force) {
        const now = Date.now();
        if (!force && now - state.lastAlert < ALERT_INTERVAL) {
            return;
        }
        state.lastAlert = now;
        if (typeof state.handlers.onError === 'function') {
            state.handlers.onError(code);
        }
    }

    // data.code отказа сервера -> ключ i18n errors.
    const REJECT_CODES = {
        NOT_MAPPED: 'errorNoPhone',
        NO_NUMBER:  'errorBadNumber',
    };

    /**
     * Отказ сервера в теле ответа: новые ядра отдают его с HTTP 422, старые — с 200 и result=false.
     * @return {string} ключ i18n или '' если отказа нет
     */
    function rejection(body) {
        if (!body || body.result !== false) {
            return '';
        }
        const code = body.data && body.data.code;
        return REJECT_CODES[code] || 'unknown';
    }

    function request(path, data) {
        if (!state.host || !state.token) {
            return $.Deferred().reject().promise();
        }
        return $.ajax({
            url:      apiUrl(path),
            type:     'POST',
            timeout:  10000,
            data:     $.extend({}, data, {token: state.token}),
        }).done(function (body) {
            const code = rejection(body);
            if (code) {
                notify(code, true);
            }
        }).fail(function (xhr) {
            if (xhr.status === 403) {
                notify('errorAuthAPI', true);
                return;
            }
            notify(rejection(xhr.responseJSON) || 'errorConnect', true);
        });
    }

    function checkAuth() {
        $.ajax({
            url:     apiUrl('panel-enable'),
            type:    'GET',
            timeout: 10000,
            data:    {token: state.token},
        }).fail(function (xhr) {
            if (xhr.status === 403) {
                state.authFailed = true;
                notify('errorAuthAPI');
            }
        });
    }

    function onPbxMessage(event) {
        let message;
        try {
            message = JSON.parse(event.data);
        } catch (e) {
            return;
        }
        if (!message || message.action !== 'open-card' || !message.data) {
            return;
        }
        if (typeof state.handlers.onOpenCard === 'function') {
            state.handlers.onOpenCard(message.data);
        }
    }

    function scheduleReconnect() {
        clearTimeout(state.timer);
        state.timer = setTimeout(connect, state.delay);
        state.delay = Math.min(state.delay * 2, RECONNECT_MAX);
    }

    function onClose() {
        if (state.stopped) {
            return;
        }
        const now = Date.now();
        if (state.downSince === 0) {
            state.downSince = now;
            checkAuth();
        } else if (!state.authFailed && now - state.downSince > DOWN_ALERT_AFTER) {
            notify('errorAPITimeOut');
        }
        scheduleReconnect();
    }

    function closeSocket() {
        clearTimeout(state.timer);
        state.timer = null;
        if (state.ws) {
            state.ws.onopen = state.ws.onmessage = state.ws.onclose = state.ws.onerror = null;
            try {
                state.ws.close();
            } catch (e) {
                // Сокет уже закрыт.
            }
            state.ws = null;
        }
    }

    function connect() {
        if (state.stopped) {
            return;
        }
        closeSocket();
        const protocol = httpProtocol() === 'http:' ? 'ws:' : 'wss:';
        const url = `${protocol}//${state.host}/pbxcore/api/nchan/sub/pbx-events?token=${encodeURIComponent(state.token)}`;
        try {
            state.ws = new WebSocket(url);
        } catch (e) {
            notify('errorConnect');
            scheduleReconnect();
            return;
        }
        state.ws.onopen = function () {
            state.delay      = RECONNECT_MIN;
            state.downSince  = 0;
            state.authFailed = false;
        };
        state.ws.onmessage = onPbxMessage;
        // onerror всегда сопровождается onclose — переподключение там.
        state.ws.onclose = onClose;
    }

    return {
        /**
         * @param {{pbxHost: string, token: string}} settings
         * @param {{onOpenCard: function(Object), onError: function(string)}} handlers
         * @param {boolean} listen подписаться на события АТС. Карточку открывает только
         *        ответственный с внутренним номером: без номера сокет не нужен, а REST
         *        (сохранение настроек администратором) остаётся доступен.
         */
        init: function (settings, handlers, listen) {
            this.destroy();
            state.host       = normalizeHost(settings.pbxHost);
            state.token      = String(settings.token || '').trim();
            state.handlers   = handlers || {};
            state.delay      = RECONNECT_MIN;
            state.downSince  = 0;
            state.authFailed = false;
            if (!state.host || !state.token) {
                return;
            }
            if (listen === false) {
                // Без сокета неверный токен иначе не обнаружится (проверка идёт из onClose).
                checkAuth();
                return;
            }
            state.stopped = false;
            connect();
        },

        destroy: function () {
            state.stopped = true;
            closeSocket();
        },

        /**
         * Звонок из карточки: АТС вызывает сотрудника, затем набирает номер.
         */
        callback: function (number, userNumber, userId) {
            return request('callback', {
                'action':      'callback',
                'number':      number,
                'user-number': userNumber,
                'user-id':     userId,
            });
        },

        /**
         * Передать на АТС сопоставление пользователей amoCRM и внутренних номеров.
         * users — объект {amoUserId: номер}: jQuery отправит users[id]=номер. Модули до v1.101
         * включительно принимают только массив, JSON-строку они молча игнорируют.
         */
        saveSettings: function (users, portalId) {
            return request('change-settings', {
                'action':   'saveSettings',
                'users':    users,
                'portalId': portalId,
            });
        },

        /**
         * Выполнить fn один раз для ключа во всех вкладках amoCRM этого браузера.
         * Видимая вкладка выполняет сразу, скрытые ждут, чтобы уступить ей.
         */
        once: function (key, fn) {
            const storageKey = CARD_KEY_PREFIX + key;
            setTimeout(function () {
                try {
                    const now  = Date.now();
                    const prev = parseInt(window.localStorage.getItem(storageKey) || '0', 10);
                    if (now - prev < CARD_KEY_TTL) {
                        return;
                    }
                    window.localStorage.setItem(storageKey, String(now));
                    for (let i = window.localStorage.length - 1; i >= 0; i--) {
                        const k = window.localStorage.key(i);
                        if (k && k.indexOf(CARD_KEY_PREFIX) === 0
                            && now - parseInt(window.localStorage.getItem(k) || '0', 10) > CARD_KEY_PURGE) {
                            window.localStorage.removeItem(k);
                        }
                    }
                } catch (e) {
                    // localStorage недоступен — открываем без дедупликации.
                }
                fn();
            }, document.hidden ? 400 : 0);
        },
    };
});
