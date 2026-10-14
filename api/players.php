<?php

require __DIR__ . '/_bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && has_role(['admin'])) { json_response(['error'=>'Use a prévia administrativa antes de alterar inscrições.', 'admin_url'=>'index.php?page=match_manager'],428); }


if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = current_user();

    if (!empty($_GET['id'])) {
        $stmt = db()->prepare(
            'SELECT p.id, p.team_id, p.name, p.email, p.birth_date, p.jersey_number,
                    t.event_id, t.representative_id
             FROM players p
             JOIN teams t ON t.id = p.team_id
             WHERE p.id = ?'
        );
        $stmt->execute([(int) $_GET['id']]);
        $player = $stmt->fetch();
        if (!$player) {
            json_response(['data' => null]);
        }

        $canViewPrivate = can_view_private_roster((int) $player['event_id'], (int) ($player['representative_id'] ?? 0));

        $data = [
            'id' => (int) $player['id'],
            'team_id' => (int) $player['team_id'],
            'name' => $player['name'],
            'jersey_number' => $player['jersey_number'],
        ];

        if ($canViewPrivate) {
            $data['email'] = $player['email'];
            $data['birth_date'] = $player['birth_date'];
        }

        json_response(['data' => $data]);
    }

    $teamId = (int) ($_GET['team_id'] ?? 0);
    $teamStmt = db()->prepare('SELECT event_id, representative_id FROM teams WHERE id = ?');
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch();

    if (!$team) {
        json_response(['data' => []]);
    }

    $canViewPrivate = can_view_private_roster((int) $team['event_id'], (int) ($team['representative_id'] ?? 0));
    $columns = $canViewPrivate
        ? 'id, team_id, name, email, birth_date, jersey_number'
        : 'id, team_id, name, jersey_number';

    $stmt = db()->prepare("SELECT {$columns} FROM players WHERE team_id = ? ORDER BY name");
    $stmt->execute([$teamId]);
    json_response(['data' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = require_api_login();
    $data = $_SERVER['REQUEST_METHOD'] === 'PUT' ? json_input() : [];
    verify_api_csrf($data);
    $playerId = (int) ($data['id'] ?? ($_GET['id'] ?? 0));
    $stmt = db()->prepare(
        'SELECT p.*, t.representative_id, t.event_id, t.modality, e.status AS event_status, e.roster_edit_until
         FROM players p
         JOIN teams t ON t.id = p.team_id
         JOIN events e ON e.id = t.event_id
         WHERE p.id = ?'
    );
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();

    if (!$player) {
        json_response(['error' => 'Jogador não encontrado.'], 404);
    }

    $canManage = can_edit_team_roster((int) $player['event_id'], (int) ($player['representative_id'] ?? 0));
    if (!$canManage) {
        json_response(['error' => 'Permissão insuficiente.'], 403);
    }
    if (game_is_new_modality($player['modality'])) {
        $generated = db()->prepare('SELECT 1 FROM matches WHERE event_id = ? AND modality = ? LIMIT 1');
        $generated->execute([$player['event_id'], $player['modality']]);
        if ($generated->fetchColumn()) json_response(['error' => 'Participantes bloqueados após a geração dos confrontos.'], 409);
    }

    $rosterDeadlinePassed = $player['roster_edit_until'] && strtotime($player['roster_edit_until']) < time();
    $rosterClosed = !in_array($player['event_status'], ['rascunho', 'inscricoes'], true) || $rosterDeadlinePassed;
    if ($rosterClosed && !can_manage_event_structure((int) $player['event_id'])) {
        json_response(['error' => 'As inscrições deste evento não estão abertas para edição de jogadores.'], 403);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $delete = db()->prepare('DELETE FROM players WHERE id = ?');
        $delete->execute([$playerId]);
        json_response(['ok' => true]);
    }

    $update = db()->prepare('UPDATE players SET name = ?, email = ?, birth_date = ?, jersey_number = ? WHERE id = ?');
    $update->execute([
        trim($data['name'] ?? ''),
        trim($data['email'] ?? ''),
        $data['birth_date'] ?? null,
        $data['jersey_number'] ?? null,
        $playerId,
    ]);
    json_response(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$user = require_api_login();
$data = json_input();
verify_api_csrf($data);
$teamId = (int) ($data['team_id'] ?? 0);

$teamStmt = db()->prepare('SELECT t.*, e.status AS event_status, e.roster_edit_until FROM teams t JOIN events e ON e.id = t.event_id WHERE t.id = ?');
$teamStmt->execute([$teamId]);
$team = $teamStmt->fetch();

if (!$team) {
    json_response(['error' => 'Time não encontrado.'], 404);
}

$canManage = can_edit_team_roster((int) $team['event_id'], (int) ($team['representative_id'] ?? 0));
if (!$canManage) {
    json_response(['error' => 'Permissão insuficiente.'], 403);
}
if (game_is_new_modality($team['modality'])) {
    $generated = db()->prepare('SELECT 1 FROM matches WHERE event_id = ? AND modality = ? LIMIT 1');
    $generated->execute([$team['event_id'], $team['modality']]);
    if ($generated->fetchColumn()) json_response(['error' => 'Participantes bloqueados após a geração dos confrontos.'], 409);
    $participants = db()->prepare('SELECT COUNT(*) FROM players p JOIN teams t ON t.id = p.team_id WHERE t.event_id = ? AND t.modality = ?');
    $participants->execute([$team['event_id'], $team['modality']]);
    if ((int) $participants->fetchColumn() >= 32) json_response(['error' => 'Limite de 32 participantes nesta chave atingido.'], 422);
}

$rosterDeadlinePassed = $team['roster_edit_until'] && strtotime($team['roster_edit_until']) < time();
$rosterClosed = !in_array($team['event_status'], ['rascunho', 'inscricoes'], true) || $rosterDeadlinePassed;

// Prevent player registration when event is 'em_andamento' for all users
if ($team['event_status'] === 'em_andamento') {
    json_response(['error' => 'Não é possível cadastrar jogadores enquanto o evento estiver em andamento.'], 403);
}

if ($rosterClosed && !can_manage_event_structure((int) $team['event_id'])) {
    json_response(['error' => 'As inscrições deste evento não estão abertas para edição de jogadores.'], 403);
}

$countStmt = db()->prepare('SELECT COUNT(*) AS total FROM players WHERE team_id = ?');
$countStmt->execute([$teamId]);
$playerLimit = player_limit_for_team($team);
if ((int) $countStmt->fetch()['total'] >= $playerLimit) {
    json_response(['error' => 'Este time já atingiu o limite de ' . $playerLimit . ' jogador' . ($playerLimit > 1 ? 'es' : '') . '.'], 422);
}

$stmt = db()->prepare('INSERT INTO players (team_id, name, email, birth_date, jersey_number) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([
    $teamId,
    trim($data['name'] ?? ''),
    trim($data['email'] ?? ''),
    $data['birth_date'] ?? null,
    $data['jersey_number'] ?? null,
]);

json_response(['id' => (int) db()->lastInsertId()], 201);
