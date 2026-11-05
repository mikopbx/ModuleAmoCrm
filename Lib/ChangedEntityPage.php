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
 * Разбор страницы списка сущностей amoCRM (GET /api/v4/{contacts|leads|companies}).
 * Без зависимостей — покрывается тестами в tests/.
 *
 * Пустая страница — только HTTP 204 (amoCRM так отвечает, когда сущностей нет).
 * Любая другая ошибка (401, 5xx, таймаут, неожиданный ответ) — сбой: иначе SyncDaemon
 * принимал её за конец выборки, в init завершал проход и deleteWithFailTime удалял кэш,
 * а в инкременте сдвигал курсор и терял изменения.
 */
final class ChangedEntityPage
{
    public const HTTP_NO_CONTENT = 204;

    /**
     * @param bool   $httpSuccess Результат ClientHTTP (success).
     * @param mixed  $data        Тело ответа (decoded JSON) при успехе.
     * @param array  $messages    Сообщения ClientHTTP при ошибке (error-code, error-msg, ...).
     * @param string $type        Тип в API: contacts | leads | companies.
     * @param string $itemType    Значение поля 'type' для элементов (contact | company | leads).
     * @return array{success:bool, items:array, nextPage:string, messages:array}
     */
    public static function parse(bool $httpSuccess, $data, array $messages, string $type, string $itemType): array
    {
        if (!$httpSuccess) {
            if ((int)($messages['error-code'] ?? 0) === self::HTTP_NO_CONTENT) {
                return ['success' => true, 'items' => [], 'nextPage' => '', 'messages' => []];
            }
            return [
                'success'  => false,
                'items'    => [],
                'nextPage' => '',
                'messages' => [
                    'error-code' => (int)($messages['error-code'] ?? 0),
                    'error-msg'  => (string)($messages['error-msg'] ?? ''),
                ],
            ];
        }
        if (!is_array($data) || !isset($data['_embedded'][$type]) || !is_array($data['_embedded'][$type])) {
            return [
                'success'  => false,
                'items'    => [],
                'nextPage' => '',
                'messages' => ['error-code' => 200, 'error-msg' => "Unexpected response: no _embedded.$type"],
            ];
        }
        $items = [];
        foreach ($data['_embedded'][$type] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item['type'] = $itemType;
            $items[] = $item;
        }
        return [
            'success'  => true,
            'items'    => $items,
            'nextPage' => (string)($data['_links']['next']['href'] ?? ''),
            'messages' => [],
        ];
    }
}
