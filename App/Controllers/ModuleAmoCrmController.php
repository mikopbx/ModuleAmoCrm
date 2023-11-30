<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 11 2018
 */
namespace Modules\ModuleAmoCrm\App\Controllers;
use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Common\Models\CallQueues;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\PbxExtensionModules;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleAmoCrm\App\Forms\ModuleAmoCrmEntitySettingsModifyForm;
use Modules\ModuleAmoCrm\App\Forms\ModuleAmoCrmForm;
use Modules\ModuleAmoCrm\bin\ConnectorDb;
use Modules\ModuleAmoCrm\bin\WorkerAmoHTTP;
use MikoPBX\Common\Models\Providers;

use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use Modules\ModuleAmoCrm\Models\ModuleAmoEntitySettings;

class ModuleAmoCrmController extends BaseController
{
    private $moduleUniqueID = 'ModuleAmoCrm';
    private $moduleDir;

    /**
     * Basic initial class
     */
    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
        $this->view->logoImagePath = "{$this->url->get()}assets/img/cache/{$this->moduleUniqueID}/logo.jpeg";
        $this->view->submitMode = null;
        parent::initialize();
    }

    public function getTablesDescriptionAction(): void
    {
        $this->view->data = $this->getTablesDescription();
    }

    public function getNewRecordsAction(): void
    {
        $currentPage                 = $this->request->getPost('draw');
        $table                       = $this->request->get('table');
        $this->view->draw            = $currentPage;
        $this->view->recordsTotal    = 0;
        $this->view->recordsFiltered = 0;
        $this->view->data            = [];

        $descriptions = $this->getTablesDescription();
        if(!isset($descriptions[$table])){
            return;
        }
        $className = $this->getClassName($table);
        if(!empty($className)){
            $filter = [];
            if(isset($descriptions[$table]['cols']['priority'])){
                $filter = ['order' => 'priority'];
            }
            $allRecords = $className::find($filter)->toArray();
            $records    = [];
            $emptyRow   = [
                'rowIcon'  =>  $descriptions[$table]['cols']['rowIcon']['icon']??'',
                'DT_RowId' => 'TEMPLATE'
            ];
            foreach ($descriptions[$table]['cols'] as $key => $metadata) {
                if('rowIcon' !== $key){
                    $emptyRow[$key] = '';
                }
            }
            $records[] = $emptyRow;
            foreach ($allRecords as $rowData){
                $tmpData = [];
                $tmpData['DT_RowId'] =  $rowData['id'];
                foreach ($descriptions[$table]['cols'] as $key => $metadata){
                    if('rowIcon' === $key){
                        $tmpData[$key] = $metadata['icon']??'';
                    }elseif('delButton' === $key){
                        $tmpData[$key] = '';
                    }elseif(isset($rowData[$key])){
                        $tmpData[$key] =  $rowData[$key];
                    }
                }
                $records[] = $tmpData;
            }
            $this->view->data      = $records;
        }
    }

    /**
     * Проверка соединения с amoCRM
     * @return void
     */
    public function checkAction():void
    {
        $result      = WorkerAmoHTTP::invokeAmoApi('checkConnection', []);
        $allSettings = ConnectorDb::invoke('getModuleSettings', [true]);
        $lastContactsSyncTime = (int)($allSettings['ModuleAmoCrm']['lastContactsSyncTime']??0);
        $result->data['lastContactsSyncTime'] = $lastContactsSyncTime;
        $this->view->success = $result->success;
        $this->view->data    = $result->data;
        $this->view->messages= $result->messages;
    }

    /**
     * Index page controller
     */
    public function indexAction(): void
    {
        $footerCollection = $this->assets->collection('footerJS');
        $footerCollection->addJs('js/pbx/main/form.js', true);
        $footerCollection->addJs('js/vendor/datatable/dataTables.semanticui.js', true);
        $footerCollection->addJs("js/cache/{$this->moduleUniqueID}/module-amo-crm-index.js", true);
        $footerCollection->addJs('js/vendor/jquery.tablednd.min.js', true);
        $footerCollection->addJs('js/vendor/semantic/modal.min.js', true);

        $headerCollectionCSS = $this->assets->collection('headerCSS');
        $headerCollectionCSS->addCss("css/cache/{$this->moduleUniqueID}/module-amo-crm.css", true);
        $headerCollectionCSS->addCss('css/vendor/datatable/dataTables.semanticui.min.css', true);
        $headerCollectionCSS->addCss('css/vendor/semantic/modal.min.css', true);


        $ModuleSettings = PbxExtensionModules::findFirst(["uniqid='$this->moduleUniqueID'",'columns' => ['disabled']]);
        if($ModuleSettings->disabled === '1'){
            // Если модуль отключен.
            $settings = ModuleAmoCrm::findFirst();
            if ($settings === null) {
                $settings = new ModuleAmoCrm();
                $settings->isPrivateWidget = '0';
            }
            $rules = ModuleAmoEntitySettings::find(["portalId='$settings->portalId'", 'columns' => ['id', 'did', 'type', 'create_lead', 'create_contact', 'create_unsorted', 'create_task']])->toArray();
        }else{
            // Если включен, то пробуем получить настройки средствами демона ConnectorDb.
            $allSettings = ConnectorDb::invoke('getModuleSettings', [false]);
            $settings    = (object)$allSettings['ModuleAmoCrm'];
            $rules       = $allSettings['ModuleAmoEntitySettings'];
        }
        foreach ($rules as $index => $rule){
            $rules[$index]['type_translate'] = 'mod_amo_type_'.$rule['type'];
        }

        // For example we add providers list on the form
        $providers = Providers::find();
        $providersList = [];
        foreach ($providers as $provider){
            $providersList[ $provider->uniqid ] = $provider->getRepresent();
        }
        $options['providers']=$providersList;

        $this->view->form = new ModuleAmoCrmForm($settings, $options);
        $this->view->pick("{$this->moduleDir}/App/Views/index");

        $this->view->workIsAllowed = ((int)$settings->lastContactsSyncTime) > 0;
        // Список выбора очередей.
        $this->view->queues = CallQueues::find(['columns' => ['id', 'name']]);
        $this->view->users  = Extensions::find(["type = 'SIP'", 'columns' => ['number', 'callerid']]);
        $this->view->entitySettings  = $rules;
    }

    /**
     * Save settings action
     */
    public function saveAction() :void
    {
        $data   = $this->request->getPost();
        $ModuleSettings = PbxExtensionModules::findFirst(["uniqid='$this->moduleUniqueID'",'columns' => ['disabled']]);
        if($ModuleSettings->disabled !== '1'){
            $settings = [];
            foreach ($data as $key => $value) {
                if(in_array($key, ['id','offsetCdr','authData'], true)){
                    continue;
                }
                if(in_array($key, ['useInterception', 'isPrivateWidget', 'panelIsEnable'])){
                    $settings[$key] = ($value === 'on') ? '1' : '0';
                } else {
                    $settings[$key]  = $value;
                }
            }
            $this->view->success = ConnectorDb::invoke('saveNewSettings', [$settings]);
            if(!$this->view->success){
                $this->flash->error(implode('<br>', [$this->translation->_('mod_amo_SaveSettingsError')]));
            }
        }else{
            $this->db->begin();
            $record = ModuleAmoCrm::findFirst();
            if ($record === null) {
                $record = new ModuleAmoCrm();
            }
            foreach ($record as $key => $value) {
                if(in_array($key, ['id','offsetCdr','authData'], true)){
                    continue;
                }
                if('useInterception' === $key || 'isPrivateWidget' === $key ){
                    $record->$key = ($data[$key] === 'on') ? '1' : '0';
                } elseif (array_key_exists($key, $data)) {
                    if($record->$key !== trim($data[$key])){
                        $record->$key = trim($data[$key]);
                    }
                } else {
                    $record->$key = '';
                }
            }
            if(empty($record->referenceDate)){
                $record->referenceDate = Util::getNowDate();
            }
            if (FALSE === $record->save()) {
                $errors = $record->getMessages();
                $this->flash->error(implode('<br>', $errors));
                $this->view->success = false;
                $this->db->rollback();
                return;
            }
            $this->db->commit();
            $this->view->success = true;
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));

        }
    }

    /**
     * The modify action for creating or editing
     *
     * @param string|null $id The ID of the user group (optional)
     *
     * @return void
     */
    public function modifyAction(string $id = null): void
    {
        $footerCollection = $this->assets->collection('footerJS');
        $footerCollection->addJs('js/pbx/main/form.js', true);
        $footerCollection->addJs("js/cache/{$this->moduleUniqueID}/module-amo-crm-modify-entity-settings.js", true);

        $result = ConnectorDb::invoke('getEntitySettings', [$id]);
        if(!$result){
            return;
        }
        $rule   = (object)$result['data'];
        $this->view->form      = new ModuleAmoCrmEntitySettingsModifyForm($rule);
        $this->view->represent = $rule->represent;
        $this->view->id        = $rule->id;
        $this->view->pick("{$this->moduleDir}/App/Views/modify");
    }

    /**
     * Save settings entity settings action
     */
    public function saveEntitySettingsAction():void
    {
        $data= $this->request->getPost();
        $response = ConnectorDb::invoke('saveEntitySettingsAction', [$data]);
        if (FALSE === $response) {
            $this->flash->error(implode('<br>', [$this->translation->_('mod_amo_SaveSettingsError')]));
            $this->view->success = false;
        } elseif (FALSE === $response->result) {
            $this->flash->error(implode('<br>', $response->messages));
            $this->view->success = false;
            return;
        }
        $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        $this->view->success = true;
        $this->view->id = $response->data['id'];
    }

    /**
     * Delete record
     */
    public function deleteAction($id): void
    {
        $result = ConnectorDb::invoke('deleteEntitySettings', [$id]);
        if (!$result['result']) {
            $this->flash->error(implode('<br>', $result['messages']).json_encode($result));
            $this->view->success = false;
            return;
        }
        $this->view->test   = '323';
        $this->view->id     = $result['data']['id'];
        $this->view->success = true;
    }

    /**
     * Возвращает метаданные таблицы.
     * @return array
     */
    private function getTablesDescription():array
    {
        $description = [];
        return $description;
    }

    /**
     * Обновление данных в таблице.
     */
    public function saveTableDataAction():void
    {
        $data       = $this->request->getPost();
        $tableName  = $data['pbx-table-id']??'';

        $className = $this->getClassName($tableName);
        if(empty($className)){
            return;
        }
        $rowId      = $data['pbx-row-id']??'';
        if(empty($rowId)){
            $this->view->success = false;
            return;
        }
        $this->db->begin();
        $rowData = $className::findFirst('id="'.$rowId.'"');
        if(!$rowData){
            $rowData = new $className();
        }
        foreach ($rowData as $key => $value) {
            if($key === 'id'){
                continue;
            }
            if (array_key_exists($key, $data)) {
                $rowData->writeAttribute($key, $data[$key]);
            }
        }
        // save action
        if ($rowData->save() === FALSE) {
            $errors = $rowData->getMessages();
            $this->flash->error(implode('<br>', $errors));
            $this->view->success = false;
            $this->db->rollback();
            return;
        }
        $this->view->data = ['pbx-row-id'=>$rowId, 'newId'=>$rowData->id, 'pbx-table-id' => $data['pbx-table-id']];
        $this->view->success = true;
        $this->db->commit();

    }

    /**
     * Получение имени класса по имени таблицы
     * @param $tableName
     * @return string
     */
    private function getClassName($tableName):string
    {
        if(empty($tableName)){
            return '';
        }
        $className = "Modules\ModuleAmoCrm\Models\\$tableName";
        if(!class_exists($className)){
            $className = '';
        }
        return $className;
    }

    /**
     * Changes rules priority
     *
     */
    public function changePriorityAction(): void
    {
        $this->view->disable();
        $result = true;

        if ( ! $this->request->isPost()) {
            return;
        }
        $priorityTable = $this->request->getPost();
        $tableName     = $this->request->get('table');
        $className = $this->getClassName($tableName);
        if(empty($className)){
            echo "table not found -- ы$tableName --";
            return;
        }
        $rules = $className::find();
        foreach ($rules as $rule){
            if (array_key_exists ( $rule->id, $priorityTable)){
                $rule->priority = $priorityTable[$rule->id];
                $result         .= $rule->update();
            }
        }
        echo json_encode($result);
    }
}