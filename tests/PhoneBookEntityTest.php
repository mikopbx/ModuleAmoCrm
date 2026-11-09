<?php

declare(strict_types=1);

// GitHub #55: контакт/компания без доп. полей (custom_fields_values = null) или с некорректными
// данными не должна обрывать сохранение пачки в ConnectorDb::updatePhoneBook().

$root = dirname(__DIR__);
require_once $root . '/Lib/PhoneBookEntity.php';

use Modules\ModuleAmoCrm\Lib\PhoneBookEntity;

function assertPb($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// Предупреждения → исключения, как Whoops в ConnectorDb: любая notice/warning — провал теста.
set_error_handler(static function (int $no, string $str): bool {
    throw new ErrorException($str, 0, $no);
});

$apiPhone = ['field_code' => 'PHONE', 'values' => [['value' => '+7 (925) 718-30-47', 'enum_code' => 'WORK']]];
$hookPhone = ['code' => 'PHONE', 'values' => [['value' => '79257183048']]];
$email = ['field_code' => 'EMAIL', 'values' => [['value' => 'a@b.c']]];

// --- phones ---
$cases = [
    'API v4 contact without custom fields (null)' => [['id' => 1, 'type' => 'contact', 'custom_fields_values' => null], []],
    'neither custom_fields nor custom_fields_values' => [['id' => 1, 'type' => 'contact'], []],
    'custom fields as string (old fallback)' => [['id' => 1, 'type' => 'contact', 'custom_fields_values' => ''], []],
    'field is not an array' => [['custom_fields_values' => ['x', null, 5]], []],
    'PHONE field without values' => [['custom_fields_values' => [['field_code' => 'PHONE']]], []],
    'PHONE field with values = null' => [['custom_fields_values' => [['field_code' => 'PHONE', 'values' => null]]], []],
    'value is not an array / empty' => [['custom_fields_values' => [['field_code' => 'PHONE', 'values' => ['79250000000', null, ['value' => ''], ['value' => ' '], []]]]], []],
    'API v4 contact with phone and email' => [['custom_fields_values' => [$email, $apiPhone]], ['+7 (925) 718-30-47']],
    'webhook contact (custom_fields, code)' => [['custom_fields' => [$hookPhone]], ['79257183048']],
    'integer phone value' => [['custom_fields' => [['code' => 'PHONE', 'values' => [['value' => 79257183049]]]]], ['79257183049']],
    'several phones' => [['custom_fields_values' => [$apiPhone, $hookPhone]], ['+7 (925) 718-30-47', '79257183048']],
];
foreach ($cases as $message => [$entity, $expected]) {
    assertPb($expected, PhoneBookEntity::phones($entity), "phones: {$message}");
}

// --- idField ---
assertPb('idEntity', PhoneBookEntity::idField(['id' => 15, 'type' => 'contact']), 'contact id field');
assertPb('linked_company_id', PhoneBookEntity::idField(['id' => '16', 'type' => 'company']), 'company id field (webhook string id)');
assertPb(null, PhoneBookEntity::idField(['id' => 15]), 'missing type');
assertPb(null, PhoneBookEntity::idField(['id' => 15, 'type' => 'lead']), 'unknown type');
assertPb(null, PhoneBookEntity::idField(['type' => 'contact']), 'missing id');
assertPb(null, PhoneBookEntity::idField(['id' => "1' OR '1'='1", 'type' => 'contact']), 'non-numeric id is rejected');
assertPb(null, PhoneBookEntity::idField(['id' => null, 'type' => 'contact']), 'null id');
assertPb(null, PhoneBookEntity::idField('not an entity'), 'entity is not an array');
assertPb(null, PhoneBookEntity::idField(['id' => 15, 'type' => ['contact']]), 'type is not a string');
foreach ([0, -1, '0', '-1', ' 15', '15 ', '015', 1.5, true] as $badId) {
    assertPb(null, PhoneBookEntity::idField(['id' => $badId, 'type' => 'contact']), 'invalid id ' . var_export($badId, true));
}

// --- attr ---
$company = ['id' => 16, 'type' => 'company', 'name' => 'ООО Ромашка', 'responsible_user_id' => 123, 'linked_company_id' => null];
assertPb('ООО Ромашка', PhoneBookEntity::attr($company, 'name'), 'string attribute');
assertPb('123', PhoneBookEntity::attr($company, 'responsible_user_id'), 'integer attribute as string');
assertPb('', PhoneBookEntity::attr($company, 'linked_company_id'), 'null attribute');
assertPb('', PhoneBookEntity::attr($company, 'company_name'), 'missing attribute (company without responsible etc.)');
assertPb('', PhoneBookEntity::attr(['name' => ['x']], 'name'), 'non-scalar attribute');

// --- rows: записи m_ModuleAmoPhones ---
$contact = ['id' => 15, 'type' => 'contact', 'name' => 'Иван', 'responsible_user_id' => 7, 'custom_fields_values' => [$apiPhone, $hookPhone]];
assertPb(
    [
        ['entityType' => 'contact', 'responsible_user_id' => '7', 'phone' => '+7 (925) 718-30-47', 'name' => 'Иван', 'company_name' => '', 'linked_company_id' => '', 'idEntity' => '15'],
        ['entityType' => 'contact', 'responsible_user_id' => '7', 'phone' => '79257183048', 'name' => 'Иван', 'company_name' => '', 'linked_company_id' => '', 'idEntity' => '15'],
    ],
    PhoneBookEntity::rows($contact, (string)PhoneBookEntity::idField($contact)),
    'contact rows: one per phone, id in idEntity'
);
$companyWithPhone = ['id' => '16', 'type' => 'company', 'name' => 'ООО', 'custom_fields' => [$hookPhone]];
assertPb(
    [['entityType' => 'company', 'responsible_user_id' => null, 'phone' => '79257183048', 'name' => 'ООО', 'company_name' => '', 'linked_company_id' => '16']],
    PhoneBookEntity::rows($companyWithPhone, (string)PhoneBookEntity::idField($companyWithPhone)),
    'company rows: id in linked_company_id, missing responsible stays NULL'
);
assertPb([], PhoneBookEntity::rows(['id' => 2, 'type' => 'contact', 'custom_fields_values' => null], 'idEntity'), 'no rows for entity without custom fields');

// --- пачка из SyncDaemon: сущность без доп. полей не мешает остальным ---
$chunk = [
    ['id' => 1, 'type' => 'contact', 'custom_fields_values' => [$apiPhone]],
    ['id' => 2, 'type' => 'contact', 'custom_fields_values' => null],
    ['id' => 3, 'type' => 'company'],
    ['type' => 'contact'],
    ['id' => 4, 'type' => 'contact', 'custom_fields_values' => [$hookPhone]],
];
$saved = [];
foreach ($chunk as $entity) {
    $idField = PhoneBookEntity::idField($entity);
    if ($idField === null) {
        continue;
    }
    foreach (PhoneBookEntity::rows($entity, $idField) as $row) {
        $saved[] = [$row['idEntity'], $row['phone']];
    }
}
assertPb([['1', '+7 (925) 718-30-47'], ['4', '79257183048']], $saved, 'entities after a malformed one keep their phones');

restore_error_handler();
echo "OK PhoneBookEntityTest\n";
