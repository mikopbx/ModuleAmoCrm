define(function (require) {
    const $         = require('jquery');
    const Twig      = require('twig');
    const connector = require('connector');
    const PubSub    = require('pubsub');
    const cache     = require('cache');
    let   self      = undefined;
    require('rowGroup');
    require('datatables.net');

    return {
        hide: function (){
            $('#web-rtc-phone').addClass('invisible');
        },
        show: function (){
            $('#web-rtc-phone').removeClass('invisible');
        },
        updateContact: function (event){
            cache.flush();
            cache.set('phone:'+event.data.number, event.data.contact, { ttl: 300 });
            $('#web-rtc-phone div.m-contact-name[data-phone="'+event.data.number+'"]').each(function() {
                $(this).text(event.data.contact);
                $(this).attr('data-contact-id', event.data.id);
            });
        },
        pbxAction: function (event){
            PubSub.publish('COMMAND', {action: event.data.action, 'data': event.data});
        },
        resize: function() {
            let calls    = $('#web-rtc-phone-calls');
            let buttonsH = $('#web-rtc-phone-buttons').height();
            let statusH  = $('#web-rtc-phone-status').height();
            let cdr      = $('#web-rtc-phone-cdr');

            let delta    = $('body').height() - buttonsH - statusH - calls.height();
            let deltaCdr = calls.height() - cdr.height();

            calls.height($( window ).height() - buttonsH - statusH - delta);
            cdr.height(calls.height() - deltaCdr);
            let rowsHeight = 0;
            $('.m-cdr-card').each(function() {
                rowsHeight += $(this).outerHeight(true);
            });
            $('#empty-row').height(calls.outerHeight() - rowsHeight);
        },
        onGetEvent: function (event){
            if(typeof self[event.originalEvent.data.action] !== 'undefined'){
                self[event.originalEvent.data.action](event.originalEvent.data);
            }else{
                self.pbxAction(event.originalEvent);
            }
        },
        connect: function (event){
            connector.init(event.data);
        },
        addCall: function (event){
            let contact = cache.get('phone:'+event.data.number);
            if(contact !== null){
                event.data.contact = contact;
            }else if(cache.get('find:'+event.data.number) === null){
                cache.set('find:'+event.data.number, '1', { ttl: 5 });
                self.sendMessage({action: 'findContact', number: event.data.number});
            }
            let template = Twig.twig({
                data: $('#active-call-twig').html()
            });
            if($('#web-rtc-phone .m-cdr-card[data-callid="'+event.data.call_id+'"]').length !== 0 ){
                return
            }
            let html = template.render(event.data);
            $("#web-rtc-phone-cdr").append(html)
            self.resize();
        },
        answerCall:function (event){
            let element = $('#web-rtc-phone .m-cdr-card[data-callid="'+event.data.call_id+'"]');
            element.attr('data-answer', event.data.answer);
        },
        delCall: function (event){
            $('#web-rtc-phone .m-cdr-card[data-callid="'+event.data.call_id+'"]').remove();
            self.resize();
        },
        init: function() {
            self = this;
            $(window).resize(self.resize);
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
                        id: $(this).parents('.m-cdr-card').find('.m-contact-name').attr('data-contact-id')
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
            self.drawUsers();

            // create a function to subscribe to topics
            self.token = PubSub.subscribe('CALLS', function (msg, message) {
                if(message.action === 'CDRs'){
                    $('#web-rtc-phone .m-cdr-card').each(function (index, element) {
                        if($.inArray( $(element).attr('data-callid'), message.IDs ) < 0){
                            $(element).remove();
                        }
                    });
                    $.each(message.data, function (i, cdr){
                        self.addCall({data: cdr});
                    });
                }else if(message.action === 'addCall'){
                    self.addCall({data: message.data});
                }else if(message.action === 'delCall'){
                    self.delCall({data: message.data});
                }else if(message.action === 'answerCall'){
                    self.answerCall({data: message.data});
                }
            });
        },
        drawUsers: function (){
            let translates = {
                "SIP":   "Сотрудники",
                "QUEUE": "Группы"
            };
            let dataSet = [
                    {name: 'Отдел продаж', number: '2003', amoId: '', type: 'QUEUE'},
                    {name: 'Петр', number: '201', amoId: '2222', type: 'SIP'},
                    {name: 'Фудор', number: '202', amoId: '3333', type: 'SIP'},
                    {name: 'Никита', number: '203', amoId: '233344', type: 'SIP'},
                ];
            let table = $('#users').DataTable( {
                data: dataSet,
                ordering: true,
                paging:   false,
                scrollY:  '50vh',
                scrollCollapse: true,
                dom: 't',
                columns: [
                    { data: 'name'},
                    { data: 'number'},
                ],
                rowGroup: {
                    dataSrc: 'type',
                    startRender: function ( rows, group ) {
                        return translates[group];
                    },
                },
                initComplete:function( settings, json ) {
                    $(this.api().table().header()).hide();
                    self.resize();
                },
                createdRow: function (row, data) {
                    $(row).attr('data-number', data['number']);
                    $(row).attr('data-amo-id', data['amoId']);
                    $(row).attr('role', 'button');
                },
                columnDefs: [
                    {
                        targets: 0,
                        className: 'text-start cursor-pointer '
                    },
                    {
                        targets: 1,
                        width: "25%",
                        className: 'text-end'
                    }
                ],
                language: {
                    search: "",
                    info:           "",
                    infoEmpty:      "",
                    infoFiltered:   "",
                    infoPostFix:    "",
                    emptyTable:     "",
                    zeroRecords:    ""
                }
            } );
            $("#searchInput").on('keyup',function(e) {
                table.search( $("#searchInput").val() );
                table.draw();
                self.resize();
            });
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
