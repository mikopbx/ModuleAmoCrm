define(function (require) {
    const $         = require('jquery');
    const Twig      = require('twig');
    const connector = require('connector');
    const users     = require('users');
    const PubSub    = require('pubsub');
    const cache     = require('cache');
    let   self      = undefined;
    require('rowGroup');
    require('datatables.net');

    return {
        heightWindow: $(window).height(),
        hide: function (){
            $('#web-rtc-phone').addClass('invisible');
        },
        show: function (){
            $('#web-rtc-phone').removeClass('invisible');
        },
        updateContact: function (event){
            cache.flush();
            cache.set('phone:'+event.data.number, event.data, { ttl: 300 });
            $('#web-rtc-phone div.m-contact-name[data-phone="'+event.data.number+'"]').each(function() {
                $(this).text(event.data.name);
                $(this).attr('data-contact-id', event.data.id);
                self.resize();
            });
            $('#web-rtc-phone div.m-company-name[data-phone="'+event.data.number+'"]').each(function() {
                $(this).text(event.data.company);
                $(this).attr('data-contact-id', event.data.id);
                self.resize();
            });
        },
        pbxAction: function (event){
            PubSub.publish('COMMAND', {action: event.data.action, 'data': event.data});
        },
        resize: function(args) {
            let webPanel = $("#web-rtc-phone");
            let calls    = $('#web-rtc-phone-calls');
            let cdr      = $('#web-rtc-phone-cdr');
            let usersList= $('#users-list');
            if(args !== undefined && typeof args.height !== 'undefined'){
                self.heightWindow = args.height || self.heightWindow;
            }
            usersList.height(Math.min(self.heightWindow/2, $('#users-list div.container').outerHeight()));

            let scrollDiv = $('#users-list .dataTables_scrollBody');
            if(scrollDiv.length !== 0 ){
                let delta = $("#users-list .container").outerHeight() - scrollDiv.height();
                scrollDiv.css('height', self.heightWindow/2 - delta);
                scrollDiv.css('max-height', self.heightWindow);
            }

            let availHeight = self.heightWindow - usersList.outerHeight() - $("#web-rtc-phone-status").outerHeight() - 30;
            calls.height(Math.min(cdr.outerHeight() + 20, availHeight));
            self.sendMessage({action: 'resize', height: webPanel.height()});
        },
        onGetEvent: function (event){
            if(typeof event.originalEvent.data === 'undefined'){
                // Не корректные данные.
                return;
            }
            if(typeof self[event.originalEvent.data.action] !== 'undefined'){
                self[event.originalEvent.data.action](event.originalEvent.data);
            }else{
                self.pbxAction(event.originalEvent);
            }
        },
        connect: function (event){
            self.heightWindow = event.data.heightWindow;
            connector.init(event.data);
        },
        addCall: function (event){
            let contact = cache.get('phone:'+event.data.number);
            if(contact !== null){
                event.data.contact = contact.name;
                event.data.company = contact.company;
            }else if(typeof event.data.enableGetContact !== 'undefined') {
                self.pbxAction({data: {phone: event.data.number, action: 'findContact'}});
            }
            let template = Twig.twig({
                data: $('#active-call-twig').html()
            });
            if($('#web-rtc-phone .m-cdr-card[data-callid="'+event.data.call_id+'"]').length !== 0 ){
                // Звонок существует, уже добавлен ранее.
                return
            }
            if(event.data.number.length <= 4){
                // Скроем кнопки для внутренних звонков.
                event.data.additionalCardClass = 'd-none';
            }
            let html = template.render(event.data);
            $("#web-rtc-phone-cdr").append(html);
            self.resize();
        },
        answerCall:function (event){
            let element = $('#web-rtc-phone .m-cdr-card[data-callid="'+event.data.call_id+'"]');
            element.attr('data-answer', event.data.answer);
            // Открываем карточку при соединении с клиентом.
            if(element.attr('data-call-type') !== 'in'){
                return;
            }
            let params = {
                number: element.find('div.m-company-name').attr('data-phone'),
                id: element.find('div.m-company-name').attr('data-contact-id'),
                fromPanel: false
            };
            self.sendMessage({action: 'openCard', data: params});
        },
        delCall: function (event){
            $('#web-rtc-phone .m-cdr-card[data-callid="'+event.data.call_id+'"]').remove();
            self.resize();
        },
        onMessage: function (msg, message) {
            if(message.action === 'CDRs'){
                $('#web-rtc-phone .m-cdr-card').each(function (index, element) {
                    if($.inArray( $(element).attr('data-callid'), message.IDs ) < 0){
                        $(element).remove();
                    }
                });
                $.each(message.data, function (i, cdr){
                    cdr.enableGetContact = true;
                    self.addCall({data: cdr});
                });
            }else if(message.action === 'openCardEntities'){
                self.sendMessage(message);
            }else if(message.action === 'addCall'){
                self.addCall({data: message.data});
            }else if(message.action === 'delCall'){
                self.delCall({data: message.data});
            }else if(message.action === 'updateContact'){
                self.updateContact({data: message.data});
            }else if(message.action === 'answerCall'){
                self.answerCall({data: message.data});
            }else if(message.action === "error"){
                self.sendMessage(message);
            }else if(message.action === 'resize'){
                self.resize();
            }
        },
        init: function() {
            self = this;
            $(window).on("message", self.onGetEvent);
            self.show();
            $(document).on('click', 'button', function(){
                if($(this).attr('data-action') === undefined){
                    return;
                }
                let params;
                if($(this).attr('data-action') === 'card'){
                    params = {
                        number: $(this).parents('.m-cdr-card').find('.m-contact-name').attr('data-phone'),
                        id: $(this).parents('.m-cdr-card').find('.m-contact-name').attr('data-contact-id'),
                        fromPanel: true
                    };
                    self.sendMessage({action: 'openCard', data: params});
                }else{
                    params = {
                        'action': $(this).attr('data-action'),
                        'call-id': $(this).parents('.m-cdr-card').attr('data-callid'),
                        'user-id': $(this).parents('.m-cdr-card').attr('data-user-id'),
                        'user-phone': $(this).parents('.m-cdr-card').attr('data-user-phone')
                    };
                    PubSub.publish('COMMAND', {action: $(this).attr('data-action'), 'data': params});
                }
            });

            setInterval(self.updateDuration, 1000);

            self.sendMessage({action: 'init-done'});
            // create a function to subscribe to topics
            PubSub.subscribe('CALLS', self.onMessage);

            $("#hideButton").on('click',function() {
                self.sendMessage({action: 'hide-panel'});
            });
            users.init();
            self.resize();
            $('#web-rtc-phone').removeClass('invisible');
        },
        sendMessage: function (msgData){
            window.parent.postMessage(JSON.stringify(msgData), '*')
        },
        updateDuration: function (){
            $('#web-rtc-phone .m-cdr-card').each(function (index, element) {
                if($(element).attr('data-answer') === ''){
                    return;
                }
                let duration = Math.round((new Date()).getTime()/1000) - $(element).attr('data-answer');
                $(element).find('[data-type="duration"]').text(duration + ' ' + $(element).attr('data-time-unit'));
            })
        }
    };
});
