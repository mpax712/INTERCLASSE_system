<?php
require_roles(['admin']);
ensure_interclasse_schema_updates();

$requestedModality = (string) ($_GET['modality'] ?? $_POST['modality'] ?? '');
if (game_is_new_modality($requestedModality)) {
    require __DIR__ . '/game_manager.php';
    return;
}

$allowedStages = ['grupo', 'repescagem', 'preliminar', 'dezesseis_avos', 'oitavas', 'quartas', 'semifinal', 'final', 'individual'];

function match_manager_group_value(?string $value): string
{
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/[^A-Z0-9_-]/', '', $value);

    return substr($value ?? '', 0, 10);
}

function match_manager_redirect(int $eventId, string $modality, string $gender): void
{
    redirect('index.php?page=match_manager&event_id=' . $eventId . '&modality=' . urlencode($modality) . '&gender=' . urlencode($gender));
}

function match_manager_next_group(array $groups): string
{
    $letters = array_values(array_filter($groups, static fn(string $group): bool => preg_match('/^[A-Z]$/', $group) === 1));
    sort($letters);

    if (!$letters) {
        return 'A';
    }

    $last = end($letters);
    if ($last >= 'Z') {
        return 'G' . (count($groups) + 1);
    }

    return chr(ord($last) + 1);
}

function match_manager_team_options(array $teams): string
{
    $html = '<option value="">Selecione um time</option>';
    foreach ($teams as $team) {
        $groupLabel = ($team['group_name'] ?? '') !== '' ? ' · Grupo ' . $team['group_name'] : ' · Sem grupo';
        $html .= '<option value="' . (int) $team['id'] . '" data-group="' . e($team['group_name'] ?? '') . '">' . e($team['name'] . $groupLabel) . '</option>';
    }

    return $html;
}

function match_manager_validate_team(int $teamId, int $eventId, string $modality, string $gender): ?array
{
    if ($teamId <= 0) {
        return null;
    }

    $sql = 'SELECT id, name, group_name FROM teams WHERE id = ? AND event_id = ? AND modality = ?';
    $params = [$teamId, $eventId, $modality];

    if (!is_mixed_modality($modality)) {
        $sql .= ' AND gender = ?';
        $params[] = $gender;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $team = $stmt->fetch();

    return $team ?: null;
}

function match_manager_stage_rank(string $stage): int
{
    $ranks = [
        'grupo' => 10,
        'repescagem' => 20,
        'dezesseis_avos' => 30,
        'oitavas' => 40,
        'quartas' => 50,
        'preliminar' => 55,
        'semifinal' => 60,
        'final' => 70,
        'individual' => 10,
    ];

    return $ranks[$stage] ?? 0;
}

function match_manager_prune_future_scheduled_matches(int $eventId, string $modality, string $gender, string $stage): int
{
    $currentRank = match_manager_stage_rank($stage);
    if ($currentRank <= 0 || in_array($stage, ['final', 'individual'], true)) {
        return 0;
    }

    $futureStages = array_keys(array_filter([
        'grupo' => 10,
        'repescagem' => 20,
        'dezesseis_avos' => 30,
        'oitavas' => 40,
        'quartas' => 50,
        'preliminar' => 55,
        'semifinal' => 60,
        'final' => 70,
    ], static fn(int $rank): bool => $rank > $currentRank));

    if (!$futureStages) {
        return 0;
    }

    $stagePlaceholders = implode(',', array_fill(0, count($futureStages), '?'));
    $params = array_merge([$eventId, $modality], $futureStages);
    $genderSql = '';
    if (!is_mixed_modality($modality)) {
        $genderSql = ' AND gender = ?';
        $params[] = $gender;
    }

    $locked = db()->prepare(
        "SELECT COUNT(*) FROM matches
         WHERE event_id = ? AND modality = ? AND stage IN ($stagePlaceholders)
         AND status <> 'agendada' $genderSql"
    );
    $locked->execute($params);
    if ((int) $locked->fetchColumn() > 0) {
        throw new RuntimeException('Nao e possivel alterar esta estrutura porque ha fase futura em andamento, finalizada ou W.O.');
    }

    $delete = db()->prepare(
        "DELETE FROM matches
         WHERE event_id = ? AND modality = ? AND stage IN ($stagePlaceholders)
         AND status = 'agendada' $genderSql"
    );
    $delete->execute($params);

    return $delete->rowCount();
}

function match_manager_score_cache_forget(int $matchId): void
{
    $file = __DIR__ . '/../storage/cache/public/score:' . $matchId . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
}

function match_manager_future_stages_after(string $stage): array
{
    $currentRank = match_manager_stage_rank($stage);
    if ($currentRank <= 0) {
        return [];
    }

    $stageRanks = [
        'repescagem' => 20,
        'dezesseis_avos' => 30,
        'oitavas' => 40,
        'quartas' => 50,
        'preliminar' => 55,
        'semifinal' => 60,
        'final' => 70,
    ];

    return array_keys(array_filter($stageRanks, static fn(int $rank): bool => $rank > $currentRank));
}

function match_manager_reset_match_payload(string $modality): array
{
    return [
        'home_score' => 0,
        'away_score' => 0,
        'score_data' => json_encode(default_score_data($modality), JSON_UNESCAPED_UNICODE),
        'status' => 'agendada',
        'winner_team_id' => null,
    ];
}

function match_manager_corrected_score_payload(array $match, string $winnerSide, ?int $postedHomeScore, ?int $postedAwayScore): array
{
    $modality = (string) $match['modality'];
    $scoreType = score_type_for_modality($modality);
    $oldScoreData = decode_score_data($match['score_data'] ?? null, $modality);
    $winnerTeamId = $winnerSide === 'home' ? (int) $match['home_team_id'] : (int) $match['away_team_id'];
    $winnerScore = max(1, $winnerSide === 'home' ? ($postedHomeScore ?? 1) : ($postedAwayScore ?? 1));
    $loserScore = max(0, $winnerSide === 'home' ? ($postedAwayScore ?? 0) : ($postedHomeScore ?? 0));

    if ($scoreType === 'sets') {
        $winnerScore = max(1, $winnerScore);
        if ($loserScore >= $winnerScore) {
            $loserScore = max(0, $winnerScore - 1);
        }
        $homeScore = $winnerSide === 'home' ? $winnerScore : $loserScore;
        $awayScore = $winnerSide === 'away' ? $winnerScore : $loserScore;
        $scoreData = array_merge($oldScoreData, ['sets' => [], 'home_sets' => $homeScore, 'away_sets' => $awayScore]);
    } elseif (is_individual_modality($modality)) {
        $homeScore = $winnerSide === 'home' ? 1 : 0;
        $awayScore = $winnerSide === 'away' ? 1 : 0;
        $scoreData = array_merge($oldScoreData, ['winner' => $winnerSide]);
    } else {
        if ($loserScore >= $winnerScore) {
            $winnerScore = $loserScore + 1;
        }
        $homeScore = $winnerSide === 'home' ? $winnerScore : $loserScore;
        $awayScore = $winnerSide === 'away' ? $winnerScore : $loserScore;
        $scoreData = array_merge($oldScoreData, ['home' => $homeScore, 'away' => $awayScore]);
    }

    return [
        'home_score' => $homeScore,
        'away_score' => $awayScore,
        'score_data' => $scoreData,
        'winner_team_id' => $winnerTeamId,
    ];
}

function match_manager_delete_matches_with_logs(array $matchIds): int
{
    $matchIds = array_values(array_unique(array_filter(array_map('intval', $matchIds))));
    if (!$matchIds) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
    try {
        $deleteLogs = db()->prepare("DELETE FROM score_logs WHERE match_id IN ($placeholders)");
        $deleteLogs->execute($matchIds);
    } catch (Throwable $exception) {
        // score_logs may not exist in older installs; deleting matches is still the main rollback.
    }
    try {
        $deleteOccurrences = db()->prepare("DELETE FROM occurrences WHERE match_id IN ($placeholders)");
        $deleteOccurrences->execute($matchIds);
    } catch (Throwable $exception) {
        // occurrences may not exist in older installs.
    }

    foreach ($matchIds as $matchId) {
        match_manager_score_cache_forget($matchId);
    }

    $delete = db()->prepare("DELETE FROM matches WHERE id IN ($placeholders)");
    $delete->execute($matchIds);

    return $delete->rowCount();
}

function match_manager_reset_future_after_stage(int $eventId, string $modality, string $gender, string $stage): int
{
    $futureStages = match_manager_future_stages_after($stage);
    if (!$futureStages) {
        return 0;
    }

    $stagePlaceholders = implode(',', array_fill(0, count($futureStages), '?'));
    $params = array_merge([$eventId, $modality], $futureStages);
    $genderSql = '';
    if (!is_mixed_modality($modality)) {
        $genderSql = ' AND gender = ?';
        $params[] = $gender;
    }

    $locked = db()->prepare(
        "SELECT COUNT(*) FROM matches
         WHERE event_id = ? AND modality = ? AND stage IN ($stagePlaceholders)
         AND status <> 'agendada' $genderSql"
    );
    $locked->execute($params);
    if ((int) $locked->fetchColumn() > 0) {
        throw new RuntimeException('Correcao bloqueada: existem partidas posteriores ja iniciadas. Revise os confrontos afetados.');
    }

    $idsStmt = db()->prepare(
        "SELECT id FROM matches
         WHERE event_id = ? AND modality = ? AND stage IN ($stagePlaceholders) $genderSql"
    );
    $idsStmt->execute($params);
    $matchIds = array_map('intval', array_column($idsStmt->fetchAll(), 'id'));

    return match_manager_delete_matches_with_logs($matchIds);
}

function match_manager_clear_class_points_for_modality(int $eventId, string $modality, string $gender): void
{
    $stmt = db()->prepare(
        'SELECT cs.id
         FROM class_standings cs
         JOIN class_modality_points cmp ON cmp.class_standings_id = cs.id
         WHERE cs.event_id = ? AND cmp.modality = ? AND cmp.gender = ?'
    );
    $stmt->execute([$eventId, $modality, $gender]);
    $classStandingIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

    $delete = db()->prepare(
        'DELETE cmp
         FROM class_modality_points cmp
         JOIN class_standings cs ON cs.id = cmp.class_standings_id
         WHERE cs.event_id = ? AND cmp.modality = ? AND cmp.gender = ?'
    );
    $delete->execute([$eventId, $modality, $gender]);

    foreach (array_unique($classStandingIds) as $classStandingId) {
        $recalc = db()->prepare(
            'UPDATE class_standings SET total_points = (
                SELECT COALESCE(SUM(points), 0) FROM class_modality_points WHERE class_standings_id = ?
            ) WHERE id = ?'
        );
        $recalc->execute([$classStandingId, $classStandingId]);
    }
}

function match_manager_apply_result_correction(array $match, string $winnerSide, string $reason, ?int $postedHomeScore, ?int $postedAwayScore, int $adminUserId, int $expectedVersion): array
{
    if (!in_array($match['status'] ?? '', ['encerrada', 'wo'], true)) {
        throw new RuntimeException('Somente partidas encerradas ou W.O. podem ter resultado corrigido.');
    }
    if (!in_array($winnerSide, ['home', 'away'], true)) {
        throw new RuntimeException('Selecione o novo vencedor.');
    }
    if (empty($match[$winnerSide . '_team_id'])) {
        throw new RuntimeException('O lado escolhido nao possui time vinculado.');
    }
    if (trim($reason) === '') {
        throw new RuntimeException('Informe o motivo da correcao.');
    }

    $eventId = (int) $match['event_id'];
    $modality = (string) $match['modality'];
    $gender = (string) ($match['gender'] ?? 'masculino');
    $stage = (string) $match['stage'];
    $oldWinnerTeamId = (int) ($match['winner_team_id'] ?? 0);
    $payload = match_manager_corrected_score_payload($match, $winnerSide, $postedHomeScore, $postedAwayScore);
    $newWinnerTeamId = (int) $payload['winner_team_id'];
    $affected = 0;
    $reset = 0;

    db()->beginTransaction();
    try {
        $currentStmt = db()->prepare('SELECT version FROM matches WHERE id = ? FOR UPDATE');
        $currentStmt->execute([(int) $match['id']]);
        if ((int) $currentStmt->fetchColumn() !== $expectedVersion) {
            throw new RuntimeException('Esta partida foi alterada por outra pessoa. Recarregue antes de corrigir.');
        }
        $update = db()->prepare(
            'UPDATE matches
             SET home_score = ?, away_score = ?, score_data = ?, status = "encerrada", winner_team_id = ?, version = version + 1
             WHERE id = ?'
        );
        $update->execute([
            $payload['home_score'],
            $payload['away_score'],
            json_encode($payload['score_data'], JSON_UNESCAPED_UNICODE),
            $newWinnerTeamId,
            (int) $match['id'],
        ]);
        match_manager_score_cache_forget((int) $match['id']);

        if ($stage === 'grupo') {
            $reset = match_manager_reset_future_after_stage($eventId, $modality, $gender, $stage);
            generate_knockout_matches($eventId, $modality, $gender);
        } elseif (in_array($stage, ['repescagem', 'preliminar', 'dezesseis_avos', 'oitavas', 'quartas', 'semifinal'], true)) {
            $futureStages = match_manager_future_stages_after($stage);
            if ($futureStages && $oldWinnerTeamId > 0 && $oldWinnerTeamId !== $newWinnerTeamId) {
                $stagePlaceholders = implode(',', array_fill(0, count($futureStages), '?'));
                $params = array_merge([$eventId, $modality], $futureStages, [$oldWinnerTeamId, $oldWinnerTeamId]);
                $genderSql = '';
                if (!is_mixed_modality($modality)) {
                    $genderSql = ' AND gender = ?';
                    $params[] = $gender;
                }

                $futureStmt = db()->prepare(
                    "SELECT id, status, home_team_id, away_team_id
                     FROM matches
                     WHERE event_id = ? AND modality = ? AND stage IN ($stagePlaceholders)
                     AND (home_team_id = ? OR away_team_id = ?) $genderSql
                     ORDER BY sort_order, id"
                );
                $futureStmt->execute($params);
                $futureMatches = $futureStmt->fetchAll();
                $lockedFuture = array_filter($futureMatches, static fn(array $future): bool => ($future['status'] ?? '') !== 'agendada');

                if ($lockedFuture) {
                    $ids = implode(', ', array_map(static fn(array $future): string => '#' . $future['id'], $lockedFuture));
                    throw new RuntimeException('Correcao bloqueada: partida(s) posterior(es) ' . $ids . ' ja iniciada(s). Revise esses confrontos antes de corrigir.');
                }

                foreach ($futureMatches as $future) {
                        $homeTeamId = (int) ($future['home_team_id'] ?? 0);
                        $awayTeamId = (int) ($future['away_team_id'] ?? 0);
                        if (($homeTeamId === $oldWinnerTeamId && $awayTeamId === $newWinnerTeamId) || ($awayTeamId === $oldWinnerTeamId && $homeTeamId === $newWinnerTeamId)) {
                            $reset += match_manager_delete_matches_with_logs([(int) $future['id']]);
                            continue;
                        }

                        $resetPayload = match_manager_reset_match_payload($modality);
                        $newHome = $homeTeamId === $oldWinnerTeamId ? $newWinnerTeamId : $homeTeamId;
                        $newAway = $awayTeamId === $oldWinnerTeamId ? $newWinnerTeamId : $awayTeamId;
                        $updateFuture = db()->prepare(
                            'UPDATE matches
                             SET home_team_id = ?, away_team_id = ?, home_score = ?, away_score = ?, score_data = ?, status = ?, winner_team_id = ?
                             WHERE id = ?'
                        );
                        $updateFuture->execute([
                            $newHome,
                            $newAway,
                            $resetPayload['home_score'],
                            $resetPayload['away_score'],
                            $resetPayload['score_data'],
                            $resetPayload['status'],
                            $resetPayload['winner_team_id'],
                            (int) $future['id'],
                        ]);
                        match_manager_score_cache_forget((int) $future['id']);
                        $affected++;
                    }
                maybe_generate_next_knockout_stage($eventId, $modality, $stage, $gender);
            }
        }

        if (in_array($stage, ['grupo', 'final', 'preliminar'], true)) {
            calculate_class_points($eventId, $modality, $gender);
        }
        if ($reset > 0) {
            match_manager_clear_class_points_for_modality($eventId, $modality, $gender);
        }

        {
            $description = 'Correção administrativa: ' . (function_exists('mb_substr') ? mb_substr(trim($reason), 0, 220) : substr(trim($reason), 0, 220));
            $log = db()->prepare(
                'INSERT INTO score_logs
                    (match_id, user_id, action, description, old_home_score, old_away_score, new_home_score, new_away_score, old_status, new_status, old_score_data, new_score_data)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $log->execute([
                (int) $match['id'],
                $adminUserId,
                'admin_correct_result',
                $description,
                (int) $match['home_score'],
                (int) $match['away_score'],
                (int) $payload['home_score'],
                (int) $payload['away_score'],
                (string) $match['status'],
                'encerrada',
                (string) ($match['score_data'] ?? ''),
                json_encode($payload['score_data'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }

    return ['affected' => $affected, 'reset' => $reset];
}

function match_manager_match_data(int $eventId, string $modality, string $gender, string $bracketType, array $allowedStages): array
{
    $stage = $_POST['stage'] ?? 'grupo';
    if (!in_array($stage, $allowedStages, true)) {
        throw new RuntimeException('Fase inválida.');
    }

    $usesGrouping = $bracketType === 'groups';
    $allowGroupStage = in_array($bracketType, ['groups', 'round_robin'], true);

    if (!$allowGroupStage && $stage === 'grupo') {
        throw new RuntimeException('Modalidades sem grupos não aceitam fase de grupos manual.');
    }

    $groupName = $usesGrouping ? match_manager_group_value($_POST['group_name'] ?? '') : '';
    $homeTeamId = (int) ($_POST['home_team_id'] ?? 0);
    $awayTeamId = (int) ($_POST['away_team_id'] ?? 0);

    if (!$homeTeamId || !$awayTeamId) {
        throw new RuntimeException('Selecione os dois times da partida.');
    }

    if ($homeTeamId === $awayTeamId) {
        throw new RuntimeException('Mandante e visitante não podem ser o mesmo time.');
    }

    $homeTeam = match_manager_validate_team($homeTeamId, $eventId, $modality, $gender);
    $awayTeam = match_manager_validate_team($awayTeamId, $eventId, $modality, $gender);

    if (!$homeTeam || !$awayTeam) {
        throw new RuntimeException('Os times precisam pertencer a este evento, modalidade e gênero.');
    }

    if ($stage === 'grupo' && $usesGrouping) {
        if ($groupName === '') {
            throw new RuntimeException('Partidas de grupo precisam de um grupo válido.');
        }

        if (($homeTeam['group_name'] ?? '') === '' || ($awayTeam['group_name'] ?? '') === '') {
            throw new RuntimeException('Times sem grupo não podem entrar em partida de grupo.');
        }

        if ($homeTeam['group_name'] !== $groupName || $awayTeam['group_name'] !== $groupName) {
            throw new RuntimeException('Na fase de grupos, os dois times precisam estar no grupo selecionado.');
        }
    } elseif ($stage === 'grupo') {
        $groupName = '';
    } else {
        $groupName = null;
    }

    $startsAt = trim($_POST['starts_at'] ?? '');
    $startsAt = $startsAt !== '' ? str_replace('T', ' ', $startsAt) . ':00' : null;
    $place = trim($_POST['place'] ?? '');
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    return [
        'stage' => $stage,
        'group_name' => $groupName,
        'home_team_id' => $homeTeamId,
        'away_team_id' => $awayTeamId,
        'starts_at' => $startsAt,
        'place' => $place !== '' ? $place : null,
        'sort_order' => $sortOrder,
    ];
}

$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$eventStmt = db()->prepare('SELECT * FROM events WHERE id = ?');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch();

if (!$event) {
    http_response_code(404);
    echo '<div class="alert alert-warning">Evento não encontrado.</div>';
    return;
}

$modalitiesStmt = db()->prepare('SELECT modality FROM event_modalities WHERE event_id = ? ORDER BY array_position(ARRAY["futsal", "handbol", "queimada", "volei", "basquete", "cubo_magico", "xadrez", "quiz", "fifa", "clash_royale"], modality::text)');
$modalitiesStmt->execute([$eventId]);
$modalities = array_column($modalitiesStmt->fetchAll(), 'modality');

if (!$modalities) {
    echo '<div class="alert alert-warning">Este evento não possui modalidades cadastradas.</div>';
    return;
}

$selectedModality = $_GET['modality'] ?? $_POST['modality'] ?? $modalities[0];
if (!in_array($selectedModality, $modalities, true)) {
    $selectedModality = $modalities[0];
}

$isMixedModality = is_mixed_modality($selectedModality);
$selectedBracketType = bracket_type_for_event_modality($eventId, $selectedModality);
$usesGrouping = $selectedBracketType === 'groups';
$gender = $isMixedModality ? 'masculino' : ($_GET['gender'] ?? $_POST['gender'] ?? 'masculino');
if (!$isMixedModality && !in_array($gender, ['masculino', 'feminino'], true)) {
    $gender = 'masculino';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($event['status'] === 'encerrado') {
        flash('Evento encerrado. A montagem não pode ser alterada.', 'warning');
        match_manager_redirect($eventId, $selectedModality, $gender);
    }

    try {
        if ($action === 'save_bracket_type') {
            $newBracketType = $_POST['bracket_type'] ?? $selectedBracketType;
            if (!in_array($newBracketType, allowed_bracket_types(), true)) {
                throw new RuntimeException('Tipo de chaveamento invalido.');
            }

            $lockedSql = 'SELECT COUNT(*) FROM matches
                WHERE event_id = ? AND modality = ?
                AND status IN ("em_andamento", "encerrada", "wo")
                AND NOT (status = "encerrada" AND away_team_id IS NULL)';
            $lockedParams = [$eventId, $selectedModality];
            if (!$isMixedModality) {
                $lockedSql .= ' AND gender = ?';
                $lockedParams[] = $gender;
            }
            $lockedStmt = db()->prepare($lockedSql);
            $lockedStmt->execute($lockedParams);
            if ((int) $lockedStmt->fetchColumn() > 0) {
                throw new RuntimeException('Nao e possivel mudar o tipo de chaveamento depois que ha partida iniciada, encerrada ou W.O.');
            }

            $deleteSql = 'DELETE FROM matches
                WHERE event_id = ? AND modality = ?
                AND (status = "agendada" OR (status = "encerrada" AND away_team_id IS NULL))';
            $deleteParams = [$eventId, $selectedModality];
            if (!$isMixedModality) {
                $deleteSql .= ' AND gender = ?';
                $deleteParams[] = $gender;
            }
            $deleteStmt = db()->prepare($deleteSql);
            $deleteStmt->execute($deleteParams);

            $updateType = db()->prepare('UPDATE event_modalities SET bracket_type = ? WHERE event_id = ? AND modality = ?');
            $updateType->execute([$newBracketType, $eventId, $selectedModality]);
            if ($newBracketType === 'round_robin' && in_array($selectedModality, ['quiz', 'cubo_magico'], true)) {
                $created = generate_round_robin_matches($eventId, $selectedModality, null, $event['place'] ?? null, $gender);
                flash($created > 0 ? 'Tipo atualizado e partida coletiva criada.' : 'Tipo atualizado. A partida coletiva ja existe ou ainda faltam participantes.');
            } else {
                flash('Tipo de chaveamento atualizado.');
            }
        } elseif ($action === 'save_advancement') {
            if (!$usesGrouping) {
                throw new RuntimeException('Esta modalidade não usa classificação por grupos.');
            }

            $mode = ($_POST['advancement_mode'] ?? 'global') === 'per_group' ? 'per_group' : 'global';

            if ($mode === 'global') {
                $globalAdvancing = max(1, min(32, (int) ($_POST['teams_advancing_per_group'] ?? 2)));
                $stmt = db()->prepare('UPDATE events SET advancement_mode = "global", teams_advancing_per_group = ? WHERE id = ?');
                $stmt->execute([$globalAdvancing, $eventId]);
                flash('Classificação definida para todos os grupos.');
            } else {
                $groupCountsSql = 'SELECT group_name, COUNT(*) AS team_count FROM teams WHERE event_id = ? AND modality = ? AND group_name <> ""';
                $groupCountsParams = [$eventId, $selectedModality];
                if (!$isMixedModality) {
                    $groupCountsSql .= ' AND gender = ?';
                    $groupCountsParams[] = $gender;
                }
                $groupCountsSql .= ' GROUP BY group_name';
                $groupCountsStmt = db()->prepare($groupCountsSql);
                $groupCountsStmt->execute($groupCountsParams);
                $teamCountsByGroup = [];
                foreach ($groupCountsStmt->fetchAll() as $row) {
                    $teamCountsByGroup[$row['group_name']] = (int) $row['team_count'];
                }

                $values = $_POST['group_advancing'] ?? [];
                $upsert = db()->prepare(
                    'INSERT INTO event_group_advancement (event_id, modality, gender, group_name, teams_advancing)
                     VALUES (?, ?, ?, ?, ?)
                     ON CONFLICT (event_id, modality, gender, group_name)
                     DO UPDATE SET teams_advancing = EXCLUDED.teams_advancing'
                );

                foreach ($teamCountsByGroup as $groupName => $teamCount) {
                    $requested = (int) ($values[$groupName] ?? 0);
                    $teamsAdvancing = max(0, min($teamCount, $requested));
                    $upsert->execute([$eventId, $selectedModality, $gender, $groupName, $teamsAdvancing]);
                }

                $stmt = db()->prepare('UPDATE events SET advancement_mode = "per_group" WHERE id = ?');
                $stmt->execute([$eventId]);
                flash('Classificação definida grupo por grupo.');
            }
        } elseif ($action === 'create_team') {
            $classId = (int) ($_POST['class_id'] ?? 0);
            $groupName = $usesGrouping ? match_manager_group_value($_POST['group_name'] ?? '') : '';

            $classStmt = db()->prepare('SELECT name FROM classes WHERE id = ?');
            $classStmt->execute([$classId]);
            $class = $classStmt->fetch();

            if (!$class) {
                throw new RuntimeException('Selecione uma turma válida.');
            }

            $duplicateStmt = db()->prepare('SELECT id FROM teams WHERE event_id = ? AND modality = ? AND gender = ? AND class_name = ? LIMIT 1');
            $duplicateStmt->execute([$eventId, $selectedModality, $gender, $class['name']]);
            if ($duplicateStmt->fetch()) {
                throw new RuntimeException('Esta turma já está cadastrada nesta modalidade e gênero.');
            }

            $insert = db()->prepare('INSERT INTO teams (event_id, modality, gender, name, class_name, group_name) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([$eventId, $selectedModality, $gender, $class['name'], $class['name'], $groupName]);
            flash('Time criado na montagem.');
        } elseif ($action === 'move_team') {
            $teamId = (int) ($_POST['team_id'] ?? 0);
            $groupName = $usesGrouping ? match_manager_group_value($_POST['group_name'] ?? '') : '';
            $team = match_manager_validate_team($teamId, $eventId, $selectedModality, $gender);

            if (!$team) {
                throw new RuntimeException('Time inválido para esta modalidade.');
            }

            $update = db()->prepare('UPDATE teams SET group_name = ? WHERE id = ?');
            $update->execute([$groupName, $teamId]);
            flash($groupName === '' ? 'Time movido para Sem grupo.' : 'Time movido para o grupo ' . $groupName . '.');
        } elseif ($action === 'delete_team') {
            $teamId = (int) ($_POST['team_id'] ?? 0);
            $team = match_manager_validate_team($teamId, $eventId, $selectedModality, $gender);

            if (!$team) {
                throw new RuntimeException('Time inválido para esta modalidade.');
            }

            $lockedStmt = db()->prepare(
                'SELECT COUNT(*) FROM matches
                 WHERE event_id = ? AND modality = ? AND (home_team_id = ? OR away_team_id = ?)
                 AND status IN ("em_andamento", "encerrada", "wo")
                 AND NOT (status = "encerrada" AND away_team_id IS NULL)'
            );
            $lockedStmt->execute([$eventId, $selectedModality, $teamId, $teamId]);
            if ((int) $lockedStmt->fetchColumn() > 0) {
                throw new RuntimeException('Não é possível remover time com partida em andamento, encerrada ou W.O.');
            }

            if (is_mixed_modality($selectedModality)) {
                $modalityLockedStmt = db()->prepare(
                    'SELECT COUNT(*) FROM matches
                     WHERE event_id = ? AND modality = ?
                     AND status IN ("em_andamento", "encerrada", "wo")
                     AND NOT (status = "encerrada" AND away_team_id IS NULL)'
                );
                $modalityLockedStmt->execute([$eventId, $selectedModality]);
                if ((int) $modalityLockedStmt->fetchColumn() > 0) {
                    throw new RuntimeException('Nao e possivel remover time desta modalidade depois que o chaveamento teve partida iniciada, encerrada ou W.O.');
                }

                $deleteMatches = db()->prepare(
                    'DELETE FROM matches
                     WHERE event_id = ? AND modality = ?
                     AND (status = "agendada" OR (status = "encerrada" AND away_team_id IS NULL))'
                );
                $deleteMatches->execute([$eventId, $selectedModality]);
            } else {
                $deleteMatches = db()->prepare(
                    'DELETE FROM matches
                     WHERE event_id = ? AND modality = ?
                     AND (status = "agendada" OR (status = "encerrada" AND away_team_id IS NULL))
                     AND (home_team_id = ? OR away_team_id = ?)'
                );
                $deleteMatches->execute([$eventId, $selectedModality, $teamId, $teamId]);
            }

            $delete = db()->prepare('DELETE FROM teams WHERE id = ? AND event_id = ? AND modality = ?');
            $delete->execute([$teamId, $eventId, $selectedModality]);
            flash('Time removido da modalidade.');
        } elseif ($action === 'save_match') {
            if ($selectedBracketType === 'round_robin' && in_array($selectedModality, ['quiz', 'cubo_magico'], true)) {
                throw new RuntimeException('Quiz e Cubo Magico em todos contra todos usam uma unica partida coletiva.');
            }

            $matchId = (int) ($_POST['match_id'] ?? 0);
            $data = match_manager_match_data($eventId, $selectedModality, $gender, $selectedBracketType, $allowedStages);

            if ($matchId > 0) {
                $matchSql = 'SELECT * FROM matches WHERE id = ? AND event_id = ? AND modality = ?';
                $matchParams = [$matchId, $eventId, $selectedModality];
                if (!$isMixedModality) {
                    $matchSql .= ' AND gender = ?';
                    $matchParams[] = $gender;
                }
                $matchStmt = db()->prepare($matchSql);
                $matchStmt->execute($matchParams);
                $match = $matchStmt->fetch();

                if (!$match) {
                    throw new RuntimeException('Partida não encontrada.');
                }

                if (($match['status'] ?? '') !== 'agendada') {
                    throw new RuntimeException('Somente partidas agendadas podem ter estrutura alterada.');
                }

                match_manager_prune_future_scheduled_matches($eventId, $selectedModality, $gender, (string) $match['stage']);

                $update = db()->prepare(
                    'UPDATE matches
                     SET stage = ?, group_name = ?, home_team_id = ?, away_team_id = ?, starts_at = ?, place = ?, sort_order = ?
                     WHERE id = ? AND event_id = ?'
                );
                $update->execute([
                    $data['stage'],
                    $data['group_name'],
                    $data['home_team_id'],
                    $data['away_team_id'],
                    $data['starts_at'],
                    $data['place'],
                    $data['sort_order'],
                    $matchId,
                    $eventId,
                ]);
                flash('Partida atualizada.');
            } else {
                $insert = db()->prepare(
                    'INSERT INTO matches (event_id, modality, gender, stage, group_name, home_team_id, away_team_id, score_data, starts_at, place, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $eventId,
                    $selectedModality,
                    $gender,
                    $data['stage'],
                    $data['group_name'],
                    $data['home_team_id'],
                    $data['away_team_id'],
                    json_encode(default_score_data($selectedModality), JSON_UNESCAPED_UNICODE),
                    $data['starts_at'],
                    $data['place'],
                    $data['sort_order'],
                ]);
                flash('Partida criada.');
            }
        } elseif ($action === 'delete_match') {
            $matchId = (int) ($_POST['match_id'] ?? 0);
            $matchSql = 'SELECT * FROM matches WHERE id = ? AND event_id = ? AND modality = ?';
            $matchParams = [$matchId, $eventId, $selectedModality];
            if (!$isMixedModality) {
                $matchSql .= ' AND gender = ?';
                $matchParams[] = $gender;
            }
            $matchStmt = db()->prepare($matchSql);
            $matchStmt->execute($matchParams);
            $match = $matchStmt->fetch();

            if (!$match) {
                throw new RuntimeException('Partida nao encontrada.');
            }

            if (($match['status'] ?? '') !== 'agendada') {
                throw new RuntimeException('Somente partidas agendadas podem ser excluidas.');
            }

            match_manager_prune_future_scheduled_matches($eventId, $selectedModality, $gender, (string) $match['stage']);

            $delete = db()->prepare('DELETE FROM matches WHERE id = ? AND event_id = ?');
            $delete->execute([$matchId, $eventId]);
            flash('Partida excluida.');
        } elseif ($action === 'correct_match_result') {
            $matchId = (int) ($_POST['match_id'] ?? 0);
            $matchSql = 'SELECT m.*, ht.name AS home_name, at.name AS away_name
                FROM matches m
                LEFT JOIN teams ht ON ht.id = m.home_team_id
                LEFT JOIN teams at ON at.id = m.away_team_id
                WHERE m.id = ? AND m.event_id = ? AND m.modality = ?';
            $matchParams = [$matchId, $eventId, $selectedModality];
            if (!$isMixedModality) {
                $matchSql .= ' AND m.gender = ?';
                $matchParams[] = $gender;
            }
            $matchStmt = db()->prepare($matchSql);
            $matchStmt->execute($matchParams);
            $match = $matchStmt->fetch();

            if (!$match) {
                throw new RuntimeException('Partida nao encontrada.');
            }

            $adminUser = current_user();
            $result = match_manager_apply_result_correction(
                $match,
                (string) ($_POST['winner_side'] ?? ''),
                (string) ($_POST['correction_reason'] ?? ''),
                isset($_POST['home_score']) && $_POST['home_score'] !== '' ? (int) $_POST['home_score'] : null,
                isset($_POST['away_score']) && $_POST['away_score'] !== '' ? (int) $_POST['away_score'] : null,
                (int) ($adminUser['id'] ?? 0),
                (int) ($_POST['version'] ?? -1)
            );

            $message = 'Resultado corrigido.';
            if ((int) $result['affected'] > 0) {
                $message .= ' ' . (int) $result['affected'] . ' partida(s) futura(s) atualizada(s).';
            }
            if ((int) $result['reset'] > 0) {
                $message .= ' ' . (int) $result['reset'] . ' partida(s) futura(s) resetada(s).';
            }
            flash($message);
        }
    } catch (RuntimeException $exception) {
        flash($exception->getMessage(), 'warning');
    }

    match_manager_redirect($eventId, $selectedModality, $gender);
}

$classes = db()->query('SELECT id, name FROM classes ORDER BY name')->fetchAll();

$teamSql = 'SELECT t.*,
        COALESCE(pc.player_count, 0) AS player_count,
        COALESCE(mc.match_count, 0) AS match_count,
        COALESCE(mc.locked_match_count, 0) AS locked_match_count
    FROM teams t
    LEFT JOIN (
        SELECT team_id, COUNT(*) AS player_count
        FROM players
        GROUP BY team_id
    ) pc ON pc.team_id = t.id
    LEFT JOIN (
        SELECT team_id, COUNT(*) AS match_count, SUM(CASE WHEN status IN ("em_andamento", "encerrada", "wo") THEN 1 ELSE 0 END) AS locked_match_count
        FROM (
            SELECT home_team_id AS team_id, status FROM matches WHERE event_id = ? AND modality = ? AND home_team_id IS NOT NULL
            UNION ALL
            SELECT away_team_id AS team_id, status FROM matches WHERE event_id = ? AND modality = ? AND away_team_id IS NOT NULL
        ) match_refs
        GROUP BY team_id
    ) mc ON mc.team_id = t.id
    WHERE t.event_id = ? AND t.modality = ?';
$teamParams = [$eventId, $selectedModality, $eventId, $selectedModality, $eventId, $selectedModality];
if (!$isMixedModality) {
    $teamSql .= ' AND t.gender = ?';
    $teamParams[] = $gender;
}
$teamSql .= ' ORDER BY CASE WHEN t.group_name = "" THEN 1 ELSE 0 END, t.group_name, t.name';
$teamsStmt = db()->prepare($teamSql);
$teamsStmt->execute($teamParams);
$teams = $teamsStmt->fetchAll();

$registeredClassNames = [];
$groups = [];
$teamsByGroup = ['' => []];
foreach ($teams as $team) {
    $registeredClassNames[$team['class_name']] = true;
    $groupName = (string) ($team['group_name'] ?? '');
    if ($groupName !== '' && !in_array($groupName, $groups, true)) {
        $groups[] = $groupName;
    }
    $teamsByGroup[$groupName][] = $team;
}
sort($groups);
$nextGroup = match_manager_next_group($groups);
$groupChoices = $usesGrouping ? array_values(array_unique(array_merge($groups, [$nextGroup]))) : [];
$groupTeamCounts = [];
foreach ($groups as $group) {
    $groupTeamCounts[$group] = count($teamsByGroup[$group] ?? []);
}
$advancementMode = ($event['advancement_mode'] ?? 'global') === 'per_group' ? 'per_group' : 'global';
$globalAdvancing = max(1, (int) ($event['teams_advancing_per_group'] ?? 2));
$groupAdvancing = [];
foreach ($groups as $group) {
    $groupAdvancing[$group] = min($globalAdvancing, $groupTeamCounts[$group] ?? $globalAdvancing);
}

if ($usesGrouping && $groups) {
    $advancementStmt = db()->prepare(
        'SELECT group_name, teams_advancing
         FROM event_group_advancement
         WHERE event_id = ? AND modality = ? AND gender = ?'
    );
    $advancementStmt->execute([$eventId, $selectedModality, $gender]);
    foreach ($advancementStmt->fetchAll() as $row) {
        if (isset($groupTeamCounts[$row['group_name']])) {
            $groupAdvancing[$row['group_name']] = max(0, min($groupTeamCounts[$row['group_name']], (int) $row['teams_advancing']));
        }
    }
}

$matchSql = 'SELECT m.id, m.event_id, m.modality, m.gender, m.stage, m.group_name,
        m.home_team_id, m.away_team_id, m.home_score, m.away_score, m.score_data,
        m.status, m.starts_at, m.place, m.winner_team_id, m.sort_order,
        ht.name AS home_name, at.name AS away_name
    FROM matches m
    LEFT JOIN teams ht ON ht.id = m.home_team_id
    LEFT JOIN teams at ON at.id = m.away_team_id
    WHERE m.event_id = ? AND m.modality = ?';
$matchParams = [$eventId, $selectedModality];
if (!$isMixedModality) {
    $matchSql .= ' AND m.gender = ?';
    $matchParams[] = $gender;
}
$matchSql .= ' ORDER BY m.stage, m.group_name, m.sort_order, m.id LIMIT 500';
$matchesStmt = db()->prepare($matchSql);
$matchesStmt->execute($matchParams);
$matches = $matchesStmt->fetchAll();
$matchesTruncated = count($matches) >= 500;

$summarySql = 'SELECT
        SUM(CASE WHEN status = "agendada" THEN 1 ELSE 0 END) AS scheduled,
        SUM(CASE WHEN status = "em_andamento" THEN 1 ELSE 0 END) AS running,
        SUM(CASE WHEN status IN ("encerrada", "wo") THEN 1 ELSE 0 END) AS finished
    FROM matches
    WHERE event_id = ? AND modality = ?';
$summaryParams = [$eventId, $selectedModality];
if (!$isMixedModality) {
    $summarySql .= ' AND gender = ?';
    $summaryParams[] = $gender;
}
$summaryStmt = db()->prepare($summarySql);
$summaryStmt->execute($summaryParams);
$matchSummary = $summaryStmt->fetch() ?: [];

$summary = [
    'teams' => count($teams),
    'groups' => count($groups),
    'ungrouped' => count($teamsByGroup[''] ?? []),
    'scheduled' => (int) ($matchSummary['scheduled'] ?? 0),
    'running' => (int) ($matchSummary['running'] ?? 0),
    'finished' => (int) ($matchSummary['finished'] ?? 0),
];

$teamOptions = match_manager_team_options($teams);
$stageOptions = [];
$allowGroupStage = in_array($selectedBracketType, ['groups', 'round_robin'], true);
foreach ($allowedStages as $stage) {
    if (!$allowGroupStage && $stage === 'grupo') {
        continue;
    }
    $stageOptions[$stage] = stage_label($stage);
}
$teamClientData = array_map(static function (array $team): array {
    return [
        'id' => (int) $team['id'],
        'name' => $team['name'],
        'group_name' => (string) ($team['group_name'] ?? ''),
    ];
}, $teams);
$usesCollectiveRoundRobin = $selectedBracketType === 'round_robin' && in_array($selectedModality, ['quiz', 'cubo_magico'], true);
?>
<div class="page-title match-manager-title">
    <div>
        <h1 class="h3 mb-1">Montagem de partidas</h1>
        <p class="text-muted mb-0"><?= e($event['name']) ?> · <?= e(modality_label($selectedModality)) ?><?= !$isMixedModality ? ' · ' . e(ucfirst($gender)) : '' ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary" href="index.php?page=event&id=<?= $eventId ?>&modality=<?= e($selectedModality) ?>&gender=<?= e($gender) ?>">Voltar ao evento</a>
        <a class="btn btn-outline-primary" href="index.php?page=teams&event_id=<?= $eventId ?>&modality=<?= e($selectedModality) ?>&gender=<?= e($gender) ?>">Times</a>
        <button class="btn btn-primary" type="button" id="match-manager-open-create" <?= $event['status'] === 'encerrado' || $usesCollectiveRoundRobin ? 'disabled' : '' ?>>Nova partida</button>
    </div>
</div>

<form class="match-manager-switcher mb-3" method="get">
    <noscript><button class="btn btn-outline-primary">Aplicar seleção</button></noscript>
    <input type="hidden" name="page" value="match_manager">
    <input type="hidden" name="event_id" value="<?= $eventId ?>">
    <select class="form-select" name="modality" onchange="this.form.submit()">
        <?php foreach ($modalities as $modality): ?>
            <option value="<?= e($modality) ?>" <?= $selectedModality === $modality ? 'selected' : '' ?>><?= e(modality_label($modality)) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (!$isMixedModality): ?>
        <select class="form-select" name="gender" onchange="this.form.submit()">
            <option value="masculino" <?= $gender === 'masculino' ? 'selected' : '' ?>>Masculino</option>
            <option value="feminino" <?= $gender === 'feminino' ? 'selected' : '' ?>>Feminino</option>
        </select>
    <?php endif; ?>
</form>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end" data-confirm-submit="Alterar o tipo de chaveamento remove partidas agendadas desta modalidade. Continuar?">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
            <input type="hidden" name="gender" value="<?= e($gender) ?>">
            <input type="hidden" name="action" value="save_bracket_type">
            <div class="col-md-8">
                <label class="form-label">Tipo de chaveamento</label>
                <select class="form-select" name="bracket_type" <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>
                    <?php foreach (allowed_bracket_types() as $bracketType): ?>
                        <option value="<?= e($bracketType) ?>" <?= $selectedBracketType === $bracketType ? 'selected' : '' ?>><?= e(bracket_type_label($bracketType)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button class="btn btn-outline-primary w-100" <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>Salvar tipo</button>
            </div>
        </form>
    </div>
</div>

<div class="match-manager-stats mb-3">
    <div><span>Times</span><strong><?= (int) $summary['teams'] ?></strong></div>
    <div><span>Grupos</span><strong><?= (int) $summary['groups'] ?></strong></div>
    <div><span>Sem grupo</span><strong><?= (int) $summary['ungrouped'] ?></strong></div>
    <div><span>Agendadas</span><strong><?= (int) $summary['scheduled'] ?></strong></div>
    <div><span>Ao vivo</span><strong><?= (int) $summary['running'] ?></strong></div>
    <div><span>Finalizadas</span><strong><?= (int) $summary['finished'] ?></strong></div>
</div>

<?php if ($event['status'] === 'encerrado'): ?>
    <div class="alert alert-warning">Evento encerrado. A montagem está disponível apenas para consulta.</div>
<?php endif; ?>

<div class="match-manager-layout match-manager-workspace mb-3">
    <section class="match-manager-groups">
        <div class="match-manager-section-head">
            <div>
                <h2>Grupos e times</h2>
                <p><?= $usesGrouping ? 'Monte os grupos antes de gerar ou criar jogos de fase.' : 'Modalidade sem divisão por grupos.' ?></p>
            </div>
            <?php if ($usesGrouping && $event['status'] !== 'encerrado'): ?>
                <button class="btn btn-sm btn-outline-primary" type="button" id="match-manager-add-group" data-next-group="<?= e($nextGroup) ?>">Novo grupo</button>
            <?php endif; ?>
        </div>

        <?php if ($usesGrouping): ?>
            <div class="card shadow-sm mb-3 match-manager-advancement-card">
                <div class="card-body">
                    <form method="post" id="match-manager-advancement-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                        <input type="hidden" name="gender" value="<?= e($gender) ?>">
                        <input type="hidden" name="action" value="save_advancement">
                        <div class="match-manager-advancement-head">
                            <div>
                                <h3>Classificação para próxima fase</h3>
                                <p>Escolha quantos times avançam. No modo um a um, apenas esses classificados entram nas eliminatórias.</p>
                            </div>
                            <button class="btn btn-primary btn-sm" <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>Salvar classificação</button>
                        </div>
                        <div class="match-manager-advancement-mode" role="group" aria-label="Modo de classificação">
                            <label class="<?= $advancementMode === 'global' ? 'is-active' : '' ?>">
                                <input type="radio" name="advancement_mode" value="global" <?= $advancementMode === 'global' ? 'checked' : '' ?> <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>
                                <span>Definir para todos</span>
                            </label>
                            <label class="<?= $advancementMode === 'per_group' ? 'is-active' : '' ?>">
                                <input type="radio" name="advancement_mode" value="per_group" <?= $advancementMode === 'per_group' ? 'checked' : '' ?> <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>
                                <span>Definir um a um</span>
                            </label>
                        </div>
                        <div class="match-manager-global-advance" data-advance-panel="global">
                            <label class="form-label">Times que avançam por grupo</label>
                            <input class="form-control" type="number" name="teams_advancing_per_group" min="1" max="32" value="<?= (int) $globalAdvancing ?>" <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>
                        </div>
                        <div class="match-manager-group-advance" data-advance-panel="per_group">
                            <?php foreach ($groups as $group): ?>
                                <label class="match-manager-advance-chip">
                                    <span>Grupo <?= e($group) ?></span>
                                    <input class="form-control form-control-sm" type="number" name="group_advancing[<?= e($group) ?>]" min="0" max="<?= (int) ($groupTeamCounts[$group] ?? 0) ?>" value="<?= (int) ($groupAdvancing[$group] ?? 0) ?>" <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>
                                    <small>de <?= (int) ($groupTeamCounts[$group] ?? 0) ?></small>
                                </label>
                            <?php endforeach; ?>
                            <?php if (!$groups): ?>
                                <div class="match-manager-empty-card">Crie grupos para definir classificados individualmente.</div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <form method="post" class="match-manager-create-team">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                    <input type="hidden" name="gender" value="<?= e($gender) ?>">
                    <input type="hidden" name="action" value="create_team">
                    <select class="form-select" name="class_id" required <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>
                        <option value="">Turma</option>
                        <?php foreach ($classes as $class): ?>
                            <?php $alreadyRegistered = isset($registeredClassNames[$class['name']]); ?>
                            <option value="<?= (int) $class['id'] ?>" <?= $alreadyRegistered ? 'disabled' : '' ?>>
                                <?= e($class['name']) ?><?= $alreadyRegistered ? ' · já cadastrada' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($usesGrouping): ?>
                        <select class="form-select match-manager-group-select" name="group_name" <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>
                            <option value="">Sem grupo</option>
                            <?php foreach ($groupChoices as $group): ?>
                                <option value="<?= e($group) ?>">Grupo <?= e($group) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button class="btn btn-primary" <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>Criar time</button>
                </form>
            </div>
        </div>

        <?php if ($usesGrouping): ?>
            <div class="match-manager-kanban" id="match-manager-kanban">
                <?php
                $kanbanGroups = array_merge(['' => 'Sem grupo'], array_combine($groups, array_map(fn($group) => 'Grupo ' . $group, $groups)) ?: []);
                if (!$groups) {
                    $kanbanGroups[$nextGroup] = 'Grupo ' . $nextGroup;
                }
                ?>
                <?php foreach ($kanbanGroups as $groupValue => $groupLabel): ?>
                    <div class="match-manager-column" data-group-column="<?= e($groupValue) ?>">
                        <div class="match-manager-column-head">
                            <div>
                                <strong><?= e($groupLabel) ?></strong>
                                <?php if ($groupValue !== ''): ?>
                                    <small>Avançam: <?= (int) ($groupAdvancing[$groupValue] ?? $globalAdvancing) ?></small>
                                <?php endif; ?>
                            </div>
                            <span><?= count($teamsByGroup[$groupValue] ?? []) ?></span>
                        </div>
                        <div class="match-manager-team-stack">
                            <?php foreach ($teamsByGroup[$groupValue] ?? [] as $team): ?>
                                <article class="match-manager-team-card">
                                    <div>
                                        <strong><?= e($team['name']) ?></strong>
                                        <span><?= (int) $team['player_count'] ?> jogador<?= (int) $team['player_count'] === 1 ? '' : 'es' ?> · <?= (int) $team['match_count'] ?> jogo<?= (int) $team['match_count'] === 1 ? '' : 's' ?></span>
                                    </div>
                                    <form method="post" class="match-manager-team-actions">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                        <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                                        <input type="hidden" name="gender" value="<?= e($gender) ?>">
                                        <input type="hidden" name="action" value="move_team">
                                        <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                                        <select class="form-select form-select-sm match-manager-group-select" name="group_name" <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>
                                            <option value="" <?= $groupValue === '' ? 'selected' : '' ?>>Sem grupo</option>
                                            <?php foreach ($groupChoices as $group): ?>
                                                <option value="<?= e($group) ?>" <?= $groupValue === $group ? 'selected' : '' ?>>Grupo <?= e($group) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm btn-outline-primary" <?= $event['status'] === 'encerrado' ? 'disabled' : '' ?>>Mover</button>
                                    </form>
                                    <form method="post" data-confirm-submit="Remover este time da modalidade? Jogadores vinculados também serão removidos.">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                        <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                                        <input type="hidden" name="gender" value="<?= e($gender) ?>">
                                        <input type="hidden" name="action" value="delete_team">
                                        <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger w-100" <?= $event['status'] === 'encerrado' || (int) $team['locked_match_count'] > 0 ? 'disabled' : '' ?>>Remover</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                            <?php if (empty($teamsByGroup[$groupValue])): ?>
                                <div class="match-manager-empty-card">Nenhum time aqui.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="match-manager-flat-teams">
                <?php foreach ($teams as $team): ?>
                    <article class="match-manager-team-card">
                        <div>
                            <strong><?= e($team['name']) ?></strong>
                            <span><?= e($team['class_name']) ?> · <?= (int) $team['player_count'] ?> jogador<?= (int) $team['player_count'] === 1 ? '' : 'es' ?></span>
                        </div>
                        <form method="post" data-confirm-submit="Remover este time da modalidade? Jogadores vinculados tambem serao removidos.">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                            <input type="hidden" name="gender" value="<?= e($gender) ?>">
                            <input type="hidden" name="action" value="delete_team">
                            <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger w-100" <?= $event['status'] === 'encerrado' || (int) $team['locked_match_count'] > 0 ? 'disabled' : '' ?>>Remover</button>
                        </form>
                    </article>
                <?php endforeach; ?>
                <?php if (!$teams): ?><div class="match-manager-empty-card">Nenhum time cadastrado.</div><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

</div>

<section class="card shadow-sm match-manager-list-card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <span>Partidas da modalidade</span>
        <span class="small text-muted" id="match-manager-visible-count"><?= count($matches) ?> partida<?= count($matches) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
        <?php if ($matchesTruncated): ?>
            <div class="alert alert-warning py-2 small mb-3">Mostrando as primeiras 500 partidas desta modalidade/gÃªnero para manter a pÃ¡gina leve durante o evento. Use os filtros de modalidade/gÃªnero ou exporte os dados para conferir listas maiores.</div>
        <?php endif; ?>
        <div class="match-manager-filters mb-3">
            <input class="form-control" type="search" id="match-manager-search" placeholder="Buscar por time, fase, grupo, local ou status">
            <select class="form-select" id="match-manager-status-filter">
                <option value="">Todos os status</option>
                <option value="agendada">Agendada</option>
                <option value="em_andamento">Em andamento</option>
                <option value="encerrada">Encerrada</option>
                <option value="wo">W.O.</option>
            </select>
        </div>
        <div class="table-responsive">
            <table class="table table-sm responsive-table match-manager-table">
                <thead>
                    <tr>
                        <th>Fase</th>
                        <th>Confronto</th>
                        <th>Data</th>
                        <th>Local</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $match): ?>
                        <?php
                        $matchScoreData = decode_score_data($match['score_data'] ?? null, $match['modality']);
                        $isCollectiveMatch = !empty($matchScoreData['collective_match']) && empty($match['home_team_id']) && empty($match['away_team_id']);
                        $homeName = $isCollectiveMatch ? 'Partida coletiva' : ($match['home_name'] ?? 'A definir');
                        $awayName = $isCollectiveMatch ? '' : ($match['away_name'] ?? 'A definir');
                        $groupLabel = ($match['group_name'] ?? '') !== '' ? 'Grupo ' . $match['group_name'] : '';
                        $startsValue = $match['starts_at'] ? date('Y-m-d\TH:i', strtotime($match['starts_at'])) : '';
                        $searchText = trim(stage_label($match['stage']) . ' ' . $groupLabel . ' ' . $homeName . ' ' . $awayName . ' ' . ($match['place'] ?? '') . ' ' . match_status_label($match['status']));
                        $searchText = function_exists('mb_strtolower') ? mb_strtolower($searchText, 'UTF-8') : strtolower($searchText);
                        ?>
                        <tr class="match-manager-row" data-status="<?= e($match['status']) ?>" data-search="<?= e($searchText) ?>">
                            <td data-label="Fase">
                                <strong><?= e(stage_label($match['stage'])) ?></strong>
                                <?php if ($groupLabel): ?><span class="d-block small text-muted"><?= e($groupLabel) ?></span><?php endif; ?>
                            </td>
                            <td data-label="Confronto">
                                <span class="match-manager-versus">
                                    <?= e($homeName) ?>
                                    <?php if (!$isCollectiveMatch): ?>
                                        <strong>x</strong> <?= e($awayName) ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td data-label="Data"><?= e(format_datetime($match['starts_at'] ?? null)) ?></td>
                            <td data-label="Local"><?= e($match['place'] ?: 'A definir') ?></td>
                            <td data-label="Status"><span class="<?= e(status_pill_class($match['status'])) ?>"><?= e(match_status_label($match['status'])) ?></span></td>
                            <td data-label="Ações" class="text-end">
                                <div class="match-manager-table-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="index.php?page=match&id=<?= (int) $match['id'] ?>">Ver</a>
                                    <?php if ($match['status'] === 'agendada' && $event['status'] !== 'encerrado'): ?>
                                        <button class="btn btn-sm btn-primary" type="button"
                                            data-edit-match
                                            data-id="<?= (int) $match['id'] ?>"
                                            data-stage="<?= e($match['stage']) ?>"
                                            data-group="<?= e($match['group_name'] ?? '') ?>"
                                            data-home="<?= (int) ($match['home_team_id'] ?? 0) ?>"
                                            data-away="<?= (int) ($match['away_team_id'] ?? 0) ?>"
                                            data-starts="<?= e($startsValue) ?>"
                                            data-place="<?= e($match['place'] ?? '') ?>"
                                            data-sort="<?= (int) ($match['sort_order'] ?? 0) ?>">Editar</button>
                                        <form method="post" class="d-inline" data-confirm-submit="Excluir esta partida? Fases futuras agendadas desta modalidade tambem serao recalculadas.">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                                            <input type="hidden" name="gender" value="<?= e($gender) ?>">
                                            <input type="hidden" name="action" value="delete_match">
                                            <input type="hidden" name="match_id" value="<?= (int) $match['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger">Excluir</button>
                                        </form>
                                    <?php elseif (in_array($match['status'], ['encerrada', 'wo'], true) && !$isCollectiveMatch && $event['status'] !== 'encerrado'): ?>
                                        <button class="btn btn-sm btn-warning" type="button"
                                            data-correct-match
                                            data-id="<?= (int) $match['id'] ?>"
                                            data-stage="<?= e($match['stage']) ?>"
                                            data-status="<?= e($match['status']) ?>"
                                            data-home="<?= (int) ($match['home_team_id'] ?? 0) ?>"
                                            data-away="<?= (int) ($match['away_team_id'] ?? 0) ?>"
                                            data-home-name="<?= e($homeName) ?>"
                                            data-away-name="<?= e($awayName) ?>"
                                            data-home-score="<?= (int) ($match['home_score'] ?? 0) ?>"
                                            data-away-score="<?= (int) ($match['away_score'] ?? 0) ?>"
                                            data-winner="<?= (int) ($match['winner_team_id'] ?? 0) ?>"
                                            data-version="<?= (int) ($match['version'] ?? 0) ?>">Corrigir resultado</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" disabled>Bloqueada</button>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary" href="index.php?page=scoreboard&id=<?= (int) $match['id'] ?>">Placar</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$matches): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma partida criada para esta modalidade.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="match-manager-empty-search d-none" id="match-manager-empty-search">Nenhuma partida encontrada com esses filtros.</div>
    </div>
</section>

<div class="modal fade" id="match-manager-edit-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content match-manager-edit-modal">
            <form method="post" id="match-manager-edit-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                <input type="hidden" name="gender" value="<?= e($gender) ?>">
                <input type="hidden" name="action" value="save_match">
                <input type="hidden" name="match_id" id="match-edit-id">
                <input type="hidden" name="group_name" id="match-edit-group-hidden">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Alterar confronto</h5>
                        <p class="mb-0 text-muted small" id="match-edit-summary">Escolha um time para ver os adversários disponíveis.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="match-manager-edit-grid">
                        <div>
                            <label class="form-label">Fase</label>
                            <select class="form-select" name="stage" id="match-edit-stage" required>
                                <?php foreach ($stageOptions as $stage => $label): ?>
                                    <option value="<?= e($stage) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Grupo</label>
                            <input class="form-control" id="match-edit-group-display" value="A definir" readonly>
                        </div>
                        <div>
                            <label class="form-label">Time escolhido</label>
                            <select class="form-select" name="home_team_id" id="match-edit-home" required></select>
                        </div>
                        <div>
                            <label class="form-label">Adversário</label>
                            <select class="form-select" name="away_team_id" id="match-edit-away" required></select>
                        </div>
                        <div>
                            <label class="form-label">Data e hora</label>
                            <input class="form-control" type="datetime-local" name="starts_at" id="match-edit-starts">
                        </div>
                        <div>
                            <label class="form-label">Ordem</label>
                            <input class="form-control" type="number" name="sort_order" id="match-edit-sort">
                        </div>
                        <div class="match-manager-edit-place">
                            <label class="form-label">Local</label>
                            <input class="form-control" name="place" id="match-edit-place" maxlength="160">
                        </div>
                    </div>
                    <div class="match-manager-edit-hint mt-3" id="match-edit-hint">
                        Na fase de grupos, o adversário precisa estar no mesmo grupo do time escolhido.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" id="match-edit-save">Salvar partida</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="match-manager-create-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content match-manager-edit-modal">
            <form method="post" id="match-manager-create-match-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                <input type="hidden" name="gender" value="<?= e($gender) ?>">
                <input type="hidden" name="action" value="save_match">
                <input type="hidden" name="match_id" value="">
                <input type="hidden" name="group_name" id="match-create-group-hidden">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Criar partida manual</h5>
                        <p class="mb-0 text-muted small" id="match-create-summary">Escolha um time para montar o confronto.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="match-manager-edit-grid">
                        <div>
                            <label class="form-label">Fase</label>
                            <select class="form-select" name="stage" id="match-create-stage" required>
                                <?php foreach ($stageOptions as $stage => $label): ?>
                                    <option value="<?= e($stage) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Grupo</label>
                            <input class="form-control" id="match-create-group-display" value="A definir" readonly>
                        </div>
                        <div>
                            <label class="form-label">Time escolhido</label>
                            <select class="form-select" name="home_team_id" id="match-create-home" required></select>
                        </div>
                        <div>
                            <label class="form-label">Adversário</label>
                            <select class="form-select" name="away_team_id" id="match-create-away" required></select>
                        </div>
                        <div>
                            <label class="form-label">Data e hora</label>
                            <input class="form-control" type="datetime-local" name="starts_at" id="match-create-starts">
                        </div>
                        <div>
                            <label class="form-label">Ordem</label>
                            <input class="form-control" type="number" name="sort_order" id="match-create-sort" value="0">
                        </div>
                        <div class="match-manager-edit-place">
                            <label class="form-label">Local</label>
                            <input class="form-control" name="place" id="match-create-place" maxlength="160" value="<?= e($event['place'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="match-manager-edit-hint mt-3" id="match-create-hint">
                        Na fase de grupos, o adversário será limitado ao mesmo grupo do time escolhido.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" id="match-create-save">Criar partida</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="match-manager-correct-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content match-manager-edit-modal">
            <form method="post" id="match-manager-correct-form" data-confirm-submit="Salvar correcao administrativa deste resultado?">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                <input type="hidden" name="gender" value="<?= e($gender) ?>">
                <input type="hidden" name="action" value="correct_match_result">
                <input type="hidden" name="match_id" id="match-correct-id">
                <input type="hidden" name="version" id="match-correct-version">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Corrigir resultado</h5>
                        <p class="mb-0 text-muted small" id="match-correct-summary">Selecione o vencedor correto.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small">
                        Correcoes administrativas podem alterar ou resetar fases seguintes. Use somente para corrigir erro de lancamento.
                    </div>
                    <label class="form-label">Vencedor correto</label>
                    <div class="d-grid gap-2 mb-3">
                        <label class="btn btn-outline-success text-start">
                            <input class="form-check-input me-2" type="radio" name="winner_side" id="match-correct-home-winner" value="home" required>
                            <span id="match-correct-home-label">Mandante</span>
                        </label>
                        <label class="btn btn-outline-success text-start">
                            <input class="form-check-input me-2" type="radio" name="winner_side" id="match-correct-away-winner" value="away" required>
                            <span id="match-correct-away-label">Visitante</span>
                        </label>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="match-correct-home-score">Placar mandante</label>
                            <input class="form-control" type="number" min="0" name="home_score" id="match-correct-home-score">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="match-correct-away-score">Placar visitante</label>
                            <input class="form-control" type="number" min="0" name="away_score" id="match-correct-away-score">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="match-correct-reason">Motivo da correcao</label>
                        <textarea class="form-control" name="correction_reason" id="match-correct-reason" rows="3" required maxlength="240"></textarea>
                    </div>
                    <p class="small text-muted mb-0">Se uma partida posterior já começou, a correção será bloqueada e os confrontos afetados serão indicados.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-danger">Salvar correcao</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="application/json" data-workspace-config="manager">
<?= json_encode(['teams' => $teamClientData, 'bracketType' => $selectedBracketType, 'createStage' => array_key_first($stageOptions), 'place' => $event['place'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>
