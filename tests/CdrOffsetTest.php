<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Lib/CdrBatch.php';

use Modules\ModuleAmoCrm\Lib\CdrBatch;

function checkCdrOffset(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// The old call has a late-inserted leg beyond the missing October 14 call.
$records = [
    ['id' => 294416, 'linkedid' => 'old-call'],
    ['id' => 297870, 'linkedid' => 'october-13'],
    ['id' => 298385, 'linkedid' => 'missing-october-14'],
    ['id' => 299054, 'linkedid' => 'old-call'],
    ['id' => 299055, 'linkedid' => 'october-16'],
];
$fetch = static function (array $filter) use ($records): array {
    if (isset($filter['bind']['linkedid'])) {
        return array_values(array_filter($records, static fn(array $r): bool =>
            in_array($r['linkedid'], $filter['bind']['linkedid'], true)));
    }
    return array_slice(array_values(array_filter($records, static fn(array $r): bool =>
        $r['id'] > $filter['bind']['id'])), 0, $filter['limit']);
};
$filter = ['bind' => ['id' => 294415], 'limit' => 2, 'order' => 'id'];
$legsFilter = ['bind' => ['linkedid' => null]];
$first = CdrBatch::loadPage($fetch, $filter, $legsFilter);
checkCdrOffset($first['offset'] === 297870, 'Expanded leg must not move cursor to 299054');
checkCdrOffset(in_array(299054, array_column($first['rows'], 'id'), true), 'Full call legs must still be loaded');
$filter['bind']['id'] = $first['offset'];
$second = CdrBatch::loadPage($fetch, $filter, $legsFilter);
checkCdrOffset(in_array(298385, array_column($second['rows'], 'id'), true), 'Next page must include missing October 14 call');
checkCdrOffset($second['offset'] === 299054, 'Cursor must follow source page');
$filter['bind']['id'] = 299055;
checkCdrOffset(CdrBatch::loadPage($fetch, $filter, $legsFilter) === null, 'Empty page must not advance');
foreach ([[], [['id' => 299054, 'linkedid' => 'old-call']]] as $incomplete) {
    $failed = false;
    try {
        CdrBatch::loadPage(static fn(array $f): array =>
            isset($f['bind']['linkedid']) ? $incomplete : [['id' => 298385, 'linkedid' => 'missing']],
            $filter, $legsFilter);
    } catch (RuntimeException $e) {
        $failed = true;
    }
    checkCdrOffset($failed, 'Empty or truncated expansion must not advance cursor');
}
echo "CDR offset regression tests passed\n";
