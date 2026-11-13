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
 * Выбор внутреннего номера для звонка из карточки (/callback).
 *
 * Номер берётся из сопоставления модуля (m_ModuleAmoUsers) для user-id, а не из запроса:
 * звонок идёт только с номера, сопоставленного этому пользователю amoCRM, и в имя канала
 * Local/<номер>@amo-orig-leg-1 попадают только цифры.
 *
 * Ограничение: user-id тоже приходит из запроса, а токен виджета общий для аккаунта.
 * Подмену пользователя это не исключает — для этого нужна проверка подписи amoCRM.
 */
final class CallbackPeer
{
    /** Причины отказа, передаются виджету в data.code. */
    public const NOT_MAPPED = 'NOT_MAPPED';
    public const NO_NUMBER  = 'NO_NUMBER';

    /**
     * @param mixed $requestedNumber внутренний номер из запроса (настройки виджета)
     * @param array $userNumbers     номера пользователя из сопоставления модуля
     * @return string|null номер для originate или null, если звонить нельзя
     */
    public static function resolve($requestedNumber, array $userNumbers): ?string
    {
        $numbers = [];
        foreach ($userNumbers as $number) {
            $number = is_scalar($number) ? trim((string)$number) : '';
            if ($number !== '' && ctype_digit($number) && !in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }
        $requestedNumber = is_scalar($requestedNumber) ? trim((string)$requestedNumber) : '';
        if (in_array($requestedNumber, $numbers, true)) {
            return $requestedNumber;
        }
        // Номер в настройках виджета разошёлся с модулем: однозначное сопоставление модуля главнее.
        return count($numbers) === 1 ? $numbers[0] : null;
    }

    /**
     * id пользователя amoCRM: только цифры, иначе ''.
     * @param mixed $userId
     * @return string
     */
    public static function userId($userId): string
    {
        $userId = is_scalar($userId) ? trim((string)$userId) : '';
        return ctype_digit($userId) ? $userId : '';
    }

    /**
     * Номер назначения: только цифры и ведущий «+». Без цифр — ''.
     * @param mixed $number
     * @return string
     */
    public static function destination($number): string
    {
        $number = preg_replace('/[^0-9+]/', '', is_scalar($number) ? (string)$number : '');
        $number = preg_replace('/(?!^)\+/', '', (string)$number);
        return preg_match('/\d/', (string)$number) ? (string)$number : '';
    }
}
