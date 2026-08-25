<?php

namespace Modules\ModuleAmoCrm\Lib;

final class CdrBatch
{
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
