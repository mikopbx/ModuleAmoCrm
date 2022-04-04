/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2022 Alexey Portnov and Nikolay Beketov
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

define(function (require) {
    let $           = require('jquery');
    const PubSub    = require('pubsub');
    let self        = undefined;

    return {
        channels: [],
        eventSource: {},
        settings: {
            currentUser:  '',
            currentPhone: '',
            pbxHost:      '',
            users:        [],
            token:        ''
        },
        currentPhone: '',
        init: function (settings){
            self = this;
            self.settings = settings;
            self.initEventSource('calls');
            self.initEventSource('active-calls');
            setInterval(self.checkConnection, 5000);
        },
        initEventSource: function (chan){
            self.eventSource[chan] = new EventSource(`${window.location.origin}/pbxcore/api/nchan/sub/${chan}?token=${self.settings.token}`, {
                withCredentials: true
            });
            self.eventSource[chan].onmessage = self.onPbxMessage;
            self.eventSource[chan].onerror   = self.onPbxMessageError;
        },
        onPbxMessage: function(event) {
            let callData = undefined;
            try{
                callData = $.parseJSON(event.data);
            }catch (e) {
                return;
            }
            if(callData.action === 'CDRs'){
                self.parseCDRs(callData.data)
                // Обновим таблицу активных линий.
            }else if( callData.action === 'call' && self.settings.currentUser === callData.user && typeof self.channels[callData.uid] === 'undefined'){
                self.channels[callData.uid] = 1;
                console.log(callData);
            }else if(callData.action === 'hangup'){
                delete self.channels[callData.uid];
            }
        },
        onPbxMessageError: function(event) {
            console.log("Error", event);
        },
        checkConnection: function(){
            if(self.eventSource['calls'].readyState !== 1){
                console.log('Not connected to PBX', self.eventSource);
            }
        },
        parseCDRs: function (data){
            let calls = [], IDs=[];
            $.each(data, function (i, cdr){
                let number = '', type = '';
                if(self.settings.currentUser === cdr['user-src']){
                    type = 'in';
                    number = cdr['dst'];
                }else if(self.settings.currentUser === cdr['user-dst']){
                    type = 'out';
                    number = cdr['src'];
                }else{
                    return;
                }
                let call = {
                    start:      Math.round((new Date(cdr.start)).getTime()/1000),
                    number:     number,
                    call_id:    cdr['uid'],
                    call_type:  type,
                    time_unit: 'c.'
                };
                calls.push(call);
                IDs.push(cdr['uid']);
            });
            PubSub.publish('CALLS', {action: 'CDRs', 'data': calls, 'IDs': IDs});
        }

    };
});