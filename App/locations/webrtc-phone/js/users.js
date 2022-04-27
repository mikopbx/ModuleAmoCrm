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
    let self;

    return {
        table: undefined,
        statuses: {
            bridge:     'bg-warning',
            calling:    'bg-danger',
            idle:       '',
            offline:    'bg-secondary',
        },
        translates: {
            "SIP":   "Сотрудники",
            "QUEUE": "Группы"
        },
        changeState: function (number, state){
            let el = $(`#users tr[data-number='${number}']`);
            $.each( self.statuses, function(key, classes){
                el.removeClass(classes);
            });
            el.addClass(self.statuses[state]);
        },
        onMessage: function (msg, message) {
            if(message.action === 'calls'){
                self.parseCDRs(message.data)
            }else if(message.action === 'initUsers'){
                self.initUsers(message.data);
            }
        },
        parseCDRs: function (data){
            let states={};
            $.each(data, function (i, cdr){
                if(cdr.end !== ''){
                    return;
                }
                let newState, oldState;
                if(cdr.answer === ''){
                    newState = 'calling';
                }else{
                    newState = 'bridge';
                }
                oldState = states[cdr.src]||'calling';
                if(oldState !== 'bridge'){
                    states[cdr.src] = newState;
                }
                oldState = states[cdr.dst]||'calling';
                if(oldState !== 'bridge'){
                    states[cdr.dst] = newState;
                }
            });

            $('#users tr[role="button"]').each(function() {
                let number = $(this).attr('data-number');
                let state  = states[number]||'idle';
                self.changeState(number, state);
            });

        },
        init: function (){
            self = this;
            self.token = PubSub.subscribe('USERS', self.onMessage);

            $("#usersButton").on('click',function() {
                let el = $('#users-list');
                if(el.hasClass('d-none')){
                    el.removeClass('d-none');
                }else{
                    el.addClass('d-none');
                }
                PubSub.publish('CALLS', {action: 'resize', 'data': {}});
            });
        },
        dialOrTransfer:function (phone){
            let input = $("#searchInput");
            let users = $(`#users tr[role="button"]`);
            let inputVal = input.val();

            if(users.length === 1 || phone !== undefined){
                if(phone === undefined){
                    phone = users.attr('data-number');
                }
                let calls = $('#web-rtc-phone .m-cdr-card');
                if(calls.length === 0){
                    PubSub.publish('COMMAND', {action: 'call', 'phone': phone});
                }else{
                    PubSub.publish('COMMAND', {action: 'transfer', 'phone': phone});
                }
                input.val('');
                self.table.search(input.val());
                self.table.draw();
            }else if($.isNumeric(inputVal)){
                PubSub.publish('COMMAND', {action: 'call', 'phone': inputVal});
                input.val('');
                self.table.search(input.val());
                self.table.draw();
            }
        },
        initUsers: function (dataSet){
            if(self.table !== undefined){
                return;
            }
            if(dataSet.length !== 0){
                $('#users-list').removeClass('d-none');
            }
            self.table = $('#users').DataTable( {
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
                        return self.translates[group];
                    },
                },
                initComplete:function() {
                    $(this.api().table().header()).hide();
                    PubSub.publish('CALLS', {action: 'resize', 'data': {}});
                },
                createdRow: function (row, data) {
                    $(row).attr('data-number', data['number']);
                    $(row).attr('data-amo-id', data['amoId']);
                    $(row).attr('role', 'button');
                    $(row).addClass('bg-gradient bg-opacity-10');
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
                let input = $("#searchInput");
                self.table.search( input.val() );
                self.table.draw();
                PubSub.publish('CALLS', {action: 'resize', 'data': {}});
                if(e.which === 13) {
                    self.dialOrTransfer();
                }
            });

            $('#users tbody').on('dblclick', 'tr', function () {
                let data = self.table.row(this).data();
                if(data === undefined){
                    return;
                }
                self.dialOrTransfer(data.number);
            } );
        }
    }
});