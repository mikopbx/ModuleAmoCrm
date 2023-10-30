/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 */

const idUrl     	 = 'module-amo-crm';
const idForm    	 = 'module-amo-crm-entity-settings-form';
const className 	 = 'ModuleAmoCrmEntityEdit';

/* global $, globalRootUrl, globalTranslate, Form, Config */
const ModuleAmoCrmEntityEdit = {
	$formObj: 				$('#'+idForm),
	$checkBoxes: 			$('#'+idForm+' .ui.checkbox'),
	$dropDowns: 			$('#'+idForm+' .ui.dropdown'),
	$pipelineDropdown:      $('#lead_pipeline_id'),
	$entityActionDropdown:  $('#entityAction'),
	$typeDropdown:      	$('#type'),
	$task_responsible_type:	$('#task_responsible_type'),
	/**
	/**
	 * Field validation rules
	 * https://semantic-ui.com/behaviors/form.html
	 */
	validateRules: {

	},

	/**
	 * On page load we init some Semantic UI library
	 */
	initialize() {
		// инициализируем чекбоксы и выподающие менюшки
		window[className].$checkBoxes.checkbox({
			onChange: window[className].onChangeSettings
		});
		window[className].$dropDowns.dropdown({
			onChange: window[className].onChangeDropdown
		});

		window[className].onChangeDropdown();
		window[className].onChangeEntityAction();
		window[className].initializeForm();
	},

	onChangeEntityAction(value, text, $selectedItem) {
		if (value === undefined){
			value = window[className].$entityActionDropdown.parent().dropdown('get value');
		}
		$("#responsible").parents('div.field').first().show();
		$("#def_responsible").parents('div.field').first().show();

		if('none' === value){
			$('#create_contact').parents('div.checkbox').first().checkbox('set unchecked');
			$('#create_lead').parents('div.checkbox').first().checkbox('set unchecked');
			$('#create_unsorted').parents('div.checkbox').first().checkbox('set unchecked');

			$("#lead_pipeline_status_id").parents('div.field').first().hide();
			$("#lead_pipeline_id").parents('div.field').first().hide();

			$("#responsible").parents('div.field').first().hide();
			$("#def_responsible").parents('div.field').first().hide();
		}else if('unsorted' === value){
			$('#create_contact').parents('div.checkbox').first().checkbox('set unchecked');
			$('#create_lead').parents('div.checkbox').first().checkbox('set unchecked');
			$('#create_unsorted').parents('div.checkbox').first().checkbox('set checked');

			$("#lead_pipeline_status_id").parents('div.field').first().hide();
			$("#lead_pipeline_id").parents('div.field').first().show();
			$("#def_responsible").parents('div.field').first().hide();
		}else if('contact' === value){
			$('#create_contact').parents('div.checkbox').first().checkbox('set checked');
			$('#create_lead').parents('div.checkbox').first().checkbox('set unchecked');
			$('#create_unsorted').parents('div.checkbox').first().checkbox('set unchecked');

			$("#lead_pipeline_status_id").parents('div.field').first().hide();
			$("#lead_pipeline_id").parents('div.field').first().hide();
		}else if('contact_lead'  === value){
			$('#create_contact').parents('div.checkbox').first().checkbox('set checked');
			$('#create_lead').parents('div.checkbox').first().checkbox('set checked');
			$('#create_unsorted').parents('div.checkbox').first().checkbox('set unchecked');

			$("#lead_pipeline_status_id").parents('div.field').first().show();
			$("#lead_pipeline_id").parents('div.field').first().show();
		}else if('lead' === value){
			$('#create_contact').parents('div.checkbox').first().checkbox('set unchecked');
			$('#create_lead').parents('div.checkbox').first().checkbox('set checked');
			$('#create_unsorted').parents('div.checkbox').first().checkbox('set unchecked');

			$("#lead_pipeline_status_id").parents('div.field').first().show();
			$("#lead_pipeline_id").parents('div.field').first().show();
		}

		let type = window[className].$typeDropdown.parent().dropdown('get value');
		if(type === 'MISSING_UNKNOWN' || type === 'MISSING_KNOWN'){
			$("#responsible").parents('div.field').first().hide();
		}
		window[className].setVisibilityElements();
	},
	onChangePipelineStatusId(value, text, $selectedItem) {

	},
	onChangeDropdown(value, text, $selectedItem) {
		let id = '';
		if($selectedItem !== undefined){
			id = $selectedItem.parent().parent().find('select').attr('id');
		}
		if( '' === id || id === window[className].$pipelineDropdown.attr('id')){
			let pipeLineId = window[className].$pipelineDropdown.parent().dropdown('get value');
			let statuses = JSON.parse($('#pipeLineStatuses').val())[pipeLineId];
			$('#lead_pipeline_status_id').find('option').remove();
			$.each(statuses, function (i, item) {
				let options = {
					value: item.value,
					text : item.name
				};
				if(item.selected){
					options.selected = 'selected';
				}
				$('#lead_pipeline_status_id').append($('<option>', options));
			});
			$('#lead_pipeline_status_id').parent().dropdown({
				values: statuses,
				onChange: window[className].onChangePipelineStatusId
			});
		}

		if( '' === id || id === window[className].$typeDropdown.attr('id')){
			let create_contact 	= $('#create_contact').parents('div.checkbox').first().checkbox('is checked');
			let create_lead 	= $('#create_lead').parents('div.checkbox').first().checkbox('is checked');
			let create_unsorted	= $('#create_unsorted').parents('div.checkbox').first().checkbox('is checked');

			let type = window[className].$typeDropdown.parent().dropdown('get value');
			let entityActionVariants = [];
			if(type === 'INCOMING_UNKNOWN' || type === 'MISSING_UNKNOWN'){
				entityActionVariants = [
					{name: globalTranslate['mod_amo_action_none'], 		   	value: 'none', selected: (create_contact===false && create_lead===false && create_unsorted===false)},
					{name: globalTranslate['mod_amo_action_unsorted'],	   	value: 'unsorted', selected: (create_unsorted===true)},
					{name: globalTranslate['mod_amo_action_contact'], 	   	value: 'contact', selected: (create_contact===true && create_lead===false && create_unsorted===false)},
					{name: globalTranslate['mod_amo_action_contact_lead'], 	value: 'contact_lead', selected: (create_contact===true && create_lead===true && create_unsorted===false)},
				];
			}else if(type === 'INCOMING_KNOWN' || type === 'MISSING_KNOWN' || type === 'OUTGOING_KNOWN_FAIL' || type === 'OUTGOING_KNOWN'){
				entityActionVariants = [
					{name: globalTranslate['mod_amo_action_none'], 			value: 'none', selected: (create_contact===false && create_lead===false && create_unsorted===false)},
					{name: globalTranslate['mod_amo_action_lead'], 			value: 'lead', selected: (create_contact===false && create_lead===true && create_unsorted===false)},
				];
			}else if(type === 'OUTGOING_UNKNOWN'){
				entityActionVariants = [
					{name: globalTranslate['mod_amo_action_none'], 			value: 'none', selected: (create_contact===false && create_lead===false && create_unsorted===false)},
					{name: globalTranslate['mod_amo_action_contact'], 		value: 'contact', selected: (create_contact===true && create_lead===false && create_unsorted===false)},
					{name: globalTranslate['mod_amo_action_contact_lead'], 	value: 'contact_lead', selected: (create_contact===true && create_lead===true && create_unsorted===false)},
				];
			}
			window[className].$entityActionDropdown.find('option').remove();
			$.each(entityActionVariants, function (i, item) {
				let options = {
					value: item.value,
					text : item.name
				};
				if(item.selected){
					options.selected = 'selected';
				}
				window[className].$entityActionDropdown.append($('<option>', options));
			});
			window[className].$entityActionDropdown.parent().dropdown({
				values: entityActionVariants,
				onChange: window[className].onChangeEntityAction
			});

			let task_responsible_type = window[className].$task_responsible_type.parent().dropdown('get value');
			let taskRespVariants = [];
			if(type === 'OUTGOING_KNOWN_FAIL' || type === 'OUTGOING_KNOWN') {
				taskRespVariants = [
					{name: globalTranslate['mod_amo_task_responsible_type_'], value: '', selected: task_responsible_type === ''},
					{name: globalTranslate['mod_amo_task_responsible_type_firstAnswerUser'], value: 'firstAnswerUser', selected: task_responsible_type === 'firstAnswerUser'},
					{name: globalTranslate['mod_amo_task_responsible_type_lastAnswerUser'], value: 'lastAnswerUser', selected: task_responsible_type === 'lastAnswerUser'},
					{name: globalTranslate['mod_amo_task_responsible_type_clientResponsible'], value: 'clientResponsible', selected: task_responsible_type === 'clientResponsible'},
					{name: globalTranslate['mod_amo_task_responsible_type_def_responsible'], 	value: 'def_responsible', selected: task_responsible_type === 'def_responsible'},
				];

			}else if (type === 'OUTGOING_UNKNOWN'){
				taskRespVariants = [
					{name: globalTranslate['mod_amo_task_responsible_type_'], value: '', selected: task_responsible_type === ''},
					{name: globalTranslate['mod_amo_task_responsible_type_def_responsible'], 	value: 'def_responsible', selected: task_responsible_type === 'def_responsible'},
					{name: globalTranslate['mod_amo_task_responsible_type_firstAnswerUser'], value: 'firstAnswerUser', selected: task_responsible_type === 'firstAnswerUser'},
					{name: globalTranslate['mod_amo_task_responsible_type_lastAnswerUser'], value: 'lastAnswerUser', selected: task_responsible_type === 'lastAnswerUser'},
				];
			}else if (type === 'MISSING_KNOWN'){
				taskRespVariants = [
					{name: globalTranslate['mod_amo_task_responsible_type_'], value: '', selected: task_responsible_type === ''},
					{name: globalTranslate['mod_amo_task_responsible_type_def_responsible'], 	value: 'def_responsible', selected: task_responsible_type === 'def_responsible'},
					{name: globalTranslate['mod_amo_task_responsible_type_clientResponsible'], value: 'clientResponsible', selected: task_responsible_type === 'clientResponsible'},
					{name: globalTranslate['mod_amo_task_responsible_type_firstMissedUser'], value: 'firstMissedUser', selected: task_responsible_type === 'firstAnswerUser'},
					{name: globalTranslate['mod_amo_task_responsible_type_lastMissedUser'], value: 'lastMissedUser', selected: task_responsible_type === 'lastAnswerUser'},
				];
			}else if (type === 'INCOMING_UNKNOWN'){
				taskRespVariants = [
					{name: globalTranslate['mod_amo_task_responsible_type_'], value: '', selected: task_responsible_type === ''},
					{name: globalTranslate['mod_amo_task_responsible_type_def_responsible'], 	value: 'def_responsible', selected: task_responsible_type === 'def_responsible'},
					{name: globalTranslate['mod_amo_task_responsible_type_firstAnswerUser'], value: 'firstAnswerUser', selected: task_responsible_type === 'firstAnswerUser'},
					{name: globalTranslate['mod_amo_task_responsible_type_lastAnswerUser'], value: 'lastAnswerUser', selected: task_responsible_type === 'lastAnswerUser'},
				];
			}else if (type === 'MISSING_UNKNOWN'){
				taskRespVariants = [
					{name: globalTranslate['mod_amo_task_responsible_type_'], value: '', selected: task_responsible_type === ''},
					{name: globalTranslate['mod_amo_task_responsible_type_def_responsible'], 	value: 'def_responsible', selected: task_responsible_type === 'def_responsible'},
					{name: globalTranslate['mod_amo_task_responsible_type_firstMissedUser'], value: 'firstMissedUser', selected: task_responsible_type === 'firstAnswerUser'},
					{name: globalTranslate['mod_amo_task_responsible_type_lastMissedUser'], value: 'lastMissedUser', selected: task_responsible_type === 'lastAnswerUser'},
				];
			}else if (type === 'INCOMING_KNOWN'){
				taskRespVariants = [
					{name: globalTranslate['mod_amo_task_responsible_type_'], value: '', selected: task_responsible_type === ''},
					{name: globalTranslate['mod_amo_task_responsible_type_firstAnswerUser'], value: 'firstAnswerUser', selected: task_responsible_type === 'firstAnswerUser'},
					{name: globalTranslate['mod_amo_task_responsible_type_lastAnswerUser'], value: 'lastAnswerUser', selected: task_responsible_type === 'lastAnswerUser'},
					{name: globalTranslate['mod_amo_task_responsible_type_clientResponsible'], value: 'clientResponsible', selected: task_responsible_type === 'clientResponsible'},
					{name: globalTranslate['mod_amo_task_responsible_type_def_responsible'], 	value: 'def_responsible', selected: task_responsible_type === 'def_responsible'},
				];
			}
			window[className].$task_responsible_type.find('option').remove();
			$.each(taskRespVariants, function (i, item) {
				let options = {
					value: item.value,
					text : item.name
				};
				if(item.selected){
					options.selected = 'selected';
				}
				window[className].$task_responsible_type.append($('<option>', options));
			});
			window[className].$task_responsible_type.parent().dropdown({
				values: taskRespVariants,
				onChange: window[className].onChangeEntityAction
			});
		}
		window[className].setVisibilityElements();
	},

	/**
	 *
	 */
	onChangeSettings(){
		window[className].setVisibilityElements();
	},

	/**
	 * We can modify some data before form send
	 * @param settings
	 * @returns {*}
	 */
	cbBeforeSendForm(settings) {
		const result = settings;
		result.data = window[className].$formObj.form('get values');
		delete result.data.pipeLineStatuses;
		return result;
	},
	/**
	 * Some actions after forms send
	 */
	cbAfterSendForm(response) {
		console.log(response);
		if(response.success === true && $('#id').val() === ''){
			$('#id').val(response.id);
			let title = $('head title').html();
			window.history.pushState({page: title}, title, `${window.location.href}${response.id}`);
		}
	},

	/**
	 * Initialize form parameters
	 */
	initializeForm() {
		Form.$formObj = window[className].$formObj;
		Form.url = `${window.location.origin}${globalRootUrl}${idUrl}/saveEntitySettings`;
		Form.validateRules = window[className].validateRules;
		Form.cbBeforeSendForm = window[className].cbBeforeSendForm;
		Form.cbAfterSendForm = window[className].cbAfterSendForm;
		Form.initialize();
	},

	setVisibilityElements(){
		let create_contact  = $('#create_contact').parents('div.checkbox').checkbox('is checked');
		let create_unsorted	= $('#create_unsorted').parents('div.checkbox').first().checkbox('is checked');
		if(create_contact || create_unsorted){
			$('#template_contact_name').parents('div.field').first().show();
		}else{
			$('#template_contact_name').parents('div.field').first().hide();
		}
		let create_lead 	= $('#create_lead').parents('div.checkbox').checkbox('is checked');
		if(create_lead || create_unsorted){
			$('#template_lead_name').parents('div.field').first().show();
		}else{
			$('#template_lead_name').parents('div.field').first().hide();
		}
		let create_task		= $('#create_task').parents('div.checkbox').checkbox('is checked');
		if(create_task){
			$('#template_task_text').parents('div.field').first().show();
			$('#deadline_task').parents('div.field').first().show();
			window[className].$task_responsible_type.parents('div.field').first().show();
		}else{
			$('#template_task_text').parents('div.field').first().hide();
			$('#deadline_task').parents('div.field').first().hide();
			window[className].$task_responsible_type.parents('div.field').first().hide();
		}
		let type = window[className].$typeDropdown.parent().dropdown('get value');

		if(type === 'OUTGOING_KNOWN_FAIL' || type === 'OUTGOING_KNOWN' || type === 'OUTGOING_UNKNOWN') {
			$('#did').parents('div.field').first().hide();
		}else{
			$('#did').parents('div.field').first().show();
		}
	}
};

$(document).ready(() => {
	window[className].initialize();
});

