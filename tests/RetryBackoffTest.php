<?php

declare(strict_types=1);

// #57: пауза между попытками и частота записи ошибок.

require_once dirname(__DIR__) . '/Lib/RetryBackoff.php';

use Modules\ModuleAmoCrm\Lib\RetryBackoff;

function checkBackoff($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$b = new RetryBackoff(2, 60, 60);
checkBackoff(true, $b->canAttempt(1000), 'fresh state allows attempt');
checkBackoff(null, $b->succeed(1000), 'success without failures reports no outage');

// Пауза растёт 2, 4, 8, 16, 32, 60, 60.
$now = 1000;
$delays = [];
$logged = [];
for ($i = 0; $i < 7; $i++) {
    $logged[] = $b->fail($now);
    $delays[] = $b->retryAt() - $now;
    checkBackoff(false, $b->canAttempt($b->retryAt() - 1), "attempt blocked before retryAt (#$i)");
    checkBackoff(true, $b->canAttempt($b->retryAt()), "attempt allowed at retryAt (#$i)");
    $now = $b->retryAt();
}
checkBackoff([2, 4, 8, 16, 32, 60, 60], $delays, 'exponential delay capped at max');
// Неудачи в моменты 1000, 1002, 1006, 1014, 1030, 1062, 1122:
// запись на первой, затем не чаще раза в 60 с (1062, 1122).
checkBackoff([true, false, false, false, false, true, true], $logged, 'error log is throttled');
checkBackoff(7, $b->failures(), 'failures counted');

checkBackoff(1122 - 1000, $b->succeed(1122), 'outage measured from the first failure');
checkBackoff(0, $b->failures(), 'success resets failures');
checkBackoff(true, $b->canAttempt(0), 'success resets retryAt');
checkBackoff(true, $b->fail(2000), 'first failure after recovery is logged');
checkBackoff(2, $b->retryAt() - 2000, 'delay restarts from base after recovery');

echo "OK\n";
