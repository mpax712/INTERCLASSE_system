<?php

require __DIR__ . '/_bootstrap.php';

diagnostics_require_key();

$start = diagnostics_timer();
$sleepSeconds = max(0, min(10, (int) ($_GET['sleep'] ?? 2)));
$releaseLock = (int) ($_GET['close'] ?? 0) === 1;
$sessionKey = preg_replace('/[^a-zA-Z0-9,-]/', '', (string) ($_GET['sid'] ?? 'shared'));
if ($sessionKey === '') {
    $sessionKey = 'shared';
}

$sessionPath = session_save_path();
if ($sessionPath && !is_writable($sessionPath) && is_writable(sys_get_temp_dir())) {
    session_save_path(sys_get_temp_dir());
}

session_id('diag_' . substr($sessionKey, 0, 48));
session_start();

$_SESSION['diagnostic_hits'] = (int) ($_SESSION['diagnostic_hits'] ?? 0) + 1;
$_SESSION['diagnostic_last_hit_at'] = date('c');
$hits = (int) $_SESSION['diagnostic_hits'];

if ($releaseLock) {
    session_write_close();
}

if ($sleepSeconds > 0) {
    sleep($sleepSeconds);
}

if (!$releaseLock && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

diagnostics_json([
    'ok' => true,
    'test' => 'session_lock',
    'sid' => 'diag_' . substr($sessionKey, 0, 48),
    'sleep_seconds' => $sleepSeconds,
    'session_write_close_before_sleep' => $releaseLock,
    'session_hits' => $hits,
    'elapsed_ms' => diagnostics_elapsed_ms($start),
]);

