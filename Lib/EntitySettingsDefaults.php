<?php

namespace Modules\ModuleAmoCrm\Lib;

/**
 * Правила обработки звонков по умолчанию (Setup/default-entity-settings.json).
 *
 * Файл лежит вне db/: при обновлении модуля установщик копирует
 * custom_modules/Backup/<module>/db/* поверх нового db/, и обновлённые
 * правила по умолчанию заменялись бы старыми (#64).
 */
final class EntitySettingsDefaults
{
    /**
     * Читает правила по умолчанию.
     * @param string|null $file
     * @return array
     */
    public static function load(?string $file = null): array
    {
        $file = $file ?? dirname(__DIR__) . '/Setup/default-entity-settings.json';
        try {
            $data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Правила для типов, появившихся после установки модуля (#64): для каждого
     * portalId, у которого уже есть правила, но нет общего правила (did = '')
     * указанного типа, возвращается правило по умолчанию с этим portalId.
     * Порталы без правил не трогаются: их заполнит ConnectorDb::fillEntitySettings().
     *
     * @param array $existing Строки m_ModuleAmoEntitySettings: portalId, type, did.
     * @param array $defaults Правила по умолчанию.
     * @param array $types    Типы, которые нужно добавить.
     * @return array
     */
    public static function missingRules(array $existing, array $defaults, array $types): array
    {
        // Ключ с префиксом: числовой portalId иначе станет int-ключом массива.
        $present = [];
        foreach ($existing as $row) {
            $portalId = (string)($row['portalId'] ?? '');
            $key = 'p' . $portalId;
            if (!isset($present[$key])) {
                $present[$key] = ['portalId' => $portalId, 'types' => []];
            }
            if (trim((string)($row['did'] ?? '')) === '') {
                $present[$key]['types'][(string)($row['type'] ?? '')] = true;
            }
        }
        $result = [];
        foreach ($present as $portal) {
            $portalId    = $portal['portalId'];
            $portalTypes = $portal['types'];
            foreach ($defaults as $default) {
                $type = (string)($default['type'] ?? '');
                if (!in_array($type, $types, true) || isset($portalTypes[$type])
                    || trim((string)($default['did'] ?? '')) !== '') {
                    continue;
                }
                $default['portalId'] = $portalId;
                $result[] = $default;
            }
        }
        return $result;
    }
}
