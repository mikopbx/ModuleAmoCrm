<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$resolverFile = $root . '/Lib/ResponsibleResolver.php';
if (!file_exists($resolverFile)) {
    fwrite(STDERR, "FAIL: missing production file {$resolverFile}\n");
    exit(1);
}
require_once $resolverFile;

use Modules\ModuleAmoCrm\Lib\ResponsibleResolver;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n"
        );
        exit(1);
    }
}

$resolverCases = [
    'call responsible has priority' => ['42', '99', 42],
    'numeric default is used when call responsible is empty' => ['', '99', 99],
    'numeric default is used when call responsible is invalid' => ['not-a-user', '99', 99],
    'zero is not a valid call responsible' => [0, '99', 99],
    'empty values do not resolve to zero' => ['', '', null],
    'non-numeric values do not resolve to zero' => ['first', 'default', null],
    'negative values are rejected' => ['-42', '-99', null],
];

foreach ($resolverCases as $message => [$callResponsible, $defaultResponsible, $expected]) {
    assertSameValue(
        $expected,
        ResponsibleResolver::resolve($callResponsible, $defaultResponsible),
        $message
    );
}

assertSameValue(
    true,
    ResponsibleResolver::requiresDefault(['create_contact' => 'on']),
    'contact creation requires a default responsible'
);
assertSameValue(
    true,
    ResponsibleResolver::requiresDefault(['create_task' => 1]),
    'task creation requires a default responsible'
);
assertSameValue(
    false,
    ResponsibleResolver::requiresDefault([
        'create_contact' => '0',
        'create_lead' => false,
        'create_unsorted' => 0,
        'create_task' => '',
    ]),
    'a rule without entity creation does not require a default responsible'
);

$cdrBatchFile = $root . '/Lib/CdrBatch.php';
if (!file_exists($cdrBatchFile)) {
    fwrite(STDERR, "FAIL: missing production file {$cdrBatchFile}\n");
    exit(1);
}
require_once $cdrBatchFile;

$calls = [
    'phone-a' => [
        ['id' => 'failed-linkedid', 'params' => ['uniq' => 'leg-1']],
        ['id' => 'ok-linkedid', 'params' => ['uniq' => 'leg-2']],
    ],
    'phone-b' => [
        ['id' => 'failed-linkedid', 'params' => ['uniq' => 'leg-3']],
    ],
];

assertSameValue(
    [
        'phone-a' => [
            1 => ['id' => 'ok-linkedid', 'params' => ['uniq' => 'leg-2']],
        ],
        'phone-b' => [],
    ],
    \Modules\ModuleAmoCrm\Lib\CdrBatch::removeLinkedId($calls, 'failed-linkedid'),
    'all legs of the failed linkedid are removed while other calls are preserved'
);

fwrite(STDOUT, "PASS: responsible handling regression tests\n");
