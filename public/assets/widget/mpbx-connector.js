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
    let $       = require('jquery');
    const PubSub= require('pubsub');

    let connector = {
        settings: {},
        iFrame: null,
        resizeFunc: function (height){
            if(height === undefined){
                height = $(window).height();
            }
            $('iframe[id="miko-pbx-phone"]').attr('height', height);
        },
        init: function(newSettings) {
            connector.settings = newSettings;
            if(connector.settings.pbxHost){
                let href = `//${connector.settings.pbxHost}/webrtc-phone/index.html?random=${(new Date()).getTime()}`;
                $.ajax(href, {
                    complete: function(xhr) {
                        if(xhr.status !== 200){
                            return;
                        }
                        if ($('iframe[src="' + href +'"').length < 1) {
                            let css = 'position: fixed; z-index: 999; right: 0;bottom: 0; border: 0;';
                            //  Подключаем файл style.css передавая в качестве параметра версию виджета
                            $("body").prepend(`<iframe id="miko-pbx-phone" src="${href}" width="300" height="${$(window).height()}" style="${css}"></iframe>`);
                        }
                        connector.iFrame = document.getElementById('miko-pbx-phone');
                        connector.iFrame.onerror = function (){
                            console.debug('MikoPBX', 'Error load iframe');
                            $('#miko-pbx-phone').hide();
                        }
                        window.addEventListener("message", connector.onMessage);

                        $(window).resize(() => {
                            connector.resizeFunc();
                            connector.iFrame.contentWindow.postMessage({action: 'resize', height: $(window).height()}, '*');
                        });
                        $(window).mousemove(event => {
                            if( $(window).width() - event.pageX < 5){
                                $('#miko-pbx-phone').show();
                            }
                        });
                    }
                });
            }
            PubSub.subscribe(connector.settings.ns + ':connector', connector.onMessage);
        },
        onMessage: function(event, message = null) {
            if( typeof event.origin !== 'undefined'
                && location.protocol+`//${connector.settings.pbxHost}` !== event.origin){
                return;
            }
            let params = message || JSON.parse(event.data);
            if(params.action === 'init-done'){
                connector.resizeFunc();
                connector.iFrame.contentWindow.postMessage({action: 'connect', data: connector.settings}, '*');
            }else if(params.action === 'findContact'){
                PubSub.publish(connector.settings.ns + ':main', params);
            }else if(params.action === 'resultFindContact'){
                let result = {
                    number:  params.number,
                    contact: params.element.name,
                    company: params.element.company,
                    id:      params.element.company
                };
                connector.iFrame.contentWindow.postMessage({action: 'updateContact', data:  result}, '*');
            }else if(params.action === 'openCard'){
                // Открыть карточку клиента.
                PubSub.publish(connector.settings.ns + ':main', params);
            }else if(params.action === 'hide-panel'){
                $('#miko-pbx-phone').hide();
            }else if(params.action === 'resize'){
                connector.resizeFunc(params.height);
            }else{
                connector.iFrame.contentWindow.postMessage(message, '*');
            }
        }
    };
    return connector;
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


curl 'http://172.16.156.223/pbxcore/api/nchan/sub/calls?token=test' \
-X 'GET' \
-H 'Accept: text/event-stream' \
-H 'Cache-Control: no-cache' \
-H 'Origin: null' \
-H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Safari/605.1.15' \
-H 'Pragma: no-cache' -k


curl -k --request POST --data "test message" -H "Accept: text/json" https://172.16.156.223/pbxcore/api/amo/pub/active-calls
*/