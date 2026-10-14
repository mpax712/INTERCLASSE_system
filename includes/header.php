<?php
require_once __DIR__ . '/helpers.php';
$user = current_user();
$appName = config()['app']['name'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/app-cache.js?v=<?= filemtime(__DIR__ . '/../assets/js/app-cache.js') ?>"></script>
    <script>
        window.addEventListener('error', function (event) {
            console.error('[Sistema Interclasse]', {
                type: 'JavaScriptError',
                message: event.message,
                file: event.filename,
                line: event.lineno,
                column: event.colno,
                error: event.error && event.error.stack ? event.error.stack : event.error
            });
        });

        window.addEventListener('unhandledrejection', function (event) {
            const reason = event.reason;
            console.error('[Sistema Interclasse]', {
                type: 'UnhandledPromiseRejection',
                message: reason && reason.message ? reason.message : String(reason),
                stack: reason && reason.stack ? reason.stack : null
            });
        });

        if (window.fetch) {
            const nativeFetch = window.fetch.bind(window);
            window.fetch = function () {
                const request = arguments[0];
                const requestUrl = typeof request === 'string' ? request : (request && request.url ? request.url : 'fetch');

                return nativeFetch.apply(window, arguments).then(function (response) {
                    if (!response.ok) {
                        response.clone().text().then(function (body) {
                            console.error('[Sistema Interclasse]', {
                                type: 'FetchError',
                                url: requestUrl,
                                status: response.status,
                                statusText: response.statusText,
                                response: body.slice(0, 1000)
                            });
                        }).catch(function (error) {
                            console.error('[Sistema Interclasse]', {
                                type: 'FetchError',
                                url: requestUrl,
                                status: response.status,
                                statusText: response.statusText,
                                message: error.message
                            });
                        });
                    }

                    return response;
                }).catch(function (error) {
                    console.error('[Sistema Interclasse]', {
                        type: 'FetchNetworkError',
                        url: requestUrl,
                        message: error.message,
                        stack: error.stack || null
                    });
                    throw error;
                });
            };
        }
    </script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top app-navbar">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php"><img src="image/Logo.jpg" alt="<?= e($appName) ?>" height="60"></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 app-main-nav">
                <li class="nav-item"><a class="nav-link" href="index.php">Início</a></li>
                <li class="nav-item"><a class="nav-link nav-link-priority" href="index.php?page=events">Interclasse</a></li>
                <?php if ($user): ?>
                    <li class="nav-item"><a class="nav-link" href="index.php?page=suggestions">Sugestões</a></li>
                <?php endif; ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Tabelas</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="index.php?page=standings&view=points">Tabela de pontuação</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="index.php?page=class_standings">Classificação geral das turmas</a></li>
                    </ul>
                </li>
                <?php if ($user && in_array($user['role'], ['admin', 'professor'], true)): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Gestão</a>
                        <ul class="dropdown-menu">
                            <?php if ($user['role'] === 'admin'): ?>
                                <li><h6 class="dropdown-header">Operação</h6></li>
                                <li><a class="dropdown-item" href="index.php?page=dashboard">Painel geral</a></li>
                                <li><a class="dropdown-item" href="index.php?page=occurrence_records">Ocorrências</a></li>
                                <li><a class="dropdown-item" href="index.php?page=score_logs">Registros de placar</a></li>
                                <li><a class="dropdown-item" href="index.php?page=suggestion_records">Sugestões recebidas</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Cadastros</h6></li>
                                <li><a class="dropdown-item" href="index.php?page=users">Usuários</a></li>
                                <li><a class="dropdown-item" href="index.php?page=classes">Turmas</a></li>
                                <li><a class="dropdown-item" href="index.php?page=archived_events">Eventos arquivados</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item" href="index.php?page=events">Meus eventos</a></li>
                                <li><a class="dropdown-item" href="index.php?page=occurrence_records">Ocorrências</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>
                <?php if ($user && $user['role'] === 'representante'): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Representante</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="index.php?page=events">Eventos com inscrições</a></li>
                            <li><a class="dropdown-item" href="index.php?page=standings&view=points">Acompanhar tabelas</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2 app-user-area">
                <?php if ($user): ?>
                    <span class="navbar-text small app-user-badge"><?= e($user['name']) ?> · <?= e(role_label($user['role'])) ?></span>
                    <a class="btn btn-outline-light btn-sm" href="index.php?page=logout">Sair</a>
                <?php else: ?>
                    <a class="btn btn-outline-light btn-sm" href="index.php?page=login">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
<main class="container py-4" <?php if (in_array($page ?? '', ['event', 'match_manager'], true)): ?>id="event-workspace" data-page="<?= e($page) ?>" data-event-id="<?= (int) ($_GET['event_id'] ?? $_GET['id'] ?? 0) ?>"<?php endif; ?>>
<?php if ($flash = flash()): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" data-workspace-message="<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php
if (!$user && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
