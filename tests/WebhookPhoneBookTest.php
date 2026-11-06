<?php

declare(strict_types=1);

// Webhook entity-update: компании приходят в contacts (type=company) или отдельным ключом companies.

require_once dirname(__DIR__) . '/Lib/PhoneBookEntity.php';

use Modules\ModuleAmoCrm\Lib\PhoneBookEntity;

function checkWebhook($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$data = [
    'contacts'  => [
        'update' => [['id' => '10', 'type' => 'contact'], ['id' => '11', 'type' => 'company']],
        'delete' => [['id' => '12', 'type' => 'contact']],
    ],
    'companies' => [
        'add'    => [['id' => '20']],
        'update' => [['id' => '21', 'type' => 'contact']],
    ],
    'leads'     => ['update' => [['id' => '30']]],
];
$updates = PhoneBookEntity::webhookUpdates($data);

checkWebhook(['add', 'update', 'delete'], array_keys($updates), 'actions present, leads ignored');
checkWebhook([['id' => '20', 'type' => 'company']], $updates['add'], 'companies key gets company type');
checkWebhook(
    [['id' => '10', 'type' => 'contact'], ['id' => '11', 'type' => 'company'], ['id' => '21', 'type' => 'company']],
    $updates['update'],
    'contacts and companies merged, companies forced to company'
);
checkWebhook('linked_company_id', PhoneBookEntity::idField($updates['add'][0]), 'company id field');
checkWebhook('idEntity', PhoneBookEntity::idField(PhoneBookEntity::webhookUpdates(['contacts' => ['add' => [['id' => '5']]]])['add'][0]), 'missing type defaults to contact');
checkWebhook([], PhoneBookEntity::webhookUpdates(['contacts' => 'x', 'companies' => ['add' => 'y']]), 'malformed input ignored');

echo "OK\n";
