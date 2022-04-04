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
    let self = null;
    let $ = require('jquery');
    let eventSource = null;
    let channels = {};

    let onPbxMessage = function(event) {
        let currentUser = AMOCRM.constant('user').id;
        let callData = $.parseJSON(event.data);
        let n_data = null;

        if( callData.action === 'call' && currentUser === callData.user && typeof channels[callData.uid] === 'undefined'){
            channels[callData.uid] = 1;
            let phone = getUserPhone(currentUser);
            if(phone === callData.src){
                n_data = {
                    to: AMOCRM.constant('user').name,
                    number: callData.dst,
                    type: 'outgoing'
                };
            }else{
                n_data = {
                    to: AMOCRM.constant('user').name,
                    number: callData.src,
                    type: 'incoming'
                };
            }
            self.findContact(n_data, self.addCallNotify);
        }else if(callData.action === 'hangup'){
            delete channels[callData.uid];
        }
    };
    let onPbxMessageError = function(event) {
        console.log("Error", event);
    };
    let checkConnection = function(){
        if(eventSource.readyState !== 1){
            console.log('Not connected to PBX', self.eventSource);
        }
    };

    let onSaveSettings = function () {
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
    };

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

    let onClickPhone = function (params) {
        let settings     = self.get_settings();
        let current_user = AMOCRM.constant('user').id;
        let host         = settings.miko_pbx_host || false;
        let userPhone = getUserPhone(current_user);
        if (host && userPhone !== '') {
            let postParams = {
                'action': 'call',
                'number': params.value,
                'user-number': userPhone,
                'user-id': current_user
            };
            $.post(`https://${host}/pbxcore/api/amo-crm/v1/callback`, postParams, function( data ) {
                console.log('result', data);
            });
        }else {
            // Вывести сообщение ош ошибке. self.langs
        }
    };

    return function(context) {
        if (!self && context) {
            self = context;
        }
        self.add_action("phone", onClickPhone);
        let pbxHost = self.get_settings().miko_pbx_host || false;
        if(pbxHost){
            eventSource = new EventSource(`https://${pbxHost}/pbxcore/api/nchan/sub/calls?token=test`, {
                withCredentials: false
            });
            eventSource.onmessage = onPbxMessage;
            eventSource.onerror   = onPbxMessageError;
            setInterval(checkConnection, 5000);

            let href = `//${pbxHost}/webrtc-phone/index.html?random=${(new Date()).getTime()}`;
            let height = $(window).height();
            if ($('iframe[src="' + href +'"').length < 1) {
                let css = 'position: fixed; z-index: 99; right: 0;bottom: 0; border: 0;';
                //  Подключаем файл style.css передавая в качестве параметра версию виджета
                $("body").prepend(`<iframe id="miko-pbx-phone" src="${href}" width="300" height="${height}" style="${css}"></iframe>`);
            }
            setInterval(function (){
                $('iframe[id="miko-pbx-phone"]').attr('height', $(window).height());
            }, 1000);

            let iFrame = document.getElementById('miko-pbx-phone');
            iFrame.onload = function(){
                setTimeout(function (){
                    iFrame.contentWindow.postMessage ('Сообщение, отправленное родительской страницей', '*');
                }, 2000)
            }
            window.addEventListener("message", function(event) {
                if(location.protocol+`//${pbxHost}` !== event.origin){
                    return;
                }
                console.log( "received: ", event);
            });
        }
        return {
            'eventSource':    eventSource,
            'onSaveSettings': onSaveSettings,
        };
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