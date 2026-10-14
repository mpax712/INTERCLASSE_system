<?php

require_once __DIR__ . '/../includes/match_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'score.php') {
    register_shutdown_function('public_cache_forget_all');
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
    if ($status >= 400 && db()->inTransaction()) {
        db()->rollBack();
    }
    http_response_code($status);
    if ($status >= 400 && !array_key_exists('ok', $data)) {
        $data = ['ok' => false] + $data;
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function (Throwable $exception): void {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    $message = sprintf(
        '[API] %s: %s in %s:%d',
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );
    error_log($message);

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

    if ((bool) (config()['app']['debug'] ?? false)) {
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
    return false;
});

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
