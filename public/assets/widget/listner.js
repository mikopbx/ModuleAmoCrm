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

eventSource = new EventSource("https://172.16.156.223/pbxcore/api/nchan/sub/calls?token=test", {
    withCredentials: false
});
eventSource.onmessage = function(event) {
    console.log("New message", event.data);
    // will log 3 times for the data stream above
};

eventSource.onerror = function(event) {
    console.log("Error", event.data);
    // will log 3 times for the data stream above
};

setInterval(function(){
    if(eventSource.readyState !== 1){
        // console.log('---', eventSource, eventSource.readyState);
    }
}, 5);

/*
curl 'https://172.16.156.223/pbxcore/api/nchan/sub/calls?token=test' \
-X 'GET' \
-H 'Accept: text/event-stream' \
-H 'Cache-Control: no-cache' \
-H 'Origin: null' \
-H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Safari/605.1.15' \
-H 'Pragma: no-cache' -k

curl --request POST --data "test message" -H "Accept: text/json" https://172.16.156.223/pbxcore/api/nchan/pub/calls
*/