<?php

require __DIR__ . '/_bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && has_role(['admin'])) { json_response(['error'=>'Use a prévia administrativa antes de alterar inscrições.', 'admin_url'=>'index.php?page=match_manager'],428); }

ensure_interclasse_schema_updates();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = current_user();
    $teamColumns = 'id, event_id, name, class_name, group_name, representative_id, representative_name, modality, gender, created_at';
    $publicTeamColumns = 'id, event_id, name, class_name, group_name, modality, gender';

    if (!empty($_GET['id'])) {
        $stmt = db()->prepare("SELECT {$teamColumns} FROM teams WHERE id = ?");
        $stmt->execute([(int) $_GET['id']]);
        $team = $stmt->fetch();
        if (!$team) {
            json_response(['data' => null]);
        }

        if (can_manage_event_structure((int) $team['event_id'])) {
            json_response(['data' => $team]);
        }

        json_response(['data' => [
            'id' => (int) $team['id'],
            'event_id' => (int) $team['event_id'],
            'name' => $team['name'],
            'class_name' => $team['class_name'],
            'group_name' => $team['group_name'],
            'modality' => $team['modality'],
            'gender' => $team['gender'],
        ]]);
    }

    $eventId = (int) ($_GET['event_id'] ?? 0);
    $modality = $_GET['modality'] ?? 'futsal';
    if (!in_array($modality, allowed_modalities(), true)) {
        $modality = 'futsal';
    }
    $columns = can_manage_event_structure($eventId)
        ? $teamColumns
        : $publicTeamColumns;
    $stmt = db()->prepare("SELECT {$columns} FROM teams WHERE event_id = ? AND modality = ? ORDER BY group_name, name");
    $stmt->execute([$eventId, $modality]);
    json_response(['data' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    require_api_roles(['admin', 'professor']);
    $data = json_input();
    verify_api_csrf($data);
    $teamId = (int) ($data['id'] ?? 0);
    $teamStmt = db()->prepare('SELECT event_id, modality FROM teams WHERE id = ?');
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch();

    if (!$team || !can_manage_event_structure((int) $team['event_id'])) {
        json_response(['error' => 'Time inválido ou sem permissão.'], 403);
    }
    if (game_is_new_modality($team['modality'])) {
        $generated = db()->prepare('SELECT 1 FROM matches WHERE event_id = ? AND modality = ? LIMIT 1');
        $generated->execute([$team['event_id'], $team['modality']]);
        if ($generated->fetchColumn()) json_response(['error' => 'Inscrições bloqueadas após a geração dos confrontos.'], 409);
    }

    $gender = $data['gender'] ?? 'masculino';
    if (!in_array($gender, ['masculino', 'feminino'], true)) {
        json_response(['error' => 'Gênero inválido.'], 422);
    }

    $representativeName = trim($data['representative_name'] ?? '');
    $modality = $data['modality'] ?? $team['modality'];
    $groupName = uses_grouping($modality) ? strtoupper(trim($data['group_name'] ?? 'A')) : '';

    $stmt = db()->prepare('UPDATE teams SET name = ?, class_name = ?, group_name = ?, representative_id = ?, representative_name = ?, gender = ? WHERE id = ?');
    $stmt->execute([
        trim($data['name'] ?? ''),
        trim($data['class_name'] ?? ''),
        $groupName,
        $data['representative_id'] ?? null,
        $representativeName !== '' ? $representativeName : null,
        $gender,
        $teamId,
    ]);
    json_response(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    require_api_roles(['admin', 'professor']);
    verify_api_csrf();
    $teamId = (int) ($_GET['id'] ?? 0);
    $teamStmt = db()->prepare('SELECT event_id, modality FROM teams WHERE id = ?');
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch();

    if (!$team || !can_manage_event_structure((int) $team['event_id'])) {
        json_response(['error' => 'Time inválido ou sem permissão.'], 403);
    }
    if (game_is_new_modality($team['modality'])) {
        $generated = db()->prepare('SELECT 1 FROM matches WHERE event_id = ? AND modality = ? LIMIT 1');
        $generated->execute([$team['event_id'], $team['modality']]);
        if ($generated->fetchColumn()) json_response(['error' => 'Inscrições bloqueadas após a geração dos confrontos.'], 409);
    }

    try {
        db()->beginTransaction();

        $deletePlayers = db()->prepare('DELETE FROM players WHERE team_id = ?');
        $deletePlayers->execute([$teamId]);

        $stmt = db()->prepare('DELETE FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);

        db()->commit();
    } catch (PDOException $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        json_response(['error' => 'Falha ao excluir o time e seus jogadores vinculados.'], 500);
    }

    json_response(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

require_api_roles(['admin', 'professor']);
$data = json_input();
verify_api_csrf($data);
$eventId = (int) ($data['event_id'] ?? 0);

if (!$eventId || !can_manage_event_structure($eventId)) {
    json_response(['error' => 'Evento inválido ou sem permissão.'], 403);
}

$eventStmt = db()->prepare('SELECT status FROM events WHERE id = ?');
$eventStmt->execute([$eventId]);
$eventStatus = $eventStmt->fetchColumn();
if ($eventStatus === 'encerrado') {
    json_response(['error' => 'Evento encerrado. Não é possível cadastrar novos times.'], 403);
}

$className = '';
$classId = (int) ($data['class_id'] ?? 0);
if ($classId) {
    $classStmt = db()->prepare('SELECT name FROM classes WHERE id = ?');
    $classStmt->execute([$classId]);
    $classRow = $classStmt->fetch();
    $className = $classRow['name'] ?? '';
}

$modality = $data['modality'] ?? 'futsal';
if (!in_array($modality, allowed_modalities(), true)) {
    json_response(['error' => 'Modalidade invalida.'], 422);
}
$registered = db()->prepare('SELECT 1 FROM event_modalities WHERE event_id = ? AND modality = ?');
$registered->execute([$eventId, $modality]);
if (!$registered->fetchColumn()) json_response(['error' => 'Modalidade não cadastrada neste evento.'], 422);
if (game_is_new_modality($modality)) {
    $generated = db()->prepare('SELECT 1 FROM matches WHERE event_id = ? AND modality = ? LIMIT 1');
    $generated->execute([$eventId, $modality]);
    if ($generated->fetchColumn()) json_response(['error' => 'Inscrições bloqueadas após a geração dos confrontos.'], 409);
    if (!$className) json_response(['error' => 'Selecione uma turma cadastrada.'], 422);
    $duplicate = db()->prepare('SELECT 1 FROM teams WHERE event_id = ? AND modality = ? AND class_name = ? LIMIT 1');
    $duplicate->execute([$eventId, $modality, $className]);
    if ($duplicate->fetchColumn()) json_response(['error' => 'Turma já cadastrada nesta modalidade.'], 409);
}

// For mixed modalities, gender is not applicable
if (is_mixed_modality($modality)) {
    $gender = 'masculino';
} else {
    $gender = $data['gender'] ?? 'masculino';
    if (!in_array($gender, ['masculino', 'feminino'], true)) {
        json_response(['error' => 'Gênero inválido.'], 422);
    }
}

$groupName = uses_grouping($modality) ? auto_assign_group_name($eventId, $modality, $gender ?? 'masculino') : '';

$representativeName = trim($data['representative_name'] ?? '');

$stmt = db()->prepare('INSERT INTO teams (event_id, modality, gender, name, class_name, group_name, representative_id, representative_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    $eventId,
    $modality,
    $gender,
    $className ?: trim($data['name'] ?? ''),
    $className,
    $groupName,
    $data['representative_id'] ?? null,
    $representativeName !== '' ? $representativeName : null,
]);

$teamId = (int) db()->lastInsertId();
if (uses_grouping($modality)) {
    assign_team_groups($eventId, $modality, $gender ?? 'masculino');
}

json_response(['id' => $teamId], 201);
