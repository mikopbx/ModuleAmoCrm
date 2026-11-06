<?php

declare(strict_types=1);

// Настройка интеграции: адреса webhook/виджета, план webhook, сопоставление пользователей.

require_once dirname(__DIR__) . '/Lib/IntegrationConfig.php';

use Modules\ModuleAmoCrm\Lib\IntegrationConfig as C;

function checkIntegration($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$token = 'Abc123';

// Хост.
checkIntegration('pbx.example.com', C::resolveHost(' https://pbx.example.com/ ', 'fallback.ru'), 'scheme and path stripped');
checkIntegration('fallback.ru', C::resolveHost('', 'fallback.ru'), 'fallback used when empty');
checkIntegration('', C::resolveHost('', ''), 'no host');

// Порт обязателен для webhook.
checkIntegration(C::STATE_PORT_REQUIRED, C::webhookBlocker('h', '', $token), 'empty port blocks webhook');
checkIntegration(C::STATE_PORT_REQUIRED, C::webhookBlocker('h', '70000', $token), 'port out of range');
checkIntegration(C::STATE_PORT_REQUIRED, C::webhookBlocker('h', '0443', $token), 'leading zero rejected');
checkIntegration(C::STATE_NO_TOKEN, C::webhookBlocker('h', '61446', ' '), 'token required');
checkIntegration(C::STATE_NO_HOST, C::webhookBlocker('', '61446', $token), 'host required');
checkIntegration(null, C::webhookBlocker('h', '61446', $token), 'all set');
checkIntegration('https://h:61446/Abc123/entity-update', C::webhookUrl('h', '61446', $token), 'target url');
checkIntegration(null, C::webhookUrl('h', '', $token), 'no target without port');

// miko_pbx_host: host:port/token, без порта — host.
checkIntegration('h:61446/Abc123', C::mikoPbxHost('h', '61446', $token), 'widget host with port');
checkIntegration('h', C::mikoPbxHost('h', '', $token), 'widget host without port');

// Свои webhook.
checkIntegration(true, C::isOwnWebhook('http://h/pbxcore/api/amo-crm/v1/entity-update'), 'legacy http');
checkIntegration(true, C::isOwnWebhook('https://h:443/pbxcore/api/amo-crm/v1/entity-update'), 'legacy https with port');
checkIntegration(true, C::isOwnWebhook('https://h:61446/OldToken9/entity-update'), 'new format with other token');
checkIntegration(false, C::isOwnWebhook('https://example.com/x'), 'foreign webhook');
checkIntegration(false, C::isOwnWebhook('https://h/a/b/entity-update'), 'nested path is foreign');

// События: список (GET) и объект (POST).
checkIntegration(['add_contact', 'add_lead'], C::webhookEvents(['add_lead', 'add_contact']), 'events from list');
checkIntegration(['add_contact', 'add_lead'], C::webhookEvents(['add_lead' => 1, 'add_contact' => 1, 'x' => 0]), 'events from object');

// План webhook.
$target = C::webhookUrl('h', '61446', $token);
$plan = C::planWebhooks([], $target, C::WEBHOOK_EVENTS);
checkIntegration(['create' => true, 'delete' => []], $plan, 'no webhooks -> create');

$plan = C::planWebhooks([
    ['destination' => 'http://h/pbxcore/api/amo-crm/v1/entity-update', 'settings' => ['add_contact']],
    ['destination' => 'https://h:61446/OldToken/entity-update', 'settings' => ['add_contact']],
    ['destination' => 'https://example.com/hook', 'settings' => ['add_contact']],
], $target, C::WEBHOOK_EVENTS);
checkIntegration(true, $plan['create'], 'legacy present -> create target');
checkIntegration(
    ['http://h/pbxcore/api/amo-crm/v1/entity-update', 'https://h:61446/OldToken/entity-update'],
    $plan['delete'],
    'old own webhooks deleted, foreign kept'
);

$plan = C::planWebhooks([['destination' => $target, 'settings' => array_reverse(C::WEBHOOK_EVENTS)]], $target, C::WEBHOOK_EVENTS);
checkIntegration(['create' => false, 'delete' => []], $plan, 'target up to date');

$plan = C::planWebhooks([['destination' => $target, 'settings' => ['add_contact']]], $target, C::WEBHOOK_EVENTS);
checkIntegration(true, $plan['create'], 'events differ -> upsert');

$plan = C::planWebhooks([['destination' => $target, 'settings' => C::WEBHOOK_EVENTS, 'disabled' => true]], $target, C::WEBHOOK_EVENTS);
checkIntegration(true, $plan['create'], 'disabled -> upsert');

$plan = C::planWebhooks([['destination' => 'http://h/pbxcore/api/amo-crm/v1/entity-update']], null, C::WEBHOOK_EVENTS);
checkIntegration(['create' => false, 'delete' => []], $plan, 'no target -> keep existing');

// pbx_users: строка (UI amoCRM) и объект (API).
$asString = '{"480711":"201","2794642":" 203 ","7689754":""}';
checkIntegration(['2794642' => '203', '480711' => '201'], C::normalizeUsersMap($asString), 'users map from string');
checkIntegration(['480711' => '201'], C::normalizeUsersMap(['480711' => 201, 'x' => '1', '0' => '5']), 'users map from array');
checkIntegration([], C::normalizeUsersMap('not json'), 'broken string');

// Применение сопоставления.
$valid = ['201', '202', '203'];
$r = C::applyUsersMap(['1' => '201', '2' => '202'], ['1' => '203'], $valid);
checkIntegration(['1' => '203', '2' => '202'], $r['map'], 'valid change accepted, others untouched');
checkIntegration(['1'], $r['changed'], 'changed list');

$r = C::applyUsersMap(['1' => '201'], ['1' => '999'], $valid);
checkIntegration(['1' => ''], $r['map'], 'unknown number resets mapping');
checkIntegration(['1' => '999'], $r['rejected'], 'rejected number reported');

$r = C::applyUsersMap(['1' => '201'], ['1' => '201', '3' => '999'], $valid);
checkIntegration([], $r['changed'], 'same value and new invalid user -> no change');
checkIntegration(['1' => '201'], $r['map'], 'invalid new user not added');

$r = C::applyUsersMap(['1' => '201'], ['1' => ''], $valid);
checkIntegration(['1' => ''], $r['map'], 'empty value clears mapping');

// Настройки виджета.
$desired = C::desiredWidgetSettings('h', '61446', $token, ['2' => '202', '1' => '201', '3' => '']);
checkIntegration(['1' => '201', '2' => '202'], $desired['pbx_users'], 'empty mapping dropped, sorted');
$current = ['token' => $token, 'active' => 'Y', 'miko_pbx_host' => 'h:61446/Abc123', 'pbx_users' => '{"2":"202","1":"201"}'];
checkIntegration(false, C::widgetSettingsDiffer($current, $desired), 'string vs object format is not a difference');
$current['pbx_users'] = '{"1":"201"}';
checkIntegration(true, C::widgetSettingsDiffer($current, $desired), 'users differ');
$current['pbx_users'] = ['1' => '201', '2' => '202'];
$current['token'] = 'other';
checkIntegration(true, C::widgetSettingsDiffer($current, $desired), 'token differs');

$body = C::widgetRequestBody(C::desiredWidgetSettings('h', '', $token, []));
checkIntegration('{"token":"Abc123","miko_pbx_host":"h","pbx_users":{}}', json_encode($body), 'empty users sent as object, no active key');

// Случайная ошибка "Invalid URL".
checkIntegration(true, C::isTransientWebhookError(['error-code' => 400, 'error-string' => '{"detail":"Invalid URL"}']), 'transient 400');
checkIntegration(false, C::isTransientWebhookError(['error-code' => 401, 'error-string' => 'Invalid URL']), 'other code');

echo "OK\n";
