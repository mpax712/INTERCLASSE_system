<?php

require __DIR__ . '/_bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && has_role(['admin'])) { require_api_roles(['admin']); json_response(['error'=>'A exclusão de eventos exige análise administrativa.', 'admin_url'=>'index.php?page=match_manager'],428); }

ensure_interclasse_schema_updates();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventColumns = 'id, name, description, created_by, start_date, end_date, place, status, group_count, best_thirds_count, teams_advancing_per_group, advancement_mode, roster_edit_until, archived, created_at';

    if (!empty($_GET['id'])) {
        $stmt = db()->prepare("SELECT {$eventColumns} FROM events WHERE id = ?");
        $stmt->execute([(int) $_GET['id']]);
        json_response(['data' => $stmt->fetch()]);
    }

    $stmt = db()->query("SELECT {$eventColumns} FROM events ORDER BY start_date DESC");
    json_response(['data' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    require_api_roles(['admin']);
    $data = json_input();
    verify_api_csrf($data);
    $eventId = (int) ($data['id'] ?? 0);

    if (!$eventId || !can_manage_event_structure($eventId)) {
        json_response(['error' => 'Evento inválido ou sem permissão.'], 403);
    }

    // Handle archive/unarchive
    if (isset($data['archived'])) {
        try {
            $stmt = db()->prepare('UPDATE events SET archived = ? WHERE id = ?');
            $stmt->execute([$data['archived'] ? 1 : 0, $eventId]);
        } catch (PDOException $exception) {
            json_response(['error' => 'Falha ao atualizar o status de arquivamento.'], 500);
        }
        json_response(['ok' => true]);
    }

    $startDate = $data['start_date'] ?? date('Y-m-d');
    $endDate = $data['end_date'] ?? null;
    $rosterEditUntil = $data['roster_edit_until'] ?? null;

    // Validate dates to prevent unrealistic values
    $minDate = '1900-01-01';
    $maxDate = date('Y-m-d', strtotime('+10 years'));

    if ($startDate && ($startDate < $minDate || $startDate > $maxDate)) {
        json_response(['error' => 'Data inicial inválida. Deve estar entre 01/01/1900 e ' . date('d/m/Y', strtotime('+10 years')) . '.'], 422);
    }

    if ($endDate && ($endDate < $minDate || $endDate > $maxDate)) {
        json_response(['error' => 'Data final inválida. Deve estar entre 01/01/1900 e ' . date('d/m/Y', strtotime('+10 years')) . '.'], 422);
    }

    if ($rosterEditUntil) {
        $rosterDate = substr($rosterEditUntil, 0, 10);
        if ($rosterDate < $minDate || $rosterDate > $maxDate) {
            json_response(['error' => 'Data de prazo de elenco inválida. Deve estar entre 01/01/1900 e ' . date('d/m/Y', strtotime('+10 years')) . '.'], 422);
        }
    }

    $stmt = db()->prepare(
        'UPDATE events SET name = ?, description = ?, start_date = ?, end_date = ?, place = ?, status = ?, group_count = ?, best_thirds_count = ?, roster_edit_until = ? WHERE id = ?'
    );
    $stmt->execute([
        trim($data['name'] ?? ''),
        $data['description'] ?? '',
        $startDate,
        $endDate,
        $data['place'] ?? '',
        $data['status'] ?? 'inscricoes',
        (int) ($data['group_count'] ?? 2),
        (int) ($data['best_thirds_count'] ?? 0),
        $rosterEditUntil,
        $eventId,
    ]);

    json_response(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    require_api_roles(['admin']);
    ensure_score_logs_schema();
    ensure_occurrences_schema();
    $data = json_input();
    verify_api_csrf($data);
    $eventId = (int) ($_GET['id'] ?? 0);

    if (!$eventId || !can_manage_event_structure($eventId)) {
        json_response(['error' => 'Evento inválido ou sem permissão.'], 403);
    }

    try {
        db()->beginTransaction();

        $matchIdsStmt = db()->prepare('SELECT id FROM matches WHERE event_id = ?');
        $matchIdsStmt->execute([$eventId]);
        $matchIds = array_map('intval', array_column($matchIdsStmt->fetchAll(), 'id'));

        if ($matchIds) {
            $placeholders = implode(',', array_fill(0, count($matchIds), '?'));

            $deleteScoreLogs = db()->prepare("DELETE FROM score_logs WHERE match_id IN ($placeholders)");
            $deleteScoreLogs->execute($matchIds);
        }

        $deleteOccurrences = db()->prepare('DELETE FROM occurrences WHERE event_id = ?');
        $deleteOccurrences->execute([$eventId]);

        $deleteMatches = db()->prepare('DELETE FROM matches WHERE event_id = ?');
        $deleteMatches->execute([$eventId]);

        $deleteClassModalityPoints = db()->prepare(
            'DELETE FROM class_modality_points
             WHERE class_standings_id IN (SELECT id FROM class_standings WHERE event_id = ?)'
        );
        $deleteClassModalityPoints->execute([$eventId]);

        $deleteClassStandings = db()->prepare('DELETE FROM class_standings WHERE event_id = ?');
        $deleteClassStandings->execute([$eventId]);

        $deletePlayers = db()->prepare(
            'DELETE FROM players
             WHERE team_id IN (SELECT id FROM teams WHERE event_id = ?)'
        );
        $deletePlayers->execute([$eventId]);

        $deleteTeams = db()->prepare('DELETE FROM teams WHERE event_id = ?');
        $deleteTeams->execute([$eventId]);

        $deleteEventModalities = db()->prepare('DELETE FROM event_modalities WHERE event_id = ?');
        $deleteEventModalities->execute([$eventId]);

        $deleteEvent = db()->prepare('DELETE FROM events WHERE id = ?');
        $deleteEvent->execute([$eventId]);

        db()->commit();
        public_cache_forget_all();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        json_response(['error' => 'Falha ao excluir o evento e seus jogadores vinculados.'], 500);
    }

    json_response(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$user = require_api_roles(['admin']);
$data = json_input();
verify_api_csrf($data);
$name = trim($data['name'] ?? '');
$startDate = $data['start_date'] ?? '';
$endDate = $data['end_date'] ?? null;
$rosterEditUntil = $data['roster_edit_until'] ?? null;
$modalities = $data['modalities'] ?? [];

if (!$name || !$startDate || !$modalities) {
    json_response(['error' => 'Nome, data inicial e modalidades são obrigatórios.'], 422);
}
if (!is_array($modalities) || count($modalities) !== count(array_filter($modalities, static fn($value): bool => is_string($value) && in_array($value, allowed_modalities(), true)))) {
    json_response(['error' => 'Lista de modalidades inválida.'], 422);
}
$modalities = array_values(array_unique($modalities));

// Validate dates to prevent unrealistic values
$minDate = '1900-01-01';
$maxDate = date('Y-m-d', strtotime('+10 years'));

if ($startDate && ($startDate < $minDate || $startDate > $maxDate)) {
    json_response(['error' => 'Data inicial inválida. Deve estar entre 01/01/1900 e ' . date('d/m/Y', strtotime('+10 years')) . '.'], 422);
}

if ($endDate && ($endDate < $minDate || $endDate > $maxDate)) {
    json_response(['error' => 'Data final inválida. Deve estar entre 01/01/1900 e ' . date('d/m/Y', strtotime('+10 years')) . '.'], 422);
}

if ($rosterEditUntil) {
    $rosterDate = substr($rosterEditUntil, 0, 10);
    if ($rosterDate < $minDate || $rosterDate > $maxDate) {
        json_response(['error' => 'Data de prazo de elenco inválida. Deve estar entre 01/01/1900 e ' . date('d/m/Y', strtotime('+10 years')) . '.'], 422);
    }
}

try {
    db()->beginTransaction();
    $stmt = db()->prepare(
        'INSERT INTO events (name, description, created_by, start_date, end_date, place, group_count, best_thirds_count, roster_edit_until, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "inscricoes")'
    );
    $stmt->execute([$name, $data['description'] ?? '', $user['id'], $startDate, $endDate, $data['place'] ?? '', (int) ($data['group_count'] ?? 2), (int) ($data['best_thirds_count'] ?? 0), $data['roster_edit_until'] ?? null]);
    $eventId = (int) db()->lastInsertId();
    $insertModality = db()->prepare('INSERT INTO event_modalities (event_id, modality, score_type, set_best_of, set_points, quarters_count, max_players_per_team, bracket_type, rules_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $playerLimits = is_array($data['player_limits'] ?? null) ? $data['player_limits'] : [];
    $rulesByModality = is_array($data['rules'] ?? null) ? $data['rules'] : [];
    foreach ($modalities as $modality) {
        $playerLimit = max(1, (int) ($playerLimits[$modality] ?? default_player_limit_for_modality($modality)));
        if (game_is_new_modality($modality)) $playerLimit = min(32, $playerLimit);
        $submittedRules = $rulesByModality[$modality] ?? [];
        if (!is_array($submittedRules)) throw new InvalidArgumentException('Regras da modalidade inválidas.');
        $rules = game_is_new_modality($modality) ? validate_game_rules($modality, $submittedRules) : null;
        $insertModality->execute([$eventId, $modality, score_type_for_modality($modality), $modality === 'volei' ? 3 : null, $modality === 'volei' ? 25 : null, $modality === 'basquete' ? 4 : null, $playerLimit, default_bracket_type_for_modality($modality), $rules === null ? null : json_encode($rules, JSON_UNESCAPED_UNICODE)]);
    }
    db()->commit();
} catch (InvalidArgumentException $exception) {
    if (db()->inTransaction()) db()->rollBack();
    json_response(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if (db()->inTransaction()) db()->rollBack();
    json_response(['error' => 'Não foi possível criar o evento. Tente novamente.'], 500);
}

json_response(['id' => $eventId], 201);
