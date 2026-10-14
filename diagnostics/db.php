<?php

require __DIR__ . '/_bootstrap.php';

diagnostics_require_key();

$start = diagnostics_timer();
$queries = [];

try {
    $pdo = db();

    $queryStart = diagnostics_timer();
    $stmt = $pdo->query('SELECT 1 AS ok');
    $queries['select_1_ms'] = diagnostics_elapsed_ms($queryStart);
    $selectOk = (int) ($stmt->fetch()['ok'] ?? 0);

    $tables = ['events', 'event_modalities', 'teams', 'players', 'matches', 'score_logs', 'occurrences'];
    $counts = [];
    foreach ($tables as $table) {
        $queryStart = diagnostics_timer();
        $stmt = $pdo->query('SELECT COUNT(*) AS total FROM ' . $table);
        $counts[$table] = (int) ($stmt->fetch()['total'] ?? 0);
        $queries['count_' . $table . '_ms'] = diagnostics_elapsed_ms($queryStart);
    }

    diagnostics_json([
        'ok' => $selectOk === 1,
        'test' => 'database_light_queries',
        'counts' => $counts,
        'query_times_ms' => $queries,
        'elapsed_ms' => diagnostics_elapsed_ms($start),
    ]);
} catch (Throwable $exception) {
    diagnostics_json([
        'ok' => false,
        'test' => 'database_light_queries',
        'error_type' => get_class($exception),
        'error_message' => $exception->getMessage(),
        'elapsed_ms' => diagnostics_elapsed_ms($start),
    ], 500);
}
