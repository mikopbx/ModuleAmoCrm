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
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleAmoCrm\App\Forms\ModuleAmoCrmEntitySettingsModifyForm;
use Modules\ModuleAmoCrm\App\Forms\ModuleAmoCrmForm;
use Modules\ModuleAmoCrm\bin\WorkerAmoHTTP;
use Modules\ModuleAmoCrm\Models\ModuleAmoCrm;
use MikoPBX\Common\Models\Providers;
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
        $result = WorkerAmoHTTP::invokeAmoApi('checkConnection', []);
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

        $settings = ModuleAmoCrm::findFirst();
        if ($settings === null) {
            $settings = new ModuleAmoCrm();
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

        // Список выбора очередей.
        $this->view->queues = CallQueues::find(['columns' => ['id', 'name']]);
        $this->view->users  = Extensions::find(["type = 'SIP'", 'columns' => ['number', 'callerid']]);

        $rules = ModuleAmoEntitySettings::find(["portalId='$settings->portalId'", 'columns' => ['id', 'did', 'type', 'create_lead', 'create_contact', 'create_unsorted', 'create_task']])->toArray();
        foreach ($rules as $index => $rule){
            $rules[$index]['type_translate'] = 'mod_amo_type_'.$rule['type'];
        }
        $this->view->entitySettings  = $rules;
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
        $rule = null;
        if($id){
            $rule = ModuleAmoEntitySettings::findFirst("id='$id'");
        }
        if(!$rule){
            $rule = new ModuleAmoEntitySettings();
            $settings = ModuleAmoCrm::findFirst();
            if ($settings) {
                $rule->portalId = $settings->portalId;
            }
        }
        $this->view->form      = new ModuleAmoCrmEntitySettingsModifyForm($rule);
        $this->view->represent = $rule->getRepresent();
        $this->view->id        = $id;
        $this->view->pick("{$this->moduleDir}/App/Views/modify");
    }

    /**
     * Save settings action
     */
    public function saveAction() :void
    {
        $data       = $this->request->getPost();
        $record = ModuleAmoCrm::findFirst();
        if ($record === null) {
            $record = new ModuleAmoCrm();
        }
        $this->db->begin();
        foreach ($record as $key => $value) {
            if(in_array($key, ['id','offsetCdr','authData'], true)){
                continue;
            }
            if('useInterception' === $key || 'isPrivateWidget' === $key ){
                $record->$key = ($data[$key] === 'on') ? '1' : '0';
            } else if (array_key_exists($key, $data)) {
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
        $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        $this->view->success = true;
        $this->db->commit();
    }

    /**
     * Save settings entity settings action
     */
    public function saveEntitySettingsAction():void
    {
        $data= $this->request->getPost();
        $did = trim($data['did']);

        $filter = [
            "did=:did: AND type=:type: AND id<>:id:",
            'bind'    => [
                'did' => $did,
                'type'=> $data['type']??'',
                'id'  => $data['id']
            ]
        ];
        $record = ModuleAmoEntitySettings::findFirst($filter);
        if($record){
            $errorText = Util::translate('mod_amo_rule_for_type_did_exists', false);
            $this->flash->error($errorText);
            $this->view->success = false;
            return;
        }

        $record = null;
        if(!empty($data['id'])){
            $record = ModuleAmoEntitySettings::findFirstById($data['id']);
        }
        if ($record === null) {
            $record = new ModuleAmoEntitySettings();
        }
        $this->db->begin();
        foreach ($record as $key => $value) {
            if($key === 'id'){
                continue;
            }
            if(in_array($key,['create_contact', 'create_lead', 'create_unsorted', 'create_task'])){
                $record->$key = ($data[$key] === 'on' || $data[$key] === true) ? '1' : '0';
            } elseif (array_key_exists($key, $data)) {
                $record->$key = trim($data[$key]);
            } else {
                $record->$key = '';
            }
        }

        if (FALSE === $record->save()) {
            $errors = $record->getMessages();
            $this->flash->error(implode('<br>', $errors));
            $this->view->success = false;
            $this->db->rollback();
            return;
        }
        $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        $this->view->success = true;
        $this->view->id = $record->id;
        $this->db->commit();
    }

    /**
     * Delete record
     */
    public function deleteAction($id): void
    {
        $record = ModuleAmoEntitySettings::findFirstById($id);
        if ($record !== null && ! $record->delete()) {
            $this->flash->error(implode('<br>', $record->getMessages()));
            $this->view->success = false;
            return;
        }
        $this->view->id     = $id;
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