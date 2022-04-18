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

requirejs.config({
    urlArgs: "bust=" + (new Date()).getTime(),
    baseUrl: 'js',
    paths: {
        'jquery':         'vendors/jquery-3.6.0.min',
        'datatables.net': 'vendors/jquery.dataTables-1.11.5.min',
        'rowGroup':       'vendors/dataTables.rowGroup-1.1.4.min',
        'moment':         'vendors/moment-with-locales.min',
        'twig':           'vendors/twig.min',
        'pubsub':         'vendors/pubsub.min',
        'cache':          'vendors/localstorage-slim',
        'bootstrap':      '../bootstrap/js/bootstrap.bundle.min',
    },
});

requirejs(['main']);
