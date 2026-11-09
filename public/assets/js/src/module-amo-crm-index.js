/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 */

const idUrl     	 = 'module-amo-crm/module-amo-crm';
const idForm    	 = 'module-amo-crm-form';
const className 	 = 'ModuleAmoCrm';
const inputClassName = 'mikopbx-module-input';

/* global $, globalRootUrl, globalTranslate, globalWebAdminLanguage, Form, Config */
const ModuleAmoCrm = {
	$formObj: 				$('#'+idForm),
	$checkBoxes: 			$('#'+idForm+' .ui.checkbox'),
	$dropDowns: 			$('#'+idForm+' .ui.dropdown'),
	saveTableAJAXUrl: 		`${window.location.origin}${globalRootUrl}${idUrl}/saveTableData`,
	deleteRecordAJAXUrl: 	`${window.location.origin}${globalRootUrl}${idUrl}/delete`,
	$disabilityFields: 		$('div.disability'),
	$statusToggle: 			$('#module-status-toggle'),
	$moduleStatus: 			$('#status'),
	authWindow: 			undefined,
	usersLoaded: 			false,
	// Значение пункта «не сопоставлен»; при сохранении превращается в пустой номер.
	usersNotMappedValue: 	'__none__',

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
		$('.info.circle').not('.tab-hint').popup();
		// Над вкладками строка модуля — подсказки к вкладкам показываем снизу.
		$('.tab-hint').popup({ position: 'bottom left', variation: 'wide' });
		$('#copy-pbx-address').on('click', function () {
			const host = $('#externalHostname').val() || $('#externalHostname').attr('placeholder') || '';
			const port = $('#webhookPort').val();
			const token = $('#tokenForAmo').val();
			let address = host;
			if (port && token) {
				address = host + ':' + port + '/' + token;
			}
			const tmp = $('<input>').val(address).appendTo('body').select();
			document.execCommand('copy');
			tmp.remove();
		});
		window[className].initIntegrationBadges();
		window[className].checkStatusToggle();
		window.addEventListener('ModuleStatusChanged', window[className].checkStatusToggle);
		window[className].initializeForm();
		window[className].initTabs();

		window[className].checkStatus();
		setInterval(window[className].checkStatus, 5000);

		window[className].loadUsers();
		$('#refresh-users-button').popup({ position: 'bottom left' }).on('click', (e) => {
			// Клик по значку в заголовке вкладки: обновляем список и открываем вкладку.
			e.preventDefault();
			window[className].refreshUsers();
		});

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
		// Кнопка "Добавить правило" — в свободную левую колонку над таблицей (рядом с поиском).
		const $rulesLeftColumn = $('#entitySettingsTable_wrapper .ui.grid > .row:first > .eight.wide.column:not(.right)');
		if ($rulesLeftColumn.length > 0) {
			$('#add-new-button').appendTo($rulesLeftColumn.first());
		}
	},
	/**
	 * Метки состояния интеграции (webhook, виджет, пользователи) в строке "Модуль включен".
	 * Строку рендерит ядро, поэтому метки добавляются отсюда.
	 */
	/**
	 * Инициализация вкладок с сохранением активной вкладки в URL-хеше (#calls),
	 * чтобы после обновления страницы открывалась та же вкладка.
	 */
	initTabs() {
		const $items = $('.menu .item[data-tab]');
		$items.tab({
			onVisible(tabPath) {
				if (window.history && window.history.replaceState) {
					window.history.replaceState(null, '', `#${tabPath}`);
				}
			},
		});
		const hash = window.location.hash.replace(/[^a-zA-Z0-9_-]/g, '');
		if (hash !== '' && $items.filter(`[data-tab="${hash}"]`).length > 0) {
			$items.tab('change tab', hash);
		}
	},
	initIntegrationBadges() {
		const $segment = $('#module-status-toggle-segment');
		if ($segment.length === 0 || $('#integration-badges').length > 0) {
			return;
		}
		const escape = window[className].escapeHtml;
		const sections = {
			webhook: globalTranslate.mod_amo_integrationWebhook,
			widget: globalTranslate.mod_amo_integrationWidget,
			users: globalTranslate.mod_amo_integrationUsers,
		};
		// Те же размер и стиль, что у кнопки настроек модуля (ui icon basic button).
		let html = '<div id="integration-badges" class="ui basic buttons">';
		Object.keys(sections).forEach((section) => {
			html += `<div class="ui button integration-badge" data-section="${section}"><i class="icon grey circle"></i>${escape(sections[section])}</div>`;
		});
		html += `<button class="ui icon button" id="integration-sync-button" type="button" data-content="${escape(globalTranslate.mod_amo_integrationSyncNow)}"><i class="sync icon"></i></button>`;
		html += '</div>';
		const $settingsButton = $segment.find('.pbx-extensions-settings');
		if ($settingsButton.length > 0) {
			$settingsButton.after(html);
		} else {
			$segment.append(html);
		}
		// Копирование URL webhook из подсказки метки.
		$('body').on('click', '.copy-webhook-url', (e) => {
			e.preventDefault();
			const $link = $(e.currentTarget);
			window[className].copyToClipboard($link.attr('data-url'));
			$link.find('i.icon').removeClass('copy').addClass('check green');
			setTimeout(() => $link.find('i.icon').removeClass('check green').addClass('copy'), 1500);
		});
		$('#integration-sync-button')
			.popup({ position: 'bottom right' })
			.on('click', (e) => {
				e.preventDefault();
				window[className].startIntegrationSync();
			});
	},

	/**
	 * @param text
	 */
	copyToClipboard(text) {
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text);
			return;
		}
		const $tmp = $('<textarea>').val(text).css({ position: 'fixed', top: 0, opacity: 0 }).appendTo('body');
		$tmp[0].select();
		document.execCommand('copy');
		$tmp.remove();
	},

	/**
	 * @param text
	 * @returns {string}
	 */
	escapeHtml(text) {
		// Результат подставляется и в атрибуты: экранируем кавычки тоже.
		return $('<div>').text(text === undefined || text === null ? '' : String(text)).html()
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	},
	onChangeSettings() {
		if($('#isPrivateWidget').parent().checkbox('is checked')) {
			$('#private-fields').show();
		}else{
			$('#private-fields').hide();
		}
		if($('#disableDetailedCdr').parent().checkbox('is checked')){
			$('.limited-cdr-settings').show();
		}else{
			$('.limited-cdr-settings').hide();
		}
	},
	/**
	 * Deletes an extension with the given ID.
	 * @param {string} id - The ID of the rule to delete.
	 */
	deleteRule(id) {
		$('.message.ajax').remove();
		$.api({
			url: `${globalRootUrl}${idUrl}/delete/${id}`,
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
			if(result.data.lastContactsSyncTime === 0){
				$('#WaitSyncMsg').show();
			}else{
				$('#WaitSyncMsg').hide();
			}
			window[className].renderIntegrationState(result.data.integrationState || {});
		});
	},

	/**
	 * Состояние настройки интеграции в amoCRM: цвет метки и расшифровка во всплывающей подсказке.
	 * @param state
	 */
	renderIntegrationState(state) {
		const escape = window[className].escapeHtml;
		const formatTime = (time) => new Date(time * 1000).toLocaleString(globalWebAdminLanguage || undefined);
		['webhook', 'widget', 'users'].forEach((section) => {
			const $label = $(`#integration-badges .integration-badge[data-section="${section}"]`);
			if ($label.length === 0) {
				return;
			}
			const data = state[section];
			let color = 'grey';
			const lines = [];
			if (data) {
				const isOk = data.state === 'ok';
				color = isOk ? 'green' : (data.state === 'error' ? 'red' : 'yellow');
				let text = globalTranslate[`mod_amo_state_${data.state}`] || data.state;
				if (section === 'users' && isOk && data.count !== undefined) {
					text += ` (${data.count})`;
				}
				lines.push(`<b>${escape(text)}</b>`);
				if (data.message) {
					lines.push(escape(data.message));
				}
				if (section === 'webhook' && data.url) {
					lines.push(`<span style="word-break: break-all;">${escape(data.url)}</span> `
						+ `<a href="#" class="copy-webhook-url" data-url="${escape(data.url)}" title="${escape(globalTranslate.mod_amo_copy)}"><i class="copy icon"></i></a>`);
					if (isOk && data.selfCheck !== undefined && (data.selfCheck < 200 || data.selfCheck >= 300)) {
						color = 'yellow';
						lines.push(escape(globalTranslate.mod_amo_state_selfCheckFail));
					}
				}
				if (data.time) {
					lines.push(`<span style="color: grey;">${escape(globalTranslate.mod_amo_integrationLastSync)}: ${escape(formatTime(data.time))}</span>`);
				}
			} else {
				lines.push(escape(globalTranslate.mod_amo_state_unknown));
			}
			const $icon = $label.find('i.icon');
			$icon.removeClass('grey green red yellow').addClass(color);
			const html = lines.join('<br>');
			// Подсказку пересоздаём только при изменении текста: иначе открытая подсказка мигает каждые 5 с.
			if ($label.attr('data-popup-html') !== html) {
				$label.attr('data-popup-html', html);
				$label.popup('destroy');
				// hoverable: подсказка не закрывается при переходе в неё мышью — можно выделить и скопировать текст.
				$label.popup({
					html,
					position: 'bottom center',
					variation: 'wide',
					hoverable: true,
					delay: { show: 100, hide: 400 },
				});
			}
		});
	},

	/**
	 * Запуск настройки интеграции в фоне. Результат появится в состоянии при следующей проверке.
	 */
	startIntegrationSync() {
		const $button = $('#integration-sync-button');
		$button.addClass('loading disabled');
		$.post(`${window.location.origin}${globalRootUrl}${idUrl}/syncIntegration`, {}, () => {
			setTimeout(() => $button.removeClass('loading disabled'), 5000);
		}).fail(() => $button.removeClass('loading disabled'));
	},

	/**
	 * Загрузка сопоставления пользователей из БД модуля.
	 */
	loadUsers() {
		$.get(`${window.location.origin}${globalRootUrl}${idUrl}/getAccountUsers`, (response) => {
			window[className].renderUsers(response);
		});
	},

	/**
	 * Обновление списка пользователей из amoCRM.
	 */
	refreshUsers() {
		const $button = $('#refresh-users-button');
		if ($button.hasClass('loading')) {
			return;
		}
		$button.addClass('loading');
		$.post(`${window.location.origin}${globalRootUrl}${idUrl}/refreshUsers`, {}, (response) => {
			window[className].renderUsers(response);
		}).always(() => $button.removeClass('loading'));
	},

	/**
	 * Таблица пользователей amoCRM с выбором сотрудника MikoPBX.
	 * @param response
	 */
	renderUsers(response) {
		const $table = $('#amo-users-table');
		const $message = $('#users-message');
		const users = (response && response.users) || [];
		const employees = (response && response.employees) || [];
		const escape = window[className].escapeHtml;
		$message.hide().removeClass('negative warning');
		if (response && response.success === false && response.message) {
			$message.addClass('negative').text(response.message).show();
		}
		if (users.length === 0) {
			$table.hide();
			if (!$message.is(':visible')) {
				$message.addClass('warning').text(globalTranslate.mod_amo_usersEmpty).show();
			}
			window[className].usersLoaded = false;
			return;
		}
		const $body = $table.find('tbody').empty();
		const notMapped = window[className].usersNotMappedValue;
		users.forEach((user) => {
			// Пустое value Semantic UI делает placeholder-ом, который нельзя выбрать в списке.
			const noneSelected = user.number === '' ? ' selected' : '';
			let options = `<option value="${notMapped}"${noneSelected}>${escape(globalTranslate.mod_amo_usersNotMapped)}</option>`;
			let found = user.number === '';
			employees.forEach((employee) => {
				const selected = employee.number === user.number ? ' selected' : '';
				if (selected) {
					found = true;
				}
				options += `<option value="${escape(employee.number)}"${selected}>${escape(employee.number)} — ${escape(employee.name)}</option>`;
			});
			if (!found) {
				options += `<option value="${escape(user.number)}" selected>${escape(user.number)}</option>`;
			}
			$body.append(`<tr>
				<td>${escape(user.name || user.amoUserId)}</td>
				<td>${escape(user.email)}</td>
				<td><select class="ui search fluid dropdown amo-user-select" data-amo-user-id="${escape(user.amoUserId)}">${options}</select></td>
			</tr>`);
		});
		$table.find('select[data-amo-user-id]').dropdown({
			fullTextSearch: true,
			onChange() {
				// Списки без name не входят в значения формы: отмечаем изменение явно.
				Form.dataChanged();
			},
		});
		$table.show();
		window[className].usersLoaded = true;
	},

	/**
	 * Сопоставление amoUserId => внутренний номер из таблицы пользователей.
	 * @returns {{}}
	 */
	getUsersMapping() {
		const mapping = {};
		// Semantic UI переносит классы select на обёртку — ищем по data-атрибуту.
		$('#amo-users-table select[data-amo-user-id]').each((index, obj) => {
			const value = $(obj).val() || '';
			mapping[$(obj).attr('data-amo-user-id')] = value === window[className].usersNotMappedValue ? '' : value;
		});
		return mapping;
	},

	updateAuthInfo(e) {
		let payload = (e && e.originalEvent && e.originalEvent.data) || {};
		if (!payload.code) {
			return;
		}
		let params = {
			'code': payload.code,
			'referer': $('#baseDomain').val(),
			'save-only': true
		};
		$("#warning-message").hide();
		let elStatusAuth = $('#login-button');
		elStatusAuth.removeClass('red green');
		elStatusAuth.text(globalTranslate.module_amo_crm_connect_refresh);

		const onResponse = function(data, requestFailed) {
			if (requestFailed === true || (data && data.result === false)) {
				let messages = (data && data.messages) || {};
				let errorData = messages['error-data'] || {};
				let hint = errorData.hint || '';
				let detail = errorData.detail || '';
				let errorText = hint + (detail ? " (" + detail + ")." : "");
				if (!errorText) {
					errorText = globalTranslate.module_amo_crm_connect_fail;
				}
				elStatusAuth.removeClass('green').addClass('red');
				elStatusAuth.text(globalTranslate.module_amo_crm_connect_fail);
				$("#warning-message div.header").text(globalTranslate.mod_amo_Error);
				$("#warning-message div.body").text(errorText);
				$("#warning-message").show();
			}
		};
		$.post(`${window.location.origin}/pbxcore/api/modules/${className}/listener`, params, onResponse)
			// Newer cores preserve HTTP 4xx/5xx for failed module REST actions.
			.fail((xhr) => onResponse(xhr.responseJSON, true));
		if (window[className] && window[className].popup && typeof window[className].popup.close === 'function') {
			window[className].popup.close();
		}
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
		if (window[className].usersLoaded) {
			result.data.amoUsersMapping = JSON.stringify(window[className].getUsersMapping());
		}
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
