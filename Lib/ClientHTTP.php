<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
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
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp;
use MikoPBX\Core\System\Util;
use Throwable;

class ClientHTTP
{
    /**
     * Отправка POST запроса к API.
     * @param string $url
     * @param array $params
     * @param array $headers
     * @return PBXAmoResult
     */
    public static function sendHttpPostRequest(string $url, array $params, array $headers=[]):PBXAmoResult{
        $client  = new GuzzleHttp\Client();
        $options = [
            'timeout'       => 5,
            'http_errors'   => false,
            'headers'       => $headers,
            'json'          => $params,
        ];
        $message = '';
        $resultHttp = null;
        try {
            $resultHttp = $client->request('POST', $url, $options);
            $code       = $resultHttp->getStatusCode();
        }catch (GuzzleHttp\Exception\ConnectException $e ){
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "ConnectException");
            $code = 0;
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "GuzzleException");
            $code = 0;
        }
        return ClientHTTP::parseResponse($resultHttp, $message, $code);
    }

    /**
     * Отправка POST запроса к API.
     * @param string $url
     * @param array $params
     * @param array $headers
     * @return PBXAmoResult
     */
    public static function sendHttpPatchRequest(string $url, array $params, array $headers=[]):PBXAmoResult{
        $client  = new GuzzleHttp\Client();
        $options = [
            'timeout'       => 5,
            'http_errors'   => false,
            'headers'       => $headers,
            'json'          => $params,
        ];
        $message = '';
        $resultHttp = null;
        try {
            $resultHttp = $client->request('PATCH', $url, $options);
            $code       = $resultHttp->getStatusCode();
        }catch (GuzzleHttp\Exception\ConnectException $e ){
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "ConnectException");
            $code = 0;
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "GuzzleException");
            $code = 0;
        }
        return ClientHTTP::parseResponse($resultHttp, $message, $code);
    }

    /**
     * Отправка http GET запроса.
     * @param string $url
     * @param array  $params
     * @param array  $headers
     * @return PBXAmoResult
     */
    public static function sendHttpGetRequest(string $url, array $params, array $headers=[]):PBXAmoResult{
        if(!empty($params)){
            $url .= "?".http_build_query($params);
        }
        $client  = new GuzzleHttp\Client();
        $options = [
            'timeout'       => 5,
            'http_errors'   => false,
            'headers'       => $headers,
        ];
        $message = '';
        $resultHttp = null;
        try {
            $resultHttp = $client->request('GET', $url, $options);
            $code       = $resultHttp->getStatusCode();
        }catch (GuzzleHttp\Exception\ConnectException $e ){
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "ConnectException");
            $code = 0;
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            Util::sysLogMsg('ModuleAmoCrm', "GuzzleException");
            $code = 0;
        }
        return self::parseResponse($resultHttp, $message, $code);
    }

    /**
     * Разбор ответа сервера.
     * @param $resultHttp
     * @param $message
     * @param $code
     * @return PBXAmoResult
     */
    private static function parseResponse($resultHttp, $message, $code):PBXAmoResult
    {
        $res = new PBXAmoResult();
        if( isset($resultHttp) && ($code === 200 || $resultHttp->getReasonPhrase() === 'Accepted')){
            $content = $resultHttp->getBody()->getContents();
            $data    = [];
            try {
                $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            }catch (Throwable $e){
                $message = $e->getMessage();
            }
            $res->success = is_array($data);
            if($res->success){
                $res->data = $data;
            }else{
                $res->messages[] = $content;
                $res->messages[] = $message;
            }
        }else{
            $res->success = false;
            $res->messages['error-code'] = $code;
            $res->messages['error-msg']  = $message;
            if($resultHttp){
                try {
                    $res->messages['error-string']  = $resultHttp->getBody()->getContents();
                    $res->messages['error-data']    = json_decode($res->messages['error-string'], true);
                }catch (Throwable $e){
                    $res->messages['error-data']    = [];
                }
            }
        }
        return $res;
    }
}