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
    // Открытие карточки между вкладками amoCRM. Те же ключи и формат — в sites/webrtc-phone/index.html.
    const CARD_DONE_PREFIX = 'mikopbx:open-card:'; // + id звонка => время открытия
    const CARD_DONE_TTL    = 600000;               // Карточка звонка уже открыта: не открывать повторно
    const CARD_PENDING_KEY = 'mikopbx:pending-card';
    const CARD_PENDING_TTL = 300000;               // Отложенная карточка ждёт переключения в amoCRM 5 минут
    const CARD_HIDDEN_WAIT = 400;                  // Скрытая вкладка уступает видимой
    const CARD_ENTITIES    = ['leads', 'contacts', 'companies'];

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
        onVisible:  null,
        onStorage:  null,
    };

    function storage() {
        try {
            return window.localStorage || null;
        } catch (e) {
            return null;
        }
    }

    function isCardDone(ls, key, now) {
        const at = parseInt(ls.getItem(CARD_DONE_PREFIX + key) || '0', 10);
        return now - at < CARD_DONE_TTL;
    }

    function markCardDone(ls, key, now) {
        ls.setItem(CARD_DONE_PREFIX + key, String(now));
        for (let i = ls.length - 1; i >= 0; i--) {
            const k = ls.key(i);
            if (k && k.indexOf(CARD_DONE_PREFIX) === 0
                && now - parseInt(ls.getItem(k) || '0', 10) > CARD_DONE_TTL) {
                ls.removeItem(k);
            }
        }
    }

    /**
     * @return {?{key: string, entity: string, id: string, responsible: *, phone: string, ts: number}}
     */
    function readPendingCard(ls) {
        let card;
        try {
            card = JSON.parse(ls.getItem(CARD_PENDING_KEY) || 'null');
        } catch (e) {
            return null;
        }
        if (!card || typeof card.key !== 'string' || CARD_ENTITIES.indexOf(card.entity) < 0
            || !/^\d+$/.test(String(card.id)) || typeof card.ts !== 'number') {
            return null;
        }
        return card;
    }

    /**
     * Открыть карточку в этой вкладке и отметить звонок, чтобы другие вкладки её не открывали.
     */
    /**
     * Занять карточку для этой вкладки: отметить звонок и снять его из отложенных.
     * @return {boolean} false — localStorage недоступен
     */
    function claimCard(ls, card) {
        try {
            markCardDone(ls, card.key, Date.now());
            const pending = readPendingCard(ls);
            if (pending && pending.key === card.key) {
                ls.removeItem(CARD_PENDING_KEY);
            }
            return true;
        } catch (e) {
            return false;
        }
    }

    /**
     * Открыть отложенную карточку, если вкладка видима, карточка свежая и принадлежит текущему
     * пользователю (localStorage общий для браузера и переживает смену пользователя amoCRM).
     * Вызывается только по visibilitychange и storage — не при загрузке страницы: открытая
     * пользователем ссылка важнее отложенной карточки.
     */
    function openPendingCard(open, currentUser) {
        const ls = storage();
        if (!ls || document.hidden) {
            return;
        }
        let card;
        try {
            card = readPendingCard(ls);
            if (!card) {
                return;
            }
            const now = Date.now();
            if (now - card.ts > CARD_PENDING_TTL || isCardDone(ls, card.key, now)) {
                ls.removeItem(CARD_PENDING_KEY);
                return;
            }
        } catch (e) {
            return;
        }
        if (String(card.responsible) !== String(currentUser())) {
            return;
        }
        if (claimCard(ls, card)) {
            open(card.entity, card.id);
        }
    }

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
         * Открыть карточку звонка один раз на все вкладки amoCRM этого браузера.
         * Видимая вкладка открывает сразу. Если видимой нет, карточка откладывается и
         * открывается во вкладке, на которую пользователь переключится (не позже 5 минут).
         * @param {{key: string, entity: string, id: string, responsible: *, phone: string}} card
         * @param {function(string, string)} open (entity, id)
         */
        showCard: function (card, open) {
            const ls = storage();
            if (!ls) {
                // Без localStorage вкладки не договориться — открываем, как до дедупликации.
                open(card.entity, card.id);
                return;
            }
            // 'open' | 'skip' | 'wait'; ошибки localStorage -> 'open' без дедупликации.
            const decide = function (final) {
                try {
                    const now = Date.now();
                    if (isCardDone(ls, card.key, now)) {
                        return 'skip';
                    }
                    if (!document.hidden) {
                        claimCard(ls, card);
                        return 'open';
                    }
                    if (final) {
                        ls.setItem(CARD_PENDING_KEY, JSON.stringify($.extend({}, card, {ts: now})));
                    }
                    return 'wait';
                } catch (e) {
                    return 'open';
                }
            };
            const result = decide(false);
            if (result === 'open') {
                open(card.entity, card.id);
            } else if (result === 'wait') {
                setTimeout(function () {
                    if (decide(true) === 'open') {
                        open(card.entity, card.id);
                    }
                }, CARD_HIDDEN_WAIT);
            }
        },

        /**
         * Открывать отложенную карточку, когда пользователь переключается в эту вкладку
         * (visibilitychange) или когда её откладывает другая вкладка, а эта уже видима
         * (storage: эта вкладка могла не получить событие, пока переподключался сокет).
         * @param {function(string, string)} open (entity, id)
         * @param {function(): *} currentUser id текущего пользователя amoCRM
         */
        watchPendingCards: function (open, currentUser) {
            this.unwatchPendingCards();
            state.onVisible = function () {
                openPendingCard(open, currentUser);
            };
            state.onStorage = function (event) {
                if (event.key === CARD_PENDING_KEY && event.newValue) {
                    openPendingCard(open, currentUser);
                }
            };
            document.addEventListener('visibilitychange', state.onVisible);
            window.addEventListener('storage', state.onStorage);
        },

        unwatchPendingCards: function () {
            if (state.onVisible) {
                document.removeEventListener('visibilitychange', state.onVisible);
                state.onVisible = null;
            }
            if (state.onStorage) {
                window.removeEventListener('storage', state.onStorage);
                state.onStorage = null;
            }
        },
    };
});
