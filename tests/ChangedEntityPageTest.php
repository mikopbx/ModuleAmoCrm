<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pageFile = $root . '/Lib/ChangedEntityPage.php';
if (!file_exists($pageFile)) {
    fwrite(STDERR, "FAIL: missing production file {$pageFile}\n");
    exit(1);
}
require_once $pageFile;

use Modules\ModuleAmoCrm\Lib\ChangedEntityPage;

function assertPage($expected, $actual, string $message): void
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

// 200 со списком и ссылкой на следующую страницу.
$data = [
    '_embedded' => ['contacts' => [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]],
    '_links'    => ['next' => ['href' => 'https://x/api/v4/contacts?page=2']],
];
$p = ChangedEntityPage::parse(true, $data, [], 'contacts', 'contact');
assertPage(true, $p['success'], '200 page is success');
assertPage(2, count($p['items']), '200 page items');
assertPage('contact', $p['items'][0]['type'], 'item type is set');
assertPage('https://x/api/v4/contacts?page=2', $p['nextPage'], 'next page link');

// 200, последняя страница.
$p = ChangedEntityPage::parse(true, ['_embedded' => ['leads' => [['id' => 5]]]], [], 'leads', 'leads');
assertPage('', $p['nextPage'], 'last page has no next link');

// 204 — легитимно пустая выборка.
$p = ChangedEntityPage::parse(false, [], ['error-code' => 204, 'error-msg' => ''], 'leads', 'leads');
assertPage(true, $p['success'], '204 is an empty successful page');
assertPage([], $p['items'], '204 has no items');
assertPage('', $p['nextPage'], '204 ends pagination');

// 401 — сбой, не пустая страница (иначе init удалит кэш).
$p = ChangedEntityPage::parse(false, [], ['error-code' => 401, 'error-msg' => ''], 'contacts', 'contact');
assertPage(false, $p['success'], '401 is a failure');
assertPage(401, $p['messages']['error-code'], '401 code is reported');

// Таймаут соединения — код 0.
$p = ChangedEntityPage::parse(false, [], ['error-code' => 0, 'error-msg' => 'cURL error 28'], 'leads', 'leads');
assertPage(false, $p['success'], 'timeout is a failure');
assertPage('cURL error 28', $p['messages']['error-msg'], 'timeout message is reported');

// 5xx.
$p = ChangedEntityPage::parse(false, [], ['error-code' => 502], 'companies', 'company');
assertPage(false, $p['success'], '502 is a failure');

// Нет сообщений вообще — сбой.
$p = ChangedEntityPage::parse(false, [], [], 'leads', 'leads');
assertPage(false, $p['success'], 'unknown failure is a failure');

// 200 без _embedded — неожиданный ответ, сбой.
$p = ChangedEntityPage::parse(true, ['_links' => []], [], 'contacts', 'contact');
assertPage(false, $p['success'], '200 without _embedded is a failure');
$p = ChangedEntityPage::parse(true, null, [], 'contacts', 'contact');
assertPage(false, $p['success'], '200 with non-array body is a failure');

// 200 с пустым списком — успех (на всякий случай, amoCRM обычно отвечает 204).
$p = ChangedEntityPage::parse(true, ['_embedded' => ['contacts' => []]], [], 'contacts', 'contact');
assertPage(true, $p['success'], '200 with empty list is success');

echo "OK ChangedEntityPageTest\n";
