/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 */

const idUrl     	 = 'module-amo-crm';
const idForm    	 = 'module-amo-crm-form';
const className 	 = 'ModuleAmoCrm';
const inputClassName = 'mikopbx-module-input';

/* global $, globalRootUrl, globalTranslate, Form, Config */
const ModuleAmoCrm = {
	$formObj: 				$('#'+idForm),
	$checkBoxes: 			$('#'+idForm+' .ui.checkbox'),
	$dropDowns: 			$('#'+idForm+' .ui.dropdown'),
	saveTableAJAXUrl: 		`${window.location.origin}${globalRootUrl}${idUrl}/saveTableData`,
	deleteRecordAJAXUrl: 	`${window.location.origin}${globalRootUrl}${idUrl}/delete`,
	$disabilityFields: 		$('#'+idForm+'  .disability'),
	$statusToggle: 			$('#module-status-toggle'),
	$moduleStatus: 			$('#status'),
	authWindow: 			undefined,

	/**
	/**
	 * Field validation rules
	 * https://semantic-ui.com/behaviors/form.html
	 */
	validateRules: {
		textField: {
			identifier: 'text_field',
			rules: [
				{
					type: 'empty',
					prompt: globalTranslate.mod_tplValidateValueIsEmpty,
				},
			],
		},
		areaField: {
			identifier: 'text_area_field',
			rules: [
				{
					type: 'empty',
					prompt: globalTranslate.mod_tplValidateValueIsEmpty,
				},
			],
		},
		passwordField: {
			identifier: 'password_field',
			rules: [
				{
					type: 'empty',
					prompt: globalTranslate.mod_tplValidateValueIsEmpty,
				},
			],
		},
	},
	generatePassword() {
		let length = 50,
			charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789",
			retVal = "";
		for (let i = 0, n = charset.length; i < length; ++i) {
			retVal += charset.charAt(Math.floor(Math.random() * n));
		}
		return retVal;
	},
	/**
	 * On page load we init some Semantic UI library
	 */
	initialize() {
		// инициализируем чекбоксы и выподающие менюшки
		window[className].$checkBoxes.checkbox(
			{
				onChange: window[className].onChangeSettings
			}
		);
		window[className].onChangeSettings()
		window[className].$dropDowns.dropdown();
		window[className].checkStatusToggle();
		window.addEventListener('ModuleStatusChanged', window[className].checkStatusToggle);
		window[className].initializeForm();
		$('.menu .item').tab();

		window[className].checkStatus();
		setInterval(window[className].checkStatus, 5000);

		$(window).bind('message',  window[className].updateAuthInfo);
		$("#createPassword").on('click', function (e) {
			$("#tokenForAmo").val(window[className].generatePassword());
			$('#submitbutton').removeClass('disabled');
		});
		$("#login-button").on('click', function (e) {
			let client_id 	 = $('#clientId').val();
			if($('#isPrivateWidget').parent().checkbox('is checked')){
				client_id = $('#privateClientId').val();
			}
			let state 		 = encodeURIComponent(client_id);
			let redirect_uri = encodeURIComponent($('#redirectUri').val());
			let url = `https://www.amocrm.ru/oauth?client_id=${client_id}&state=${state}&redirect_uri=${redirect_uri}&mode=post_message&scope=&approval_prompt=auto`;
			window[className].popup = window.open(url, 'Auth', 'scrollbars, status, resizable, width=750, height=580');
		});

		$('body').on('click', 'a.delete', (e) => {
			e.preventDefault();
			const id = $(e.target).closest('tr').attr('id');
			ModuleAmoCrm.deleteRule(id);
		});

		$('#entitySettingsTable').dataTable( {
			lengthChange: false,
			processing: true,
			paging: false,
			deferRender: true,
			autoWidth: false,
			columns: [
				{orderable: true, searchable: true},
				{orderable: true, searchable: true},
				{orderable: false, searchable: false},
				{orderable: false, searchable: false},
				{orderable: false, searchable: false},
				{orderable: false, searchable: false},
				{orderable: false, searchable: false},
			],
			language: SemanticLocalization.dataTableLocalisation,
			order: [1, 'asc'],
		});
	},
	onChangeSettings() {
		if($('#isPrivateWidget').parent().checkbox('is checked')) {
			$('#private-fields').show();
		}else{
			$('#private-fields').hide();
		}
	},
	/**
	 * Deletes an extension with the given ID.
	 * @param {string} id - The ID of the rule to delete.
	 */
	deleteRule(id) {
		$('.message.ajax').remove();
		$.api({
			url: `${globalRootUrl}module-amo-crm/delete/${id}`,
			on: 'now',
			successTest(response) {
				// test whether a JSON response is valid
				return response !== undefined
					&& Object.keys(response).length > 0;
			},
			onSuccess(response) {
				if (response.success === true) {
					$('#entitySettingsTable').find(`tr[id=${id}]`).remove();
				} else {
					UserMessage.showError(response.message.error, globalTranslate.ex_ImpossibleToDeleteExtension);
				}
			},
		});
	},
	checkStatus(){
		$.get( `${window.location.origin}${globalRootUrl}${idUrl}/check`, function( result ) {
			let elStatusAuth = $('#login-button');
			elStatusAuth.removeClass('red green');
			if(result.success === true){
				elStatusAuth.addClass('green')
				elStatusAuth.text(globalTranslate.module_amo_crm_connect_ok);
			}else{
				elStatusAuth.addClass('red')
				elStatusAuth.text(globalTranslate.module_amo_crm_connect_fail);
			}
		});
	},

	updateAuthInfo(e) {
		let params = {
			'code': e.originalEvent.data.code,
			'referer': $('#baseDomain').val(),
			'save-only': true
		};
		$("#warning-message").hide();
		let elStatusAuth = $('#login-button');
		elStatusAuth.removeClass('red green');
		elStatusAuth.text(globalTranslate.module_amo_crm_connect_refresh);

		$.post(`${window.location.origin}/pbxcore/api/modules/${className}/listener`, params, function( data ) {
			if(data.result === false){
				let errorText = data.messages['error-data'].hint || '' + " ("+ data.messages['error-data'].detail || '' + ").";
				$("#warning-message div.header").text(globalTranslate.mod_amo_Error)
				$("#warning-message div.body").text(errorText)
				$("#warning-message").show();
			}
		});
		window[className].popup.close();
	},

	/**
	 * Подготавливает список выбора
	 * @param selected
	 * @returns {[]}
	 */
	makeDropdownList(selectType, selected) {
		const values = [
			{
				name: ' --- ',
				value: '',
				selected: ('' === selected),
			}
		];
		$('#'+selectType+' option').each((index, obj) => {
			values.push({
				name: obj.text,
				value: obj.value,
				selected: (selected === obj.value),
			});
		});
		return values;
	},
	/**
	 * Обработка изменения группы в списке
	 */
	changeGroupInList(value, text, choice) {
		let tdInput = $(choice).closest('td').find('input');
		tdInput.attr('data-value', 	value);
		tdInput.attr('value', 		value);
		let currentRowId = $(choice).closest('tr').attr('id');
		let tableName    = $(choice).closest('table').attr('id').replace('-table', '');
		if (currentRowId !== undefined && tableName !== undefined) {
			window[className].sendChangesToServer(tableName, currentRowId);
		}
	},

	/**
	 * Add new Table.
	 */
	initTable(tableName, options) {
		let columns = [];
		let columnsArray4Sort = []
		for (let colName in options['cols']) {
			columns.push( {data: colName});
			columnsArray4Sort.push(colName);
		}
		$('#' + tableName).DataTable( {
			ajax: {
				url: idUrl + options.ajaxUrl + '?table=' +tableName.replace('-table', ''),
				dataSrc: 'data'
			},
			columns: columns,
			paging: false,
			sDom: 'rtip',
			deferRender: true,
			pageLength: 17,
			infoCallback( settings, start, end, max, total, pre ) {
				return '';
			},
			language: SemanticLocalization.dataTableLocalisation,
			ordering: false,
			/**
			 * Builder row presentation
			 * @param row
			 * @param data
			 */
			createdRow(row, data) {
				let cols    = $('td', row);
				let headers = $('#'+ tableName + ' thead tr th');
				for (let key in data) {
					let index = columnsArray4Sort.indexOf(key);
					if(key === 'rowIcon'){
						cols.eq(index).html('<i class="ui ' + data[key] + ' circle icon"></i>');
					}else if(key === 'delButton'){
						let templateDeleteButton = '<div class="ui small basic icon buttons action-buttons">' +
							'<a href="' + window[className].deleteRecordAJAXUrl + '/' +
							data.id + '" data-value = "' + data.DT_RowId + '"' +
							' class="ui button delete two-steps-delete popuped" data-content="' + globalTranslate.bt_ToolTipDelete + '">' +
							'<i class="icon trash red"></i></a></div>';
						cols.eq(index).html(templateDeleteButton);
					}else if(key === 'priority'){
						cols.eq(index).addClass('dragHandle')
						cols.eq(index).html('<i class="ui sort circle icon"></i>');
						// Приоритет устанавливаем для строки.
						$(row).attr('m-priority', data[key]);
					}else{
						let template = '<div class="ui transparent fluid input inline-edit">' +
							'<input colName="'+key+'" class="'+inputClassName+'" type="text" data-value="'+data[key] + '" value="' + data[key] + '"></div>';
						$('td', row).eq(index).html(template);
					}
					if(options['cols'][key] === undefined){
						continue;
					}
					let additionalClass = options['cols'][key]['class'];
					if(additionalClass !== undefined && additionalClass !== ''){
						headers.eq(index).addClass(additionalClass);
					}
					let header = options['cols'][key]['header'];
					if(header !== undefined && header !== ''){
						headers.eq(index).html(header);
					}

					let selectMetaData = options['cols'][key]['select'];
					if(selectMetaData !== undefined){
						let newTemplate = $('#template-select').html().replace('PARAM', data[key]);
						let template = '<input class="'+inputClassName+'" colName="'+key+'" selectType="'+selectMetaData+'" style="display: none;" type="text" data-value="'+data[key] + '" value="' + data[key] + '"></div>';
						cols.eq(index).html(newTemplate + template);
					}
				}
			},
			/**
			 * Draw event - fired once the table has completed a draw.
			 */
			drawCallback(settings) {
				window[className].drowSelectGroup(settings.sTableId);
			},
		} );

		let body = $('body');
		// Клик по полю. Вход для редактирования значения.
		body.on('focusin', '.'+inputClassName, function (e) {
			if($(e.target).parents('table').attr('id') === 'ModuleAmoPipeLines-table' && $(e.target).attr('colname') === 'name'){
				return;
			}
			$(e.target).transition('glow');
			$(e.target).closest('div').removeClass('transparent').addClass('changed-field');
			$(e.target).attr('readonly', false);
		})
		// Отправка формы на сервер по Enter или Tab
		$(document).on('keydown', function (e) {
			let keyCode = e.keyCode || e.which;
			if (keyCode === 13 || keyCode === 9 && $(':focus').hasClass('mikopbx-module-input')) {
				window[className].endEditInput();
			}
		});

		body.on('click', 'a.delete', function (e) {
			e.preventDefault();
			let currentRowId = $(e.target).closest('tr').attr('id');
			let tableName    = $(e.target).closest('table').attr('id').replace('-table', '');
			window[className].deleteRow(tableName, currentRowId);
		}); // Добавление новой строки

		// Отправка формы на сервер по уходу с поля ввода
		body.on('focusout', '.'+inputClassName, window[className].endEditInput);

		// Кнопка "Добавить новую запись"
		$('[id-table = "'+tableName+'"]').on('click', window[className].addNewRow);
	},

	/**
	 * Перемещение строки, изменение приоритета.
	 */
	cbOnDrop(table, row) {
		let priorityWasChanged = false;
		const priorityData = {};
		$(table).find('tr').each((index, obj) => {
			const ruleId = $(obj).attr('id');
			const oldPriority = parseInt($(obj).attr('m-priority'), 10);
			const newPriority = obj.rowIndex;
			if (!isNaN( ruleId ) && oldPriority !== newPriority) {
				priorityWasChanged = true;
				priorityData[ruleId] = newPriority;
			}
		});
		if (priorityWasChanged) {
			$.api({
				on: 'now',
				url:`${window.location.origin}${globalRootUrl}${idUrl}/changePriority?table=`+$(table).attr('id').replace('-table', ''),
				method: 'POST',
				data: priorityData,
			});
		}
	},

	/**
	 * Окончание редактирования поля ввода.
	 * Не относится к select.
	 * @param e
	 */
	endEditInput(e){
		let $el = $('.changed-field').closest('tr');
		$el.each(function (index, obj) {
			let currentRowId = $(obj).attr('id');
			let tableName    = $(obj).closest('table').attr('id').replace('-table', '');
			if (currentRowId !== undefined && tableName !== undefined) {
				window[className].sendChangesToServer(tableName, currentRowId);
			}
		});
	},

	/**
	 * Добавление новой строки в таблицу.
	 * @param e
	 */
	addNewRow(e){
		let idTable = $(e.target).attr('id-table');
		let table   = $('#'+idTable);
		e.preventDefault();
		table.find('.dataTables_empty').remove();
		// Отправим на запись все что не записано еще
		let $el = table.find('.changed-field').closest('tr');
		$el.each(function (index, obj) {
			let currentRowId = $(obj).attr('id');
			if (currentRowId !== undefined) {
				window[className].sendChangesToServer(currentRowId);
			}
		});
		let id = "new"+Math.floor(Math.random() * Math.floor(500));
		let rowTemplate = '<tr id="'+id+'" role="row" class="even">'+table.find('tr#TEMPLATE').html().replace('TEMPLATE', id)+'</tr>';
		table.find('tbody > tr:first').before(rowTemplate);
		window[className].drowSelectGroup(idTable);
	},
	/**
	 * Обновление select элементов.
	 * @param tableId
	 */
	drowSelectGroup(tableId) {
		$('#' + tableId).find('tr#TEMPLATE').hide();
		let selestGroup = $('.select-group');
		selestGroup.each((index, obj) => {
			let selectType = $(obj).closest('td').find('input').attr('selectType');
			$(obj).dropdown({
				values: window[className].makeDropdownList(selectType, $(obj).attr('data-value')),
			});
		});
		selestGroup.dropdown({
			onChange: window[className].changeGroupInList,
		});

		$('#' + tableId).tableDnD({
			onDrop: window[className].cbOnDrop,
			onDragClass: 'hoveringRow',
			dragHandle: '.dragHandle',
		});
	},
	/**
	 * Удаление строки
	 * @param tableName
	 * @param id - record id
	 */
	deleteRow(tableName, id) {
		let table = $('#'+ tableName+'-table');
		if (id.substr(0,3) === 'new') {
			table.find('tr#'+id).remove();
			return;
		}
		$.api({
			url: window[className].deleteRecordAJAXUrl+'?id='+id+'&table='+tableName,
			on: 'now',
			onSuccess(response) {
				if (response.success) {
					table.find('tr#'+id).remove();
					if (table.find('tbody > tr').length === 0) {
						table.find('tbody').append('<tr class="odd"></tr>');
					}
				}
			}
		});
	},

	/**
	 * Отправка данных на сервер при измении
	 */
	sendChangesToServer(tableName, recordId) {
		let data = { 'pbx-table-id': tableName, 'pbx-row-id':  recordId};
		let notEmpty = false;
		$("tr#"+recordId + ' .' + inputClassName).each(function (index, obj) {
			let colName = $(obj).attr('colName');
			if(colName !== undefined){
				data[$(obj).attr('colName')] = $(obj).val();
				if($(obj).val() !== ''){
					notEmpty = true;
				}
			}
		});

		if(notEmpty === false){
			return;
		}
		$("tr#"+recordId+" .user.circle").removeClass('user circle').addClass('spinner loading');
		$.api({
			url: window[className].saveTableAJAXUrl,
			on: 'now',
			method: 'POST',
			data: data,
			successTest(response) {
				return response !== undefined && Object.keys(response).length > 0 && response.success === true;
			},
			onSuccess(response) {
				if (response.data !== undefined) {
					let rowId = response.data['pbx-row-id'];
					let table = $('#'+response.data['pbx-table-id']+'-table');
					table.find("tr#" + rowId + " input").attr('readonly', true);
					table.find("tr#" + rowId + " div").removeClass('changed-field loading').addClass('transparent');
					table.find("tr#" + rowId + " .spinner.loading").addClass('user circle').removeClass('spinner loading');

					if (rowId !== response.data['newId']){
						$(`tr#${rowId}`).attr('id', response.data['newId']);
					}
				}
			},
			onFailure(response) {
				if (response.message !== undefined) {
					UserMessage.showMultiString(response.message);
				}
				$("tr#" + recordId + " .spinner.loading").addClass('user circle').removeClass('spinner loading');
			},
			onError(errorMessage, element, xhr) {
				if (xhr.status === 403) {
					window.location = globalRootUrl + "session/index";
				}
			}
		});
	},
	/**
	 * Change some form elements classes depends of module status
	 */
	checkStatusToggle() {
		if (window[className].$statusToggle.checkbox('is checked')) {
			window[className].$disabilityFields.removeClass('disabled');
			window[className].$moduleStatus.show();
		} else {
			window[className].$disabilityFields.addClass('disabled');
			window[className].$moduleStatus.hide();
		}
	},
	/**
	 * Send command to restart module workers after data changes,
	 * Also we can do it on TemplateConf->modelsEventChangeData method
	 */
	applyConfigurationChanges() {
		window[className].changeStatus('Updating');
		$.api({
			url: `${window.location.origin}/pbxcore/api/modules/`+className+`/reload`,
			on: 'now',
			successTest(response) {
				// test whether a JSON response is valid
				return Object.keys(response).length > 0 && response.result === true;
			},
			onSuccess() {
				window[className].changeStatus('Connected');
			},
			onFailure() {
				window[className].changeStatus('Disconnected');
			},
		});
	},
	/**
	 * We can modify some data before form send
	 * @param settings
	 * @returns {*}
	 */
	cbBeforeSendForm(settings) {
		const result = settings;
		result.data = window[className].$formObj.form('get values');
		return result;
	},
	/**
	 * Some actions after forms send
	 */
	cbAfterSendForm() {
		window[className].applyConfigurationChanges();
	},
	/**
	 * Initialize form parameters
	 */
	initializeForm() {
		Form.$formObj = window[className].$formObj;
		Form.url = `${window.location.origin}${globalRootUrl}${idUrl}/save`;
		Form.validateRules = window[className].validateRules;
		Form.cbBeforeSendForm = window[className].cbBeforeSendForm;
		Form.cbAfterSendForm = window[className].cbAfterSendForm;
		Form.initialize();
	},
	/**
	 * Update the module state on form label
	 * @param status
	 */
	changeStatus(status) {
		switch (status) {
			case 'Connected':
				window[className].$moduleStatus
					.removeClass('grey')
					.removeClass('red')
					.addClass('green');
				window[className].$moduleStatus.html(globalTranslate.module_amo_crmConnected);
				break;
			case 'Disconnected':
				window[className].$moduleStatus
					.removeClass('green')
					.removeClass('red')
					.addClass('grey');
				window[className].$moduleStatus.html(globalTranslate.module_amo_crmDisconnected);
				break;
			case 'Updating':
				window[className].$moduleStatus
					.removeClass('green')
					.removeClass('red')
					.addClass('grey');
				window[className].$moduleStatus.html(`<i class="spinner loading icon"></i>${globalTranslate.module_amo_crmUpdateStatus}`);
				break;
			default:
				window[className].$moduleStatus
					.removeClass('green')
					.removeClass('red')
					.addClass('grey');
				window[className].$moduleStatus.html(globalTranslate.module_amo_crmDisconnected);
				break;
		}
	},
};

$(document).ready(() => {
	window[className].initialize();
});

