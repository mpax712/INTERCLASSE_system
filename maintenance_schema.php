<?php

require_once __DIR__ . '/includes/match_helpers.php';

if (PHP_SAPI !== 'cli') {
    $key = $_GET['key'] ?? '';
    $expected = (string) (config()['app']['diagnostic_key'] ?? '');
    if ($expected === '' || $expected === 'manada-check' || !hash_equals($expected, $key)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
    $schema = file_get_contents(__DIR__ . '/database/supabase_schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Arquivo database/supabase_schema.sql não encontrado.');
    }
    $pdo->exec($schema);
} else {
    ensure_interclasse_schema_updates(true);
    ensure_occurrences_schema(true);
    ensure_score_logs_schema(true);
    ensure_suggestions_schema(true);
}

$message = 'Schema de manutencao verificado com sucesso em ' . date('Y-m-d H:i:s') . '.';

if (PHP_SAPI === 'cli') {
    echo $message . PHP_EOL;
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo $message;
