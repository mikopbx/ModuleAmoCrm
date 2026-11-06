<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/Lib/CallTypeResolver.php';
require_once $root . '/Lib/IncompleteCallTypes.php';

use Modules\ModuleAmoCrm\Lib\CallTypeResolver;
use Modules\ModuleAmoCrm\Lib\IncompleteCallTypes;

function checkIncompleteType($expected, $actual, string $message): void
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

// --- CallTypeResolver::classify: прежняя классификация не изменилась.
$classifyCases = [
    // isMissed, contactExists, isIncoming => type
    [true,  false, true,  CallTypeResolver::MISSING_UNKNOWN],
    [true,  false, false, CallTypeResolver::MISSING_UNKNOWN],
    [true,  true,  true,  CallTypeResolver::MISSING_KNOWN],
    [false, false, true,  CallTypeResolver::INCOMING_UNKNOWN],
    [false, true,  true,  CallTypeResolver::INCOMING_KNOWN],
    [true,  true,  false, CallTypeResolver::OUTGOING_KNOWN_FAIL],
    [false, true,  false, CallTypeResolver::OUTGOING_KNOWN],
    [false, false, false, CallTypeResolver::OUTGOING_UNKNOWN],
];
foreach ($classifyCases as [$isMissed, $contactExists, $isIncoming, $expected]) {
    checkIncompleteType(
        $expected,
        CallTypeResolver::classify($isMissed, $contactExists, $isIncoming),
        "classify(missed=" . var_export($isMissed, true) . ", known=" . var_export($contactExists, true)
        . ", incoming=" . var_export($isIncoming, true) . ")"
    );
}

// --- CallTypeResolver::resolve
// #56: контакт создан во время разговора, CDR видит его как известный.
checkIncompleteType(
    CallTypeResolver::INCOMING_UNKNOWN,
    CallTypeResolver::resolve(CallTypeResolver::INCOMING_UNKNOWN, false, true, true),
    'answered call keeps the type determined during the conversation'
);
// По CDR звонок оказался пропущенным: тип из CDR.
checkIncompleteType(
    CallTypeResolver::MISSING_KNOWN,
    CallTypeResolver::resolve(CallTypeResolver::INCOMING_UNKNOWN, true, true, true),
    'missed call ignores the type determined during the conversation'
);
// Перевод на внешний номер: исходящее плечо того же linkedid.
checkIncompleteType(
    CallTypeResolver::OUTGOING_KNOWN,
    CallTypeResolver::resolve(CallTypeResolver::INCOMING_UNKNOWN, false, true, false),
    'outgoing leg ignores the type determined during the conversation'
);
checkIncompleteType(
    CallTypeResolver::INCOMING_KNOWN,
    CallTypeResolver::resolve(null, false, true, true),
    'without incomplete type the CDR classification is used'
);
checkIncompleteType(
    CallTypeResolver::INCOMING_UNKNOWN,
    CallTypeResolver::resolve('', false, false, true),
    'empty incomplete type is ignored'
);

// --- IncompleteCallTypes
$store = new IncompleteCallTypes();
$store->remember('1700000000.1', CallTypeResolver::INCOMING_UNKNOWN, 1000);
checkIncompleteType(CallTypeResolver::INCOMING_UNKNOWN, $store->get('1700000000.1'), 'type is stored by linkedid');
checkIncompleteType(null, $store->get('1700000000.2'), 'unknown linkedid has no type');

// Повторный проход (prepareData вернул false, finished не выставлен) не перезаписывает тип.
$store->remember('1700000000.1', CallTypeResolver::INCOMING_KNOWN, 1005);
checkIncompleteType(CallTypeResolver::INCOMING_UNKNOWN, $store->get('1700000000.1'), 'first type is not overwritten');

// Второй звонок с того же номера — другой linkedid, записи не пересекаются.
$store->remember('1700000100.7', CallTypeResolver::INCOMING_KNOWN, 1100);
checkIncompleteType(CallTypeResolver::INCOMING_UNKNOWN, $store->get('1700000000.1'), 'calls do not share types (1)');
checkIncompleteType(CallTypeResolver::INCOMING_KNOWN, $store->get('1700000100.7'), 'calls do not share types (2)');

// Откат offset / новое плечо linkedid в следующем батче: тип по-прежнему доступен,
// повторная регистрация звонка (уже известный контакт) его не перезаписывает.
$store->remember('1700000000.1', CallTypeResolver::INCOMING_KNOWN, 2000);
checkIncompleteType(CallTypeResolver::INCOMING_UNKNOWN, $store->get('1700000000.1'), 'type survives later batches');
checkIncompleteType(2, $store->count(), 'two linkedids stored');

// TTL.
$store->remember('old', CallTypeResolver::INCOMING_UNKNOWN, 0);
$store->purge(IncompleteCallTypes::DEFAULT_TTL);
checkIncompleteType(CallTypeResolver::INCOMING_UNKNOWN, $store->get('old'), 'record at TTL boundary is kept');
$store->purge(IncompleteCallTypes::DEFAULT_TTL + 1);
checkIncompleteType(null, $store->get('old'), 'expired record is purged');
checkIncompleteType(CallTypeResolver::INCOMING_KNOWN, $store->get('1700000100.7'), 'fresh record is not purged');

// Пустые значения не сохраняются.
$store->remember('', CallTypeResolver::INCOMING_UNKNOWN, 1);
$store->remember('x', '', 1);
checkIncompleteType(null, $store->get('x'), 'empty type is not stored');

echo "OK\n";
