<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleAmoCrm\Lib;

/**
 * Сообщения для очереди событий моделей (WorkerModelsEvents).
 * Без зависимостей от Phalcon/MikoPBX — покрывается тестами в tests/.
 *
 * WorkerModelsEvents передаёт каждое полученное сообщение, в том числе задания
 * SOURCE_INVOKE_ACTION, в modelsEventChangeData всех модулей. Хуки модулей обращаются
 * к $data['model'] без проверки, поэтому сообщение должно соответствовать контракту ядра
 * (WorkerModelsEvents::invokeAction): source, action, parameters, model. Иначе каждый такой
 * хук падает с "Undefined array key \"model\"" (GitHub #54).
 * Сам WorkerModelsEvents::invokeAction() не используем: его нет в старых версиях ядра.
 */
final class ModelsEventMessage
{
    /**
     * Задание на выполнение reload-действия ядра.
     * @param string $source     BeanstalkConnectionModelsProvider::SOURCE_INVOKE_ACTION
     * @param string $action     Класс действия (ReloadNginxConfAction::class и т.п.).
     * @param array  $parameters
     * @return array
     */
    public static function invokeAction(string $source, string $action, array $parameters = []): array
    {
        return [
            'source'     => $source,
            'action'     => $action,
            'parameters' => $parameters,
            'model'      => '',
        ];
    }

    /**
     * Уведомление об изменении записи модели.
     * @param string     $source        BeanstalkConnectionModelsProvider::SOURCE_MODELS_CHANGED
     * @param string     $model         Класс модели.
     * @param int|string $recordId
     * @param array      $changedFields
     * @return array
     */
    public static function modelChanged(string $source, string $model, $recordId, array $changedFields): array
    {
        return [
            'source'        => $source,
            'model'         => $model,
            'recordId'      => $recordId,
            'action'        => 'afterSave',
            'changedFields' => $changedFields,
        ];
    }
}
