<?php

declare(strict_types=1);

/*
 * /callback: внутренний номер берётся из сопоставления модуля, а не из запроса.
 */

require_once dirname(__DIR__) . '/Lib/CallbackPeer.php';

use Modules\ModuleAmoCrm\Lib\CallbackPeer;

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $message: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assertSame('201', CallbackPeer::resolve('201', ['201']), 'own number is accepted');
assertSame('201', CallbackPeer::resolve(' 201 ', [201]), 'spaces and int numbers are normalized');
assertSame('201', CallbackPeer::resolve('202', ['201']), 'foreign number is replaced by the only mapped one');
assertSame('201', CallbackPeer::resolve('', ['201', '201']), 'missing number uses the only mapped one, duplicates collapse');
assertSame('212', CallbackPeer::resolve('212', ['202', '212']), 'one of several own numbers is accepted');
assertSame(null, CallbackPeer::resolve('201', ['202', '212']), 'ambiguous mapping with a foreign number is rejected');
assertSame(null, CallbackPeer::resolve('', ['202', '212']), 'ambiguous mapping without a number is rejected');
assertSame(null, CallbackPeer::resolve('201', []), 'unmapped user is rejected');
assertSame(null, CallbackPeer::resolve('20 4', ['20 4']), 'non-digit mapped number is never used');
assertSame('201', CallbackPeer::resolve('201@internal/n', ['201', '20 4']), 'injected number falls back to the mapping');
assertSame(null, CallbackPeer::resolve(['201'], [null, ['x'], '']), 'malformed input is ignored');

assertSame('1001', CallbackPeer::userId(' 1001 '), 'user id trimmed');
assertSame('1001', CallbackPeer::userId(1001), 'int user id');
assertSame('', CallbackPeer::userId('1001 OR 1'), 'non-numeric user id');
assertSame('', CallbackPeer::userId(['1001']), 'array user id');

assertSame('+74951234567', CallbackPeer::destination('+7 (495) 123-45-67'), 'destination keeps leading plus');
assertSame('74951234567', CallbackPeer::destination('7495+1234567'), 'inner plus is dropped');
assertSame('', CallbackPeer::destination('+'), 'plus only');
assertSame('', CallbackPeer::destination('abc'), 'no digits');
assertSame('', CallbackPeer::destination(null), 'null destination');

fwrite(STDOUT, "PASS: callback peer tests\n");
