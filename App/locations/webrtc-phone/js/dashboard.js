define(function (require) {
    let $           = require('jquery');
    let Twig        = require('twig');
    let connector   = require('connector');
    const PubSub = require('pubsub');
    let self   = undefined;

    return {
        hide: function (){
            $('#web-rtc-phone').addClass('invisible');
        },
        show: function (){
            $('#web-rtc-phone').removeClass('invisible');
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
            self.sendMessage('PING');
            if(typeof self[event.originalEvent.data.action] !== 'undefined'){
                self[event.originalEvent.data.action](event.originalEvent.data);
            }
        },
        connect: function (settings){
            console.log('start init');
            connector.init(settings.data);
        },
        addCall: function (event){
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
                let params = {
                    'action': $(this).attr('data-action'),
                    'callid': $(this).parents('.m-cdr-card').attr('data-callid')
                };
                self.sendMessage(params);
            });

            setInterval(self.updateDuration, 1000);
            self.sendMessage('init-done');

            // create a function to subscribe to topics
            self.token = PubSub.subscribe('CALLS', function (msg, data) {
                console.log( msg, data );
            });
        },
        sendMessage: function (msgData){
            window.parent.postMessage (JSON.stringify(msgData), '*')
        },
        updateDuration: function (){
            $('#web-rtc-phone .m-cdr-card').each(function (index, element) {
                let duration = Math.round((new Date()).getTime()/1000) - $(element).attr('data-start');
                $(element).find('[data-type="duration"]').text(duration + ' ' + $(element).attr('data-time-unit'));
            })
        }
    };
});
