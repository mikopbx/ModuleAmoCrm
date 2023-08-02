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
        state: 0,
        startReconnect: 0,
        settings: {
            currentUser:  '',
            currentPhone: '',
            pbxHost:      '',
            users:        [],
            token:        '',
            time_unit:    ''
        },
        currentPhone: '',
        init: function (settings){
            self = this;
            $.each(settings, function (key, value){
                if(typeof self.settings[key] === 'undefined'){
                    return;
                }
                self.settings[key] = value;
            });
            self.initSocket('pbx-events');
            setInterval(self.checkConnection, 5000);

            self.token = PubSub.subscribe('COMMAND', function (msg, message) {
                let url;
                let reqDate = Date.now();
                if(message.action === 'saveSettings'){
                    url = `${window.location.origin}/pbxcore/api/amo-crm/v1/change-settings?rD=${reqDate}`;
                }else if(message.action === 'call' || message.action === 'transfer'){
                    message.data = {
                        'action':       (message.action === 'call') ? 'callback':message.action,
                        'number':       message.phone,
                        'user-number':  self.settings.currentPhone,
                        'user-id':      self.settings.currentUser
                    };
                    url = `${window.location.origin}/pbxcore/api/amo-crm/v1/callback?rD=${reqDate}`;
                }else if(message.action === 'findContact'){
                    url = `${window.location.origin}/pbxcore/api/amo-crm/v1/find-contact?rD=${reqDate}`;
                }else if(message.action === 'callback'){
                    url = `${window.location.origin}/pbxcore/api/amo-crm/v1/callback?rD=${reqDate}`;
                }else{
                    url = `${window.location.origin}/pbxcore/api/amo-crm/v1/command?rD=${reqDate}`;
                }
                message.data.token = self.settings.token;
                $.ajax(url, {timeout:5000, type: 'POST', data: message.data})
                .fail(function(jqXHR, textStatus) {
                    if(jqXHR.status === 403){
                        PubSub.publish('CALLS', {action: "error", code: 'errorAuthAPI'});
                    }else if(jqXHR.status === 0){
                        PubSub.publish('CALLS', {action: "error", code: 'errorLoadFrame'});
                    }
                });
            });
        },
        initEventSource: function (chan){
            let url = `${window.location.origin}/pbxcore/api/nchan/sub/${chan}?token=${self.settings.token}`;
            self.eventSource[chan] = new EventSource(url, {
                withCredentials: true
            });
            self.eventSource[chan].onmessage = self.onPbxMessage;
            self.eventSource[chan].onerror   = self.onPbxMessageError;

            $.ajax(url, {timeout:5000, type: 'GET'})
            .fail(function(jqXHR, textStatus) {
                if(jqXHR.status === 403){
                    delete  self.eventSource[chan];
                    PubSub.publish('CALLS', {action: "error", code: 'errorAuthAPI'});
                }
            });
        },
        initSocket: function (chan){
            let protocol = 'wss:';
            if(window.location.protocol === 'http:'){
                protocol = 'ws:';
            }
            self.eventSource[chan] = new WebSocket(`${protocol}//${window.location.host}/pbxcore/api/nchan/sub/${chan}?token=${self.settings.token}`);
            self.eventSource[chan].onopen = function() {
                console.debug('onopen:', chan);
            };
            self.eventSource[chan].onmessage = self.onPbxMessage;
            self.eventSource[chan].onclose = function(e) {
                console.debug('chan', 'Socket is closed. Reconnect will be attempted in 1 second.', e.reason);
                setTimeout(function() {
                    self.initSocket(chan);
                }, 1000);
            };
            self.eventSource[chan].onerror = function(err) {
                console.debug('chan', 'Socket encountered error: ', err.message, 'Closing socket');
                setTimeout(function() {
                    self.initSocket(chan);
                }, 5000);
            };
        },

        onPbxMessage: function(event) {
            let callData = undefined;
            try{
                callData = $.parseJSON(event.data);
            }catch (e) {
                return;
            }
            if(callData.action === 'CDRs'){
                // Обновим таблицу активных линий.
                self.parseCDRs(callData.data)
            }else if( callData.action === 'call' && self.settings.currentUser === callData.user && typeof self.channels[callData.uid] === 'undefined'){
                self.channels[callData.uid] = 1;
                self.parseCallEvent(callData);
            }else if( callData.action === 'answer' && typeof self.channels[callData.uid] !== 'undefined'){
                PubSub.publish('CALLS', {action: 'answerCall', 'data': {call_id: callData.uid, answer: (new Date(callData.date)).getTime()/1000 }});
            }else if(callData.action === 'hangup' || callData.action === 'end-dial'){
                delete self.channels[callData.uid];
                PubSub.publish('CALLS', {action: 'delCall', 'data': {call_id: callData.uid}});
            }else if(callData.action === 'findContact'){
                $.each(callData.data, function (i, contact){
                    PubSub.publish('CALLS', {action: 'updateContact', 'data': contact});
                });
            }else if(callData.action === 'USERS'){
                callData.data = $.grep(callData.data, function(value) {
                    return value.number !== self.settings.currentPhone;
                });
                PubSub.publish(callData.action, {action: 'initUsers', 'data': callData.data});
            }
        },
        onPbxMessageError: function(event) {
            console.debug("Error", event);
        },
        checkConnection: function(){
            if( typeof self.eventSource['calls'] !== 'undefined'
                && self.eventSource['calls'].readyState !== 1){
                let now = new Date().getTime() / 1000;
                if(self.startReconnect === 0){
                    self.startReconnect = now - 60;
                }
                if(self.state === 1 || ( self.state === 0 && (now - self.startReconnect) > 300 ) ){
                    self.startReconnect = now;
                    PubSub.publish('CALLS', {action: "error", code: 'errorAPITimeOut'});
                }
                self.state = 0;
            }else{
                self.state = 1;
                self.startReconnect = 0;
            }
        },
        c: function (data){
            let calls = [], IDs=[];
            $.each(data, function (i, cdr){
                let number = '', type = '';
                if(cdr.end !== ''){
                    return;
                }
                if(self.settings.currentUser === cdr['user-src']){
                    type = 'out';
                    number = cdr['dst'];
                }else if(self.settings.currentUser === cdr['user-dst']){
                    type = 'in';
                    number = cdr['src'];
                }else{
                    return;
                }
                let call = {
                    start:      Math.round((new Date(cdr.start)).getTime()/1000),
                    number:     number,
                    call_id:    cdr['uid'],
                    user_phone: self.settings.currentPhone,
                    user:       self.settings.currentUser,
                    call_type:  type,
                    time_unit: 'c.',
                    answered:  (cdr.answer === '')?'':Math.round((new Date(cdr.answer)).getTime()/1000)
                };
                calls.push(call);
                IDs.push(cdr['uid']);
            });
            PubSub.publish('CALLS', {action: 'CDRs', 'data': calls, 'IDs': IDs});
            PubSub.publish('USERS', {action: 'calls', 'data': data});
        },
        parseCallEvent: function (data){
            let number, type;
            if(self.settings.currentPhone === data.src){
                type = 'out';
                number = data['dst'];
            }else{
                type = 'in'
                number = data['src'];
            }
            let call = {
                start:      Math.round((new Date(data.date)).getTime()/1000),
                number:     number,
                call_id:    data['uid'],
                user:       self.settings.currentUser,
                user_phone: self.settings.currentPhone,
                call_type:  type,
                time_unit:  self.settings.time_unit
            };
            PubSub.publish('CALLS', {action: 'addCall', 'data': call});
        }
    };
});