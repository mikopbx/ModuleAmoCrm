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
 * Чистая логика состояния синхронизации и реакции на изменение настроек.
 * Без зависимостей от Phalcon/MikoPBX — покрывается тестами в tests/.
 */
final class SyncState
{
    /** Страница init-синхронизации типа сущности: проход завершён. */
    public const PAGE_DONE = -1;

    /**
     * Максимальная длительность init (с). Дольше — прерываем без deleteWithFailTime:
     * постоянно падающая страница не должна навсегда блокировать инкремент и ночной init.
     * Меньше суток, чтобы следующий запуск cron (01:00) смог стартовать новый init.
     */
    public const MAX_INIT_AGE = 20 * 3600;

    /** Прогресс init сохраняется раз в N страниц (каждое сохранение — запись в БД и событие моделей). */
    public const SAVE_PROGRESS_EVERY_PAGES = 10;

    /**
     * Сколько страниц перекрывать при продолжении init: при order[id]=desc удаление сущностей
     * в amoCRM сдвигает выдачу к началу, и часть записей ушла бы на уже пройденные страницы.
     */
    public const RESUME_OVERLAP_PAGES = 2;

    /** Типы сущностей в порядке синхронизации => суффикс поля настроек. */
    public const ENTITIES = [
        'contacts'  => 'Contacts',
        'leads'     => 'Leads',
        'companies' => 'Companies',
    ];

    /**
     * Поля ModuleAmoCrm, изменение которых не требует перезапуска воркеров:
     * курсоры синхронизации, OAuth-токен (держит и обновляет сам WorkerAmoHTTP),
     * токен webhook (обрабатывается отдельно: auth-файлы, WAF, nginx),
     * состояние init-синхронизации.
     */
    public const NO_RESTART_FIELDS = [
        'offsetCdr',
        'lastContactsSyncTime',
        'lastCompaniesSyncTime',
        'lastLeadsSyncTime',
        'authData',
        'tokenForAmo',
        'initSyncTime',
        'initSyncPageContacts',
        'initSyncPageLeads',
        'initSyncPageCompanies',
        'widgetCode',
        'integrationState',
    ];

    /**
     * Нужно ли перезапускать воркеры модуля при изменении настроек.
     *
     * Обновление OAuth-токена (authData) перезапуска не требует — его выполняет сам
     * WorkerAmoHTTP и держит новый токен в памяти. Но очистка authData (ошибка OAuth,
     * отзыв доступа) происходит в другом процессе: без перезапуска WorkerAmoHTTP
     * продолжил бы работать со старым токеном из памяти.
     * @param array $changedFields
     * @param string|null $authData Текущее значение authData (null — не известно).
     * @return bool
     */
    public static function needRestart(array $changedFields, ?string $authData = null): bool
    {
        if (empty($changedFields)) {
            // Список полей неизвестен — перезапускаем, как и до введения NO_RESTART_FIELDS.
            return true;
        }
        if ($authData !== null && trim($authData) === '' && in_array('authData', $changedFields, true)) {
            return true;
        }
        return !empty(array_diff($changedFields, self::NO_RESTART_FIELDS));
    }

    public static function cursorField(string $entity): string
    {
        return 'last' . self::ENTITIES[$entity] . 'SyncTime';
    }

    public static function pageField(string $entity): string
    {
        return 'initSyncPage' . self::ENTITIES[$entity];
    }

    /**
     * Определяет режим синхронизации по сохранённым настройкам.
     *
     * - initSyncTime > 0: идёт init (в т.ч. продолжение после перезапуска) — тот же initTime и страницы;
     * - initSyncTime = 0, но хотя бы один курсор = 0: нужно начать init (первая установка,
     *   старый формат запуска) — инкремент с from=0 без initTime не допускается;
     * - иначе — инкрементальный режим.
     *
     * @param array $settings Настройки ModuleAmoCrm (массив).
     * @param int   $now
     * @return array{initTime:int, startNew:bool, resumed:bool, pages:array<string,int>}
     */
    public static function resolve(array $settings, int $now): array
    {
        $pages = [];
        foreach (array_keys(self::ENTITIES) as $entity) {
            $pages[$entity] = (int)($settings[self::pageField($entity)] ?? 0);
        }
        $initSyncTime = (int)($settings['initSyncTime'] ?? 0);
        if ($initSyncTime > 0) {
            $resumed = false;
            foreach ($pages as $page) {
                if ($page !== 0) {
                    $resumed = true;
                    break;
                }
            }
            return ['initTime' => $initSyncTime, 'startNew' => false, 'resumed' => $resumed, 'pages' => $pages];
        }
        foreach (array_keys(self::ENTITIES) as $entity) {
            if ((int)($settings[self::cursorField($entity)] ?? 0) === 0) {
                return [
                    'initTime' => $now,
                    'startNew' => true,
                    'resumed'  => false,
                    'pages'    => array_fill_keys(array_keys(self::ENTITIES), 0),
                ];
            }
        }
        return [
            'initTime' => 0,
            'startNew' => false,
            'resumed'  => false,
            'pages'    => array_fill_keys(array_keys(self::ENTITIES), 0),
        ];
    }

    /**
     * Настройки для старта init-синхронизации.
     *
     * Курсоры last*SyncTime не обнуляются: в init они не используются (режим определяет
     * initSyncTime), а lastContactsSyncTime > 0 разрешает работу AmoCdrDaemon. Обнуление
     * останавливало бы отправку звонков на всё время ночного init, а при недоступном API —
     * бессрочно. При первой установке курсоры и так нулевые, и AmoCdrDaemon ждёт контакты.
     * @param int $initTime
     * @return array
     */
    public static function startSettings(int $initTime): array
    {
        $settings = ['initSyncTime' => $initTime];
        foreach (array_keys(self::ENTITIES) as $entity) {
            $settings[self::pageField($entity)] = 0;
        }
        return $settings;
    }

    /**
     * Можно ли запустить новый init по расписанию (cron).
     *
     * Если init уже идёт (initSyncTime > 0), новый не запускаем: незавершённый init продолжится
     * с сохранённой страницы. Исключение — просроченный init (MAX_INIT_AGE): его некому прервать,
     * если SyncDaemon не работает. Работающий SyncDaemon со старым initTime в памяти обнаружит
     * смену initSyncTime при записи состояния (ConnectorDb::saveInitSettings) и прервёт проход.
     * Пустые настройки (ConnectorDb недоступен) или не подключённый портал — не запускаем.
     * @param array $settings Настройки ModuleAmoCrm (массив).
     * @param int   $now
     * @return bool
     */
    public static function canStartScheduledInit(array $settings, int $now): bool
    {
        if (empty($settings) || (int)($settings['portalId'] ?? 0) <= 0) {
            // Портал не подключён: срок MAX_INIT_AGE истёк бы до подключения.
            return false;
        }
        $initSyncTime = (int)($settings['initSyncTime'] ?? 0);
        return $initSyncTime === 0 || self::isInitExpired($initSyncTime, $now);
    }

    /**
     * Настройки для прерывания просроченного init: сбрасываем только состояние init.
     *
     * Курсоры не трогаем. Ночной init: курсоры ненулевые (startSettings их не обнуляет) —
     * инкремент продолжит с них. Первая установка: курсоры нулевые — AmoCdrDaemon остаётся
     * заблокированным (кэш не загружен), SyncDaemon начнёт новый init. Выставление курсоров
     * (как в finishSettings) разблокировало бы отправку CDR при пустом кэше — дубли в amoCRM.
     * @return array
     */
    public static function abortSettings(): array
    {
        return self::startSettings(0);
    }

    /**
     * Настройки по завершении init: курсоры = начало init, чтобы инкремент
     * догнал изменения, сделанные во время многочасового полного прохода.
     * @param int $initTime
     * @return array
     */
    public static function finishSettings(int $initTime): array
    {
        $settings = ['initSyncTime' => 0];
        foreach (array_keys(self::ENTITIES) as $entity) {
            $settings[self::cursorField($entity)] = $initTime;
            $settings[self::pageField($entity)]   = 0;
        }
        return $settings;
    }

    /**
     * @param array<string,int> $pages
     * @return bool
     */
    public static function isInitDone(array $pages): bool
    {
        foreach (array_keys(self::ENTITIES) as $entity) {
            if (($pages[$entity] ?? 0) !== self::PAGE_DONE) {
                return false;
            }
        }
        return true;
    }

    /**
     * Страница, с которой продолжать проход: сохранённая минус перекрытие
     * (RESUME_OVERLAP_PAGES) на случай сдвига выдачи order[id]=desc.
     * Повторная обработка страниц безопасна — записи обновляются.
     * @param int $savedPage
     * @return int
     */
    public static function resumePage(int $savedPage): int
    {
        $page = $savedPage - self::RESUME_OVERLAP_PAGES;
        return $page > 1 ? $page : 1;
    }

    /**
     * Нужно ли сохранить прогресс init после обработки страницы.
     * @param int $page
     * @return bool
     */
    public static function shouldSaveProgress(int $page): bool
    {
        return $page > 0 && $page % self::SAVE_PROGRESS_EVERY_PAGES === 0;
    }

    /**
     * Init идёт дольше MAX_INIT_AGE — его нужно прервать.
     * @param int $initTime
     * @param int $now
     * @return bool
     */
    public static function isInitExpired(int $initTime, int $now): bool
    {
        return $initTime > 0 && ($now - $initTime) > self::MAX_INIT_AGE;
    }

    /**
     * Номер страницы из URL запроса amoCRM (без параметра page — первая).
     * @param string $url
     * @return int
     */
    public static function pageFromUrl(string $url): int
    {
        $query = (string)parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        $page = (int)($params['page'] ?? 1);
        return $page > 0 ? $page : 1;
    }
}
