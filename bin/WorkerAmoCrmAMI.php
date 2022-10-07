<?php
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

namespace Modules\ModuleAmoCrm\bin;
require_once 'Globals.php';

use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;

class WorkerAmoCrmAMI extends WorkerBase
{
    public const CHANNEL_CALL_NAME = 'http://127.0.0.1/pbxcore/api/nchan/pub/calls';
    public const CHANNEL_CDR_NAME  = 'http://127.0.0.1/pbxcore/api/amo/pub/active-calls';
    public const CHANNEL_USERS_NAME= 'http://127.0.0.1/pbxcore/api/amo/pub/users';

    private AmoCrmMain $amoApi;
    private int     $extensionLength = 3;
    private array   $users = [];
    private array   $innerNums = [];
    private bool    $useInterception = false;
    private int     $lastUpdateSettings = 0;

    private $beanstalk;

    /**
     * Соответстввие Linked ID и сведиений о каналах.
     * @var array
     */
    private array $calls = [];

    /**
     * Счетчик каналов. Считаем каналы при входящем с множественной регистрацией.
     * @var array
     */
    private array $channelCounter = [];

    /**
     * Соответствие канала и номера телефона.
     * @var array
     */
    private array $activeChannels = [];

    /**
     * Старт работы листнера.
     *
     * @param $params
     */
    public function start($params):void
    {
        $this->amoApi    = new AmoCrmMain();
        $this->beanstalk = new BeanstalkClient(WorkerAmoContacts::class);

        $this->am     = Util::getAstManager();
        $this->setFilter();
        $this->checkUpdateSettings();

        $this->am->addEventHandler("userevent", [$this, "callback"]);
        while (true) {
            $result = $this->am->waitUserEvent(true);
            if (!$result) {
                // Нужен реконнект.
                usleep(100000);
                $this->am = Util::getAstManager();
                $this->setFilter();
            }
        }
    }

    private function checkUpdateSettings():void
    {
        if(time() - $this->lastUpdateSettings <= 10){
            return;
        }
        $this->lastUpdateSettings = time();
        [, $this->users, $this->innerNums] = AmoCrmMain::updateUsers();
        $settings = ModuleAmoCrm::findFirst();
        if($settings){
            $this->useInterception = $settings->useInterception;
        }
    }

    /**
     * Установка фильтра
     *
     */
    private function setFilter():void
    {
        $pingTube = $this->makePingTubeName(self::class);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: '.$pingTube];
        $this->am->sendRequestTimeout('Filter', $params);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: Interception'];
        $this->am->sendRequestTimeout('Filter', $params);
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: CdrConnector'];
        $this->am->sendRequestTimeout('Filter', $params);
    }

    /**
     * Событие для перехвата на ответственного.
     * @param $data
     * @return void
     */
    private function interception($data):void
    {
        if(!$this->useInterception){
            return;
        }
        $params = [
            'id'      => $data['Linkedid'],
            'date'    => date(\DateTimeInterface::ATOM),
            'phone'   => $data['CALLERID'],
            'channel' => $data['chan1c'],
            'did'     => $data['FROM_DID'],
            'action'  => 'interception'
        ];
        $this->beanstalk->publish(json_encode($params));
    }

    /**
     * Функция обработки оповещений.
     * @param $parameters
     * @return void
     * @throws \JsonException
     */
    public function callback($parameters):void{

        if ($this->replyOnPingRequest($parameters)){
            return;
        }
        if (stripos($parameters['UserEvent'],'InterceptionAMO' ) !== false) {
            $this->interception($parameters);
            return;
        }
        if ('CdrConnector' !== $parameters['UserEvent']) {
            return;
        }
        $this->checkUpdateSettings();

        $data = json_decode(base64_decode($parameters['AgiData']), true, 512, JSON_THROW_ON_ERROR);
        switch ($data['action']) {
            case 'hangup_chan':
                $this->actionHangupChan($data);
                break;
            case 'dial_create_chan':
            case 'transfer_dial_create_chan':
                $this->actionDialCreateChan($data);
                break;
            case 'dial_answer':
            case 'transfer_dial_answer':
                $this->actionDialAnswer($data);
                break;
            case 'dial_end':
                $this->actionDialEnd($data);
                break;
            case 'dial':
            case 'transfer_dial':
            case 'sip_transfer':
            case 'answer_pickup_create_cdr':
                $this->actionDial($data);
                break;
            case 'hangup_update_cdr':
                $this->actionCompleteCdr($data);
                break;
            default:
                break;
        }

    }

    /**
     * Обработка оповещения о звонке.
     *
     * @param $data
     */
    private function actionDial($data):void {
        $findContactsParams = [
            'action'  => 'findContacts',
            'numbers' => [
                $data['src_num'],
                $data['dst_num'],
            ]
        ];
        $general_src_num = null;
        if ($data['transfer'] === '1') {
            $history = $this->calls[$data['linkedid']]??[];
            if (!empty($history)) {
                // Определим номер того, кого переадресуют.
                if ($data['src_num'] === $history[0]['src']) {
                    $general_src_num = $history[0]['dst'];
                } else {
                    $general_src_num = $history[0]['src'];
                }
                $findContactsParams['numbers'][] = $general_src_num;
            }
        }
        $this->beanstalk->publish(json_encode($findContactsParams));
        $this->actionCreateCdr($data, $general_src_num);
    }

    /**
     * Обработка оповещения о начале телефонного звонка.
     *
     * @param $data
     * @param $generalNumber
     */
    private function actionCreateCdr($data, $generalNumber):void
    {
        $tmpCalls = [];
        if(in_array($data['action_extra']??'', ['originate_start', 'originate_end'], true)){
            return;
        }
        $this->createCdrCheckInner($tmpCalls, $data, $generalNumber);
        $this->createCdrCheckOutgoing($tmpCalls, $data, $generalNumber);
        $this->createCdrCheckIncoming($tmpCalls, $data, $generalNumber);
        foreach ($tmpCalls as $call){
            $callFound = false;
            foreach ($this->calls[$data['linkedid']] as $oldCall) {
                if($call['uid'] === $oldCall['uid']){
                    $callFound = true;
                    break;
                }
            }
            if(!$callFound){
                $this->calls[$call['id']][] = $call;
            }
            $this->activeChannels[$data['src_chan']] = $data['src_num'];
            $this->amoApi->sendHttpPostRequest(self::CHANNEL_CALL_NAME, $call);
        }
    }

    /**
     * Проверка на внутренний вызов.
     * @param $calls
     * @param $data
     * @param $generalNumber
     * @return void
     */
    private function createCdrCheckInner(&$calls, $data, $generalNumber):void
    {
        if(strlen($data['src_num']) <= $this->extensionLength
            && strlen($data['dst_num']) <= $this->extensionLength
            && strlen($generalNumber) <= $this->extensionLength){

            $srcUser = $this->users[$data['src_num']]??'';
            $dstUser = $this->users[$data['dst_num']]??'';

            $param = [
                'uid'              => $data['UNIQUEID'],
                'id'               => $data['linkedid'],
                'date'             => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                'user'             => $srcUser,
                'src'              => $data['src_num'],
                'dst'              => $data['dst_num'],
                'src-chan'         => $data['src_chan'],
                'dst-chan'         => '',
                'action'           => 'call',
            ];

            $calls[] = $param;
            if($dstUser !== $srcUser){
                $param['user'] = $dstUser;
                $calls[] = $param;
            }

        }
    }

    /**
     * Проверка на входящий вызов.
     * @param $calls
     * @param $data
     * @param $generalNumber
     * @return void
     */
    private function createCdrCheckIncoming(&$calls, $data, $generalNumber):void
    {
        if(in_array($data['src_num'], $this->innerNums, true)
           || in_array($generalNumber, $this->innerNums, true)){
            // Это точно не входящий. Как вариант - внутренний.
            return;
        }

        // Это входящий вызов на внутренний номер сотрудника.
        if (strlen($generalNumber) > $this->extensionLength && !in_array($generalNumber, $this->innerNums, true)) {
            // Это переадресация вызова. (консультационная)
            $calls[] = [
                'uid'              => $data['UNIQUEID'],
                'id'               => $data['linkedid'],
                'date'             => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                'user'             => $this->users[$data['dst_num']]??'',
                'g-src'            => $generalNumber,
                'src'              => $data['src_num'],
                'dst'              => $data['dst_num'],
                'src-chan'         => $data['src_chan'],
                'action'           => 'call',
                'dst-chan'         => '',
            ];
        } elseif (strlen($data['src_num']) > $this->extensionLength && !in_array($data['src_num'], $this->innerNums, true)) {
            // Входящий вызов с номера клиента.
            $calls[] = [
                'uid'              => $data['UNIQUEID'],
                'id'               => $data['linkedid'],
                'date'             => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                'user'             => $this->users[$data['dst_num']]??'',
                'src'              => $data['src_num'],
                'dst'              => $data['dst_num'],
                'action'           => 'call',
                'src-chan'         => $data['src_chan'],
                'dst-chan'         => '',
            ];
        }
    }

    /**
     * Проверка на исходящий вызов.
     * @param $calls
     * @param $data
     * @param $generalNumber
     * @return void
     */
    private function createCdrCheckOutgoing(&$calls, $data, $generalNumber):void
    {
        if (in_array($data['src_num'], $this->innerNums, true)
            && strlen($generalNumber) <= $this->extensionLength
            && strlen($data['dst_num']) > $this->extensionLength
            && !in_array($data['dst_num'], $this->innerNums, true))
        {
            // Это исходящий вызов с внутреннего номера.
            $calls[] = [
                'uid'              => $data['UNIQUEID'],
                'id'               => $data['linkedid'],
                'date'             => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
                'user'             => $this->users[$data['src_num']]??'',
                'src'              => $data['src_num'],
                'dst'              => $data['dst_num'],
                'src-chan'         => $data['src_chan'],
                'dst-chan'         => '',
                'action'           => 'call',
            ];
        }
    }

    /**
     * Завершение телефонного звонка.
     * @param $data
     * @return void
     */
    private function actionHangupChan($data):void
    {
        $channels = [];
        $channel = $data['agi_channel'];
        if(strpos($channel, 'Local/') === 0){
            return;
        }
        $transferCall = [];
        $data['end'] = date(\DateTimeInterface::ATOM, strtotime($data['end']));
        foreach ($this->calls[$data['linkedid']] as &$call) {
            if(isset($call['end'])){
                continue;
            }
            if($channel !== $call['src-chan'] && $channel !== $call['dst-chan']){
                continue;
            }
            $call['end'] = $data['end'];
            $channels[] = [$call['src-chan'], $call['uid']];
            $channels[] = [$call['dst-chan'], $call['uid']];
            $this->cloneCdr($data, $transferCall, $call);
        }
        unset($call, $transferCall);
        // Считаем каналы с одинаковым UID
        $countChannel = $this->channelCounter[$data['UNIQUEID']]??0;
        $countChannel--;
        if($countChannel>0){
            $this->channelCounter[$data['UNIQUEID']] = $countChannel;
            // Не все каналы с этим ID были завершены.Вероятно это множественная регистрация.
            return;
        }
        foreach ($channels as $channelData){
            [$channel, $uid] = $channelData;
            if(!isset($this->activeChannels[$channel])){
                continue;
            }
            $phone  = $this->activeChannels[$channel];
            $userId = $this->users[$phone]??null;
            if(empty($userId)){
                continue;
            }
            $params = [
                'id'      => $data['linkedid'],
                'date'    => $data['end'],
                'uid'     => $uid,
                'user'    => $userId,
                'action'  => 'hangup'
            ];
            $this->amoApi->sendHttpPostRequest(self::CHANNEL_CALL_NAME, $params);
        }
    }

    /**
     *
     * @param $data - Данные события завершения вызова.
     * @param $transferCall - Вызов, который переводят.
     * @param $call - Звонок / консультативная переадресация.
     * @return void
     */
    private function cloneCdr($data, &$transferCall, $call):void
    {
        $endTime = date(\DateTimeInterface::ATOM, strtotime($data['end']));
        if(empty($transferCall)){
            $transferCall = $call;
            $transferCall['date']   = $endTime;
            $transferCall['answer'] = $endTime;
            return;
        }
        // Канал того, кто переадресует.
        $channel = $data['agi_channel'];
        if($transferCall['src-chan'] !== $channel){
            $transferCall['dst-chan'] = $call['dst-chan'];
            $transferCall['dst']      = $call['dst'];
        }else{
            $transferCall['src-chan'] = $call['dst-chan'];
            $transferCall['src']      = $call['dst'];
        }
        $transferCall['uid'] = md5($call['uid']. $transferCall['uid']);
        unset($transferCall['end']);
        if(!isset($this->users[$call['dst']])){
            return;
        }
        $this->calls[$data['linkedid']][]= $transferCall;
        $this->activeChannels[$transferCall['src-chan']] = $transferCall['src'];
        $this->activeChannels[$transferCall['dst-chan']] = $transferCall['dst'];

        $params = [
            'id'      => $data['linkedid'],
            'date'    => $endTime,
            'uid'     => $transferCall['uid'],
            'user'    => $this->users[$call['dst']],
            'src'     => $transferCall['src'],
            'dst'     => $transferCall['dst'],
            'action'  => 'call'
        ];
        $this->amoApi->sendHttpPostRequest(self::CHANNEL_CALL_NAME, $params);

        $data = [
            'action'   => 'answer',
            'date'     => $endTime,
            'id'       => $data['linkedid'],
            'uid'      => $transferCall['uid'],
        ];
        $this->amoApi->sendHttpPostRequest(self::CHANNEL_CALL_NAME, $data);
    }

    /**
     * Создание нового канала (dst_chan).
     * @param $data
     * @return void
     */
    private function actionDialCreateChan($data):void{
        $uid = $data['transfer_UNIQUEID']??$data['UNIQUEID'];
        foreach ($this->calls[$data['linkedid']] as &$call){
            if($uid !== $call['uid']){
                continue;
            }
            $call['dst-chan'] = $data['dst_chan'];
            break;
        }
        unset($call);

        // Считаем каналы с одинаковым UID
        $countChannel = $this->channelCounter[$uid]??0;
        $countChannel++;
        $this->channelCounter[$uid] = $countChannel;

        $chan   = str_replace('/','-', $data['dst_chan']);
        $number = explode('-', $chan)[1]??'';
        if(!isset($this->users[$number])){
            // нет такого пользователя в Amo.
            return;
        }
        $this->activeChannels[$data['dst_chan']] = $number;

        $eventTime = isset($data['event_time'])?strtotime($data['event_time']):time();
        $params = [
            'id'      => $data['linkedid'],
            'date'    => date(\DateTimeInterface::ATOM, $eventTime),
            'uid'     => $uid,
            'user'    => $this->users[$number],
            'action'  => 'create-chan'
        ];
        $this->amoApi->sendHttpPostRequest(self::CHANNEL_CALL_NAME, $params);
    }

    /**
     * Скрываем карточку звонка для всех агентов, кто пропустил вызов.
     * @param $params
     */
    private function actionDialAnswer($params):void
    {
        $channel = $params['agi_channel'];
        foreach ($this->calls[$params['linkedid']] as &$call){
            if(isset($call['answer'])){
                continue;
            }
            if($channel !== $call['src-chan'] && $channel !== $call['dst-chan']){
                continue;
            }
            $call['answer'] = date(\DateTimeInterface::ATOM, strtotime($params['answer']));
            $data = [
                'action'   => 'answer',
                'date'     => $call['answer'],
                'id'       => $params['linkedid'],
                'uid'      => $call['uid'],
            ];
            $this->amoApi->sendHttpPostRequest(self::CHANNEL_CALL_NAME, $data);
            break;
        }
        unset($call);
    }

    /**
     * Завершение попытки звонка на внутренний номер. Контакт не определн. Dial не был вызван.
     * @param $data
     * @return void
     */
    private function actionDialEnd($data):void
    {
        $src_num = $this->activeChannels[$data['src_chan']];
        if (isset($this->users[$src_num])) {
            // Это исходящий вызов.
            $USER_ID = $this->users[$src_num];
        } else {
            return;
        }
        $call = [
            'uid'              => $data['UNIQUEID'],
            'id'               => $data['linkedid'],
            'date'             => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
            'user'             => $USER_ID,
            'src'              => $src_num,
            'dst'              => '', // Канал назначения не был создан.
            'action'           => 'end-dial',
        ];
        $this->amoApi->sendHttpPostRequest(self::CHANNEL_CALL_NAME, $call);
    }

    /**
     * Обработка события завершения телефонного звонка.
     *
     * @param $data
     */
    private function actionCompleteCdr($data):void
    {
        // Это событие приходит только когда все cdr обработаны.
        if (isset($this->users[$data['src_num']])) {
            // Это исходящий вызов.
            $USER_ID = $this->users[$data['src_num']];
        } elseif (isset($this->users[$data['dst_num']])) {
            // Это входящие вызов.
            $USER_ID = $this->users[$data['dst_num']];
        } else {
            return;
        }
        $uid     = $data['UNIQUEID'];
        $endTime = date(\DateTimeInterface::ATOM, strtotime($data['endtime']));
        $start   = date(\DateTimeInterface::ATOM, strtotime($data['start']));
        foreach ( $this->calls[$data['linkedid']] as $index => $callData){
            if($callData['src'] === $data['src_num'] && $callData['dst'] === $data['dst_num']
               && $callData['date'] === $start && $callData['end'] === $endTime){
                $uid = $callData['uid'];
                unset($this->calls[$data['linkedid']][$index]);
                break;
            }
        }

        $call = [
            'uid'              => $uid,
            'id'               => $data['linkedid'],
            'date'             => date(\DateTimeInterface::ATOM, strtotime($data['start'])),
            'user'             => $USER_ID,
            'src'              => $data['src_num'],
            'dst'              => $data['dst_num'],
            'g-missed'         => $data['GLOBAL_STATUS'] !== 'ANSWERED',
            'missed'           => $data['disposition'] !== 'ANSWERED',
            'filename'         => $data['recordingfile'],
            'action'           => 'end-call',
        ];
        $this->amoApi->sendHttpPostRequest(self::CHANNEL_CALL_NAME, $call);

        // Чистим мусор.
        unset(
            $this->activeChannels[$data['src_chan']],
            $this->activeChannels[$data['dst_chan']],
            $this->channelCounter[$data['UNIQUEID']]
        );
        if(empty($this->calls[$data['linkedid']])){
            unset($this->calls[$data['linkedid']]);
        }
    }
}

WorkerAmoCrmAMI::startWorker($argv??null);