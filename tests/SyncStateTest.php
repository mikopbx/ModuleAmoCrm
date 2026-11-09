<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$stateFile = $root . '/Lib/SyncState.php';
if (!file_exists($stateFile)) {
    fwrite(STDERR, "FAIL: missing production file {$stateFile}\n");
    exit(1);
}
require_once $stateFile;

use Modules\ModuleAmoCrm\Lib\SyncState;

function assertSyncState($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n"
        );
        exit(1);
    }
}

// --- needRestart (#61) ---
$restartCases = [
    'token refresh does not restart workers' => [['authData'], false],
    'single cursor update does not restart' => [['lastLeadsSyncTime'], false],
    'end of init (3 cursors + state) does not restart' => [
        ['lastContactsSyncTime', 'lastCompaniesSyncTime', 'lastLeadsSyncTime', 'initSyncTime',
         'initSyncPageContacts', 'initSyncPageLeads', 'initSyncPageCompanies'],
        false,
    ],
    'init page progress does not restart' => [['initSyncPageLeads'], false],
    'cdr offset does not restart' => [['offsetCdr'], false],
    'webhook token alone does not restart' => [['tokenForAmo'], false],
    'new portal authorization restarts' => [['authData', 'portalId'], true],
    'settings form change restarts' => [['panelIsEnable'], true],
    'webhook port change restarts' => [['webhookPort'], true],
    'mixed sync and real setting restarts' => [['offsetCdr', 'useInterception'], true],
    'unknown change set (empty) restarts as before' => [[], true],
];
foreach ($restartCases as $message => [$fields, $expected]) {
    assertSyncState($expected, SyncState::needRestart($fields), $message);
}

// authData со значением: обновление токена — без перезапуска, очистка — с перезапуском.
$tokenJson = '{"token_type":"Bearer","expires_in":1793900000,"access_token":"a","refresh_token":"r"}';
assertSyncState(false, SyncState::needRestart(['authData'], $tokenJson), 'token refresh with value does not restart');
assertSyncState(true, SyncState::needRestart(['authData'], ''), 'cleared token restarts workers');
assertSyncState(true, SyncState::needRestart(['authData'], '  '), 'blank token restarts workers');
assertSyncState(false, SyncState::needRestart(['offsetCdr'], ''), 'empty token without authData change does not restart');

$now = 1793900000;
$synced = ['lastContactsSyncTime' => 100, 'lastLeadsSyncTime' => 100, 'lastCompaniesSyncTime' => 100, 'portalId' => 7];

// --- resolve (#62) ---
$r = SyncState::resolve($synced + ['initSyncTime' => 0], $now);
assertSyncState(0, $r['initTime'], 'all cursors set, no init -> incremental');
assertSyncState(false, $r['startNew'], 'incremental does not start init');

$r = SyncState::resolve($synced, $now);
assertSyncState(0, $r['initTime'], 'legacy settings without initSyncTime -> incremental');

$r = SyncState::resolve(SyncState::startSettings(1793820000), $now);
assertSyncState(1793820000, $r['initTime'], 'init started by cron keeps its initTime');
assertSyncState(false, $r['startNew'], 'init started by cron is not restarted');
assertSyncState(false, $r['resumed'], 'fresh init is not a resume');

// Перезапуск посреди сделок: контакты пройдены, сделки на 495 странице.
$interrupted = [
    'initSyncTime' => 1793820000,
    'lastContactsSyncTime' => 1793823000, 'lastLeadsSyncTime' => 0, 'lastCompaniesSyncTime' => 0,
    'initSyncPageContacts' => SyncState::PAGE_DONE, 'initSyncPageLeads' => 495, 'initSyncPageCompanies' => 0,
];
$r = SyncState::resolve($interrupted, $now);
assertSyncState(1793820000, $r['initTime'], 'restart during init keeps the same initTime');
assertSyncState(true, $r['resumed'], 'restart during init is a resume');
assertSyncState(SyncState::PAGE_DONE, $r['pages']['contacts'], 'completed contacts pass is kept');
assertSyncState(495, $r['pages']['leads'], 'leads page is kept');
assertSyncState(false, SyncState::isInitDone($r['pages']), 'interrupted init is not done');

// Старое состояние бага: контакты синхронизированы, сделки = 0, initSyncTime нет.
$legacyBroken = ['lastContactsSyncTime' => 1793829677, 'lastLeadsSyncTime' => 0, 'lastCompaniesSyncTime' => 0];
$r = SyncState::resolve($legacyBroken, $now);
assertSyncState(true, $r['startNew'], 'zero cursor without init starts init instead of from=0 increment');
assertSyncState($now, $r['initTime'], 'new init uses current time');

$r = SyncState::resolve([], $now);
assertSyncState(true, $r['startNew'], 'first install starts init');

// --- start/finish settings ---
$start = SyncState::startSettings(1793820000);
assertSyncState(1793820000, $start['initSyncTime'], 'start sets initSyncTime');
assertSyncState(false, array_key_exists('lastContactsSyncTime', $start), 'start keeps contacts cursor (AmoCdrDaemon keeps working)');
assertSyncState(false, array_key_exists('lastLeadsSyncTime', $start), 'start keeps leads cursor');
assertSyncState(0, $start['initSyncPageLeads'], 'start zeroes pages');

// Ночной init поверх синхронизированного портала: init-режим, курсоры не тронуты.
$nightly = array_merge($synced, SyncState::startSettings(1793820000));
assertSyncState(100, $nightly['lastContactsSyncTime'], 'nightly init keeps contacts cursor > 0');
$r = SyncState::resolve($nightly, $now);
assertSyncState(1793820000, $r['initTime'], 'nightly init with kept cursors is init mode');
assertSyncState(false, $r['startNew'], 'nightly init with kept cursors is not restarted');

// --- canStartScheduledInit (cron) ---
assertSyncState(true, SyncState::canStartScheduledInit($synced + ['initSyncTime' => 0], $now), 'cron starts init when idle');
assertSyncState(true, SyncState::canStartScheduledInit($synced, $now), 'cron starts init on legacy settings');
assertSyncState(false, SyncState::canStartScheduledInit($interrupted + ['portalId' => 7], 1793820000 + 3600), 'cron does not restart running init');
assertSyncState(true, SyncState::canStartScheduledInit($interrupted + ['portalId' => 7], 1793820000 + SyncState::MAX_INIT_AGE + 1), 'cron replaces expired init');
assertSyncState(false, SyncState::canStartScheduledInit([], $now), 'cron does not start init without settings');
assertSyncState(false, SyncState::canStartScheduledInit(['portalId' => 0, 'initSyncTime' => 0], $now), 'cron does not start init without connected portal');
assertSyncState(SyncState::startSettings(0), SyncState::abortSettings(), 'abort settings equal start settings with initTime 0');

// --- abortSettings: просроченный init сбрасывается без выставления курсоров ---
$abort = SyncState::abortSettings();
assertSyncState(0, $abort['initSyncTime'], 'abort clears initSyncTime');
assertSyncState(0, $abort['initSyncPageLeads'], 'abort clears pages');
assertSyncState(false, array_key_exists('lastContactsSyncTime', $abort), 'abort keeps contacts cursor (CDR stays blocked on first install)');
assertSyncState(false, SyncState::needRestart(array_keys($abort)), 'abort settings do not restart workers');
// Первая установка (курсоры 0) после прерывания — снова init; ночной init (курсоры > 0) — инкремент.
$r = SyncState::resolve(array_merge(['lastContactsSyncTime' => 0, 'lastLeadsSyncTime' => 0, 'lastCompaniesSyncTime' => 0], $abort), $now);
assertSyncState(true, $r['startNew'], 'aborted first-install init starts a new init');
$r = SyncState::resolve(array_merge($synced, $abort), $now);
assertSyncState(0, $r['initTime'], 'aborted nightly init falls back to incremental');

$finish = SyncState::finishSettings(1793820000);
assertSyncState(0, $finish['initSyncTime'], 'finish clears initSyncTime');
assertSyncState(1793820000, $finish['lastContactsSyncTime'], 'finish sets cursors to init start');
assertSyncState(0, $finish['initSyncPageCompanies'], 'finish clears pages');
assertSyncState(false, SyncState::needRestart(array_keys($finish)), 'finish settings do not restart workers');
assertSyncState(false, SyncState::needRestart(array_keys($start)), 'start settings do not restart workers');

// --- isInitDone ---
assertSyncState(true, SyncState::isInitDone(['contacts' => -1, 'leads' => -1, 'companies' => -1]), 'all done');
assertSyncState(false, SyncState::isInitDone(['contacts' => -1, 'leads' => -1]), 'missing entity is not done');

// --- resumePage / pageFromUrl ---
assertSyncState(1, SyncState::resumePage(0), 'not started -> page 1');
assertSyncState(1, SyncState::resumePage(SyncState::PAGE_DONE), 'done marker -> page 1');
assertSyncState(493, SyncState::resumePage(495), 'resume overlaps saved page by RESUME_OVERLAP_PAGES');
assertSyncState(1, SyncState::resumePage(2), 'overlap does not go below page 1');
assertSyncState(1, SyncState::resumePage(3), 'overlap from page 3 -> page 1');

// --- shouldSaveProgress ---
assertSyncState(false, SyncState::shouldSaveProgress(1), 'page 1 progress is not saved');
assertSyncState(true, SyncState::shouldSaveProgress(SyncState::SAVE_PROGRESS_EVERY_PAGES), 'every Nth page is saved');
assertSyncState(false, SyncState::shouldSaveProgress(SyncState::SAVE_PROGRESS_EVERY_PAGES + 1), 'N+1 page is not saved');
assertSyncState(false, SyncState::shouldSaveProgress(0), 'page 0 is not saved');

// --- isInitExpired ---
assertSyncState(false, SyncState::isInitExpired(0, $now), 'no init is never expired');
assertSyncState(false, SyncState::isInitExpired($now - 3600, $now), 'fresh init is not expired');
assertSyncState(false, SyncState::isInitExpired($now - SyncState::MAX_INIT_AGE, $now), 'init exactly at max age is not expired');
assertSyncState(true, SyncState::isInitExpired($now - SyncState::MAX_INIT_AGE - 1, $now), 'init older than max age is expired');
assertSyncState(true, SyncState::MAX_INIT_AGE < 86400, 'max init age is less than cron period');
assertSyncState(1, SyncState::pageFromUrl('https://x/api/v4/leads?order%5Bid%5D=desc&limit=249&with=contacts'), 'no page param -> 1');
assertSyncState(495, SyncState::pageFromUrl('https://x/api/v4/leads?order[id]=desc&limit=249&with=contacts&page=495'), 'page param parsed');

// --- field names ---
assertSyncState('lastLeadsSyncTime', SyncState::cursorField('leads'), 'cursor field name');
assertSyncState('initSyncPageCompanies', SyncState::pageField('companies'), 'page field name');

echo "OK SyncStateTest\n";
