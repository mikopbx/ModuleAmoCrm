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
 * Разбор контакта/компании amoCRM для телефонной книги (m_ModuleAmoPhones).
 * Без зависимостей от Phalcon/MikoPBX — покрывается тестами в tests/.
 *
 * Принимает и формат API v4 (custom_fields_values, field_code), и формат webhook
 * (custom_fields, code). Некорректная сущность не должна обрывать сохранение всей пачки
 * (GitHub #55): API v4 отдаёт custom_fields_values = null у сущности без доп. полей.
 */
final class PhoneBookEntity
{
    /** Тип сущности => колонка m_ModuleAmoPhones, хранящая её ID. */
    public const ID_FIELDS = [
        'contact' => 'idEntity',
        'company' => 'linked_company_id',
    ];

    /**
     * Колонка ID для сущности или null, если тип неизвестен или ID некорректен.
     * ID — положительное целое (int или строка из цифр, как в form-encoded webhook).
     * @param mixed $entity
     * @return string|null
     */
    public static function idField($entity): ?string
    {
        if (!is_array($entity)) {
            return null;
        }
        $type = $entity['type'] ?? null;
        if (!is_string($type) || !isset(self::ID_FIELDS[$type])) {
            return null;
        }
        $id = $entity['id'] ?? null;
        if (!is_int($id) && !is_string($id)) {
            return null;
        }
        if (preg_match('/^[1-9][0-9]*$/', (string)$id) !== 1) {
            return null;
        }
        return self::ID_FIELDS[$type];
    }

    /**
     * Записи m_ModuleAmoPhones для сущности (по одной на телефон), без portalId/initTime/idPhone.
     * $idField — результат idField(); для компании ID пишется в linked_company_id.
     * @param array  $entity
     * @param string $idField
     * @return array<int, array<string, string|null>>
     */
    public static function rows(array $entity, string $idField): array
    {
        $rows = [];
        foreach (self::phones($entity) as $phone) {
            $row = [
                'entityType'          => (string)$entity['type'],
                // NULL, если ответственного нет (как и раньше), — не пустая строка.
                'responsible_user_id' => self::attrOrNull($entity, 'responsible_user_id'),
                'phone'               => $phone,
                'name'                => self::attr($entity, 'name'),
                'company_name'        => self::attr($entity, 'company_name'),
                // '' у контакта без компании: на это опирается IIF(linked_company_id = "") в getContactsByPhones.
                'linked_company_id'   => self::attr($entity, 'linked_company_id'),
            ];
            $row[$idField] = (string)$entity['id'];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Телефоны сущности (непустые строки) из поля с кодом PHONE.
     * Отсутствующие / null / не-массивы на любом уровне пропускаются.
     * @param array $entity
     * @return string[]
     */
    public static function phones(array $entity): array
    {
        $customFields = $entity['custom_fields'] ?? $entity['custom_fields_values'] ?? [];
        if (!is_array($customFields)) {
            return [];
        }
        $phones = [];
        foreach ($customFields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $code = $field['code'] ?? $field['field_code'] ?? '';
            $values = $field['values'] ?? null;
            if ($code !== 'PHONE' || !is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                $phone = is_array($value) ? ($value['value'] ?? null) : null;
                if (is_int($phone)) {
                    $phone = (string)$phone;
                }
                if (!is_string($phone) || trim($phone) === '') {
                    continue;
                }
                $phones[] = $phone;
            }
        }
        return $phones;
    }

    /**
     * Строковый атрибут сущности (name, company_name, ...) — пустая строка, если не задан или не скаляр.
     * @param array  $entity
     * @param string $key
     * @return string
     */
    public static function attr(array $entity, string $key): string
    {
        $value = $entity[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Строковый атрибут сущности или null, если не задан или не скаляр.
     * @param array  $entity
     * @param string $key
     * @return string|null
     */
    public static function attrOrNull(array $entity, string $key): ?string
    {
        $value = $entity[$key] ?? null;
        return is_scalar($value) ? (string)$value : null;
    }
}
