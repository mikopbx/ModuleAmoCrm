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
    const JsSIP     = require('jssip');
    const moment    = require('moment');

    function translate(string){
        return string;
    }

    // let ctxSip = {
    ctxSip = {
        connSettings: {
            pbxHost: '',
            currentPhone: '',
            sipPassword: '',
        },
        mediaExist: false,
        config : {},
        sessionEventHandlers: {},
        phone 		 : null,
        ringtone     : null,
        ringbacktone : null,
        dtmfTone     : null,
        sipRemoteAudio: document.getElementById("sipRemoteAudio"),
        Sessions     : [],
        callTimers   : {},
        callActiveID : null,
        callVolume    : 1,
        Stream       : null,

        addAudioTag: function (key, path){
            ctxSip[key] = new Audio(`${window.location.origin}/webrtc-phone/sounds/${path}.mp3`);
            ctxSip[key].loop = true;
            ctxSip[key].addEventListener('ended', function() {
                this.currentTime = 0;
                this.play();
            }, false);
        },
        start: function (connSettings){
            ctxSip.addAudioTag('ringtone', 'outgoing');
            ctxSip.addAudioTag('ringbacktone', 'incoming');
            ctxSip.addAudioTag('dtmfTone', 'dtmf');

            $.each(connSettings, function (key, value){
                if(typeof ctxSip.connSettings[key] === 'undefined'){
                    return;
                }
                ctxSip.connSettings[key] = value;
            });
            let protocol = 'ws';
            if(window.location.protocol === 'https:'){
                protocol = 'wss';
            }
            if(ctxSip.connSettings.pbxHost.trim() === ''){
                return;
            }

            let socket = new JsSIP.WebSocketInterface(protocol+'://'+ctxSip.connSettings.pbxHost+'/webrtc');
            ctxSip.config = {
                sockets  : [ socket ],
                uri      : new JsSIP.URI('sip', ctxSip.connSettings.currentPhone+'-WS', ctxSip.connSettings.pbxHost).toAor(),
                password : ctxSip.connSettings.sipPassword,
                authorization_user: ctxSip.connSettings.currentPhone,
                display_name : ctxSip.connSettings.currentPhone,
                user_agent: 'MIKO Panel WebRTC',
                register_expires: 30,
                // session_timers: false,
                // session_timers_refresh_method: 'invite'
            };

            ctxSip.sessionEventHandlers = {
                'peerconnection': ctxSip.sessionOnPeerConnection,
                'progress'      : ctxSip.sessionOnProgress,
                'connecting'    : ctxSip.sessionOnConnecting,
                'accepted'      : ctxSip.sessionOnAccepted,
                'hold'          : ctxSip.sessionOnHold,
                'unhold'        : ctxSip.sessionOnUnHold,
                'cancel'        : ctxSip.sessionOnCancel,
                'muted'         : ctxSip.sessionOnMuted,
                'unmuted'       : ctxSip.sessionOnUnMuted,
                'failed'        : ctxSip.sessionOnFailed,
                'ended'         : ctxSip.sessionOnEnded,
                'confirmed'     : ctxSip.sessionOnConfirmed
            };

            ctxSip.phone  = new JsSIP.UA(ctxSip.config);
            ctxSip.setStatus("Connecting");
            ctxSip.phone.start();

            ctxSip.phone.on('connected', function() {
                ctxSip.setStatus("Connected");
            });
            ctxSip.phone.on('disconnected', function() {
                ctxSip.setStatus("Disconnected");
                // disable phone
                ctxSip.setError(true, 'Websocket Disconnected.', 'An Error occurred connecting to the websocket.');
                // remove existing sessions
                $("#sessions > .session").each(function(i, session) {
                    ctxSip.removeSession(session, 500);
                });
            });
            ctxSip.phone.on('registrationFailed', function() {
                ctxSip.setError(true, 'Registration Error.', 'An Error occurred registering your phone. Check your settings.');
                ctxSip.setStatus("RegistrationFailed");
            });
            ctxSip.phone.on('unregistered', function() {
                ctxSip.setError(true, 'Registration Error.', 'An Error occurred registering your phone. Check your settings.');
                ctxSip.setStatus("Disconnected");
            });
            ctxSip.phone.on('newRTCSession', function(e){
                for (let key in ctxSip.sessionEventHandlers) {
                    e.session.on(key, ctxSip.sessionEventHandlers[key]);
                }
                if(e.originator !== 'remote'){
                    return;
                }
                e.session.direction = 'incoming';
                ctxSip.newSession(e.session);
            });
            ctxSip.phone.on('sipEvent', function(data, parameter) {
                // console.log(parameter, data);
            });
            ctxSip.phone.on('registered', function() {
                window.onbeforeunload = () => {
                    return 'If you close this window, you will not be able to make or receive calls from your browser.';
                };
                window.onunload       = () => {
                    localStorage.removeItem('ctxPhone');
                    ctxSip.phone.stop();
                };
                // This key is set to prevent multiple windows.
                localStorage.setItem('ctxPhone', 'true');
                // $("#mldError").modal('hide');
                ctxSip.setStatus("Ready");
            });

        },

        /**
         * Parses a SIP uri and returns a formatted US phone number.
         *
         * @param  {string} phone number or uri to format
         * @return {string}       formatted number
         */
        formatPhone : function(phone) {
            let num;
            if (phone.indexOf('@')) {
                return JsSIP.URI.parse(phone).user;
            } else {
                num = phone;
            }
            num = num.toString().replace(/[^0-9]/g, '');
            if (num.length === 10) {
                return '(' + num.substr(0, 3) + ') ' + num.substr(3, 3) + '-' + num.substr(6,4);
            } else if (num.length === 11) {
                return '(' + num.substr(1, 3) + ') ' + num.substr(4, 3) + '-' + num.substr(7,4);
            } else {
                return num;
            }
        },

        /**
         * Получение AOR для набираемого номера телефора.
         * @param target
         * @returns {*}
         */
        makeAor : function (target){
            return (new JsSIP.URI('sip', target, ctxSip.connSettings.pbxHost).toAor());
        },

        /**
         * sets the ui call status field
         *
         * @param {string} status
         */
        setCallSessionStatus : function(status) {
            // TODO
            $('#txtCallStatus').html(status);
        },

        /**
         * sets the ui connection status field
         *
         * @param {string} status
         */
        setStatus : function(status) {
            console.debug(status);
            let icon    = 'fa-question',
                tooltip = '???',
                text    = '';
            if(status === 'Ready' || 'Connected' === status){
                icon    = 'fa-signal';
                tooltip = translate('statusPhone.'+status);
                text    = tooltip;
            }else if(status === 'Connecting'){
                icon = 'fa-circle text-primary';
                tooltip = translate('statusPhone.'+status);
            }else if(status === 'RegistrationFailed'){
                icon = 'fa-circle text-danger';
                tooltip = translate('statusPhone.'+status);
            }else if(status === 'Disconnected'){
                icon = 'fa-circle text-dark';
                tooltip = translate('statusPhone.'+status);
                text = tooltip;
            }
            // TODO
            $("#txtRegStatus").html('<i class="fa '+icon+' me-1" role="button" data-bs-toggle="tooltip" data-bs-placement="right" title="'+tooltip+'"></i> ' + text);
            let tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            })
        },

        /**
         *  Вывод информации об ошибке.
         * @param err
         * @param title
         * @param msg
         * @param closable
         */
        setError : function(err, title, msg, closable) {
            console.log(err, title, msg, closable);
            return;
            // Show modal if err = true
            let mdlError = $("#mdlError");
            if (err === true) {
                mdlError.modal('show');
                mdlError.find('p').html(msg);
                if (closable) {
                    let b = '<button type="button" class="close" data-dismiss="modal">&times;</button>';

                    let header = $("#mdlError .modal-header");
                    header.find('button').remove();
                    header.prepend(b);
                    mdlError.find(".modal-title").html(title);
                    mdlError.modal({ keyboard : true });
                } else {
                    mdlError.find(".modal-header").find('button').remove();
                    mdlError.find(".modal-title").html(title);
                    mdlError.modal({ keyboard : false });
                }
                $('#numDisplay').prop('disabled', 'disabled');
            } else {
                $('#numDisplay').removeProp('disabled');
                mdlError.modal('hide');
            }
        },

        phoneCallButtonPressed : function(sessionid) {
            let s      = ctxSip.Sessions[sessionid];
            if (!s) {
                let numDisplay = $("#numDisplay");
                let target = numDisplay.val();
                numDisplay.val("");
                ctxSip.sipCall(target);
            } else if (s.isInProgress() && !s.start_time) {
                let options = {
                    eventHandlers: ctxSip.sessionEventHandlers,
                    mediaConstraints: { audio : true, video : false },
                    mediaStream: ctxSip.sipRemoteAudio.srcObject,
                };
                s.answer(options);
            }
        },

        startRingTone : function() {
            try { ctxSip.ringtone.play(); } catch (e) { }
        },

        stopRingTone : function() {
            try { ctxSip.ringtone.pause(); } catch (e) { }
        },

        startRingbackTone : function() {
            try { ctxSip.ringbacktone.play(); } catch (e) { }
        },

        stopRingbackTone : function() {
            try { ctxSip.ringbacktone.pause(); } catch (e) { }
        },

        getUniqueID : function() {
            return Math.random().toString(36).substr(2, 9);
        },

        /**
         * updates the call log ui
         */
        logShow : function() {
            let calllog = JSON.parse(localStorage.getItem('sipCalls'));
            if (calllog === null) {
                return;
            }

            let x       = [];
            $('#web-rtc-phone-cdr').empty();
            $.each(calllog, function(k,v) {
                if('ended' !== v.status && ctxSip.Sessions[v.id] === undefined){
                    // Сессий нет, значит и нет активных разговоров.
                    v.status = 'ended';
                    v.stop = v.start;
                }
                x.push(v);
            });
            // sort descending
            x.sort(function(a, b) {
                return b.start - a.start;
            });

            $.each(x, function(k, item) {
                ctxSip.logItem(item);
            });
        },

        /**
         * adds a ui item to the call log
         *
         * @param  {object} item log item
         */
        logItem : function(item) {

            let callActive = (item.status !== 'ended' && item.status !== 'missed'),
                callLength = (item.status !== 'ended')? '<span id="'+item.id+'"></span>': Math.trunc((item.stop - item.start) / 1000) ,
                callIcon,
                i;

            switch (item.status) {
                case 'ringing'  :
                    callIcon  = 'fa-bell';
                    break;
                case 'missed'   :
                    if (item.flow === "incoming") { callIcon = 'fa-chevron-left'; }
                    if (item.flow === "outgoing") { callIcon = 'fa-chevron-right'; }
                    break;
                case 'holding'  :
                    callIcon  = 'fa-pause';
                    break;
                case 'answered' :
                case 'resumed'  :
                    callIcon  = 'fa-phone-square';
                    break;
                case 'ended'  :
                    if (item.flow === "incoming") { callIcon = 'fa-sign-in-alt'; }
                    if (item.flow === "outgoing") { callIcon = 'fa-sign-out-alt'; }
                    break;
            }

            let callerIdName = item.clid;
            if(!callerIdName){
                callerIdName = ctxSip.formatPhone(item.uri);
            }

            let buttons = '';
            if (callActive) {
                buttons      = '                        <i class="fa fa-phone-slash text-danger m-2 btnHangUp" role="button"></i>\n';
                if (item.status === 'ringing' && item.flow === 'incoming') {
                    buttons += '                        <i class="fa fa-phone-alt text-success m-2 btnCall" role="button"></i>\n';
                } else {
                    buttons += '                        <i class="fa fa-microphone m-2 btnMute" role="button"></i>\n' +
                        '                        <i class="fa fa-share-square text-primary m-2 btnTransfer" role="button"></i>\n' +
                        '                        <i class="fa fa-pause m-2 btnHoldResume" role="button" role="button"></i>\n';
                }
            }

            i = '<div class="container card m-cdr-card p-3 shadow fw-lighter mt-2" data-uri="'+item.uri+'" data-sessionid="'+item.id+'">\n' +
                '                <div class="row">\n' +
                '                    <div class="col text-start ">\n' +
                '                        '+moment(item.start).format('MM.DD, HH:mm:ss')+'\n' +
                '                    </div>\n' +
                '                </div>\n' +
                '                <div class="row">\n' +
                '                    <div class="col text-start ">\n' +
                '                        <i class="fa '+callIcon+' me-2"></i>'+callerIdName+'\n' +
                '                    </div>\n' +
                '                    <div class="col text-end">\n' +
                '                        '+callLength+' c.\n' +
                '                    </div>\n' +
                '                </div>\n' +
                '                <div class="row">\n' +
                '                    <div class="input-group d-flex flex-row-reverse mt-1">\n' +
                buttons +
                '                    </div>\n' +
                '                </div>\n' +
                '            </div>';

            let sipLogItems = $('#web-rtc-phone-cdr');
            sipLogItems.append(i);
            // Start call timer on answer
            if (item.status === 'answered') {
                let tEle = document.getElementById(item.id);
                ctxSip.callTimers[item.id] = new Stopwatch(tEle);
                ctxSip.callTimers[item.id].start();
            }
            if (callActive && item.status !== 'ringing') {
                ctxSip.callTimers[item.id].start({startTime : item.start});
            }
            sipLogItems.scrollTop(0);
        },

        /**
         * removes log items from localstorage and updates the UI
         */
        logClear : function() {
            localStorage.removeItem('sipCalls');
            // ctxSip.logShow();
        },

        /**
         * logs a call to localstorage
         *
         * @param  {object} session
         * @param  {string} status Enum 'ringing', 'answered', 'ended', 'holding', 'resumed'
         */
        logCall : function(session, status) {
            let log = {
                    clid : session.display_name,
                    uri  : session.remote_identity.uri.toString(),
                    id   : session.ctxid,
                    time : new Date().getTime()
                },
                callLog = JSON.parse(localStorage.getItem('sipCalls'));

            if (!callLog) { callLog = {}; }

            if (!callLog.hasOwnProperty(session.ctxid)) {
                callLog[log.id] = {
                    id    : log.id,
                    clid  : log.clid,
                    uri   : log.uri,
                    start : log.time,
                    flow  : session.direction
                };
            }
            if (status === 'ended') {
                callLog[log.id].stop = log.time;
            }
            if (status === 'ended' && callLog[log.id].status === 'ringing') {
                callLog[log.id].status = 'missed';
            } else {
                callLog[log.id].status = status;
            }
            localStorage.setItem('sipCalls', JSON.stringify(callLog));
            // ctxSip.logShow();

            console.debug(log);
        },

        /**
         * Обработка события добавления нового медиа потока.
         * @param e
         */
        connectionOnAddStream: function(e){
            // https://github.com/versatica/JsSIP/issues/501
            // ctxSip.sipRemoteAudio.srcObject = e.stream;
            // ctxSip.sipRemoteAudio.play();
            // ctxSip.sipRemoteAudio = document.createElement('audio');
            if ('srcObject' in ctxSip.sipRemoteAudio) {
                ctxSip.sipRemoteAudio.srcObject = e.stream;
            } else {
                ctxSip.sipRemoteAudio.src = window.URL.createObjectURL(e.stream);
            }
            ctxSip.sipRemoteAudio.play();
        },

        sessionOnPeerConnection: function () {
            if (this.direction !== 'incoming') {
                return;
            }
            this.connection.onaddstream = ctxSip.connectionOnAddStream;
        },

        sessionOnConnecting: function() {
            if (this.direction === 'outgoing') {
                ctxSip.setCallSessionStatus('Connecting...');
            }
        },
        sessionOnProgress: function() {
            if (this.direction === 'outgoing') {
                ctxSip.setCallSessionStatus('Calling...');
            }
        },
        sessionOnAccepted: function() {
            if (ctxSip.callActiveID && ctxSip.callActiveID !== this.ctxid) {
                ctxSip.phoneHoldButtonPressed(ctxSip.callActiveID);
            }
            ctxSip.stopRingbackTone();
            ctxSip.stopRingTone();
            ctxSip.setCallSessionStatus('Answered');
            ctxSip.logCall(this, 'answered');
            ctxSip.callActiveID = this.ctxid;
        },
        sessionOnHold: function() {
            console.log('hold');
            ctxSip.logCall(this, 'resumed');
            ctxSip.callActiveID = this.ctxid;
        },
        sessionOnUnHold: function() {
            console.log('unhold');
            ctxSip.logCall(this, 'resumed');
            ctxSip.callActiveID = this.ctxid;
        },
        sessionOnCancel: function() {
            console.log('cancel');
            ctxSip.stopRingTone();
            ctxSip.stopRingbackTone();
            ctxSip.setCallSessionStatus("Canceled");
            if (this.direction === 'outgoing') {
                ctxSip.callActiveID = null;
                ctxSip.logCall(this, 'ended');
            }
            delete ctxSip.Sessions[this.ctxid];
        },

        sessionOnFailed: function() {
            console.log('failed');
            ctxSip.stopRingTone();
            ctxSip.stopRingbackTone();
            ctxSip.setCallSessionStatus('Terminated');
            ctxSip.logCall(this, 'ended');
            ctxSip.callActiveID = null;
            delete ctxSip.Sessions[this.ctxid];
        },
        sessionOnEnded: function() {
            console.log('ended');
            ctxSip.stopRingTone();
            ctxSip.stopRingbackTone();
            ctxSip.setCallSessionStatus("");
            ctxSip.logCall(this, 'ended');
            ctxSip.callActiveID = null;
            delete ctxSip.Sessions[this.ctxid]
        },
        sessionOnMuted: function() {
            console.log('muted');
        },
        sessionOnUnMuted: function() {
            console.log('unmuted');
        },
        sessionOnConfirmed: function() {
            console.log('call confirmed');
        },

        sipCall : function(target){
            let options = {
                'mediaConstraints' : {'audio': true, 'video': false},
                'iceServers':  [{url: ['stun:stun.sipnet.ru']}],
                'mediaStream': ctxSip.Stream,
            };
            let aor = ctxSip.makeAor(target);
            let s = ctxSip.phone.call(aor, options);
            s.connection.onaddstream = ctxSip.connectionOnAddStream;
            s.direction = 'outgoing';
            ctxSip.newSession(s);
        },

        sipTransfer : function(sessionid) {
            let s      = ctxSip.Sessions[sessionid],
                target = window.prompt('Enter destination number', '');
            let aor = ctxSip.makeAor(target);
            ctxSip.setCallSessionStatus('<i>Transfering the call...</i>');
            s.refer(aor);
        },

        phoneHoldButtonPressed : function(sessionid) {
            let s = ctxSip.Sessions[sessionid];
            if (s.isOnHold().local === true) {
                s.unhold();
            } else {
                s.hold();
            }
        },

        phoneMuteButtonPressed : function (sessionid) {
            let s = ctxSip.Sessions[sessionid];
            if (s.isMuted().audio === false) {
                s.mute();
            } else {
                s.unmute();
            }
        },

        sipSendDTMF : function(digit) {

            try { ctxSip.dtmfTone.play(); } catch(e) { }
            if (ctxSip.callActiveID) {
                let s = ctxSip.Sessions[ctxSip.callActiveID];
                if(s){
                    s.sendDTMF(digit);
                }
            }
        },

        sipHangUp : function(sessionid) {
            let s = ctxSip.Sessions[sessionid];
            if (!s || s.isEnded()) {
                return;
            }
            s.terminate();
        },

        newSession : function(newSess) {
            newSess.displayName = newSess.remote_identity.display_name || newSess.remote_identity.uri.user;
            newSess.ctxid       = newSess.id;
            let status;
            if (newSess.direction === 'incoming') {
                status = "Incoming: "+ newSess.displayName;
                ctxSip.startRingTone();
            } else {
                status = "Trying: "+ newSess.displayName;
                ctxSip.startRingbackTone();
            }
            ctxSip.Sessions[newSess.ctxid] = newSess;
            ctxSip.logCall(newSess, 'ringing');
            ctxSip.setCallSessionStatus(status);
        },

        /**
         * Отправка текстового сообщения.
         * @param text
         * @param target
         */
        sendMessage : function (text, target){
            let eventHandlers = {
                'succeeded': function(){ console.log('succeeded')},
                'failed':    function(){ console.log('failed') }
            };
            let options = {
                'eventHandlers': eventHandlers
            };
            let aor = ctxSip.makeAor(target);
            ctxSip.phone.sendMessage(aor, text, options);
        },

        getUserMediaSuccess : function(stream) {
            ctxSip.mediaExist = true;
        },
        getUserMediaFailure : function(e) {
            ctxSip.mediaExist = false;
            window.console.error('getUserMedia failed:', e);
            ctxSip.setError(true, 'Media Error.', 'You must allow access to your microphone.  Check the address bar.', true);
        },
        checkUserMedia: () => {
            navigator.getUserMedia = navigator.getUserMedia || navigator.webkitGetUserMedia || navigator.mozGetUserMedia || navigator.mediaDevices.getUserMedia;
            if (!navigator.getUserMedia) {
                console.log('You are using a browser that does not support the Media Capture API');
            }else{
                let constraints = { audio : true, video : false };
                try {
                    navigator.getUserMedia(constraints).then(ctxSip.getUserMediaSuccess, ctxSip.getUserMediaFailure);
                } catch(err) {
                    ctxSip.getUserMediaFailure(err);
                }
            }
        },
        init: function (settings){
            ctxSip.checkUserMedia();
            $(window).on("message", ctxSip.onWindowMessage);
        },
        onWindowMessage: (event) => {
            if(typeof event.originalEvent.data === 'undefined'){
                // Не корректные данные.
                return;
            }
            if(event.originalEvent.data.action === 'connect'){
                ctxSip.start(event.originalEvent.data);
            }
        }
    }
    return ctxSip;
});