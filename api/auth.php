<?php

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['user' => current_user()]);
}

$data = json_input();
$action = $data['action'] ?? 'login';

if ($action === 'logout') {
    logout_user();
    json_response(['ok' => true]);
}

if ($action === 'login') {
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (login_user($email, $password)) {
        json_response(['ok' => true, 'user' => current_user()]);
    }

    json_response(['error' => 'E-mail ou senha inválidos.'], 401);
}

json_response(['error' => 'Ação inválida.'], 422);
