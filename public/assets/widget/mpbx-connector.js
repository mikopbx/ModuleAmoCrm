/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2021 Alexey Portnov and Nikolay Beketov
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

/* global define, AMOCRM */

define(function (require) {
    let self    = null;
    let $       = require('jquery');
    const PubSub= require('pubsub');
    let pbxHost, iFrame;

    let getUserPhone = function (user){
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
    }

    let onResize = function (){
        $('iframe[id="miko-pbx-phone"]').attr('height', $(window).height());
    }

    let onMessage = function(event) {
        if(location.protocol+`//${pbxHost}` !== event.origin){
            return;
        }
        let data = JSON.parse(event.data);
        if(data === 'init-done'){
            let settings = {
                currentUser:  AMOCRM.constant('user').id,
                currentPhone: getUserPhone(AMOCRM.constant('user').id),
                pbxHost:      pbxHost,
                users:        self.get_settings().pbx_users,
                token:        self.get_settings().token,
                time_unit:    self.i18n('settings.time_unit')
            };
            iFrame.contentWindow.postMessage({action: 'connect', data: settings}, '*');
            return;
        }else if(data.action === 'findContact'){
            iFrame.contentWindow.postMessage({action: 'updateContact', data: {number: data.number, contact: 'МИКО ООО', id: '23233'} }, '*');
        }else if(data.action === 'openCard'){
            // Открыть карточку клиента.
        }
        console.debug( "received: ", event);
    };

    return function(context) {
        if (!self && context) {
            self = context;
        }
        pbxHost = self.get_settings().miko_pbx_host || false;
        if(pbxHost){
            let href = `//${pbxHost}/webrtc-phone/index.html?random=${(new Date()).getTime()}`;
            if ($('iframe[src="' + href +'"').length < 1) {
                let css = 'position: fixed; z-index: 99; right: 0;bottom: 0; border: 0;';
                //  Подключаем файл style.css передавая в качестве параметра версию виджета
                $("body").prepend(`<iframe id="miko-pbx-phone" src="${href}" width="300" height="${$(window).height()}" style="${css}"></iframe>`);
            }
            onResize();
            $(window).resize(onResize);
            iFrame = document.getElementById('miko-pbx-phone');
            iFrame.onerror = function (){
                console.debug('MikoPBX', 'Error load iframe');
                $('#miko-pbx-phone').hide();
            }
            window.addEventListener("message", onMessage);
        }
        PubSub.subscribe(self.ns + ':connector', function (msg, message) {
            iFrame.contentWindow.postMessage(message, '*');
        });
        return {};
    };
});

/*
curl 'https://127.0.0.1/pbxcore/api/nchan/sub/calls?token=test' \
-X 'GET' \
-H 'Accept: text/event-stream' \
-H 'Cache-Control: no-cache' \
-H 'Origin: null' \
-H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Safari/605.1.15' \
-H 'Pragma: no-cache' -k
curl 'https://boffart.ru/pbxcore/api/nchan/sub/calls?token=201' \
-X 'GET' \
-H 'Accept: text/event-stream' \
-H 'Cache-Control: no-cache' \
-H 'Origin: null' \
-H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Safari/605.1.15' \
-H 'Pragma: no-cache' -k

curl -k --request POST --data "test message" -H "Accept: text/json" https://172.16.156.223/pbxcore/api/nchan/pub/calls


curl 'https://127.0.0.1/pbxcore/api/nchan/sub/active-calls?token=test' \
-X 'GET' \
-H 'Accept: text/event-stream' \
-H 'Cache-Control: no-cache' \
-H 'Origin: null' \
-H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Safari/605.1.15' \
-H 'Pragma: no-cache' -k

curl -k --request POST --data "test message" -H "Accept: text/json" https://172.16.156.223/pbxcore/api/amo/pub/active-calls


curl 'http//172.16.156.223/pbxcore/api/nchan/sub/calls?token=test' \
-X 'GET' \
-H 'Accept: text/event-stream' \
-H 'Cache-Control: no-cache' \
-H 'Origin: null' \
-H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Safari/605.1.15' \
-H 'Pragma: no-cache' -k


curl -k --request POST --data "test message" -H "Accept: text/json" https://172.16.156.223/pbxcore/api/amo/pub/active-calls
*/