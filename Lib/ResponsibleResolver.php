<?php

namespace Modules\ModuleAmoCrm\Lib;

final class ResponsibleResolver
{
    private const CREATION_FLAGS = [
        'create_contact',
        'create_lead',
        'create_unsorted',
        'create_task',
    ];

    /**
     * Selects a valid amoCRM user ID from call data, falling back to the rule default.
     *
     * @param mixed $callResponsible
     * @param mixed $defaultResponsible
     */
    public static function resolve($callResponsible, $defaultResponsible): ?int
    {
        return self::normalize($callResponsible) ?? self::normalize($defaultResponsible);
    }

    /**
     * Returns true when the rule creates at least one amoCRM entity.
     */
    public static function requiresDefault(array $settings): bool
    {
        foreach (self::CREATION_FLAGS as $flag) {
            $value = $settings[$flag] ?? null;
            if ($value === true || $value === 1 || $value === '1' || $value === 'on') {
                return true;
            }
        }
        return false;
    }

    /**
     * Converts positive integer values to an amoCRM user ID.
     *
     * @param mixed $value
     */
    public static function normalize($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/^\d+$/D', $value) !== 1) {
            return null;
        }

        $responsibleId = (int)$value;
        return $responsibleId > 0 ? $responsibleId : null;
    }
}
