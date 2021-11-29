<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright (C) 2017-2020 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleAmoCrm\Lib;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;

class PBXAmoResult extends PBXApiResult
{
    /**
     * @var string
     */
    public string $redirect = '';

    public array  $headers   = [];

    public string $echo_file = '';
    public string $echo      = '';
    public string $html      = '';

    /**
     * Prepare structured result
     *
     * @return array
     */
    public function getResult(): array
    {
        $result = [
            'result'    => $this->success,
            'data'      => $this->data,
            'messages'  => $this->messages,
            'function'  => $this->function,
            'processor' => $this->processor,
            'pid'       => getmypid(),
        ];

        if(!empty($this->redirect)){
            $result['redirect'] = $this->redirect;
        }
        if(!empty($this->echo_file)){
            $result['echo_file'] = $this->echo_file;
        }
        if(!empty($this->echo_file)){
            $result['echo_file'] = $this->echo_file;
        }
        if(!empty($this->html)){
            $result['html'] = $this->html;
        }
        return $result;
    }

    public function setEchoFile($path):void
    {
        if(file_exists($path)){
            $this->echo_file = $path;
        }
    }

    public function setEcho($data):void
    {
        $this->echo = $data;
    }
    public function setHtml($path):void
    {
        if(file_exists($path)){
            $this->html = file_get_contents($path);
        }
    }
}