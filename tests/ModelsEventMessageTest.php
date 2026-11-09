<?php

declare(strict_types=1);

// GitHub #54: сообщения в очередь WorkerModelsEvents должны соответствовать контракту ядра
// (WorkerModelsEvents::invokeAction): хуки modelsEventChangeData всех модулей читают $data['model'].

$root = dirname(__DIR__);
require_once $root . '/Lib/ModelsEventMessage.php';

use Modules\ModuleAmoCrm\Lib\ModelsEventMessage;

function assertMsg($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// Значения констант BeanstalkConnectionModelsProvider в ядре (по ним WorkerModelsEvents маршрутизирует сообщения).
const SOURCE_INVOKE_ACTION  = 'WorkerModelsEvents->invokeAction';
const SOURCE_MODELS_CHANGED = 'ModelsBase->sendChangesToBackend';
$nginx = 'MikoPBX\\Core\\Workers\\Libs\\WorkerModelsEvents\\Actions\\ReloadNginxConfAction';

// Контракт ядра (WorkerModelsEvents::invokeAction): source, action, parameters, model => ''.
assertMsg(
    ['source' => SOURCE_INVOKE_ACTION, 'action' => $nginx, 'parameters' => [], 'model' => ''],
    ModelsEventMessage::invokeAction(SOURCE_INVOKE_ACTION, $nginx),
    'invoke-action message matches core WorkerModelsEvents::invokeAction shape (incl. model key)'
);

$decoded = json_decode(json_encode(ModelsEventMessage::invokeAction(SOURCE_INVOKE_ACTION, $nginx, ['a' => 1])), true);
assertMsg('', $decoded['model'] ?? null, 'model key survives JSON round-trip');
assertMsg(['a' => 1], $decoded['parameters'], 'parameters survive JSON round-trip');

assertMsg(
    ['source' => SOURCE_MODELS_CHANGED, 'model' => 'M\\ModuleAmoCrm', 'recordId' => 1, 'action' => 'afterSave', 'changedFields' => ['webhookPort']],
    ModelsEventMessage::modelChanged(SOURCE_MODELS_CHANGED, 'M\\ModuleAmoCrm', 1, ['webhookPort']),
    'model-changed message keeps its shape'
);

echo "OK ModelsEventMessageTest\n";
