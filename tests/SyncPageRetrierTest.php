<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$retrierFile = $root . '/Lib/SyncPageRetrier.php';
if (!file_exists($retrierFile)) {
    fwrite(STDERR, "FAIL: missing production file {$retrierFile}\n");
    exit(1);
}
require_once $retrierFile;

use Modules\ModuleAmoCrm\Lib\SyncPageRetrier;

function failSyncRetryTest(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertSyncRetrySame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        failSyncRetryTest(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

$requestedUrls = [];
$attempt = 0;
$result = SyncPageRetrier::run(
    'https://example.test/api/v4/contacts?from=100&to=200',
    static function (string $url) use (&$requestedUrls, &$attempt): array {
        $requestedUrls[] = $url;
        $attempt++;
        if ($attempt < 3) {
            return ['success' => false, 'result' => null];
        }
        return ['success' => true, 'result' => ['contacts' => [['id' => 42]]]];
    },
    static function (): void {
    }
);

assertSyncRetrySame(true, $result['success'], 'a later successful attempt must complete the page');
assertSyncRetrySame(
    [
        'https://example.test/api/v4/contacts?from=100&to=200',
        'https://example.test/api/v4/contacts?from=100&to=200',
        'https://example.test/api/v4/contacts?from=100&to=200',
    ],
    $requestedUrls,
    'every retry must use the original page URL'
);

$attemptCount = 0;
$result = SyncPageRetrier::run(
    'https://example.test/api/v4/contacts?from=300&to=400',
    static function () use (&$attemptCount): array {
        $attemptCount++;
        return ['success' => false, 'result' => null];
    },
    static function (): void {
    }
);

assertSyncRetrySame(false, $result['success'], 'ten failed attempts must report an exhausted page');
assertSyncRetrySame(10, $attemptCount, 'an exhausted page must be attempted exactly ten times');

fwrite(STDOUT, "PASS: sync page retry regression tests\n");
