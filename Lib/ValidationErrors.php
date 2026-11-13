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
 * Разбор ответа amoCRM HTTP 400 validation-errors для пакетных запросов
 * (примечания-звонки, задачи), в которых у каждой записи свой request_id.
 */
final class ValidationErrors
{
    /**
     * Ошибки по request_id. Fail closed: never infer a bad record from an index,
     * a network error or a partially understood response. Every validation error
     * must match exactly one request in the current HTTP payload.
     *
     * @param mixed $result     Ответ WorkerAmoHTTP (поле messages).
     * @param array $requestIds request_id всех записей запроса (с повторами, если они есть).
     * @return array<string, array> request_id => ошибки; [] — ответ не разобран.
     */
    public static function byRequestId($result, array $requestIds): array
    {
        $messages = is_object($result) ? (array)($result->messages ?? []) : [];
        if (($messages['error-code'] ?? null) !== 400) {
            return [];
        }
        $validation = $messages['error-data']['validation-errors'] ?? null;
        if (!is_array($validation) || $validation === []) {
            return [];
        }
        $counts = [];
        foreach ($requestIds as $id) {
            $id = (string)$id;
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        $rejected = [];
        foreach ($validation as $entry) {
            if (!is_array($entry) || !isset($entry['request_id'])
                || !is_scalar($entry['request_id'])
                || !is_array($entry['errors'] ?? null) || $entry['errors'] === []) {
                return [];
            }
            $id = (string)$entry['request_id'];
            if (($counts[$id] ?? 0) !== 1) {
                return [];
            }
            $rejected[$id] = array_merge($rejected[$id] ?? [], $entry['errors']);
        }
        return $rejected;
    }
}
