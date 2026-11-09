"use strict";

/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 */

var idUrl = 'module-amo-crm/module-amo-crm';
var idForm = 'module-amo-crm-form';
var className = 'ModuleAmoCrm';
var inputClassName = 'mikopbx-module-input';

/* global $, globalRootUrl, globalTranslate, globalWebAdminLanguage, Form, Config */
var ModuleAmoCrm = {
  $formObj: $('#' + idForm),
  $checkBoxes: $('#' + idForm + ' .ui.checkbox'),
  $dropDowns: $('#' + idForm + ' .ui.dropdown'),
  saveTableAJAXUrl: "".concat(window.location.origin).concat(globalRootUrl).concat(idUrl, "/saveTableData"),
  deleteRecordAJAXUrl: "".concat(window.location.origin).concat(globalRootUrl).concat(idUrl, "/delete"),
  $disabilityFields: $('div.disability'),
  $statusToggle: $('#module-status-toggle'),
  $moduleStatus: $('#status'),
  authWindow: undefined,
  usersLoaded: false,
  // Значение пункта «не сопоставлен»; при сохранении превращается в пустой номер.
  usersNotMappedValue: '__none__',
  /**
  /**
   * Field validation rules
   * https://semantic-ui.com/behaviors/form.html
   */
  validateRules: {
    textField: {
      identifier: 'text_field',
      rules: [{
        type: 'empty',
        prompt: globalTranslate.mod_tplValidateValueIsEmpty
      }]
    },
    areaField: {
      identifier: 'text_area_field',
      rules: [{
        type: 'empty',
        prompt: globalTranslate.mod_tplValidateValueIsEmpty
      }]
    },
    passwordField: {
      identifier: 'password_field',
      rules: [{
        type: 'empty',
        prompt: globalTranslate.mod_tplValidateValueIsEmpty
      }]
    }
  },
  generatePassword: function generatePassword() {
    var length = 50,
      charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789",
      retVal = "";
    for (var i = 0, n = charset.length; i < length; ++i) {
      retVal += charset.charAt(Math.floor(Math.random() * n));
    }
    return retVal;
  },
  /**
   * On page load we init some Semantic UI library
   */
  initialize: function initialize() {
    // инициализируем чекбоксы и выподающие менюшки
    window[className].$checkBoxes.checkbox({
      onChange: window[className].onChangeSettings
    });
    window[className].onChangeSettings();
    window[className].$dropDowns.dropdown();
    $('.info.circle').not('.tab-hint').popup();
    // Над вкладками строка модуля — подсказки к вкладкам показываем снизу.
    $('.tab-hint').popup({
      position: 'bottom left',
      variation: 'wide'
    });
    $('#copy-pbx-address').on('click', function () {
      var host = $('#externalHostname').val() || $('#externalHostname').attr('placeholder') || '';
      var port = $('#webhookPort').val();
      var token = $('#tokenForAmo').val();
      var address = host;
      if (port && token) {
        address = host + ':' + port + '/' + token;
      }
      var tmp = $('<input>').val(address).appendTo('body').select();
      document.execCommand('copy');
      tmp.remove();
    });
    window[className].initIntegrationBadges();
    window[className].checkStatusToggle();
    window.addEventListener('ModuleStatusChanged', window[className].checkStatusToggle);
    window[className].initializeForm();
    $('.menu .item').tab();
    window[className].checkStatus();
    setInterval(window[className].checkStatus, 5000);
    window[className].loadUsers();
    $('#refresh-users-button').popup({
      position: 'bottom left'
    }).on('click', function (e) {
      // Клик по значку в заголовке вкладки: обновляем список и открываем вкладку.
      e.preventDefault();
      window[className].refreshUsers();
    });
    $(window).bind('message', window[className].updateAuthInfo);
    $("#createPassword").on('click', function (e) {
      $("#tokenForAmo").val(window[className].generatePassword());
      $('#submitbutton').removeClass('disabled');
    });
    $("#login-button").on('click', function (e) {
      var client_id = $('#clientId').val();
      if ($('#isPrivateWidget').parent().checkbox('is checked')) {
        client_id = $('#privateClientId').val();
      }
      var state = encodeURIComponent(client_id);
      var redirect_uri = encodeURIComponent($('#redirectUri').val());
      var url = "https://www.amocrm.ru/oauth?client_id=".concat(client_id, "&state=").concat(state, "&redirect_uri=").concat(redirect_uri, "&mode=post_message&scope=&approval_prompt=auto");
      window[className].popup = window.open(url, 'Auth', 'scrollbars, status, resizable, width=750, height=580');
    });
    $('body').on('click', 'a.delete', function (e) {
      e.preventDefault();
      var id = $(e.target).closest('tr').attr('id');
      ModuleAmoCrm.deleteRule(id);
    });
    $('#entitySettingsTable').dataTable({
      lengthChange: false,
      processing: true,
      paging: false,
      deferRender: true,
      autoWidth: false,
      columns: [{
        orderable: true,
        searchable: true
      }, {
        orderable: true,
        searchable: true
      }, {
        orderable: false,
        searchable: false
      }, {
        orderable: false,
        searchable: false
      }, {
        orderable: false,
        searchable: false
      }, {
        orderable: false,
        searchable: false
      }, {
        orderable: false,
        searchable: false
      }],
      language: SemanticLocalization.dataTableLocalisation,
      order: [1, 'asc']
    });
    // Кнопка "Добавить правило" — в свободную левую колонку над таблицей (рядом с поиском).
    var $rulesLeftColumn = $('#entitySettingsTable_wrapper .ui.grid > .row:first > .eight.wide.column:not(.right)');
    if ($rulesLeftColumn.length > 0) {
      $('#add-new-button').appendTo($rulesLeftColumn.first());
    }
  },
  /**
   * Метки состояния интеграции (webhook, виджет, пользователи) в строке "Модуль включен".
   * Строку рендерит ядро, поэтому метки добавляются отсюда.
   */
  initIntegrationBadges: function initIntegrationBadges() {
    var $segment = $('#module-status-toggle-segment');
    if ($segment.length === 0 || $('#integration-badges').length > 0) {
      return;
    }
    var escape = window[className].escapeHtml;
    var sections = {
      webhook: globalTranslate.mod_amo_integrationWebhook,
      widget: globalTranslate.mod_amo_integrationWidget,
      users: globalTranslate.mod_amo_integrationUsers
    };
    // Те же размер и стиль, что у кнопки настроек модуля (ui icon basic button).
    var html = '<div id="integration-badges" class="ui basic buttons">';
    Object.keys(sections).forEach(function (section) {
      html += "<div class=\"ui button integration-badge\" data-section=\"".concat(section, "\"><i class=\"icon grey circle\"></i>").concat(escape(sections[section]), "</div>");
    });
    html += "<button class=\"ui icon button\" id=\"integration-sync-button\" type=\"button\" data-content=\"".concat(escape(globalTranslate.mod_amo_integrationSyncNow), "\"><i class=\"sync icon\"></i></button>");
    html += '</div>';
    var $settingsButton = $segment.find('.pbx-extensions-settings');
    if ($settingsButton.length > 0) {
      $settingsButton.after(html);
    } else {
      $segment.append(html);
    }
    // Копирование URL webhook из подсказки метки.
    $('body').on('click', '.copy-webhook-url', function (e) {
      e.preventDefault();
      var $link = $(e.currentTarget);
      window[className].copyToClipboard($link.attr('data-url'));
      $link.find('i.icon').removeClass('copy').addClass('check green');
      setTimeout(function () {
        return $link.find('i.icon').removeClass('check green').addClass('copy');
      }, 1500);
    });
    $('#integration-sync-button').popup({
      position: 'bottom right'
    }).on('click', function (e) {
      e.preventDefault();
      window[className].startIntegrationSync();
    });
  },
  /**
   * @param text
   */
  copyToClipboard: function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text);
      return;
    }
    var $tmp = $('<textarea>').val(text).css({
      position: 'fixed',
      top: 0,
      opacity: 0
    }).appendTo('body');
    $tmp[0].select();
    document.execCommand('copy');
    $tmp.remove();
  },
  /**
   * @param text
   * @returns {string}
   */
  escapeHtml: function escapeHtml(text) {
    // Результат подставляется и в атрибуты: экранируем кавычки тоже.
    return $('<div>').text(text === undefined || text === null ? '' : String(text)).html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  },
  onChangeSettings: function onChangeSettings() {
    if ($('#isPrivateWidget').parent().checkbox('is checked')) {
      $('#private-fields').show();
    } else {
      $('#private-fields').hide();
    }
    if ($('#disableDetailedCdr').parent().checkbox('is checked')) {
      $('.limited-cdr-settings').show();
    } else {
      $('.limited-cdr-settings').hide();
    }
  },
  /**
   * Deletes an extension with the given ID.
   * @param {string} id - The ID of the rule to delete.
   */
  deleteRule: function deleteRule(id) {
    $('.message.ajax').remove();
    $.api({
      url: "".concat(globalRootUrl).concat(idUrl, "/delete/").concat(id),
      on: 'now',
      successTest: function successTest(response) {
        // test whether a JSON response is valid
        return response !== undefined && Object.keys(response).length > 0;
      },
      onSuccess: function onSuccess(response) {
        if (response.success === true) {
          $('#entitySettingsTable').find("tr[id=".concat(id, "]")).remove();
        } else {
          UserMessage.showError(response.message.error, globalTranslate.ex_ImpossibleToDeleteExtension);
        }
      }
    });
  },
  checkStatus: function checkStatus() {
    $.get("".concat(window.location.origin).concat(globalRootUrl).concat(idUrl, "/check"), function (result) {
      var elStatusAuth = $('#login-button');
      elStatusAuth.removeClass('red green');
      if (result.success === true) {
        elStatusAuth.addClass('green');
        elStatusAuth.text(globalTranslate.module_amo_crm_connect_ok);
      } else {
        elStatusAuth.addClass('red');
        elStatusAuth.text(globalTranslate.module_amo_crm_connect_fail);
      }
      if (result.data.lastContactsSyncTime === 0) {
        $('#WaitSyncMsg').show();
      } else {
        $('#WaitSyncMsg').hide();
      }
      window[className].renderIntegrationState(result.data.integrationState || {});
    });
  },
  /**
   * Состояние настройки интеграции в amoCRM: цвет метки и расшифровка во всплывающей подсказке.
   * @param state
   */
  renderIntegrationState: function renderIntegrationState(state) {
    var escape = window[className].escapeHtml;
    var formatTime = function formatTime(time) {
      return new Date(time * 1000).toLocaleString(globalWebAdminLanguage || undefined);
    };
    ['webhook', 'widget', 'users'].forEach(function (section) {
      var $label = $("#integration-badges .integration-badge[data-section=\"".concat(section, "\"]"));
      if ($label.length === 0) {
        return;
      }
      var data = state[section];
      var color = 'grey';
      var lines = [];
      if (data) {
        var isOk = data.state === 'ok';
        color = isOk ? 'green' : data.state === 'error' ? 'red' : 'yellow';
        var text = globalTranslate["mod_amo_state_".concat(data.state)] || data.state;
        if (section === 'users' && isOk && data.count !== undefined) {
          text += " (".concat(data.count, ")");
        }
        lines.push("<b>".concat(escape(text), "</b>"));
        if (data.message) {
          lines.push(escape(data.message));
        }
        if (section === 'webhook' && data.url) {
          lines.push("<span style=\"word-break: break-all;\">".concat(escape(data.url), "</span> ") + "<a href=\"#\" class=\"copy-webhook-url\" data-url=\"".concat(escape(data.url), "\" title=\"").concat(escape(globalTranslate.mod_amo_copy), "\"><i class=\"copy icon\"></i></a>"));
          if (isOk && data.selfCheck !== undefined && (data.selfCheck < 200 || data.selfCheck >= 300)) {
            color = 'yellow';
            lines.push(escape(globalTranslate.mod_amo_state_selfCheckFail));
          }
        }
        if (data.time) {
          lines.push("<span style=\"color: grey;\">".concat(escape(globalTranslate.mod_amo_integrationLastSync), ": ").concat(escape(formatTime(data.time)), "</span>"));
        }
      } else {
        lines.push(escape(globalTranslate.mod_amo_state_unknown));
      }
      var $icon = $label.find('i.icon');
      $icon.removeClass('grey green red yellow').addClass(color);
      var html = lines.join('<br>');
      // Подсказку пересоздаём только при изменении текста: иначе открытая подсказка мигает каждые 5 с.
      if ($label.attr('data-popup-html') !== html) {
        $label.attr('data-popup-html', html);
        $label.popup('destroy');
        // hoverable: подсказка не закрывается при переходе в неё мышью — можно выделить и скопировать текст.
        $label.popup({
          html: html,
          position: 'bottom center',
          variation: 'wide',
          hoverable: true,
          delay: {
            show: 100,
            hide: 400
          }
        });
      }
    });
  },
  /**
   * Запуск настройки интеграции в фоне. Результат появится в состоянии при следующей проверке.
   */
  startIntegrationSync: function startIntegrationSync() {
    var $button = $('#integration-sync-button');
    $button.addClass('loading disabled');
    $.post("".concat(window.location.origin).concat(globalRootUrl).concat(idUrl, "/syncIntegration"), {}, function () {
      setTimeout(function () {
        return $button.removeClass('loading disabled');
      }, 5000);
    }).fail(function () {
      return $button.removeClass('loading disabled');
    });
  },
  /**
   * Загрузка сопоставления пользователей из БД модуля.
   */
  loadUsers: function loadUsers() {
    $.get("".concat(window.location.origin).concat(globalRootUrl).concat(idUrl, "/getAccountUsers"), function (response) {
      window[className].renderUsers(response);
    });
  },
  /**
   * Обновление списка пользователей из amoCRM.
   */
  refreshUsers: function refreshUsers() {
    var $button = $('#refresh-users-button');
    if ($button.hasClass('loading')) {
      return;
    }
    $button.addClass('loading');
    $.post("".concat(window.location.origin).concat(globalRootUrl).concat(idUrl, "/refreshUsers"), {}, function (response) {
      window[className].renderUsers(response);
    }).always(function () {
      return $button.removeClass('loading');
    });
  },
  /**
   * Таблица пользователей amoCRM с выбором сотрудника MikoPBX.
   * @param response
   */
  renderUsers: function renderUsers(response) {
    var $table = $('#amo-users-table');
    var $message = $('#users-message');
    var users = response && response.users || [];
    var employees = response && response.employees || [];
    var escape = window[className].escapeHtml;
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
    var $body = $table.find('tbody').empty();
    var notMapped = window[className].usersNotMappedValue;
    users.forEach(function (user) {
      // Пустое value Semantic UI делает placeholder-ом, который нельзя выбрать в списке.
      var noneSelected = user.number === '' ? ' selected' : '';
      var options = "<option value=\"".concat(notMapped, "\"").concat(noneSelected, ">").concat(escape(globalTranslate.mod_amo_usersNotMapped), "</option>");
      var found = user.number === '';
      employees.forEach(function (employee) {
        var selected = employee.number === user.number ? ' selected' : '';
        if (selected) {
          found = true;
        }
        options += "<option value=\"".concat(escape(employee.number), "\"").concat(selected, ">").concat(escape(employee.number), " \u2014 ").concat(escape(employee.name), "</option>");
      });
      if (!found) {
        options += "<option value=\"".concat(escape(user.number), "\" selected>").concat(escape(user.number), "</option>");
      }
      $body.append("<tr>\n\t\t\t\t<td>".concat(escape(user.name || user.amoUserId), "</td>\n\t\t\t\t<td>").concat(escape(user.email), "</td>\n\t\t\t\t<td><select class=\"ui search fluid dropdown amo-user-select\" data-amo-user-id=\"").concat(escape(user.amoUserId), "\">").concat(options, "</select></td>\n\t\t\t</tr>"));
    });
    $table.find('select[data-amo-user-id]').dropdown({
      fullTextSearch: true,
      onChange: function onChange() {
        // Списки без name не входят в значения формы: отмечаем изменение явно.
        Form.dataChanged();
      }
    });
    $table.show();
    window[className].usersLoaded = true;
  },
  /**
   * Сопоставление amoUserId => внутренний номер из таблицы пользователей.
   * @returns {{}}
   */
  getUsersMapping: function getUsersMapping() {
    var mapping = {};
    // Semantic UI переносит классы select на обёртку — ищем по data-атрибуту.
    $('#amo-users-table select[data-amo-user-id]').each(function (index, obj) {
      var value = $(obj).val() || '';
      mapping[$(obj).attr('data-amo-user-id')] = value === window[className].usersNotMappedValue ? '' : value;
    });
    return mapping;
  },
  updateAuthInfo: function updateAuthInfo(e) {
    var payload = e && e.originalEvent && e.originalEvent.data || {};
    if (!payload.code) {
      return;
    }
    var params = {
      'code': payload.code,
      'referer': $('#baseDomain').val(),
      'save-only': true
    };
    $("#warning-message").hide();
    var elStatusAuth = $('#login-button');
    elStatusAuth.removeClass('red green');
    elStatusAuth.text(globalTranslate.module_amo_crm_connect_refresh);
    var onResponse = function onResponse(data, requestFailed) {
      if (requestFailed === true || data && data.result === false) {
        var messages = data && data.messages || {};
        var errorData = messages['error-data'] || {};
        var hint = errorData.hint || '';
        var detail = errorData.detail || '';
        var errorText = hint + (detail ? " (" + detail + ")." : "");
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
    $.post("".concat(window.location.origin, "/pbxcore/api/modules/").concat(className, "/listener"), params, onResponse)
    // Newer cores preserve HTTP 4xx/5xx for failed module REST actions.
    .fail(function (xhr) {
      return onResponse(xhr.responseJSON, true);
    });
    if (window[className] && window[className].popup && typeof window[className].popup.close === 'function') {
      window[className].popup.close();
    }
  },
  /**
   * Подготавливает список выбора
   * @param selected
   * @returns {[]}
   */
  makeDropdownList: function makeDropdownList(selectType, selected) {
    var values = [{
      name: ' --- ',
      value: '',
      selected: '' === selected
    }];
    $('#' + selectType + ' option').each(function (index, obj) {
      values.push({
        name: obj.text,
        value: obj.value,
        selected: selected === obj.value
      });
    });
    return values;
  },
  /**
   * Обработка изменения группы в списке
   */
  changeGroupInList: function changeGroupInList(value, text, choice) {
    var tdInput = $(choice).closest('td').find('input');
    tdInput.attr('data-value', value);
    tdInput.attr('value', value);
    var currentRowId = $(choice).closest('tr').attr('id');
    var tableName = $(choice).closest('table').attr('id').replace('-table', '');
    if (currentRowId !== undefined && tableName !== undefined) {
      window[className].sendChangesToServer(tableName, currentRowId);
    }
  },
  /**
   * Add new Table.
   */
  initTable: function initTable(tableName, options) {
    var columns = [];
    var columnsArray4Sort = [];
    for (var colName in options['cols']) {
      columns.push({
        data: colName
      });
      columnsArray4Sort.push(colName);
    }
    $('#' + tableName).DataTable({
      ajax: {
        url: idUrl + options.ajaxUrl + '?table=' + tableName.replace('-table', ''),
        dataSrc: 'data'
      },
      columns: columns,
      paging: false,
      sDom: 'rtip',
      deferRender: true,
      pageLength: 17,
      infoCallback: function infoCallback(settings, start, end, max, total, pre) {
        return '';
      },
      language: SemanticLocalization.dataTableLocalisation,
      ordering: false,
      /**
       * Builder row presentation
       * @param row
       * @param data
       */
      createdRow: function createdRow(row, data) {
        var cols = $('td', row);
        var headers = $('#' + tableName + ' thead tr th');
        for (var key in data) {
          var index = columnsArray4Sort.indexOf(key);
          if (key === 'rowIcon') {
            cols.eq(index).html('<i class="ui ' + data[key] + ' circle icon"></i>');
          } else if (key === 'delButton') {
            var templateDeleteButton = '<div class="ui small basic icon buttons action-buttons">' + '<a href="' + window[className].deleteRecordAJAXUrl + '/' + data.id + '" data-value = "' + data.DT_RowId + '"' + ' class="ui button delete two-steps-delete popuped" data-content="' + globalTranslate.bt_ToolTipDelete + '">' + '<i class="icon trash red"></i></a></div>';
            cols.eq(index).html(templateDeleteButton);
          } else if (key === 'priority') {
            cols.eq(index).addClass('dragHandle');
            cols.eq(index).html('<i class="ui sort circle icon"></i>');
            // Приоритет устанавливаем для строки.
            $(row).attr('m-priority', data[key]);
          } else {
            var template = '<div class="ui transparent fluid input inline-edit">' + '<input colName="' + key + '" class="' + inputClassName + '" type="text" data-value="' + data[key] + '" value="' + data[key] + '"></div>';
            $('td', row).eq(index).html(template);
          }
          if (options['cols'][key] === undefined) {
            continue;
          }
          var additionalClass = options['cols'][key]['class'];
          if (additionalClass !== undefined && additionalClass !== '') {
            headers.eq(index).addClass(additionalClass);
          }
          var header = options['cols'][key]['header'];
          if (header !== undefined && header !== '') {
            headers.eq(index).html(header);
          }
          var selectMetaData = options['cols'][key]['select'];
          if (selectMetaData !== undefined) {
            var newTemplate = $('#template-select').html().replace('PARAM', data[key]);
            var _template = '<input class="' + inputClassName + '" colName="' + key + '" selectType="' + selectMetaData + '" style="display: none;" type="text" data-value="' + data[key] + '" value="' + data[key] + '"></div>';
            cols.eq(index).html(newTemplate + _template);
          }
        }
      },
      /**
       * Draw event - fired once the table has completed a draw.
       */
      drawCallback: function drawCallback(settings) {
        window[className].drowSelectGroup(settings.sTableId);
      }
    });
    var body = $('body');
    // Клик по полю. Вход для редактирования значения.
    body.on('focusin', '.' + inputClassName, function (e) {
      if ($(e.target).parents('table').attr('id') === 'ModuleAmoPipeLines-table' && $(e.target).attr('colname') === 'name') {
        return;
      }
      $(e.target).transition('glow');
      $(e.target).closest('div').removeClass('transparent').addClass('changed-field');
      $(e.target).attr('readonly', false);
    });
    // Отправка формы на сервер по Enter или Tab
    $(document).on('keydown', function (e) {
      var keyCode = e.keyCode || e.which;
      if (keyCode === 13 || keyCode === 9 && $(':focus').hasClass('mikopbx-module-input')) {
        window[className].endEditInput();
      }
    });
    body.on('click', 'a.delete', function (e) {
      e.preventDefault();
      var currentRowId = $(e.target).closest('tr').attr('id');
      var tableName = $(e.target).closest('table').attr('id').replace('-table', '');
      window[className].deleteRow(tableName, currentRowId);
    }); // Добавление новой строки

    // Отправка формы на сервер по уходу с поля ввода
    body.on('focusout', '.' + inputClassName, window[className].endEditInput);

    // Кнопка "Добавить новую запись"
    $('[id-table = "' + tableName + '"]').on('click', window[className].addNewRow);
  },
  /**
   * Перемещение строки, изменение приоритета.
   */
  cbOnDrop: function cbOnDrop(table, row) {
    var priorityWasChanged = false;
    var priorityData = {};
    $(table).find('tr').each(function (index, obj) {
      var ruleId = $(obj).attr('id');
      var oldPriority = parseInt($(obj).attr('m-priority'), 10);
      var newPriority = obj.rowIndex;
      if (!isNaN(ruleId) && oldPriority !== newPriority) {
        priorityWasChanged = true;
        priorityData[ruleId] = newPriority;
      }
    });
    if (priorityWasChanged) {
      $.api({
        on: 'now',
        url: "".concat(window.location.origin).concat(globalRootUrl).concat(idUrl, "/changePriority?table=") + $(table).attr('id').replace('-table', ''),
        method: 'POST',
        data: priorityData
      });
    }
  },
  /**
   * Окончание редактирования поля ввода.
   * Не относится к select.
   * @param e
   */
  endEditInput: function endEditInput(e) {
    var $el = $('.changed-field').closest('tr');
    $el.each(function (index, obj) {
      var currentRowId = $(obj).attr('id');
      var tableName = $(obj).closest('table').attr('id').replace('-table', '');
      if (currentRowId !== undefined && tableName !== undefined) {
        window[className].sendChangesToServer(tableName, currentRowId);
      }
    });
  },
  /**
   * Добавление новой строки в таблицу.
   * @param e
   */
  addNewRow: function addNewRow(e) {
    var idTable = $(e.target).attr('id-table');
    var table = $('#' + idTable);
    e.preventDefault();
    table.find('.dataTables_empty').remove();
    // Отправим на запись все что не записано еще
    var $el = table.find('.changed-field').closest('tr');
    $el.each(function (index, obj) {
      var currentRowId = $(obj).attr('id');
      if (currentRowId !== undefined) {
        window[className].sendChangesToServer(currentRowId);
      }
    });
    var id = "new" + Math.floor(Math.random() * Math.floor(500));
    var rowTemplate = '<tr id="' + id + '" role="row" class="even">' + table.find('tr#TEMPLATE').html().replace('TEMPLATE', id) + '</tr>';
    table.find('tbody > tr:first').before(rowTemplate);
    window[className].drowSelectGroup(idTable);
  },
  /**
   * Обновление select элементов.
   * @param tableId
   */
  drowSelectGroup: function drowSelectGroup(tableId) {
    $('#' + tableId).find('tr#TEMPLATE').hide();
    var selestGroup = $('.select-group');
    selestGroup.each(function (index, obj) {
      var selectType = $(obj).closest('td').find('input').attr('selectType');
      $(obj).dropdown({
        values: window[className].makeDropdownList(selectType, $(obj).attr('data-value'))
      });
    });
    selestGroup.dropdown({
      onChange: window[className].changeGroupInList
    });
    $('#' + tableId).tableDnD({
      onDrop: window[className].cbOnDrop,
      onDragClass: 'hoveringRow',
      dragHandle: '.dragHandle'
    });
  },
  /**
   * Удаление строки
   * @param tableName
   * @param id - record id
   */
  deleteRow: function deleteRow(tableName, id) {
    var table = $('#' + tableName + '-table');
    if (id.substr(0, 3) === 'new') {
      table.find('tr#' + id).remove();
      return;
    }
    $.api({
      url: window[className].deleteRecordAJAXUrl + '?id=' + id + '&table=' + tableName,
      on: 'now',
      onSuccess: function onSuccess(response) {
        if (response.success) {
          table.find('tr#' + id).remove();
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
  sendChangesToServer: function sendChangesToServer(tableName, recordId) {
    var data = {
      'pbx-table-id': tableName,
      'pbx-row-id': recordId
    };
    var notEmpty = false;
    $("tr#" + recordId + ' .' + inputClassName).each(function (index, obj) {
      var colName = $(obj).attr('colName');
      if (colName !== undefined) {
        data[$(obj).attr('colName')] = $(obj).val();
        if ($(obj).val() !== '') {
          notEmpty = true;
        }
      }
    });
    if (notEmpty === false) {
      return;
    }
    $("tr#" + recordId + " .user.circle").removeClass('user circle').addClass('spinner loading');
    $.api({
      url: window[className].saveTableAJAXUrl,
      on: 'now',
      method: 'POST',
      data: data,
      successTest: function successTest(response) {
        return response !== undefined && Object.keys(response).length > 0 && response.success === true;
      },
      onSuccess: function onSuccess(response) {
        if (response.data !== undefined) {
          var rowId = response.data['pbx-row-id'];
          var table = $('#' + response.data['pbx-table-id'] + '-table');
          table.find("tr#" + rowId + " input").attr('readonly', true);
          table.find("tr#" + rowId + " div").removeClass('changed-field loading').addClass('transparent');
          table.find("tr#" + rowId + " .spinner.loading").addClass('user circle').removeClass('spinner loading');
          if (rowId !== response.data['newId']) {
            $("tr#".concat(rowId)).attr('id', response.data['newId']);
          }
        }
      },
      onFailure: function onFailure(response) {
        if (response.message !== undefined) {
          UserMessage.showMultiString(response.message);
        }
        $("tr#" + recordId + " .spinner.loading").addClass('user circle').removeClass('spinner loading');
      },
      onError: function onError(errorMessage, element, xhr) {
        if (xhr.status === 403) {
          window.location = globalRootUrl + "session/index";
        }
      }
    });
  },
  /**
   * Change some form elements classes depends of module status
   */
  checkStatusToggle: function checkStatusToggle() {
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
  applyConfigurationChanges: function applyConfigurationChanges() {
    window[className].changeStatus('Updating');
    $.api({
      url: "".concat(window.location.origin, "/pbxcore/api/modules/") + className + "/reload",
      on: 'now',
      successTest: function successTest(response) {
        // test whether a JSON response is valid
        return Object.keys(response).length > 0 && response.result === true;
      },
      onSuccess: function onSuccess() {
        window[className].changeStatus('Connected');
      },
      onFailure: function onFailure() {
        window[className].changeStatus('Disconnected');
      }
    });
  },
  /**
   * We can modify some data before form send
   * @param settings
   * @returns {*}
   */
  cbBeforeSendForm: function cbBeforeSendForm(settings) {
    var result = settings;
    result.data = window[className].$formObj.form('get values');
    if (window[className].usersLoaded) {
      result.data.amoUsersMapping = JSON.stringify(window[className].getUsersMapping());
    }
    return result;
  },
  /**
   * Some actions after forms send
   */
  cbAfterSendForm: function cbAfterSendForm() {
    window[className].applyConfigurationChanges();
  },
  /**
   * Initialize form parameters
   */
  initializeForm: function initializeForm() {
    Form.$formObj = window[className].$formObj;
    Form.url = "".concat(window.location.origin).concat(globalRootUrl).concat(idUrl, "/save");
    Form.validateRules = window[className].validateRules;
    Form.cbBeforeSendForm = window[className].cbBeforeSendForm;
    Form.cbAfterSendForm = window[className].cbAfterSendForm;
    Form.initialize();
  },
  /**
   * Update the module state on form label
   * @param status
   */
  changeStatus: function changeStatus(status) {
    switch (status) {
      case 'Connected':
        window[className].$moduleStatus.removeClass('grey').removeClass('red').addClass('green');
        window[className].$moduleStatus.html(globalTranslate.module_amo_crmConnected);
        break;
      case 'Disconnected':
        window[className].$moduleStatus.removeClass('green').removeClass('red').addClass('grey');
        window[className].$moduleStatus.html(globalTranslate.module_amo_crmDisconnected);
        break;
      case 'Updating':
        window[className].$moduleStatus.removeClass('green').removeClass('red').addClass('grey');
        window[className].$moduleStatus.html("<i class=\"spinner loading icon\"></i>".concat(globalTranslate.module_amo_crmUpdateStatus));
        break;
      default:
        window[className].$moduleStatus.removeClass('green').removeClass('red').addClass('grey');
        window[className].$moduleStatus.html(globalTranslate.module_amo_crmDisconnected);
        break;
    }
  }
};
$(document).ready(function () {
  window[className].initialize();
});
//# sourceMappingURL=module-amo-crm-index.js.map