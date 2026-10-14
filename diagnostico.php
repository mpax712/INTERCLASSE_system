<?php

require_once __DIR__ . '/includes/db.php';

$config = config();
$expectedKey = (string) ($config['app']['diagnostic_key'] ?? '');
$providedKey = (string) ($_GET['key'] ?? '');

if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: text/plain; charset=utf-8');

function diagnostic_line(string $label, string $value): void
{
    echo $label . ': ' . $value . PHP_EOL;
}

$dbConfig = $config['db'];

diagnostic_line('PHP_VERSION', PHP_VERSION);
diagnostic_line('PDO_PGSQL', extension_loaded('pdo_pgsql') ? 'ok' : 'missing');
diagnostic_line('DB_DRIVER', (string) ($dbConfig['driver'] ?? 'pgsql'));
diagnostic_line('DB_HOST', (string) $dbConfig['host']);
diagnostic_line('DB_DATABASE', (string) $dbConfig['database']);
diagnostic_line('DB_USERNAME', (string) $dbConfig['username']);
diagnostic_line('APP_DEBUG', !empty($config['app']['debug']) ? 'true' : 'false');

try {
    $pdo = db();
    diagnostic_line('DB_CONNECTION', 'ok');

    $tables = ['users', 'classes', 'events', 'event_modalities', 'teams', 'players', 'matches'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?"
        );
        $stmt->execute([$table]);
        diagnostic_line('TABLE_' . strtoupper($table), $stmt->fetchColumn() ? 'ok' : 'missing');
    }

    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    diagnostic_line('USERS_COUNT', (string) $stmt->fetchColumn());
} catch (Throwable $exception) {
    diagnostic_line('DB_CONNECTION', 'fail');
    diagnostic_line('ERROR_TYPE', get_class($exception));
    diagnostic_line('ERROR_MESSAGE', $exception->getMessage());
}
