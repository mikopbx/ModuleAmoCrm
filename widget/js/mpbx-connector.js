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

/* global define */
define(function (require) {
    let $       = require('jquery');
    const PubSub= require('pubsub');

    let connector = {
        settings: {},
        iFrame: null,
        /**
         * Sending a command to a frame
         * @param message
         */
        postToFrame: function (message){
            if(connector.iFrame !== null && typeof connector.iFrame.contentWindow === 'object'){
                connector.iFrame.contentWindow.postMessage(message, '*');
            }
        },
        /**
         * Setting the frame height
         * @param height
         */
        setHeightFrame: function (height){
            if(height === undefined){
                height = $(window).height();
            }
            $('iframe[id="miko-pbx-phone"]').attr('height', height);
        },
        /**
         * Initializing the component
         * @param newSettings
         */
        init: function(newSettings) {
            // Initializing the connector to MikoPBX
            connector.settings = newSettings;
            if(connector.settings.pbxHost){
                let href = `//${connector.settings.pbxHost}/webrtc-phone/index.html?random=${(new Date()).getTime()}`;
                // Checking the availability of content located remotely
                // ModuleAmoCrm/sites/webrtc-phone/index.html
                $.ajax({
                    type : "HEAD",
                    async : true,
                    url : href
                })
                .success(function() {
                    if ($('iframe[src="' + href +'"').length < 1) {
                        // Add iframe
                        let css = 'position: fixed; z-index: 999; right: 0;bottom: 0; border: 0;';
                        // We connect the style.css file by passing the widget version as a parameter
                        $("body").prepend(`<iframe id="miko-pbx-phone" src="${href}" width="300" height="${$(window).height()}" style="${css}"></iframe>`);
                    }
                    connector.iFrame = document.getElementById('miko-pbx-phone');
                    connector.iFrame.onload = function (){
                        let frameVisibility= localStorage.getItem('frameVisibility');
                        if(frameVisibility === '1'){
                            $(connector.iFrame).show({duration: 400});
                        }else{
                            $(connector.iFrame).hide();
                        }
                        $(window).resize(() => {
                            // Set the size of the frame content. Passing a command to a frame
                            connector.postToFrame({action: 'resize', height: $(window).height()});
                            connector.setHeightFrame();
                        });
                        // Show a hidden frame when the mouse hovers over the border of the area
                        $(window).mousemove(event => {
                            if( $(window).width() - event.pageX < 5){
                                $(connector.iFrame).show({
                                    duration: 400,
                                    done: () => {
                                        connector.postToFrame({action: 'resize', height: $(window).height()});
                                        connector.setHeightFrame();                                    }
                                });

                            }
                        });
                    };
                    // Subscribing to event processing from a frame
                    window.addEventListener("message", connector.onMessage);
                })
                .error(function(){
                    $(connector.iFrame).hide();
                    PubSub.publish(connector.settings.ns + ':main', {action: "error", code: 'errorLoadFrame'});
                })
            }
            // Subscribing to events from other widget components
            PubSub.subscribe(connector.settings.ns + ':connector', connector.onMessage);
        },
        /**
         * Processing notifications from other components and from the frame (MikoPBX)
         * @param event
         * @param message
         */
        onMessage: function(event, message = null) {
            if( typeof event.origin !== 'undefined'
                && location.protocol+`//${connector.settings.pbxHost}` !== event.origin){
                return;
            }
            let params;
            try {
                params = message || JSON.parse(event.data);
            }catch (e) {
                return;
            }
            if(params.action === 'init-done'){
                // Message from MikoPBX frame
                connector.setHeightFrame();
                connector.postToFrame({action: 'connect', data: connector.settings})
            }else if(params.action === 'findContact'){
                PubSub.publish(connector.settings.ns + ':main', params);
            }else if(params.action === 'resultFindContact'){
                let result = {
                    number:  params.number,
                    contact: params.element.name,
                    company: params.element.company,
                    id:      params.element.company
                };
                connector.postToFrame({action: 'updateContact', data:  result});
            }else if(params.action === 'openCard'){
                // Открыть карточку клиента.
                PubSub.publish(connector.settings.ns + ':main', params);
            }else if(params.action === 'hide-panel'){
                $(connector.iFrame).hide();
                localStorage.setItem('frameVisibility', '0')
            }else if(params.action === 'show-panel'){
                connector.postToFrame({action: 'resize'});
                $(connector.iFrame).show({
                    duration: 400,
                    done: () => {
                        connector.postToFrame({action: 'resize'});
                    }
                });
                localStorage.setItem('frameVisibility', '1')
            }else if(params.action === 'error'){
                PubSub.publish(connector.settings.ns + ':main', params);
            }else if(params.action === 'resize'){
                connector.setHeightFrame(params.height);
            }else{
                connector.postToFrame(message);
            }
        }
    };
    return connector;
});

/*
curl -X 'GET' -H 'Accept: text/event-stream' -k 'https://127.0.0.1/pbxcore/api/nchan/sub/users?token=test-token'
curl -X 'GET' -H 'Accept: text/event-stream' -k 'https://127.0.0.1/pbxcore/api/nchan/sub/calls?token=test-token'
curl -X 'GET' -H 'Accept: text/event-stream' -k 'https://127.0.0.1/pbxcore/api/nchan/sub/active-calls?token=test-token'

curl -k --request POST --data "test message" -H "Accept: text/json" https://172.16.156.223/pbxcore/api/nchan/pub/calls
curl -k --request POST --data "test message" -H "Accept: text/json" https://172.16.156.223/pbxcore/api/amo/pub/active-calls
*/