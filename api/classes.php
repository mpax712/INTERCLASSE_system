<?php

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = db()->prepare('SELECT * FROM classes WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        json_response(['data' => $stmt->fetch()]);
    }

    $stmt = db()->query('SELECT * FROM classes ORDER BY name');
    json_response(['data' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    require_api_roles(['admin']);
    $data = json_input();
    verify_api_csrf($data);
    $classId = (int) ($data['id'] ?? 0);

    if (!$classId) {
        json_response(['error' => 'ID da turma inválido.'], 422);
    }

    $name = trim($data['name'] ?? '');
    if (strlen($name) < 2) {
        json_response(['error' => 'Nome da turma deve ter pelo menos 2 caracteres.'], 422);
    }

    // Check if name already exists for another class
    $checkStmt = db()->prepare('SELECT id FROM classes WHERE name = ? AND id != ?');
    $checkStmt->execute([$name, $classId]);
    if ($checkStmt->fetch()) {
        json_response(['error' => 'Já existe uma turma com este nome.'], 422);
    }

    $stmt = db()->prepare('UPDATE classes SET name = ? WHERE id = ?');
    $stmt->execute([$name, $classId]);
    json_response(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    require_api_roles(['admin']);
    verify_api_csrf();
    $classId = (int) ($_GET['id'] ?? 0);

    if (!$classId) {
        json_response(['error' => 'ID da turma inválido.'], 422);
    }

    // Check if class is being used by any users or teams
    $userCheck = db()->prepare('SELECT COUNT(*) FROM users WHERE class_id = ?');
    $userCheck->execute([$classId]);
    $userCount = (int) $userCheck->fetchColumn();

    $teamCheck = db()->prepare('SELECT COUNT(*) FROM teams WHERE class_name = (SELECT name FROM classes WHERE id = ?)');
    $teamCheck->execute([$classId]);
    $teamCount = (int) $teamCheck->fetchColumn();

    if ($userCount > 0 || $teamCount > 0) {
        json_response(['error' => 'Não é possível excluir esta turma pois está sendo utilizada por usuários ou times.'], 422);
    }

    $stmt = db()->prepare('DELETE FROM classes WHERE id = ?');
    $stmt->execute([$classId]);
    json_response(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

require_api_roles(['admin']);
$data = json_input();
verify_api_csrf($data);
$name = trim($data['name'] ?? '');

if (strlen($name) < 2) {
    json_response(['error' => 'Nome da turma deve ter pelo menos 2 caracteres.'], 422);
}

try {
    $stmt = db()->prepare('INSERT INTO classes (name) VALUES (?)');
    $stmt->execute([$name]);
    json_response(['id' => (int) db()->lastInsertId()], 201);
} catch (PDOException $exception) {
    json_response(['error' => 'Já existe uma turma com este nome.'], 422);
}
