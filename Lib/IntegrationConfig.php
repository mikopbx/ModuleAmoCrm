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

/**
 * Чистая логика настройки интеграции amoCRM: адреса webhook и виджета,
 * сопоставление пользователей, план изменения webhook.
 * Без зависимостей от Phalcon/MikoPBX — покрывается тестами в tests/.
 */
final class IntegrationConfig
{
    /** Код публичного виджета MikoPBX в каталоге amoCRM. */
    public const PUBLIC_WIDGET_CODE = 'mikopbx';

    /** Путь webhook старого формата (основной HTTPS-порт АТС). */
    public const LEGACY_WEBHOOK_PATH = '/pbxcore/api/amo-crm/v1/entity-update';

    /** События webhook: контакты, сделки, компании. */
    public const WEBHOOK_EVENTS = [
        'add_contact',
        'update_contact',
        'delete_contact',
        'add_lead',
        'update_lead',
        'delete_lead',
        'add_company',
        'update_company',
        'delete_company',
    ];

    /** Состояния элементов интеграции (webhook, виджет, пользователи). */
    public const STATE_OK             = 'ok';
    public const STATE_ERROR          = 'error';
    public const STATE_NO_TOKEN       = 'no_token';
    public const STATE_NO_HOST        = 'no_host';
    public const STATE_PORT_REQUIRED  = 'port_required';
    public const STATE_NOT_INSTALLED  = 'not_installed';
    public const STATE_FORBIDDEN      = 'forbidden';
    public const STATE_NOT_AUTHORIZED = 'not_authorized';

    /**
     * Внешний адрес АТС без схемы, пути и пробелов.
     * @param string $externalHostname Значение из настроек модуля.
     * @param string $fallback         Внешний адрес из сетевых настроек АТС.
     * @return string
     */
    public static function resolveHost(string $externalHostname, string $fallback): string
    {
        $host = self::cleanHost($externalHostname);
        if ($host === '') {
            $host = self::cleanHost($fallback);
        }
        return $host;
    }

    /**
     * @param string $host
     * @return string
     */
    private static function cleanHost(string $host): string
    {
        $host = trim($host);
        $host = (string)preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $host);
        $slash = strpos($host, '/');
        if ($slash !== false) {
            $host = substr($host, 0, $slash);
        }
        return trim($host);
    }

    /**
     * Корректен ли порт webhook.
     * @param string $port
     * @return bool
     */
    public static function isValidPort(string $port): bool
    {
        $port = trim($port);
        return preg_match('/^[1-9][0-9]{0,4}$/', $port) === 1 && (int)$port <= 65535;
    }

    /**
     * Причина, по которой нельзя зарегистрировать webhook, или null, если всё задано.
     * @param string $host
     * @param string $port
     * @param string $token
     * @return string|null
     */
    public static function webhookBlocker(string $host, string $port, string $token): ?string
    {
        if (trim($token) === '') {
            return self::STATE_NO_TOKEN;
        }
        if (trim($host) === '') {
            return self::STATE_NO_HOST;
        }
        if (!self::isValidPort($port)) {
            return self::STATE_PORT_REQUIRED;
        }
        return null;
    }

    /**
     * Целевой URL webhook (только новый формат: отдельный порт и токен в пути).
     * @param string $host
     * @param string $port
     * @param string $token
     * @return string|null null, если не задан хост, порт или токен.
     */
    public static function webhookUrl(string $host, string $port, string $token): ?string
    {
        if (self::webhookBlocker($host, $port, $token) !== null) {
            return null;
        }
        return 'https://' . trim($host) . ':' . trim($port) . '/' . trim($token) . '/entity-update';
    }

    /**
     * Значение настройки виджета miko_pbx_host: host:port/token, без порта — host.
     * @param string $host
     * @param string $port
     * @param string $token
     * @return string
     */
    public static function mikoPbxHost(string $host, string $port, string $token): string
    {
        $host  = trim($host);
        $port  = trim($port);
        $token = trim($token);
        if ($host !== '' && $token !== '' && self::isValidPort($port)) {
            return "$host:$port/$token";
        }
        return $host;
    }

    /**
     * Webhook модуля (старого или нового формата) по destination.
     * Одна АТС подключена к одному аккаунту, поэтому все такие webhook — наши.
     * @param string $destination
     * @return bool
     */
    public static function isOwnWebhook(string $destination): bool
    {
        $path = parse_url($destination, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        if ($path === self::LEGACY_WEBHOOK_PATH) {
            return true;
        }
        return preg_match('#^/[A-Za-z0-9]+/entity-update$#', $path) === 1;
    }

    /**
     * События webhook из ответа API: список строк (GET) или объект event => 1 (POST).
     * @param mixed $settings
     * @return string[] Отсортированный список.
     */
    public static function webhookEvents($settings): array
    {
        if (!is_array($settings)) {
            return [];
        }
        $events = [];
        foreach ($settings as $key => $value) {
            if (is_string($key)) {
                if (!empty($value)) {
                    $events[] = $key;
                }
            } elseif (is_string($value)) {
                $events[] = $value;
            }
        }
        $events = array_values(array_unique($events));
        sort($events);
        return $events;
    }

    /**
     * План изменения webhook.
     * @param array       $webhooks Список из GET /api/v4/webhooks (_embedded.webhooks).
     * @param string|null $target   Целевой URL (null — регистрировать нечего).
     * @param string[]    $events   Нужные события.
     * @return array{create: bool, delete: string[]}
     *   create — нужно создать / обновить целевой webhook (POST — upsert по destination);
     *   delete — destination прочих webhook модуля, удаляются после успешного create.
     */
    public static function planWebhooks(array $webhooks, ?string $target, array $events): array
    {
        $plan = ['create' => false, 'delete' => []];
        if ($target === null) {
            return $plan;
        }
        $wanted = self::webhookEvents($events);
        $found  = false;
        foreach ($webhooks as $webhook) {
            if (!is_array($webhook)) {
                continue;
            }
            $destination = (string)($webhook['destination'] ?? '');
            if ($destination === $target) {
                $found = true;
                $actual = self::webhookEvents($webhook['settings'] ?? []);
                if ($actual !== $wanted || !empty($webhook['disabled'])) {
                    $plan['create'] = true;
                }
                continue;
            }
            if (self::isOwnWebhook($destination)) {
                $plan['delete'][] = $destination;
            }
        }
        if (!$found) {
            $plan['create'] = true;
        }
        $plan['delete'] = array_values(array_unique($plan['delete']));
        return $plan;
    }

    /**
     * Сопоставление для настройки виджета pbx_users из строк m_ModuleAmoAccountUsers.
     * Поле типа users принимает только активных пользователей аккаунта: неактивный
     * пользователь в теле POST /api/v4/widgets/{code} даёт 400 NotSupportedChoice
     * и отклоняет всё сохранение. Локальное сопоставление (m_ModuleAmoUsers) не меняется.
     * @param array $rows [['amoUserId', 'number', 'isActive'], ...]
     * @return array<string, string> amoUserId => номер.
     */
    public static function widgetUsersMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $number = trim((string)($row['number'] ?? ''));
            if ($number === '' || (string)($row['isActive'] ?? '1') === '0') {
                continue;
            }
            $map[(string)($row['amoUserId'] ?? '')] = $number;
        }
        return $map;
    }

    /**
     * Сопоставление пользователей из настройки виджета pbx_users.
     * UI amoCRM хранит его JSON-строкой, API — объектом.
     * @param mixed $pbxUsers
     * @return array<string, string> amoUserId => номер (пустые значения отброшены).
     */
    public static function normalizeUsersMap($pbxUsers): array
    {
        if (is_string($pbxUsers)) {
            $decoded = json_decode($pbxUsers, true);
            $pbxUsers = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($pbxUsers)) {
            return [];
        }
        $map = [];
        foreach ($pbxUsers as $amoUserId => $number) {
            $amoUserId = (string)$amoUserId;
            if (preg_match('/^[1-9][0-9]*$/', $amoUserId) !== 1 || !is_scalar($number)) {
                continue;
            }
            $number = trim((string)$number);
            if ($number !== '') {
                $map[$amoUserId] = $number;
            }
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * Применяет присланное сопоставление к локальному.
     * Изменённый номер принимается, если он есть на АТС, иначе обнуляется.
     * Пользователи, которых нет во входных данных, не меняются.
     * @param array<string, string> $current  amoUserId => номер (локально).
     * @param array                 $incoming amoUserId => номер (из UI модуля или виджета).
     * @param string[]              $validNumbers Внутренние номера АТС.
     * @return array{map: array<string, string>, changed: string[], rejected: array<string, string>}
     */
    public static function applyUsersMap(array $current, array $incoming, array $validNumbers): array
    {
        $valid    = array_flip(array_map('strval', $validNumbers));
        $map      = [];
        foreach ($current as $amoUserId => $number) {
            $map[(string)$amoUserId] = trim((string)$number);
        }
        $changed  = [];
        $rejected = [];
        foreach ($incoming as $amoUserId => $number) {
            $amoUserId = (string)$amoUserId;
            if (preg_match('/^[1-9][0-9]*$/', $amoUserId) !== 1) {
                continue;
            }
            $number = is_scalar($number) ? trim((string)$number) : '';
            $old    = $map[$amoUserId] ?? '';
            if ($number === $old) {
                continue;
            }
            if ($number !== '' && !isset($valid[$number])) {
                $rejected[$amoUserId] = $number;
                $number = '';
                if ($old === '') {
                    continue;
                }
            }
            $map[$amoUserId] = $number;
            $changed[] = $amoUserId;
        }
        return ['map' => $map, 'changed' => $changed, 'rejected' => $rejected];
    }

    /**
     * Нужные значения настроек виджета.
     * @param string                $host
     * @param string                $port
     * @param string                $token
     * @param array<string, string> $usersMap amoUserId => номер.
     * @return array
     */
    public static function desiredWidgetSettings(string $host, string $port, string $token, array $usersMap): array
    {
        $users = [];
        foreach ($usersMap as $amoUserId => $number) {
            $number = trim((string)$number);
            if ($number !== '') {
                $users[(string)$amoUserId] = $number;
            }
        }
        ksort($users, SORT_STRING);
        return [
            'token'         => trim($token),
            'miko_pbx_host' => self::mikoPbxHost($host, $port, $token),
            'pbx_users'     => $users,
        ];
    }

    /**
     * Отличаются ли текущие настройки виджета от нужных (формат pbx_users не учитывается).
     * @param array $current Настройки из GET /api/v4/widgets/{code} (settings).
     * @param array $desired Результат desiredWidgetSettings().
     * @return bool
     */
    public static function widgetSettingsDiffer(array $current, array $desired): bool
    {
        if (trim((string)($current['token'] ?? '')) !== $desired['token']) {
            return true;
        }
        if (trim((string)($current['miko_pbx_host'] ?? '')) !== $desired['miko_pbx_host']) {
            return true;
        }
        return self::normalizeUsersMap($current['pbx_users'] ?? []) !== self::normalizeUsersMap($desired['pbx_users']);
    }

    /**
     * Тело POST /api/v4/widgets/{code}: только поля шаблона настроек, pbx_users — объектом.
     * Ключ active API не принимает (FieldNotExpected).
     * @param array $desired Результат desiredWidgetSettings().
     * @return array
     */
    public static function widgetRequestBody(array $desired): array
    {
        $users = $desired['pbx_users'];
        return [
            'token'         => $desired['token'],
            'miko_pbx_host' => $desired['miko_pbx_host'],
            // Пустой массив сериализуется в [], amoCRM ждёт объект.
            'pbx_users'     => empty($users) ? new \stdClass() : $users,
        ];
    }

    /**
     * Случайная ошибка валидации amoCRM при создании webhook на корректный URL
     * (400, detail "Invalid URL"; повтор позже проходит).
     * @param array $messages messages из PBXAmoResult.
     * @return bool
     */
    public static function isTransientWebhookError(array $messages): bool
    {
        if ((int)($messages['error-code'] ?? 0) !== 400) {
            return false;
        }
        return strpos((string)($messages['error-string'] ?? ''), 'Invalid URL') !== false;
    }
}
