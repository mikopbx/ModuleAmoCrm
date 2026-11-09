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

use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleAmoCrm\Lib\AmoCrmMain;
use Modules\ModuleAmoCrm\Lib\Logger;
use Modules\ModuleAmoCrm\Lib\SyncPageRetrier;
use Modules\ModuleAmoCrm\Lib\SyncState;

class SyncDaemon extends WorkerBase
{
    public const LIMIT_PART = 249;
    public const COLUMN_UPDATE_NAME = "updated_at";
    public const COLUMN_CREATE_NAME = "created_at";

    private Logger $logger;

    private int $initTime = 0;
    /** @var array<string,int> Последняя обработанная страница init по типам (-1 — завершён). */
    private array $initPages = [];
    private int $portalId = 0;

    /** @var array<string,int> Курсоры инкремента (last*SyncTime) по типам сущностей. */
    private array $cursors = [];

    /**
     * Handles the received signal.
     *
     * @param int $signal The signal to handle.
     *
     * @return void
     */
    public function signalHandler(int $signal): void
    {
        parent::signalHandler($signal);
        cli_set_process_title('SHUTDOWN_'.cli_get_process_title());
    }

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger =  new Logger('SyncDaemon', 'ModuleAmoCrm');
        $this->logger->writeInfo('Starting '. basename(__CLASS__).'...');

        // Защита от параллельных экземпляров SyncDaemon
        $lockFile = '/tmp/amo_sync_daemon.lock';
        $lockFp = fopen($lockFile, 'w');
        if ($lockFp === false) {
            $this->logger->writeError('Failed to open SyncDaemon lock file');
            return;
        }
        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            $this->logger->writeInfo('Another SyncDaemon instance is running, exiting');
            fclose($lockFp);
            return;
        }

        try {
            while ($this->needRestart === false) {
                if (!$this->checkInitMode()) {
                    sleep(10);
                    continue;
                }
                if (SyncState::isInitExpired($this->initTime, time())) {
                    // Init не завершился за MAX_INIT_AGE (постоянно падающая страница, отозванный токен):
                    // прерываем без deleteWithFailTime — кэш не трогаем, инкремент и ночной init снова работают.
                    $this->logger->writeError(
                        ['initTime' => $this->initTime, 'pages' => $this->initPages, 'maxAge' => SyncState::MAX_INIT_AGE],
                        'Init mode expired, aborted without cache cleanup'
                    );
                    $this->saveInitState(SyncState::abortSettings());
                    $this->initTime = 0;
                    sleep(1);
                    continue;
                }
                foreach (array_keys(SyncState::ENTITIES) as $entityType) {
                    if ($this->needRestart) {
                        break;
                    }
                    if ($this->initTime > 0 && ($this->initPages[$entityType] ?? 0) === SyncState::PAGE_DONE) {
                        // Проход этого типа в текущем init уже завершён (до перезапуска).
                        continue;
                    }
                    if (!$this->syncContacts($entityType) && $this->initTime > 0) {
                        // Сбой прохода в init: следующие типы не трогаем до успешного повтора (порядок важен —
                        // при первой установке AmoCdrDaemon ждёт завершения контактов).
                        break;
                    }
                }
                if($this->initTime > 0 && SyncState::isInitDone($this->initPages)){
                    // Атомарно в ConnectorDb: удаляем записи, не обновлённые при init, и сохраняем
                    // курсоры = начало init (инкремент догонит изменения за время полного прохода).
                    if ($this->saveInitState(SyncState::finishSettings($this->initTime), true)) {
                        $this->logger->writeInfo("End init mode, initTime: $this->initTime");
                    }
                    $this->initTime = 0;
                    sleep(1);
                }else{
                    // Инкремент, либо init не завершён (сбой загрузки страницы) — повтор с сохранённой страницы.
                    sleep(10);
                }
            }
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Проверка на режим инициализации.
     * Состояние init (initTime и страницы) хранится в настройках и переживает перезапуск.
     * @return bool false — настройки не получены, итерацию нужно пропустить.
     */
    private function checkInitMode():bool
    {
        $settings = ConnectorDb::invoke('getModuleSettings', [true])['ModuleAmoCrm']??[];
        if (empty($settings) || !is_array($settings)) {
            // ConnectorDb недоступен (например, перезапускается) — не принимаем решение о режиме
            // по пустым данным, иначе можно ошибочно стартовать init.
            return false;
        }
        $this->portalId = (int)($settings['portalId']??0);
        foreach (array_keys(SyncState::ENTITIES) as $entity) {
            $this->cursors[$entity] = (int)($settings[SyncState::cursorField($entity)] ?? 0);
        }

        $prevInitTime = $this->initTime;
        $state = SyncState::resolve($settings, time());
        if ($state['startNew'] && $this->portalId <= 0) {
            // Портал не подключён: init не стартуем, иначе срок MAX_INIT_AGE истечёт до подключения.
            $this->initTime  = 0;
            $this->initPages = $state['pages'];
            return false;
        }
        $this->initTime  = $state['initTime'];
        $this->initPages = $state['pages'];
        if ($state['startNew']) {
            // Курсоры обнулены без initSyncTime (первая установка / старый формат запуска).
            // Фиксируем init в настройках, чтобы перезапуск не превратил его в инкремент с from=0.
            // Атомарно: только если init за это время не стартовал cron.
            if (ConnectorDb::invoke('saveInitSettings', [0, SyncState::startSettings($this->initTime)]) !== true) {
                $this->initTime = 0;
                return false;
            }
            $this->logger->writeInfo("Start init mode, initTime: $this->initTime");
        } elseif ($this->initTime > 0 && $prevInitTime !== $this->initTime) {
            $this->logger->writeInfo(
                ['initTime' => $this->initTime, 'pages' => $this->initPages],
                $state['resumed'] ? 'Resume init mode' : 'Start init mode'
            );
        }
        return true;
    }

    /**
     * Изменение состояния init с атомарной проверкой в ConnectorDb (saveInitSettings):
     * применяется, только если сохранённый initSyncTime всё ещё равен initTime этого процесса.
     *
     * Cron может заменить просроченный init (SyncState::canStartScheduledInit), пока этот процесс
     * в проходе со старым initTime. Без проверки он записал бы свои страницы / PAGE_DONE в состояние
     * нового init, отменил бы его, а deleteWithFailTime удалил бы кэш.
     * Недоступность ConnectorDb трактуем как отказ: проход прерывается и продолжится с сохранённой страницы.
     * @param array $data
     * @param bool  $deleteNotSynced Удалить записи, не обновлённые в этом init (завершение init).
     * @return bool
     */
    private function saveInitState(array $data, bool $deleteNotSynced = false): bool
    {
        $result = ConnectorDb::invoke('saveInitSettings', [$this->initTime, $data, $deleteNotSynced]);
        if ($result !== true) {
            $this->logger->writeError(
                ['initTime' => $this->initTime, 'data' => array_keys($data), 'result' => $result],
                'Init state is not saved (init replaced/finished by another process or ConnectorDb unavailable)'
            );
            return false;
        }
        return true;
    }

    /**
     * Синхронизация контактов.
     * @param string $entityType
     * @return bool false — проход прерван (сбой загрузки страницы или остановка воркера).
     */
    private function syncContacts(string $entityType):bool
    {
        if($this->portalId <=0 ){
            // Нет подключения к порталу.
            return false;
        }
        $endTime = time();
        $fieldName = SyncState::cursorField($entityType);
        $updateFunc = [
            AmoCrmMain::ENTITY_CONTACTS  => 'updatePhoneBook',
            AmoCrmMain::ENTITY_COMPANIES => 'updatePhoneBook',
            AmoCrmMain::ENTITY_LEADS     => 'updateLeads'
        ];
        // Последняя сохранённая в БД страница init (при продолжении — перекрытие начинается ниже неё).
        $savedPage = $this->initTime > 0 ? max(0, (int)($this->initPages[$entityType] ?? 0)) : 0;
        if($this->initTime === 0){
            $params = [
                'order['.self::COLUMN_UPDATE_NAME.']' => 'desc',
                'filter['.self::COLUMN_UPDATE_NAME.'][from]'  => $this->cursors[$entityType] ?? 0,
                'filter['.self::COLUMN_UPDATE_NAME.'][to]'    => $endTime,
                'limit' => self::LIMIT_PART
            ];
        }else{
            $params = [
                'order[id]' => 'desc',
                'limit' => self::LIMIT_PART
            ];
            $startPage = SyncState::resumePage((int)($this->initPages[$entityType] ?? 0));
            if ($startPage > 1) {
                // Продолжение init после перезапуска: повторяем последнюю обработанную страницу.
                $params['page'] = $startPage;
                $this->logger->writeInfo("Resume init '$entityType' from page $startPage");
            }
        }
        $url = "https://".AmoCrmMain::EMPTY_HOST_VALUE."/api/v4/$entityType";
        if($entityType === AmoCrmMain::ENTITY_LEADS){
            $params['with'] = 'contacts';
        }
        $nextPage = $url."?".http_build_query($params);
        $count = 0;
        while(!empty($nextPage)){
            $pageUrl = $nextPage;
            $logUrl = parse_url($nextPage, PHP_URL_PATH);
            $logQuery = parse_url($nextPage, PHP_URL_QUERY);
            $this->logger->writeInfo("SYNC: $count, GET '$entityType' ".$logUrl.($logQuery ? "?$logQuery" : ''));
            $retryResult = SyncPageRetrier::run(
                $pageUrl,
                function (string $retryUrl) use ($entityType): array {
                    $result = WorkerAmoHTTP::invokeAmoApi('getChangedEntity', [$retryUrl, $entityType]);
                    $success = $result !== false
                        && empty($result->messages)
                        && isset($result->data[$entityType]);
                    if (!$success) {
                        $errCtx = [
                            'messages' => isset($result->messages) ? $result->messages : null,
                            'data' => isset($result->data) ? $result->data : null,
                            'url' => $retryUrl,
                        ];
                        $this->logger->writeError($errCtx, 'Fail getChangedEntity attempt');
                    }
                    return ['success' => $success, 'result' => $result];
                },
                static function (): void {
                    sleep(10);
                }
            );
            $result = $retryResult['result'];

            if (!$retryResult['success']) {
                $errCtx = [
                    'entityType' => $entityType,
                    'url' => $pageUrl,
                    'from' => $this->cursors[$entityType] ?? 0,
                    'to' => $endTime,
                    'attempts' => $retryResult['attempts'],
                    'messages' => isset($result->messages) ? $result->messages : null,
                    'data' => isset($result->data) ? $result->data : null,
                ];
                if ($this->initTime > 0) {
                    // В init не отмечаем проход завершённым: иначе deleteWithFailTime удалит
                    // не загруженные записи. Сохраняем последнюю успешную страницу (прогресс пишется
                    // раз в N страниц, а checkInitMode перечитает его из БД) — следующая итерация
                    // продолжит с неё, а не перекачает до N страниц.
                    $lastPage = (int)($this->initPages[$entityType] ?? 0);
                    if ($lastPage > $savedPage) {
                        $this->saveInitState([SyncState::pageField($entityType) => $lastPage]);
                    }
                    $this->logger->writeError($errCtx, 'Fail getChangedEntity after retries, init pass will be resumed');
                    return false;
                }
                // Инкремент: курсор не сдвигаем, иначе изменения за [from, endTime] будут потеряны.
                // Следующая итерация повторит тот же интервал (from прежний, to — новое время).
                $this->logger->writeError($errCtx, 'Fail getChangedEntity after retries, sync interval will be retried');
                return false;
            }

            $nextPage = $result->data['nextPage'];
            $count += count($result->data[$entityType]);
            $chunks   = array_chunk($result->data[$entityType], 25, false);
            foreach ($chunks as $chunk){
                $data = [
                    'update' => $chunk,
                    'source' => self::class
                ];
                if($this->initTime > 0){
                    $data['initTime'] = $this->initTime;
                }
                $resultSave = ConnectorDb::invoke($updateFunc[$entityType], [$data]);
                if(!isset($resultSave['update-all']) && $entityType !== AmoCrmMain::ENTITY_LEADS){
                    $this->logger->writeError($resultSave, 'Fail save contact data...');
                }
                usleep(100000);
            }
            if ($this->initTime > 0) {
                // Прогресс init: после перезапуска продолжим с этой страницы (с перекрытием).
                // Сохраняем раз в N страниц и при остановке воркера — каждое сохранение порождает
                // запись в БД и событие изменения модели.
                $page = SyncState::pageFromUrl($pageUrl);
                $this->initPages[$entityType] = $page;
                if ($this->needRestart || SyncState::shouldSaveProgress($page)) {
                    if (!$this->saveInitState([SyncState::pageField($entityType) => $page])) {
                        return false;
                    }
                    $savedPage = $page;
                }
            }
            $this->logger->rotate();
            if ($this->needRestart) {
                // Остановка воркера посреди прохода: не сохраняем курсор и не отмечаем init завершённым.
                return false;
            }
        }
        // В init курсор = initTime: изменения, сделанные во время прохода, догонит инкремент.
        // (finishSettings выставит то же значение; при первой установке это разрешает работу AmoCdrDaemon
        // сразу после контактов.)
        if ($this->initTime > 0) {
            // Завершение прохода в init — синхронно и с проверкой, что init не сменился.
            $newSettings = [
                $fieldName => $this->initTime,
                SyncState::pageField($entityType) => SyncState::PAGE_DONE,
            ];
            if (!$this->saveInitState($newSettings)) {
                return false;
            }
            $this->initPages[$entityType] = SyncState::PAGE_DONE;
        } else {
            ConnectorDb::invoke('saveNewSettings', [[$fieldName => $endTime]], false);
        }
        $this->cursors[$entityType] = $this->initTime > 0 ? $this->initTime : $endTime;
        return true;
    }
}

if(isset($argv) && count($argv) !== 1){
    SyncDaemon::startWorker($argv??[]);
}
