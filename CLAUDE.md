# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## КРИТИЧЕСКИ ВАЖНО: Запреты при разработке

**КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО:**
1. **Изменять файлы вне директории модуля** - никогда не модифицировать файлы в `/offload/`, `/usr/www/src/`, или других системных директориях MikoPBX
2. **Использовать rsync, cp -r или tar для установки модуля** - это может перезаписать системные файлы MikoPBX
3. **Удалять директорию модуля целиком** (`rm -rf /storage/.../ModuleAmoCrm`) - это удалит базу данных модуля
4. **Затирать `Lib/AmoCrmMainBase.php` при установке модуля** — этот файл содержит реальные OAuth-credentials (`CLIENT_ID`, `CLIENT_SECRET`, `REDIRECT_URL`), которые подставляются CI при сборке. В репозитории хранятся только плейсхолдеры (`%CLIENT_ID%` и т.д.). **Перед обновлением/установкой модуля на сервере необходимо сделать бэкап этого файла и восстановить его после установки.**

## Требования к коду

**Совместимость:**
- Код должен быть совместим с **PHP 7.4** и **PHP 8.x**
- Код должен работать с **Phalcon 4.x** и **Phalcon 5.x**

**Избегайте (только PHP 8+):**
- `match` выражения → используйте `switch`
- Union types `function foo(): int|string` → используйте PHPDoc
- Named arguments `foo(name: $value)`
- Constructor property promotion
- Nullsafe operator `?->`
- `str_contains()`, `str_starts_with()`, `str_ends_with()` → используйте `strpos() !== false`

## Project Overview

ModuleAmoCrm — модуль-расширение для MikoPBX, интегрирующий телефонную систему с AmoCRM. Синхронизирует звонки (CDR), контакты, сделки, компании и задачи между АТС и CRM.

- **Namespace**: `Modules\ModuleAmoCrm\` (PSR-4 от корня)
- **Стек:** PHP 7.4+, Phalcon MVC, Beanstalk (очереди), Asterisk AMI
- **Module ID**: `ModuleAmoCrm`

## Команды

```bash
# Установка PHP-зависимостей
composer install

# Проверка синтаксиса
php -l <file.php>

# Фоновые воркеры (запускаются на PBX-системе)
php bin/WorkerAmoCrmAMI.php   # AMI-слушатель событий Asterisk
php bin/AmoCdrDaemon.php      # Синхронизация CDR
php bin/ConnectorDb.php       # IPC-диспетчер, все операции с БД
php bin/WorkerAmoHTTP.php     # HTTP-запросы к AmoCRM API
php bin/SyncDaemon.php        # Синхронизация контактов/компаний/сделок
```

## Build & CI

GitHub Actions workflow (`.github/workflows/build.yml`) срабатывает на push в `master`/`develop` и использует reusable workflow из `mikopbx/.github-workflows`:
```yaml
jobs:
  build:
    uses: mikopbx/.github-workflows/.github/workflows/extension-publish.yml@master
    with:
      initial_version: "1.84"
    secrets: inherit
```

Тестов в проекте нет (нет phpunit.xml, нет каталога tests/).

## Сборка и установка модуля

### Сборка архива (локально)

```bash
cd /Volumes/DevDisk/apor/Developement/MikoPBX/Extensions/ModuleAmoCrm
zip -r ../ModuleAmoCrm.zip . -x "*.git*" -x "*tasks.md*" -x "*.DS_Store*" -x "*CLAUDE.md*"
```

### Установка через WorkerModuleInstaller (ЕДИНСТВЕННЫЙ РАЗРЕШЁННЫЙ СПОСОБ)

```bash
# На сервере: создать settings.json
cat > /tmp/settings.json << 'EOF'
{
    "currentModuleDir": "/storage/usbdisk1/mikopbx/custom_modules/ModuleAmoCrm",
    "filePath": "/home/user/ModuleAmoCrm.zip",
    "uniqid": "ModuleAmoCrm"
}
EOF

# Установить модуль (сохраняет БД!)
php -f /usr/www/src/PBXCoreREST/Workers/WorkerModuleInstaller.php start /tmp/settings.json
```

## Инициализация в скриптах

Все PHP скрипты (bin/) должны начинаться с:

```php
#!/usr/bin/php
<?php
require_once('Globals.php');
```

**Важно:** `Globals.php` должен быть симлинком на `/usr/www/src/Core/Config/Globals.php`

## Сборка JavaScript

Исходники: `public/assets/js/src/` — **редактировать только файлы в `src/`**.
Скомпилированные файлы в `public/assets/js/*.js` — автогенерируемые, не редактировать вручную.

Сборка через Babel (пресет `airbnb`, source maps включены).
PHPStorm File Watcher: https://docs.mikopbx.com/mikopbx-development/prepare-ide-tools/mac#phpstorm-setup-babel

Ручная сборка:
```bash
cd /Users/apor/Developement/MikoPBX/MikoPBXUtils && \
cp babel.config.json babel.config.json.bak && \
echo '{"presets":[["@babel/preset-env",{"targets":{"chrome":50,"ie":11,"firefox":45}}]]}' > babel.config.json && \
./node_modules/.bin/babel \
  /Volumes/DevDisk/apor/Developement/MikoPBX/Extensions/ModuleAmoCrm/public/assets/js/src/module-amo-crm-index.js \
  --out-dir /Volumes/DevDisk/apor/Developement/MikoPBX/Extensions/ModuleAmoCrm/public/assets/js/ \
  --source-maps && \
mv babel.config.json.bak babel.config.json
```

## Architecture

### Worker-Based Async System

Модуль работает через набор фоновых демонов (workers), взаимодействующих через Beanstalk-очереди:

| Worker | Файл | Тип проверки | Назначение |
|--------|------|-------------|------------|
| `WorkerAmoCrmAMI` | `bin/WorkerAmoCrmAMI.php` | AMI | Слушает события Asterisk (перехват, звонки) |
| `AmoCdrDaemon` | `bin/AmoCdrDaemon.php` | PID | Синхронизация CDR → AmoCRM (батчами по 50) |
| `ConnectorDb` | `bin/ConnectorDb.php` | PID | RPC-сервис для операций с БД через Beanstalk |
| `WorkerAmoHTTP` | `bin/WorkerAmoHTTP.php` | Beanstalk | HTTP-запросы к API AmoCRM (throttle: 7 req/s) |
| `SyncDaemon` | `bin/SyncDaemon.php` | PID | Синхронизация контактов/компаний/сделок |

Все workers наследуют `WorkerBase` из MikoPBX. `ConnectorDb::invoke()` — универсальный паттерн RPC-вызова к БД-воркеру из других процессов.

### Inter-Process Communication Flow

```
AMI Events → WorkerAmoCrmAMI → Beanstalk → ConnectorDb (DB ops)
                                         → WorkerAmoHTTP (API calls)
CDR records → AmoCdrDaemon → ConnectorDb → WorkerAmoHTTP → AmoCRM API
Webhooks → ApiController → ConnectorDb → обработка
```

### MVC Layer (Phalcon)

- **Controller**: `App/Controllers/ModuleAmoCrmController.php` — CRUD для настроек модуля
- **Models** (`Models/`): 6 моделей, все наследуют `ModulesModelsBase`. Таблицы имеют префикс `m_ModuleAmo*`
- **Forms** (`App/Forms/`): формы для настроек и правил обработки звонков
- **Views** (`App/Views/`): Volt-шаблоны (`index.volt`, `modify.volt`)

### Module Configuration (`Lib/AmoCrmConf.php`)

Центральный класс конфигурации, наследует `ConfigClass`. Отвечает за:
- Регистрацию workers в `getModuleWorkers()`
- REST-маршруты в `getPBXCoreRESTAdditionalRoutes()`
- Генерацию dialplan-контекстов (extensions.conf) для перехвата звонков
- Nginx-локации для WebRTC-телефона и воспроизведения записей
- Cron-задачи: очистка tmp (каждую минуту), начальная синхронизация (01:00 ежедневно)
- Реакцию на изменения в БД через `modelsEventChangeData()`

### REST API

Маршруты зарегистрированы в `AmoCrmConf::getPBXCoreRESTAdditionalRoutes()`. Контроллер: `Lib/RestAPI/Controllers/ApiController.php`.

Базовый путь: `/pbxcore/api/amo-crm/v1/`

| Endpoint | Метод | Назначение |
|----------|-------|------------|
| `/callback` | POST | Инициация звонка из AmoCRM |
| `/listener` | GET/POST | OAuth2 авторизация |
| `/command` | POST | Команды управления (hangup и др.) |
| `/change-settings` | POST | Сохранение настроек из виджета |
| `/find-contact` | POST | Поиск контакта по телефону |
| `/panel-enable` | GET | Проверка статуса панели |
| `/entity-update` | POST | Webhook от AmoCRM при изменении сущностей |

Авторизация API — через файл-токен в `/var/etc/auth/`.

### Call Processing Rules

Модель `ModuleAmoEntitySettings` определяет правила для 7 типов звонков:
`INCOMING_UNKNOWN`, `MISSING_UNKNOWN`, `INCOMING_KNOWN`, `MISSING_KNOWN`, `OUTGOING_UNKNOWN`, `OUTGOING_KNOWN`, `OUTGOING_KNOWN_FAIL`

Каждое правило задаёт: ответственного, создание контакта/сделки/задачи/unsorted, шаблоны имён, воронку и статус. Defaults хранятся в `db/default-entity-settings.json`.

### OAuth2 & AmoCRM API

- `Lib/AmoCrmMain.php` — основной API-клиент AmoCRM (v4 API)
- `Lib/AuthToken.php` — управление OAuth2 токенами (refresh с буфером 1 час)
- `Lib/ClientHTTP.php` — HTTP-обёртка (POST, PATCH, GET)
- Поддержка приватного виджета с отдельными client_id/secret

### Widget (`widget/`)

AmoCRM-виджет для встраивания в интерфейс CRM: WebRTC-телефон через iframe, event-driven коммуникация (PubSub), настройки в `manifest.json`.

### Database Models

- `ModuleAmoCrm` — главные настройки (OAuth-токены, домен, параметры интеграции)
- `ModuleAmoEntitySettings` — правила обработки звонков per DID/type
- `ModuleAmoUsers` — маппинг PBX extension ↔ AmoCRM user
- `ModuleAmoPhones` — маппинг телефонных номеров
- `ModuleAmoLeads` — кеш сделок
- `ModuleAmoPipeLines` — кеш воронок

## Пути на сервере

| Путь | Описание |
|------|----------|
| `/storage/usbdisk1/mikopbx/custom_modules/ModuleAmoCrm/` | Директория модуля |
| `/storage/usbdisk1/mikopbx/custom_modules/ModuleAmoCrm/db/` | Персистентные данные (БД) |
| `/usr/www/src/Core/Config/Globals.php` | MikoPBX bootstrap |

## Key Conventions

- Модели: наследовать `ModulesModelsBase`, таблицы с префиксом `m_Module*`
- Workers: наследовать `WorkerBase`, регистрировать в `AmoCrmConf::getModuleWorkers()`
- REST-маршруты: добавлять в `AmoCrmConf::getPBXCoreRESTAdditionalRoutes()`
- Логи: через `Lib/Logger.php` с ротацией (cesargb/php-log-rotation)
- Локализация: файлы переводов в `Messages/` (31 язык, управляется через Weblate)
- Схема БД создаётся из аннотаций Phalcon-моделей при `PbxExtensionSetup::installDB()`
- CSS-фреймворк — Semantic UI
