<?php

declare(strict_types=1);

// #64: добавление правил для новых типов звонков на существующих установках.

$root = dirname(__DIR__);
require_once $root . '/Lib/EntitySettingsDefaults.php';

use Modules\ModuleAmoCrm\Lib\EntitySettingsDefaults;

function checkEsd($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$defaults = EntitySettingsDefaults::load();
checkEsd(8, count($defaults), 'all default rules are loaded');
checkEsd([], EntitySettingsDefaults::load($root . '/db/missing-file.json'), 'missing file gives no rules');

$types = ['OUTGOING_UNKNOWN_FAIL'];
$existing = [
    ['portalId' => '100', 'type' => 'MISSING_UNKNOWN', 'did' => ''],
    ['portalId' => '100', 'type' => 'OUTGOING_UNKNOWN', 'did' => ''],
    // Правило нового типа только для DID — общего правила нет.
    ['portalId' => '100', 'type' => 'OUTGOING_UNKNOWN_FAIL', 'did' => '74950000000'],
    ['portalId' => '200', 'type' => 'MISSING_UNKNOWN', 'did' => ''],
    ['portalId' => '200', 'type' => 'OUTGOING_UNKNOWN_FAIL', 'did' => ''],
];
$rules = EntitySettingsDefaults::missingRules($existing, $defaults, $types);
checkEsd(1, count($rules), 'one rule is added');
checkEsd('100', $rules[0]['portalId'], 'rule is added for the portal without it');
checkEsd('OUTGOING_UNKNOWN_FAIL', $rules[0]['type'], 'only the requested type is added');
checkEsd('', $rules[0]['did'], 'general rule (empty DID)');
foreach (['create_contact', 'create_lead', 'create_task', 'create_unsorted'] as $k) {
    checkEsd('0', $rules[0][$k], "added rule: $k is off");
}

checkEsd([], EntitySettingsDefaults::missingRules([], $defaults, $types), 'empty table: nothing (fillEntitySettings fills it)');
checkEsd([], EntitySettingsDefaults::missingRules($existing, $defaults, []), 'no requested types: nothing');

echo "OK\n";
