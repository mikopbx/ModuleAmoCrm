<?php

namespace Modules\ModuleAmoCrm\Lib;

/**
 * Классификация звонка для выбора правила обработки (ModuleAmoEntitySettings).
 */
final class CallTypeResolver
{
    // Входящие
    public const MISSING_UNKNOWN     = 'MISSING_UNKNOWN';
    public const MISSING_KNOWN       = 'MISSING_KNOWN';
    public const INCOMING_UNKNOWN    = 'INCOMING_UNKNOWN';
    public const INCOMING_KNOWN      = 'INCOMING_KNOWN';
    // Исходящие
    public const OUTGOING_UNKNOWN    = 'OUTGOING_UNKNOWN';
    public const OUTGOING_KNOWN      = 'OUTGOING_KNOWN';
    public const OUTGOING_KNOWN_FAIL = 'OUTGOING_KNOWN_FAIL';

    /**
     * Тип звонка по итоговым данным CDR.
     */
    public static function classify(bool $isMissed, bool $contactExists, bool $isIncoming): string
    {
        if ($isMissed && !$contactExists) {
            return self::MISSING_UNKNOWN;
        }
        if ($isIncoming && $isMissed) {
            return self::MISSING_KNOWN;
        }
        if ($isIncoming && !$contactExists) {
            return self::INCOMING_UNKNOWN;
        }
        if ($isIncoming) {
            return self::INCOMING_KNOWN;
        }
        if ($isMissed) {
            return self::OUTGOING_KNOWN_FAIL;
        }
        if ($contactExists) {
            return self::OUTGOING_KNOWN;
        }
        return self::OUTGOING_UNKNOWN;
    }

    /**
     * Тип звонка с учётом типа, определённого во время разговора.
     *
     * Контакт, созданный во время отвеченного входящего, к приходу CDR уже есть
     * в БД. Чтобы звонок не обработался по правилу для известных, используется
     * тип, определённый при ответе. Только для отвеченных входящих плеч: если по
     * CDR плечо пропущено или исходящее (перевод на внешний номер с тем же
     * linkedid), тип берётся из CDR.
     */
    public static function resolve(
        ?string $incompleteType,
        bool $isMissed,
        bool $contactExists,
        bool $isIncoming
    ): string {
        if ($incompleteType !== null && $incompleteType !== '' && $isIncoming && !$isMissed) {
            return $incompleteType;
        }
        return self::classify($isMissed, $contactExists, $isIncoming);
    }
}
