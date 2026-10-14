<?php

require_once __DIR__ . '/includes/match_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    register_shutdown_function('public_cache_forget_all');
}

$appConfig = config()['app'];
$debug = (bool) ($appConfig['debug'] ?? false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

set_exception_handler(function (Throwable $exception) use ($debug): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $request = ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? 'cli');
    $message = sprintf(
        '[WEB] %s | %s: %s in %s:%d',
        $request,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );
    error_log($message);

    $logDir = __DIR__ . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents(
            $logDir . '/app-' . date('Y-m-d') . '.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL . $exception->getTraceAsString() . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Erro interno</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <main class="container py-5">
            <div class="alert alert-danger shadow-sm">
                <h1 class="h4">O sistema encontrou um erro interno.</h1>
                <p class="mb-2">Tente novamente em instantes. Se persistir, envie o horario e a pagina acessada para o suporte.</p>
                <?php if ($debug): ?>
                    <pre class="small bg-white border rounded p-3 mb-0"><?= e($message) ?></pre>
                <?php endif; ?>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
});

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/includes/error_console.php';

if ($debug) {
    register_browser_console_error_handlers();
}

$routes = [
    'home' => 'pages/home.php',
    'login' => 'pages/login.php',
    'register' => 'pages/register.php',
    'logout' => 'pages/logout.php',
    'dashboard' => 'pages/dashboard.php',
    'events' => 'pages/events.php',
    'archived_events' => 'pages/archived_events.php',
    'event' => 'pages/event.php',
    'match_manager' => 'pages/match_manager.php',
    'teams' => 'pages/teams.php',
    'classes' => 'pages/classes.php',
    'create_event' => 'pages/create_event.php',
    'players' => 'pages/players.php',
    'standings' => 'pages/standings.php',
    'class_standings' => 'pages/class_standings.php',
    'match' => 'pages/match.php',
    'occurrences' => 'pages/occurrences.php',
    'occurrence_records' => 'pages/occurrence_records.php',
    'suggestions' => 'pages/suggestions.php',
    'suggestion_records' => 'pages/suggestion_records.php',
    'score_logs' => 'pages/score_logs.php',
    'scoreboard' => 'pages/scoreboard.php',
    'users' => 'pages/users.php',
    'export_csv' => 'pages/export_csv.php',
    'export_class_standings_csv' => 'pages/export_class_standings_csv.php',
];

$page = $_GET['page'] ?? 'home';
$file = $routes[$page] ?? $routes['home'];

if ($page === 'export_csv' || $page === 'export_class_standings_csv') {
    require __DIR__ . '/' . $file;
    exit;
}

ob_start();
require __DIR__ . '/includes/header.php';
if (has_role(['admin']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && in_array($page, ['classes','create_event','event','events','match_manager','teams','players'], true)) {
    require_once __DIR__ . '/includes/admin_ui.php';
    admin_ui_post_fallback($page, $_POST);
} else {
    require __DIR__ . '/' . $file;
}
require __DIR__ . '/includes/footer.php';
