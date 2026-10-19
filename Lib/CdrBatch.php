<?php

namespace Modules\ModuleAmoCrm\Lib;

final class CdrBatch
{
    /**
     * The cursor belongs to the ID-ordered source page, not to the expanded
     * call legs: an old linkedid may have a much newer leg.
     */
    public static function loadPage(callable $fetch, array $filter, array $legsFilter): ?array
    {
        $page = $fetch($filter);
        if (empty($page)) {
            return null;
        }
        $offset = (int) max(array_column($page, 'id'));
        $legsFilter['bind']['linkedid'] = array_values(array_unique(array_column($page, 'linkedid')));
        $rows = $fetch($legsFilter);
        // A failed/truncated expansion must not advance past missing source rows.
        if (array_diff(array_column($page, 'id'), array_column($rows, 'id')) !== []) {
            throw new \RuntimeException('Incomplete CDR page expansion');
        }
        return ['offset' => $offset, 'rows' => $rows];
    }

    /**
     * Removes every CDR leg belonging to a linked call ID.
     */
    public static function removeLinkedId(array $calls, string $linkedId): array
    {
        foreach ($calls as &$subCalls) {
            foreach ($subCalls as $index => $call) {
                if (($call['id'] ?? null) === $linkedId) {
                    unset($subCalls[$index]);
                }
            }
        }
        unset($subCalls);

        return $calls;
    }
}
