<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
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

use GuzzleHttp;
use MikoPBX\Common\Models\LanInterfaces;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAmoCrm\bin\ConnectorDb;
use Modules\ModuleAmoCrm\bin\WorkerAmoHTTP;
use Throwable;

/**
 * Настройка интеграции в amoCRM: список пользователей, настройки виджета, webhook.
 * Вызовы API идут через WorkerAmoHTTP (каждый — отдельный запрос), запись в БД — через ConnectorDb.
 * Состояние сохраняется в ModuleAmoCrm.integrationState и показывается в настройках модуля.
 */
class IntegrationSync
{
    public const MODE_ALL    = 'all';
    public const MODE_USERS  = 'users';
    public const MODE_WIDGET = 'widget';

    /** Попытки создания webhook при случайной ошибке "Invalid URL". */
    private const WEBHOOK_ATTEMPTS = 3;
    private const WEBHOOK_RETRY_DELAY = 3;

    /** Максимум страниц каталога виджетов при поиске приватного виджета. */
    private const MAX_WIDGET_PAGES = 40;

    private ?Logger $logger;
    private array $settings = [];
    private array $state = [];
    private string $widgetCode = '';

    /**
     * @param bool $withLogger false — без записи в лог модуля (веб-интерфейс работает не от root).
     */
    public function __construct(bool $withLogger = true)
    {
        $this->logger = $withLogger ? new Logger('IntegrationSync', 'ModuleAmoCrm') : null;
    }

    /**
     * Запуск синхронизации отдельным процессом. Вызывать из процессов root (воркеры, REST, cron).
     * @param string $mode  all | users | widget
     * @param int    $delay Пауза перед запуском, секунд.
     * @return void
     */
    public static function startInBackground(string $mode = self::MODE_ALL, int $delay = 0): void
    {
        if (!in_array($mode, [self::MODE_ALL, self::MODE_USERS, self::MODE_WIDGET], true)) {
            $mode = self::MODE_ALL;
        }
        $delay  = max(0, min(300, $delay));
        $php    = Util::which('php');
        $script = dirname(__DIR__) . '/bin/sync-integration.php';
        Processes::mwExecBg("$php -f $script $mode $delay");
    }

    /**
     * Выполняет синхронизацию в текущем процессе.
     * @param string $mode all | users | widget
     * @return array Состояние интеграции.
     */
    public function run(string $mode = self::MODE_ALL): array
    {
        if (!$this->loadSettings()) {
            return [];
        }
        $widget = $this->readWidget();
        if ($mode === self::MODE_ALL || $mode === self::MODE_USERS) {
            $this->refreshUsers($widget);
        }
        if ($mode === self::MODE_ALL || $mode === self::MODE_WIDGET) {
            $this->pushWidget($widget);
        }
        if ($mode === self::MODE_ALL) {
            $this->syncWebhook();
        }
        $this->saveState();
        return $this->state;
    }

    /**
     * Обновляет список пользователей из amoCRM (для кнопки в настройках модуля).
     * @return array ['success' => bool, 'users' => [...], 'message' => string]
     */
    public function refreshUsersNow(): array
    {
        if (!$this->loadSettings()) {
            return ['success' => false, 'users' => [], 'message' => 'Module settings are unavailable'];
        }
        $widget = $this->readWidget();
        $users  = $this->refreshUsers($widget);
        $this->saveState();
        return [
            'success' => ($this->state['users']['state'] ?? '') === IntegrationConfig::STATE_OK,
            'users'   => $users,
            'message' => (string)($this->state['users']['message'] ?? ''),
        ];
    }

    /**
     * Внешний адрес АТС: из настроек модуля, иначе из сетевых настроек.
     * @param array $settings
     * @return string
     */
    public static function resolveHost(array $settings): string
    {
        $fallback = '';
        try {
            $lanData = LanInterfaces::findFirst("internet = '1'");
            if ($lanData !== null) {
                $fallback = (string)$lanData->exthostname;
            }
        } catch (Throwable $e) {
            $fallback = '';
        }
        return IntegrationConfig::resolveHost((string)($settings['externalHostname'] ?? ''), $fallback);
    }

    /**
     * @return bool
     */
    private function loadSettings(): bool
    {
        $all = ConnectorDb::invoke('getModuleSettings', [true]);
        $settings = is_array($all) ? ($all['ModuleAmoCrm'] ?? []) : [];
        if (empty($settings) || !is_array($settings)) {
            $this->log('Module settings are unavailable (ConnectorDb)', '', true);
            return false;
        }
        $this->settings   = $settings;
        $this->widgetCode = trim((string)($settings['widgetCode'] ?? ''));
        $state = json_decode((string)($settings['integrationState'] ?? ''), true);
        $this->state = is_array($state) ? $state : [];
        return true;
    }

    /**
     * Находит виджет MikoPBX в аккаунте и читает его настройки.
     * @return array|null data из AmoCrmMain::getWidgetSettings() или null.
     */
    private function readWidget(): ?array
    {
        $uuid       = $this->integrationUuid();
        $candidates = [];
        if ($this->widgetCode !== '') {
            $candidates[] = $this->widgetCode;
        }
        if (!$this->isPrivateWidget()) {
            $candidates[] = IntegrationConfig::PUBLIC_WIDGET_CODE;
        }
        $lastError = null;
        foreach (array_unique($candidates) as $code) {
            $res = WorkerAmoHTTP::invokeAmoApi('getWidgetSettings', [$code]);
            if ($res->success && ($res->data['client_uuid'] ?? '') === $uuid) {
                return $this->acceptWidget($res->data);
            }
            if (!$res->success) {
                $lastError = $res;
                $state = $this->classifyError($res);
                if ($state === IntegrationConfig::STATE_NOT_AUTHORIZED) {
                    $this->setState('widget', $state, $this->errorMessage($res));
                    return null;
                }
            }
        }
        if ($this->isPrivateWidget()) {
            $code = $this->findPrivateWidgetCode($uuid);
            if ($code !== '') {
                $res = WorkerAmoHTTP::invokeAmoApi('getWidgetSettings', [$code]);
                if ($res->success) {
                    return $this->acceptWidget($res->data);
                }
                $lastError = $res;
            }
        }
        if ($lastError !== null && $this->classifyError($lastError) !== IntegrationConfig::STATE_NOT_INSTALLED) {
            $this->setState('widget', $this->classifyError($lastError), $this->errorMessage($lastError));
        } else {
            $this->setState('widget', IntegrationConfig::STATE_NOT_INSTALLED, '');
        }
        return null;
    }

    /**
     * @param array $data
     * @return array
     */
    private function acceptWidget(array $data): array
    {
        $this->widgetCode = (string)$data['code'];
        return $data;
    }

    /**
     * Поиск кода приватного виджета в каталоге по client_uuid.
     * @param string $uuid
     * @return string
     */
    private function findPrivateWidgetCode(string $uuid): string
    {
        if ($uuid === '') {
            return '';
        }
        for ($page = 1; $page <= self::MAX_WIDGET_PAGES; $page++) {
            $res = WorkerAmoHTTP::invokeAmoApi('getWidgetsPage', [$page]);
            if (!$res->success) {
                return '';
            }
            foreach ($res->data['widgets'] ?? [] as $widget) {
                if (($widget['client_uuid'] ?? '') === $uuid) {
                    return (string)$widget['code'];
                }
            }
            if (empty($res->data['hasNext'])) {
                break;
            }
        }
        return '';
    }

    /**
     * Обновляет список пользователей amoCRM в БД модуля.
     * @param array|null $widget
     * @return array Пользователи из БД модуля.
     */
    private function refreshUsers(?array $widget): array
    {
        $res = WorkerAmoHTTP::invokeAmoApi('getAccountUsers', []);
        if (!$res->success) {
            $this->setState('users', $this->classifyError($res), $this->errorMessage($res));
            return [];
        }
        $widgetMap = is_array($widget) ? ($widget['pbx_users'] ?? []) : [];
        $saved = ConnectorDb::invoke('saveAccountUsers', [$res->data['users'] ?? [], $widgetMap]);
        if (!is_array($saved) || empty($saved['success'])) {
            $this->setState('users', IntegrationConfig::STATE_ERROR, 'Fail save users (ConnectorDb)');
            return is_array($saved) ? ($saved['users'] ?? []) : [];
        }
        $users = $saved['users'] ?? [];
        $this->setState('users', IntegrationConfig::STATE_OK, '', ['count' => count($users)]);
        return $users;
    }

    /**
     * Приводит настройки виджета к значениям модуля: token, miko_pbx_host, pbx_users.
     * @param array|null $widget
     * @return void
     */
    private function pushWidget(?array $widget): void
    {
        if ($widget === null) {
            return;
        }
        if (empty($widget['is_active_in_account'])) {
            // POST /api/v4/widgets/{code} установил бы виджет — без согласия администратора не делаем.
            $this->setState('widget', IntegrationConfig::STATE_NOT_INSTALLED, '', ['code' => $this->widgetCode]);
            return;
        }
        if (empty($widget['has_settings'])) {
            $this->setState('widget', IntegrationConfig::STATE_ERROR, 'amoCRM did not return widget settings', ['code' => $this->widgetCode]);
            return;
        }
        $token = trim((string)($this->settings['tokenForAmo'] ?? ''));
        $host  = self::resolveHost($this->settings);
        if ($token === '') {
            $this->setState('widget', IntegrationConfig::STATE_NO_TOKEN, '', ['code' => $this->widgetCode]);
            return;
        }
        if ($host === '') {
            $this->setState('widget', IntegrationConfig::STATE_NO_HOST, '', ['code' => $this->widgetCode]);
            return;
        }
        $usersMap = $this->localUsersMap();
        if ($usersMap === null) {
            // Список пользователей ещё не загружен: сопоставление виджета не трогаем.
            $usersMap = $widget['pbx_users'] ?? [];
        }
        $desired = IntegrationConfig::desiredWidgetSettings($host, (string)($this->settings['webhookPort'] ?? ''), $token, $usersMap);
        if (!IntegrationConfig::widgetSettingsDiffer($widget['settings'] ?? [], $desired)) {
            $this->setState('widget', IntegrationConfig::STATE_OK, '', ['code' => $this->widgetCode]);
            return;
        }
        $res = WorkerAmoHTTP::invokeAmoApi('saveWidgetSettings', [$this->widgetCode, IntegrationConfig::widgetRequestBody($desired)]);
        if (!$res->success) {
            $this->setState('widget', $this->classifyError($res), $this->errorMessage($res), ['code' => $this->widgetCode]);
            return;
        }
        $this->log(
            ['miko_pbx_host' => str_replace($token, '***', $desired['miko_pbx_host']), 'pbx_users' => $desired['pbx_users']],
            'Widget settings updated'
        );
        $this->setState('widget', IntegrationConfig::STATE_OK, '', ['code' => $this->widgetCode, 'updated' => time()]);
    }

    /**
     * Сопоставление из БД модуля или null, если список пользователей ещё не загружен.
     * @return array<string, string>|null
     */
    private function localUsersMap(): ?array
    {
        $rows = ConnectorDb::invoke('getAccountUsers', []);
        if (!is_array($rows) || empty($rows)) {
            return null;
        }
        $map = [];
        foreach ($rows as $row) {
            $number = trim((string)($row['number'] ?? ''));
            if ($number !== '') {
                $map[(string)$row['amoUserId']] = $number;
            }
        }
        return $map;
    }

    /**
     * Регистрирует webhook нового формата и удаляет прежние webhook модуля.
     * @return void
     */
    private function syncWebhook(): void
    {
        $token = trim((string)($this->settings['tokenForAmo'] ?? ''));
        $port  = trim((string)($this->settings['webhookPort'] ?? ''));
        $host  = self::resolveHost($this->settings);
        $blocker = IntegrationConfig::webhookBlocker($host, $port, $token);
        if ($blocker === null && (int)$port === (int)PbxSettings::getValueByKey('WEBHTTPSPort')) {
            // На основном HTTPS-порту отдельный сервер webhook не создаётся (AmoCrmConf::getWebhookServerSettings).
            $blocker = IntegrationConfig::STATE_PORT_REQUIRED;
        }
        if ($blocker !== null) {
            $this->setState('webhook', $blocker, '');
            return;
        }
        $target = (string)IntegrationConfig::webhookUrl($host, $port, $token);

        $res = WorkerAmoHTTP::invokeAmoApi('getWebhooks', []);
        if (!$res->success) {
            $this->setState('webhook', $this->classifyError($res), $this->errorMessage($res), ['url' => $target]);
            return;
        }
        $plan = IntegrationConfig::planWebhooks($res->data['webhooks'] ?? [], $target, IntegrationConfig::WEBHOOK_EVENTS);
        if ($plan['create']) {
            $created = $this->createWebhook($target);
            if (!$created->success) {
                // Прежние webhook не удаляем: без нового они продолжают работать.
                $this->setState('webhook', $this->classifyError($created), $this->errorMessage($created), ['url' => $target]);
                return;
            }
            $this->log(['destination' => $this->maskToken($target, $token)], 'Webhook registered');
        }
        $errors = [];
        foreach ($plan['delete'] as $destination) {
            $deleted = WorkerAmoHTTP::invokeAmoApi('deleteWebhook', [$destination]);
            if ($deleted->success || (int)($deleted->messages['error-code'] ?? 0) === 404) {
                $this->log(['destination' => $this->maskToken($destination, $token)], 'Old webhook deleted');
                continue;
            }
            $errors[] = $this->errorMessage($deleted);
        }
        $extra = ['url' => $target, 'selfCheck' => $this->selfCheck($port, $token)];
        if (!empty($errors)) {
            $this->setState('webhook', IntegrationConfig::STATE_ERROR, 'Fail delete old webhook: ' . implode('; ', $errors), $extra);
            return;
        }
        $this->setState('webhook', IntegrationConfig::STATE_OK, '', $extra);
    }

    /**
     * Создание webhook с повтором при случайной ошибке "Invalid URL".
     * @param string $target
     * @return PBXAmoResult|PBXApiResult
     */
    private function createWebhook(string $target)
    {
        $res = null;
        for ($attempt = 1; $attempt <= self::WEBHOOK_ATTEMPTS; $attempt++) {
            $res = WorkerAmoHTTP::invokeAmoApi('addWebhook', [$target, IntegrationConfig::WEBHOOK_EVENTS]);
            if ($res->success || !IntegrationConfig::isTransientWebhookError((array)$res->messages)) {
                break;
            }
            if ($attempt < self::WEBHOOK_ATTEMPTS) {
                $this->log("Webhook create: Invalid URL, retry $attempt", '', true);
                sleep(self::WEBHOOK_RETRY_DELAY * $attempt);
            }
        }
        return $res;
    }

    /**
     * Проверка webhook-сервера nginx на самой АТС (127.0.0.1, тот же порт и токен).
     * Внешний адрес отсюда не проверяется: без hairpin NAT запрос с АТС на свой
     * внешний адрес не проходит, хотя для amoCRM адрес доступен.
     * @param string $port
     * @param string $token
     * @return int HTTP-код ответа, 0 — нет ответа.
     */
    private function selfCheck(string $port, string $token): int
    {
        try {
            $client = new GuzzleHttp\Client();
            $response = $client->request('POST', "https://127.0.0.1:$port/$token/entity-update", [
                'timeout'         => 5,
                'connect_timeout' => 3,
                'http_errors'     => false,
                'verify'          => false,
                'form_params'     => [],
            ]);
            return $response->getStatusCode();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return string
     */
    private function integrationUuid(): string
    {
        if ($this->isPrivateWidget()) {
            return trim((string)($this->settings['privateClientId'] ?? ''));
        }
        return AmoCrmMainBase::CLIENT_ID;
    }

    /**
     * @return bool
     */
    private function isPrivateWidget(): bool
    {
        return (string)($this->settings['isPrivateWidget'] ?? '0') === '1';
    }

    /**
     * Состояние по ошибке API.
     * @param PBXAmoResult|PBXApiResult $res
     * @return string
     */
    private function classifyError($res): string
    {
        $code = (int)($res->messages['error-code'] ?? 0);
        switch ($code) {
            case 401:
                return IntegrationConfig::STATE_NOT_AUTHORIZED;
            case 403:
                return IntegrationConfig::STATE_FORBIDDEN;
            case 404:
                return IntegrationConfig::STATE_NOT_INSTALLED;
            case 0:
                // WorkerAmoHTTP не запущен (нет авторизации) или не ответил.
                return in_array('Empty or invalid response from WorkerAmoHTTP', (array)$res->messages, true)
                    ? IntegrationConfig::STATE_NOT_AUTHORIZED
                    : IntegrationConfig::STATE_ERROR;
            default:
                return IntegrationConfig::STATE_ERROR;
        }
    }

    /**
     * Краткий текст ошибки API.
     * @param PBXAmoResult|PBXApiResult $res
     * @return string
     */
    private function errorMessage($res): string
    {
        $messages = (array)$res->messages;
        $code     = (int)($messages['error-code'] ?? 0);
        $data     = $messages['error-data'] ?? [];
        $parts    = [];
        if (is_array($data)) {
            foreach ($data['validation-errors'] ?? [] as $item) {
                foreach ($item['errors'] ?? [] as $error) {
                    $parts[] = trim(($error['path'] ?? '') . ': ' . ($error['detail'] ?? ''));
                }
            }
            if (empty($parts)) {
                $parts[] = trim(($data['title'] ?? '') . ' ' . ($data['detail'] ?? ''));
            }
        }
        $text = trim(implode('; ', array_filter($parts)));
        if ($text === '') {
            $text = trim((string)($messages['error-msg'] ?? ''));
        }
        if ($text === '' && isset($messages[0]) && is_string($messages[0])) {
            $text = $messages[0];
        }
        $text = $code > 0 ? "HTTP $code" . ($text !== '' ? ": $text" : '') : $text;
        return substr($text, 0, 300);
    }

    /**
     * @param string $section webhook | widget | users
     * @param string $state
     * @param string $message
     * @param array  $extra
     * @return void
     */
    private function setState(string $section, string $state, string $message, array $extra = []): void
    {
        $this->state[$section] = array_merge(
            ['state' => $state, 'message' => $message, 'time' => time()],
            $extra
        );
        if ($state !== IntegrationConfig::STATE_OK) {
            $this->log(['section' => $section, 'state' => $state, 'message' => $message], 'Integration problem', true);
        }
    }

    /**
     * @return void
     */
    private function saveState(): void
    {
        $this->state['time'] = time();
        $data = ['integrationState' => json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)];
        if ($this->widgetCode !== '' && $this->widgetCode !== (string)($this->settings['widgetCode'] ?? '')) {
            $data['widgetCode'] = $this->widgetCode;
        }
        ConnectorDb::invoke('saveNewSettings', [$data]);
    }

    /**
     * Токен webhook в логах не пишем.
     * @param string $url
     * @param string $token
     * @return string
     */
    private function maskToken(string $url, string $token): string
    {
        $url = (string)preg_replace('#/[A-Za-z0-9]{20,}/entity-update$#', '/***/entity-update', $url);
        return $token !== '' ? str_replace($token, '***', $url) : $url;
    }

    /**
     * @param mixed  $data
     * @param string $title
     * @param bool   $isError
     * @return void
     */
    private function log($data, string $title = '', bool $isError = false): void
    {
        if ($this->logger === null) {
            return;
        }
        if ($isError) {
            $this->logger->writeError($data, $title);
        } else {
            $this->logger->writeInfo($data, $title);
        }
    }
}
