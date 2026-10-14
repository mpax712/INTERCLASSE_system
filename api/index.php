<?php
// One Vercel function serves the pages and the existing API handlers.
// Dispatch only named public handlers; never include a user-supplied path.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$handlers = ['admin_forms','admin_operations','auth','classes','class_standings',
    'events','export','games','players','score','teams','users'];
$endpoint = null;
if (preg_match('~^/api/([a-z_]+)\.php$~D', $path, $matches) && $matches[1] !== 'index') {
    $endpoint = $matches[1];
} elseif (in_array($path, ['/', '/index.php', '/api/index.php'], true)) {
    $endpoint = $_GET['__api_route'] ?? null;
}
unset($_GET['__api_route']);
if ($endpoint !== null) {
    if (!is_string($endpoint) || !in_array($endpoint, $handlers, true)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"Rota não encontrada."}';
        return;
    }
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/api/'.$endpoint.'.php';
    require __DIR__.'/'.$endpoint.'.php';
    return;
}
// Also usable as the PHP built-in server router when validating a deployment.
if (PHP_SAPI === 'cli-server' && str_starts_with($path, '/assets/') && !str_ends_with($path, '.php')) {
    return false;
}
if (!in_array($path, ['/', '/index.php', '/api/index.php'], true)) {
    http_response_code(404);
    return;
}
require __DIR__.'/../index.php';
