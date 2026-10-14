<?php

// API responses must remain JSON even when PHP emits a warning or fails during startup.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
$GLOBALS['api_output_base'] = ob_get_level();
$GLOBALS['api_response_sent'] = false;
ob_start();

function api_clear_output(): void
{
    while (ob_get_level() > $GLOBALS['api_output_base']) ob_end_clean();
}

function api_rollback_safely(): void
{
    try { if (function_exists('db') && db()->inTransaction()) db()->rollBack(); }
    catch (Throwable $rollbackError) { error_log('[API rollback] '.$rollbackError->getMessage()); }
}

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function json_response(array $data, int $status = 200): void
{
    if ($status >= 400) api_rollback_safely();
    $json = json_encode($data + ($status >= 400 ? ['ok' => false] : []), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    api_clear_output();
    $GLOBALS['api_response_sent'] = true;
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo $json;
    exit;
}

set_exception_handler(function (Throwable $exception): void {
    api_rollback_safely();
    $message = sprintf(
        '[API] %s: %s in %s:%d',
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );
    error_log($message);
    // A shutdown failure must not append a second document to a completed response.
    if ($GLOBALS['api_response_sent']) return;

    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents(
            $logDir . '/api-' . date('Y-m-d') . '.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL . $exception->getTraceAsString() . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    $payload = [
        'ok' => false,
        'error' => 'Erro interno no servidor.',
    ];

    $debug = false;
    try { $debug = function_exists('config') && (bool) (config()['app']['debug'] ?? false); } catch (Throwable $ignored) {}
    if ($debug) {
        $payload['debug'] = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    json_response($payload, 500);
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        error_log('[API deprecated] '.$message.' in '.$file.':'.$line);
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!$GLOBALS['api_response_sent'] && $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        api_clear_output();
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo '{"ok":false,"error":"Erro interno no servidor."}';
    }
});

// Install the error boundary before loading config, sessions, PDO or services.
require_once __DIR__ . '/../includes/match_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'score.php') {
    register_shutdown_function('public_cache_forget_all');
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_api_login(): array
{
    $user = current_user();

    if (!$user) {
        json_response(['error' => 'Autenticação necessária.'], 401);
    }

    return $user;
}

function require_api_roles(array $roles): array
{
    $user = require_api_login();

    if (!in_array($user['role'], $roles, true)) {
        json_response(['error' => 'Permissão insuficiente.'], 403);
    }

    return $user;
}

function verify_json_csrf(array $data): void
{
    start_app_session();
    $token = $data['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        json_response(['error' => 'Token CSRF inválido.'], 419);
    }
}

function verify_api_csrf(array $data = []): void
{
    start_app_session();
    $token = $data['csrf_token']
        ?? $_POST['csrf_token']
        ?? $_GET['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        json_response(['error' => 'Token CSRF inválido.'], 419);
    }
}

function fetch_match_payload(int $matchId): array
{
    $stmt = db()->prepare(
        'SELECT m.*, e.name AS event_name, COALESCE(hp.name, ht.name) AS home_name, COALESCE(ap.name, at.name) AS away_name
         FROM matches m
         JOIN events e ON e.id = m.event_id
         LEFT JOIN teams ht ON ht.id = m.home_team_id
         LEFT JOIN teams at ON at.id = m.away_team_id
         LEFT JOIN players hp ON hp.id = m.home_player_id
         LEFT JOIN players ap ON ap.id = m.away_player_id
         WHERE m.id = ?'
    );
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        json_response(['error' => 'Partida não encontrada.'], 404);
    }

    $match = match_historical_labels($match);
    $status = (string) $match['status'];
    $lastScoreLog = null;
    try {
        $lastLogStmt = db()->prepare('SELECT id, created_at FROM score_logs WHERE match_id = ? ORDER BY id DESC LIMIT 1');
        $lastLogStmt->execute([$matchId]);
        $lastScoreLog = $lastLogStmt->fetch();
    } catch (Throwable $exception) {
        $lastScoreLog = null;
    }

    return [
        'ok' => true,
        'id' => (int) $match['id'],
        'event_id' => (int) $match['event_id'],
        'event_name' => $match['event_name'],
        'modality' => $match['modality'],
        'stage' => $match['stage'],
        'status' => $status,
        'home_team_id' => $match['home_team_id'] ? (int) $match['home_team_id'] : null,
        'away_team_id' => $match['away_team_id'] ? (int) $match['away_team_id'] : null,
        'home_name' => $match['home_name'] ?? 'A definir',
        'away_name' => $match['away_name'] ?? 'A definir',
        'home_score' => (int) $match['home_score'],
        'away_score' => (int) $match['away_score'],
        'score_data' => decode_score_data($match['score_data'] ?? null, $match['modality']),
        'score_text' => match_score_text($match),
        'version' => (int) ($match['version'] ?? 0),
        'winner_player_id' => $match['winner_player_id'] ? (int) $match['winner_player_id'] : null,
        'cancel_reason' => $match['cancel_reason'] ?? null,
        'starts_at' => $match['starts_at'],
        'place' => $match['place'],
        'updated_at' => implode('#', [
            $match['status'],
            (int) $match['home_score'],
            (int) $match['away_score'],
            md5((string) ($match['score_data'] ?? '')),
            $lastScoreLog ? ($lastScoreLog['created_at'] . ':' . $lastScoreLog['id']) : $match['created_at'],
        ]),
        'can_edit' => false,
        'next_poll_ms' => $status === 'em_andamento' ? 20000 : 120000,
    ];
}
