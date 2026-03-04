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
        toggleTab: null,
        /**
         * Creates a fixed toggle tab on the right edge of the screen
         */
        createToggleTab: function () {
            if (connector.toggleTab) {
                return;
            }
            let tab = document.createElement('div');
            tab.id = 'miko-pbx-toggle-tab';
            tab.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#555">'
                + '<path d="M6.62 10.79a15.053 15.053 0 006.59 6.59l2.2-2.2a1.004 1.004 0 011.01-.24c1.12.37 2.33.57 3.57.57'
                + '.55 0 1 .45 1 1v3.49c0 .55-.45 1-1 1C10.07 22 2 13.93 2 4c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1'
                + ' 0 1.25.2 2.45.57 3.57.11.35.03.74-.24 1.01l-2.21 2.21z"/>'
                + '</svg>';
            let savedY = localStorage.getItem('tabPositionY');
            let topVal = savedY ? savedY + 'px' : '50%';
            tab.style.cssText = 'position:fixed;right:0;top:' + topVal + ';z-index:998;width:32px;height:32px;'
                + 'background:#e0e0e0;border-radius:6px 0 0 6px;cursor:pointer;'
                + 'box-shadow:-1px 0 4px rgba(0,0,0,0.15);display:flex;align-items:center;justify-content:center;'
                + 'transition:background 0.15s;user-select:none;';
            tab.onmouseenter = function () { tab.style.background = '#ccc'; };
            tab.onmouseleave = function () { tab.style.background = '#e0e0e0'; };

            // Drag logic
            let isDragging = false;
            let dragStartY = 0;
            let dragStartTop = 0;
            let totalDragDistance = 0;

            tab.addEventListener('mousedown', function (e) {
                isDragging = true;
                dragStartY = e.clientY;
                dragStartTop = parseInt(tab.style.top, 10) || ($(window).height() / 2);
                totalDragDistance = 0;
                e.preventDefault();
            });
            $(document).on('mousemove.mikoTab', function (e) {
                if (!isDragging) return;
                let delta = e.clientY - dragStartY;
                totalDragDistance = Math.max(totalDragDistance, Math.abs(delta));
                let newTop = dragStartTop + delta;
                let maxTop = $(window).height() - 32;
                if (newTop < 0) newTop = 0;
                if (newTop > maxTop) newTop = maxTop;
                tab.style.top = newTop + 'px';
            });
            $(document).on('mouseup.mikoTab', function () {
                if (!isDragging) return;
                isDragging = false;
                localStorage.setItem('tabPositionY', parseInt(tab.style.top, 10));
                if (totalDragDistance < 5) {
                    // Click without drag — show panel
                    $(connector.iFrame).show({
                        duration: 400,
                        done: function () {
                            connector.postToFrame({action: 'resize', height: $(window).height()});
                            connector.setHeightFrame();
                        }
                    });
                    localStorage.setItem('frameVisibility', '1');
                    $(tab).hide();
                }
            });

            document.body.appendChild(tab);
            connector.toggleTab = tab;
        },
        /**
         * Sending a command to a frame
         * @param message
         */
        postToFrame: function (message){
            if(connector.iFrame !== null && typeof connector.iFrame.contentWindow === 'object'){
                connector.iFrame.contentWindow.postMessage(message, window.location.origin);
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
                    async : true,
                    url : href
                })
                .success(function(htmlContent) {
                    htmlContent = htmlContent.replace(new RegExp('href="', 'g'), `href="//${connector.settings.pbxHost}/webrtc-phone/`);
                    htmlContent = htmlContent.replace(new RegExp('src="', 'g'),  `src="//${connector.settings.pbxHost}/webrtc-phone/`);
                    htmlContent = htmlContent.replace(new RegExp('data-main="', 'g'),  `data-main="//${connector.settings.pbxHost}/webrtc-phone/`);
                    htmlContent = htmlContent.replace('<head>', `<head><script>window.mikoPbxHost="${connector.settings.pbxHost}";</script>`);
                    if ($('#miko-pbx-phone').length < 1) {
                        // Add iframe
                        let css = 'position: fixed; z-index: 999; right: 0;bottom: 0; border: 0;';
                        // We connect the style.css file by passing the widget version as a parameter
                        $("body").prepend(`<iframe id="miko-pbx-phone" src="javascript:void(0);" width="300" height="${$(window).height()}" style="${css}"></iframe>`);
                    }
                    connector.iFrame = document.getElementById('miko-pbx-phone');
                    $(connector.iFrame).attr('title', connector.settings.pbxHost);
                    $(connector.iFrame).hide();
                    let previewIframe =  connector.iFrame.contentDocument ||  connector.iFrame.contentWindow.document;
                    previewIframe.open();
                    previewIframe.write(htmlContent);
                    previewIframe.close();

                    connector.iFrame.onload = function (){
                        let frameVisibility= localStorage.getItem('frameVisibility');
                        if(frameVisibility === '1'){
                            $(connector.iFrame).show({duration: 400});
                        }else{
                            $(connector.iFrame).hide();
                        }
                        let resizeTimer;
                        $(window).resize(() => {
                            clearTimeout(resizeTimer);
                            resizeTimer = setTimeout(() => {
                                // Set the size of the frame content. Passing a command to a frame
                                connector.postToFrame({action: 'resize', height: $(window).height()});
                                connector.setHeightFrame();
                            }, 150);
                        });
                        // Create toggle tab for showing the hidden panel
                        connector.createToggleTab();
                        if (frameVisibility !== '1') {
                            $(connector.toggleTab).show();
                        } else {
                            $(connector.toggleTab).hide();
                        }
                    };
                    // Subscribing to event processing from a frame
                    window.removeEventListener("message", connector.onMessage);
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
            let params;
            try {
                params = message || JSON.parse(event.data);
            }catch (e) {
                return;
            }
            if(params.action === 'saveSettings'){
                // Обновляем значение в настройках.
                if (params.pbxHost) {
                    connector.settings.pbxHost = params.pbxHost;
                }
                params.pbxHost = connector.settings.pbxHost;
            }
            if(connector.settings.pbxHost !== params.pbxHost){
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
                    id:      params.element.id
                };
                connector.postToFrame({action: 'updateContact', data:  result});
            }else if(params.action === 'openCardEntities'){
                // Открыть карточку клиента / сделки.
                PubSub.publish(connector.settings.ns + ':main', params);
            }else if(params.action === 'openCard'){
                // Открыть карточку клиента.
                PubSub.publish(connector.settings.ns + ':main', params);
            }else if(params.action === 'hide-panel'){
                $(connector.iFrame).hide();
                localStorage.setItem('frameVisibility', '0');
                connector.createToggleTab();
                $(connector.toggleTab).show();
            }else if(params.action === 'show-panel'){
                connector.postToFrame({action: 'resize'});
                $(connector.iFrame).show({
                    duration: 400,
                    done: () => {
                        connector.postToFrame({action: 'resize'});
                    }
                });
                localStorage.setItem('frameVisibility', '1');
                if (connector.toggleTab) {
                    $(connector.toggleTab).hide();
                }
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
curl -X 'GET' -H 'Accept: text/event-stream' -k 'https://127.0.0.1/pbxcore/api/nchan/sub/pbx-events?token=test-token'
curl -k --request POST --data "test message" -H "Accept: text/json" https://127.0.0.1/pbxcore/api/nchan/pub/pbx-events


curl -X 'GET' -H 'Accept: text/event-stream' -k 'https://127.0.0.1/pbxcore/api/nchan/sub/users?token=test-token'
curl -X 'GET' -H 'Accept: text/event-stream' -k 'https://127.0.0.1/pbxcore/api/nchan/sub/calls?token=test-token'
curl -X 'GET' -H 'Accept: text/event-stream' -k 'https://127.0.0.1/pbxcore/api/nchan/sub/active-calls?token=test-token'
curl -k --request POST --data "test message" -H "Accept: text/json" https://127.0.0.1/pbxcore/api/nchan/pub/calls
curl -k --request POST --data "test message" -H "Accept: text/json" https://172.16.156.223/pbxcore/api/amo/pub/active-calls
*/