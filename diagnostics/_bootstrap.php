<?php

require_once __DIR__ . '/../includes/db.php';

function diagnostics_require_key(): void
{
    $config = config();
    $expectedKey = (string) ($config['app']['diagnostic_key'] ?? '');
    $providedKey = (string) ($_GET['key'] ?? '');

    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(404);
        exit('Not found');
    }
}

function diagnostics_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function diagnostics_timer(): float
{
    return microtime(true);
}

function diagnostics_elapsed_ms(float $start): int
{
    return (int) round((microtime(true) - $start) * 1000);
}

