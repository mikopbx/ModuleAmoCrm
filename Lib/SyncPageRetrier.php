<?php

declare(strict_types=1);

namespace Modules\ModuleAmoCrm\Lib;

final class SyncPageRetrier
{
    private const MAX_ATTEMPTS = 10;

    /**
     * Repeats a failed synchronization request without changing its URL.
     *
     * @param string   $pageUrl
     * @param callable $request Returns ['success' => bool, 'result' => mixed].
     * @param callable $wait    Called between failed attempts.
     * @return array{success: bool, result: mixed, attempts: int}
     */
    public static function run(string $pageUrl, callable $request, callable $wait): array
    {
        $lastResult = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = $request($pageUrl);
            $lastResult = $response['result'] ?? null;
            if (($response['success'] ?? false) === true) {
                return [
                    'success' => true,
                    'result' => $lastResult,
                    'attempts' => $attempt,
                ];
            }
            if ($attempt < self::MAX_ATTEMPTS) {
                $wait();
            }
        }

        return [
            'success' => false,
            'result' => $lastResult,
            'attempts' => self::MAX_ATTEMPTS,
        ];
    }
}
