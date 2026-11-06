<?php

namespace Modules\ModuleAmoCrm\Lib;

/**
 * Типы отвеченных входящих, определённые во время разговора, по linkedid.
 *
 * Хранится отдельно от состояния автооткрытия карточки: запись должна пережить
 * откат offset CDR и приход плеч одного linkedid в разных батчах, поэтому
 * удаляется только по TTL. Состояние в памяти: при перезапуске демона теряется,
 * и звонок классифицируется по CDR.
 */
final class IncompleteCallTypes
{
    // CDR приходит через секунды после завершения, поздние плечи — через минуты.
    // Сутки покрывают и откаты offset, и отставание демона.
    public const DEFAULT_TTL = 24 * 3600;

    /** @var array<string, array{type: string, ts: int}> */
    private array $items = [];

    /**
     * Запоминает тип. Уже сохранённый тип не перезаписывается: при повторном
     * проходе контакт может уже существовать, и тип получится неверным.
     */
    public function remember(string $linkedId, string $type, int $now): void
    {
        if ($linkedId === '' || $type === '' || isset($this->items[$linkedId])) {
            return;
        }
        $this->items[$linkedId] = ['type' => $type, 'ts' => $now];
    }

    public function get(string $linkedId): ?string
    {
        return $this->items[$linkedId]['type'] ?? null;
    }

    /**
     * Удаляет записи старше TTL (CDR так и не пришёл или был отфильтрован).
     */
    public function purge(int $now, int $ttl = self::DEFAULT_TTL): void
    {
        foreach ($this->items as $linkedId => $item) {
            if ($now - $item['ts'] > $ttl) {
                unset($this->items[$linkedId]);
            }
        }
    }

    public function count(): int
    {
        return count($this->items);
    }
}
