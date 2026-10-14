<?php

require __DIR__ . '/_bootstrap.php';

diagnostics_require_key();

$start = diagnostics_timer();
diagnostics_json([
    'ok' => true,
    'test' => 'static_php_without_db',
    'php_version' => PHP_VERSION,
    'time' => date('c'),
    'elapsed_ms' => diagnostics_elapsed_ms($start),
]);

