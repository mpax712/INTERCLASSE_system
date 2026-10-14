<?php

require __DIR__ . '/_bootstrap.php';

function score_cache_file(int $matchId): string
{
    return __DIR__ . '/../storage/cache/public/score:' . $matchId . '.json';
}

function score_cache_read(int $matchId): ?array
{
    $file = score_cache_file($matchId);
    if (!is_file($file)) {
        return null;
    }

    $cached = json_decode((string) file_get_contents($file), true);
    if (!is_array($cached) || (int) ($cached['expires_at'] ?? 0) < time()) {
        return null;
    }

    return is_array($cached['payload'] ?? null) ? $cached['payload'] : null;
}

function score_cache_write(int $matchId, array $payload): void
{
    $dir = dirname(score_cache_file($matchId));
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $ttl = ($payload['status'] ?? '') === 'em_andamento' ? 15 : 120;
    @file_put_contents(
        score_cache_file($matchId),
        json_encode(['expires_at' => time() + $ttl, 'payload' => $payload], JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function score_cache_forget(int $matchId): void
{
    $file = score_cache_file($matchId);
    if (is_file($file)) {
        @unlink($file);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $matchId = (int) ($_GET['id'] ?? 0);
    $cached = score_cache_read($matchId);
    if ($cached !== null) {
        json_response($cached);
    }

    $payload = fetch_match_payload($matchId);
    score_cache_write($matchId, $payload);
    json_response($payload);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$user = require_api_roles(['admin', 'professor']);
ensure_score_logs_schema();
$data = json_input();
verify_json_csrf($data);

$matchId = (int) ($data['match_id'] ?? 0);
$action = $data['action'] ?? '';

$stmt = db()->prepare(
    'SELECT m.*, ht.name AS home_name, at.name AS away_name
     FROM matches m
     LEFT JOIN teams ht ON ht.id = m.home_team_id
     LEFT JOIN teams at ON at.id = m.away_team_id
     WHERE m.id = ?'
);
$stmt->execute([$matchId]);
$match = $stmt->fetch();

if (!$match) {
    json_response(['error' => 'Partida não encontrada.'], 404);
}

if (game_is_new_modality((string) $match['modality'])) {
    require_once __DIR__ . '/../services/GameScoreService.php';
    try {
        $result = game_apply_score_action($matchId, $data, $user);
        $payload = fetch_match_payload($matchId);
        $payload['last_action_message'] = $result['duplicate'] ? 'Operação já registrada; placar sincronizado.' : 'Alteração salva com sucesso.';
        json_response($payload);
    } catch (GameScoreException $error) {
        $response = ['error' => $error->getMessage()];
        if ($error->httpStatus === 409) $response['current'] = fetch_match_payload($matchId);
        json_response($response, $error->httpStatus);
    }
}

$requestId = (string) ($data['request_id'] ?? '');
$expectedVersion = filter_var($data['expected_version'] ?? null, FILTER_VALIDATE_INT);
if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $requestId) || $expectedVersion === false || $expectedVersion < 0) {
    json_response(['error' => 'Identificador ou versão da operação inválido.'], 422);
}
db()->beginTransaction();
$eventLock = db()->prepare('SELECT id FROM events WHERE id = (SELECT event_id FROM matches WHERE id = ?) FOR UPDATE');
$eventLock->execute([$matchId]);
if (!$eventLock->fetchColumn()) json_response(['error' => 'Partida não encontrada.'], 404);
$lockedStmt = db()->prepare('SELECT m.*, ht.name AS home_name, at.name AS away_name FROM matches m LEFT JOIN teams ht ON ht.id = m.home_team_id LEFT JOIN teams at ON at.id = m.away_team_id WHERE m.id = ? FOR UPDATE OF m');
$lockedStmt->execute([$matchId]);
$match = $lockedStmt->fetch();
$duplicateStmt = db()->prepare('SELECT id FROM score_logs WHERE match_id = ? AND request_id = ?');
$duplicateStmt->execute([$matchId, $requestId]);
if ($duplicateStmt->fetchColumn()) {
    db()->commit();
    $payload = fetch_match_payload($matchId);
    $payload['last_action_message'] = 'Operação já registrada; placar sincronizado.';
    json_response($payload);
}
if ((int) $match['version'] !== $expectedVersion) {
    db()->rollBack();
    json_response(['error' => 'Esta partida foi alterada por outra pessoa.', 'current' => fetch_match_payload($matchId)], 409);
}

if (!can_operate_match((int) $match['event_id'], $match['modality'])) {
    json_response(['error' => 'Você não tem permissão para operar esta partida.'], 403);
}

$eventStmt = db()->prepare('SELECT status FROM events WHERE id = ?');
$eventStmt->execute([$match['event_id']]);
$eventStatus = $eventStmt->fetchColumn();

if (in_array($eventStatus, ['rascunho', 'encerrado'], true)) {
    json_response(['error' => 'O evento não permite alterações de placar.'], 403);
}

$oldScoreData = decode_score_data($match['score_data'] ?? null, $match['modality']);
$scoreData = $oldScoreData;
$isCollectiveMatch = !empty($scoreData['collective_match']) && empty($match['home_team_id']) && empty($match['away_team_id']);
$existingPlayerNumbers = $scoreData['player_numbers'] ?? null;
$homeScore = (int) $match['home_score'];
$awayScore = (int) $match['away_score'];
$status = $match['status'];
$winnerTeamId = $match['winner_team_id'];
$team = $data['team'] ?? null;
$amount = (int) ($data['amount'] ?? 0);
$scoreType = score_type_for_modality($match['modality']);
if (in_array($action, ['adjust', 'volleyball_point', 'volleyball_set'], true)) {
    $rawAmount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT);
    $allowedAmounts = $action === 'adjust' && $match['modality'] === 'basquete' ? [-1, 1, 2, 3] : [-1, 1];
    if ($rawAmount === false || !in_array($rawAmount, $allowedAmounts, true)) {
        json_response(['error' => 'Ajuste de placar inválido.'], 422);
    }
}

if (!in_array($action, ['assign_numbers', 'wo'], true)) {
    $matchTeamIds = array_filter([
        (int) ($match['home_team_id'] ?? 0),
        (int) ($match['away_team_id'] ?? 0),
    ]);

    if ($matchTeamIds) {
        $overLimitTeams = array_filter(
            over_limit_teams((int) $match['event_id'], $match['modality'], $match['gender'] ?? 'masculino'),
            static fn(array $team): bool => in_array((int) $team['id'], $matchTeamIds, true)
        );

        if ($overLimitTeams) {
            json_response(['error' => 'Regularize o limite de jogadores antes de iniciar ou alterar esta partida.'], 422);
        }
    }
}

if ($action === 'assign_numbers') {
    if ($match['status'] !== 'agendada') {
        json_response(['error' => 'Numeração só pode ser definida antes do início da partida.'], 422);
    }

    $numbers = $data['numbers'] ?? [];
    if (!is_array($numbers)) {
        json_response(['error' => 'Numeração inválida.'], 422);
    }

    if ($numbers && ($match['home_team_id'] || $match['away_team_id'])) {
        $submittedIds = array_values(array_unique(array_map('intval', array_keys($numbers))));
        $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
        $validStmt = db()->prepare("SELECT id FROM players WHERE id IN ($placeholders) AND team_id IN (?, ?)");
        $validStmt->execute(array_merge($submittedIds, [(int) ($match['home_team_id'] ?? 0), (int) ($match['away_team_id'] ?? 0)]));
        if (count($validStmt->fetchAll(PDO::FETCH_COLUMN)) !== count($submittedIds)) {
            json_response(['error' => 'A numeração contém jogador de outra partida.'], 422);
        }
    }

    $validPlayerIds = array_keys($numbers);
    $playerNumbers = [];
    foreach ($validPlayerIds as $playerId) {
        $playerId = (int) $playerId;
        $value = trim((string) ($numbers[$playerId] ?? ''));
        if ($value === '') {
            $playerNumbers[$playerId] = null;
            continue;
        }
        if (!ctype_digit($value)) {
            json_response(['error' => 'Número inválido para jogador ' . $playerId . '.'], 422);
        }
        $playerNumbers[$playerId] = (int) $value;
    }

    $scoreData['player_numbers'] = $playerNumbers;

    if (!empty($data['apply_to_event'])) {
        $playerIds = array_filter(array_map('intval', array_keys($playerNumbers)), fn($id) => $id > 0);
        if ($playerIds) {
            $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
            $stmt = db()->prepare(
                "SELECT id FROM players WHERE id IN ($placeholders) AND team_id IN (?, ?)"
            );
            $params = array_merge($playerIds, [$match['home_team_id'], $match['away_team_id']]);
            $stmt->execute($params);
            $validPlayers = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($validPlayers as $playerId) {
                if (!array_key_exists($playerId, $playerNumbers)) {
                    continue;
                }
                $updatePlayer = db()->prepare('UPDATE players SET jersey_number = ? WHERE id = ?');
                $updatePlayer->execute([$playerNumbers[$playerId], $playerId]);
            }
        }
    }

    $status = $match['status'];
} elseif ($action === 'start') {
    if ($match['status'] !== 'agendada') {
        json_response(['error' => 'Esta partida não pode ser iniciada neste status.'], 422);
    }

    if (!$isCollectiveMatch && (empty($match['home_team_id']) || empty($match['away_team_id']))) {
        json_response(['error' => 'Esta partida ainda aguarda a definicao dos dois participantes.'], 422);
    }

    $status = 'em_andamento';
} elseif ($action === 'await_result') {
    if ($match['status'] !== 'em_andamento') json_response(['error' => 'A partida não está em andamento.'], 422);
    $status = 'aguardando_resultado';
} elseif ($action === 'resume') {
    if ($match['status'] !== 'aguardando_resultado') json_response(['error' => 'Esta partida não aguarda resultado.'], 422);
    $status = 'em_andamento';
} elseif ($action === 'cancel') {
    if ($user['role'] !== 'admin' || !in_array($match['status'], ['agendada','em_andamento','aguardando_resultado'], true)) json_response(['error' => 'Cancelamento indisponível.'], 403);
    $reason = trim((string) ($data['reason'] ?? ''));
    if (strlen($reason) < 3 || strlen($reason) > 240) json_response(['error' => 'Informe o motivo do cancelamento.'], 422);
    $status = 'cancelada';
    $winnerTeamId = null;
} elseif ($action === 'finish') {
    if (!in_array($match['status'], ['em_andamento', 'aguardando_resultado'], true)) {
        json_response(['error' => 'A partida precisa estar em andamento para ser encerrada.'], 422);
    }

    $status = 'encerrada';
    if ($match['modality'] === 'quiz' && $isCollectiveMatch) {
        $scores = [];
        foreach (($scoreData['scores'] ?? []) as $teamId => $score) {
            $scores[(int) $teamId] = (int) $score;
        }
        $teamIds = array_filter(array_map('intval', $scoreData['team_ids'] ?? []));
        $winnerTeamId = null;
        $bestScore = null;
        $isTie = false;

        foreach ($teamIds as $teamId) {
            $score = (int) ($scores[$teamId] ?? 0);
            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $winnerTeamId = $teamId;
                $isTie = false;
            } elseif ($score === $bestScore) {
                $isTie = true;
            }
        }

        $homeScore = $bestScore ?? 0;
        $awayScore = 0;
        if ($isTie) {
            $winnerTeamId = null;
        }
    } elseif ($match['modality'] === 'quiz') {
        $scores = $scoreData['scores'] ?? [];
        $homeScore = (int) ($scores[$match['home_team_id']] ?? 0);
        $awayScore = (int) ($scores[$match['away_team_id']] ?? 0);
    } elseif ($match['modality'] === 'cubo_magico' && $isCollectiveMatch) {
        $averages = $scoreData['player_averages'] ?? [];
        $teamIds = array_filter(array_map('intval', $scoreData['team_ids'] ?? []));
        $allowedTeamSql = '';
        $params = [$match['event_id'], $match['modality']];
        if ($teamIds) {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $allowedTeamSql = " AND t.id IN ($placeholders)";
            $params = array_merge($params, $teamIds);
        }

        $playersStmt = db()->prepare(
            'SELECT p.id, p.team_id
             FROM players p
             JOIN teams t ON t.id = p.team_id
             WHERE t.event_id = ? AND t.modality = ?' . $allowedTeamSql
        );
        $playersStmt->execute($params);

        $bestAverage = null;
        $isTie = false;
        foreach ($playersStmt->fetchAll() as $player) {
            $average = $averages[$player['id']] ?? null;
            if ($average === null) {
                continue;
            }
            if ($bestAverage === null || (float) $average < (float) $bestAverage) {
                $bestAverage = $average;
                $winnerTeamId = (int) $player['team_id'];
                $isTie = false;
            } elseif ((float) $average === (float) $bestAverage) {
                $isTie = true;
            }
        }

        $homeScore = $bestAverage !== null ? 1 : 0;
        $awayScore = 0;
        if ($isTie) {
            $winnerTeamId = null;
        }
    } elseif ($match['modality'] === 'cubo_magico') {
        $averages = $scoreData['player_averages'] ?? [];
        $homePlayerId = (int) ($scoreData['bracket_home_player_id'] ?? 0);
        $awayPlayerId = (int) ($scoreData['bracket_away_player_id'] ?? 0);
        $homeAverage = $homePlayerId ? ($averages[$homePlayerId] ?? null) : null;
        $awayAverage = $awayPlayerId ? ($averages[$awayPlayerId] ?? null) : null;

        if ($homeAverage === null || $awayAverage === null) {
            $playersStmt = db()->prepare(
                'SELECT p.id, p.team_id
                 FROM players p
                 WHERE p.team_id IN (?, ?)'
            );
            $playersStmt->execute([(int) ($match['home_team_id'] ?? 0), (int) ($match['away_team_id'] ?? 0)]);
            foreach ($playersStmt->fetchAll() as $player) {
                $average = $averages[$player['id']] ?? null;
                if ($average === null) {
                    continue;
                }
                if ((int) $player['team_id'] === (int) $match['home_team_id'] && ($homeAverage === null || (float) $average < (float) $homeAverage)) {
                    $homeAverage = $average;
                }
                if ((int) $player['team_id'] === (int) $match['away_team_id'] && ($awayAverage === null || (float) $average < (float) $awayAverage)) {
                    $awayAverage = $average;
                }
            }
        }

        if ($homeAverage !== null && $awayAverage !== null) {
            $homeScore = (float) $homeAverage < (float) $awayAverage ? 1 : 0;
            $awayScore = (float) $awayAverage < (float) $homeAverage ? 1 : 0;
        }
    }

    if (!$isCollectiveMatch && $homeScore > $awayScore) {
        $winnerTeamId = $match['home_team_id'];
    } elseif (!$isCollectiveMatch && $awayScore > $homeScore) {
        $winnerTeamId = $match['away_team_id'];
    } elseif (!$isCollectiveMatch) {
        $winnerTeamId = null;
    }
    if (in_array($match['stage'], ['preliminar','dezesseis_avos','oitavas','quartas','semifinal','final'], true) && !$isCollectiveMatch && !$winnerTeamId) {
        json_response(['error' => 'Partida eliminatória precisa ter um vencedor.'], 422);
    }
    if ($existingPlayerNumbers !== null) {
        $scoreData['player_numbers'] = $existingPlayerNumbers;
    }
} elseif ($action === 'adjust') {
    if ($match['status'] !== 'em_andamento') {
        json_response(['error' => 'Inicie a partida antes de alterar o placar.'], 422);
    }

    if ($match['modality'] === 'quiz') {
        $teamId = (int) ($data['team_id'] ?? 0);
        if ($teamId <= 0) {
            json_response(['error' => 'Equipe inválida para quiz.'], 422);
        }

        $teamCheckSql = 'SELECT id FROM teams WHERE event_id = ? AND modality = ? AND id = ?';
        $teamCheckParams = [$match['event_id'], $match['modality'], $teamId];
        if ($isCollectiveMatch) {
            $teamIds = array_filter(array_map('intval', $scoreData['team_ids'] ?? []));
            if ($teamIds) {
                $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
                $teamCheckSql .= " AND id IN ($placeholders)";
                $teamCheckParams = array_merge($teamCheckParams, $teamIds);
            }
        } else {
            $teamCheckSql .= ' AND id IN (?, ?)';
            $teamCheckParams[] = (int) ($match['home_team_id'] ?? 0);
            $teamCheckParams[] = (int) ($match['away_team_id'] ?? 0);
        }
        $teamCheck = db()->prepare($teamCheckSql);
        $teamCheck->execute($teamCheckParams);
        if (!$teamCheck->fetch()) {
            json_response(['error' => 'Equipe não encontrada para este quiz.'], 404);
        }

        $scores = $scoreData['scores'] ?? [];
        $scores[$teamId] = max(0, ($scores[$teamId] ?? 0) + $amount);
        $scoreData['scores'] = $scores;
        $homeScore = 0;
        $awayScore = 0;
    } elseif (!in_array($team, ['home', 'away'], true)) {
        json_response(['error' => 'Time inválido.'], 422);
    } elseif ($match['modality'] === 'basquete') {
        $quarter = max(0, min(3, (int) ($data['quarter'] ?? 0)));
        $scoreData['quarters'][$quarter][$team] = max(0, (int) $scoreData['quarters'][$quarter][$team] + $amount);
        $homeScore = array_sum(array_column($scoreData['quarters'], 'home'));
        $awayScore = array_sum(array_column($scoreData['quarters'], 'away'));
    } else {
        $scoreData[$team] = max(0, (int) ($scoreData[$team] ?? 0) + $amount);
        $homeScore = max(0, $team === 'home' ? $homeScore + $amount : $homeScore);
        $awayScore = max(0, $team === 'away' ? $awayScore + $amount : $awayScore);
    }
} elseif ($action === 'save_cube_attempts') {
    if ($match['modality'] !== 'cubo_magico') {
        json_response(['error' => 'Ação inválida para esta modalidade.'], 422);
    }

    if ($match['status'] !== 'em_andamento') {
        json_response(['error' => 'Inicie a partida antes de registrar tentativas.'], 422);
    }

    $playerId = (int) ($data['player_id'] ?? 0);
    if ($playerId <= 0) {
        json_response(['error' => 'Jogador inválido.'], 422);
    }

    $attempts = $data['attempts'] ?? [];
    if (!is_array($attempts) || count($attempts) === 0) {
        json_response(['error' => 'Nenhuma tentativa informada.'], 422);
    }

    $validAttempts = [];
    foreach ($attempts as $attempt) {
        $value = trim((string) $attempt);
        if ($value === '') {
            continue;
        }

        if (!is_numeric($value) || (float) $value < 0) {
            json_response(['error' => 'Tempo de tentativa inválido.'], 422);
        }

        $validAttempts[] = round((float) $value, 2);
    }

    if (count($validAttempts) === 0) {
        json_response(['error' => 'Nenhuma tentativa válida informada.'], 422);
    }

    $bracketPlayerIds = array_filter([
        (int) ($scoreData['bracket_home_player_id'] ?? 0),
        (int) ($scoreData['bracket_away_player_id'] ?? 0),
    ]);
    $teamIds = array_filter([
        (int) ($match['home_team_id'] ?? 0),
        (int) ($match['away_team_id'] ?? 0),
    ]);
    $params = [$playerId, $match['event_id'], $match['modality']];
    $extraWhere = '';
    if ($bracketPlayerIds) {
        $placeholders = implode(',', array_fill(0, count($bracketPlayerIds), '?'));
        $extraWhere = " AND p.id IN ($placeholders)";
        $params = array_merge($params, $bracketPlayerIds);
    } elseif ($teamIds) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $extraWhere = " AND t.id IN ($placeholders)";
        $params = array_merge($params, $teamIds);
    }

    $playerCheck = db()->prepare(
        'SELECT p.id
         FROM players p
         JOIN teams t ON t.id = p.team_id
         WHERE p.id = ? AND t.event_id = ? AND t.modality = ?' . $extraWhere
    );
    $playerCheck->execute($params);
    if (!$playerCheck->fetch()) {
        json_response(['error' => 'Jogador não pertence aos times desta partida.'], 404);
    }

    $scoreData['player_attempts'] = $scoreData['player_attempts'] ?? [];
    $scoreData['player_averages'] = $scoreData['player_averages'] ?? [];
    $scoreData['player_attempts'][$playerId] = $validAttempts;
    $scoreData['player_averages'][$playerId] = round(array_sum($validAttempts) / count($validAttempts), 2);
    $homeScore = 0;
    $awayScore = 0;
} elseif ($action === 'volleyball_point') {
    if ($match['status'] !== 'em_andamento') {
        json_response(['error' => 'Inicie a partida antes de alterar o placar.'], 422);
    }

    if (!in_array($team, ['home', 'away'], true)) {
        json_response(['error' => 'Time inválido.'], 422);
    }

    $lastIndex = max(0, count($scoreData['sets']) - 1);
    $scoreData['sets'][$lastIndex][$team] = max(0, (int) $scoreData['sets'][$lastIndex][$team] + $amount);
} elseif ($action === 'volleyball_set') {
    if ($match['status'] !== 'em_andamento') {
        json_response(['error' => 'Inicie a partida antes de alterar o placar.'], 422);
    }

    if (!in_array($team, ['home', 'away'], true)) {
        json_response(['error' => 'Time inválido.'], 422);
    }

    $setKey = $team . '_sets';
    $currentSets = max(0, (int) ($scoreData[$setKey] ?? 0));
    $scoreData['sets'] = $scoreData['sets'] ?? [['home' => 0, 'away' => 0]];

    if ($amount < 0) {
        if ($currentSets <= 0) {
            json_response(['error' => 'Este time ainda não tem set para remover.'], 422);
        }

        $scoreData[$setKey] = $currentSets - 1;
        $lastSet = end($scoreData['sets']);
        if (count($scoreData['sets']) > 1 && (int) ($lastSet['home'] ?? 0) === 0 && (int) ($lastSet['away'] ?? 0) === 0) {
            array_pop($scoreData['sets']);
        }
    } elseif ($team === 'home') {
        $scoreData['home_sets'] = $currentSets + 1;
    } else {
        $scoreData['away_sets'] = $currentSets + 1;
    }

    $homeScore = (int) $scoreData['home_sets'];
    $awayScore = (int) $scoreData['away_sets'];
    if ($amount >= 0) {
        $scoreData['sets'][] = ['home' => 0, 'away' => 0];
    }
} elseif ($action === 'wo') {
    if ($match['status'] !== 'agendada') {
        json_response(['error' => 'W.O. só pode ser marcado antes da partida iniciar.'], 422);
    }

    if (!in_array($team, ['home', 'away'], true)) {
        json_response(['error' => 'Time inválido.'], 422);
    }

    $winnerSide = $team === 'home' ? 'away' : 'home';
    $winnerTeamId = $winnerSide === 'home' ? $match['home_team_id'] : $match['away_team_id'];
    $status = 'wo';

    if ($scoreType === 'sets') {
        $homeScore = $winnerSide === 'home' ? 2 : 0;
        $awayScore = $winnerSide === 'away' ? 2 : 0;
        $scoreData = ['sets' => [], 'home_sets' => $homeScore, 'away_sets' => $awayScore];
    } elseif ($scoreType === 'pontos') {
        $woPoints = $match['modality'] === 'basquete' ? 20 : 10;
        $homeScore = $winnerSide === 'home' ? $woPoints : 0;
        $awayScore = $winnerSide === 'away' ? $woPoints : 0;
        $scoreData = $match['modality'] === 'basquete' ? default_score_data('basquete') : ['home' => $homeScore, 'away' => $awayScore];
    } else {
        $homeScore = $winnerSide === 'home' ? 3 : 0;
        $awayScore = $winnerSide === 'away' ? 3 : 0;
        $scoreData = ['home' => $homeScore, 'away' => $awayScore];
    }
    if ($existingPlayerNumbers !== null) {
        $scoreData['player_numbers'] = $existingPlayerNumbers;
    }
} elseif ($action === 'set_winner') {
    if (!is_individual_modality($match['modality'])) {
        json_response(['error' => 'Ação apenas válida para modalidades individuais.'], 422);
    }

    if ($match['status'] !== 'em_andamento') {
        json_response(['error' => 'Inicie a partida antes de definir o vencedor.'], 422);
    }

    if (!in_array($team, ['home', 'away'], true)) {
        json_response(['error' => 'Time inválido.'], 422);
    }

    $winnerTeamId = $team === 'home' ? $match['home_team_id'] : $match['away_team_id'];
    $scoreData = array_merge($oldScoreData, ['winner' => $team]);
    if ($existingPlayerNumbers !== null) {
        $scoreData['player_numbers'] = $existingPlayerNumbers;
    }
    $homeScore = $team === 'home' ? 1 : 0;
    $awayScore = $team === 'away' ? 1 : 0;
    $status = 'encerrada';
} else {
    json_response(['error' => 'Ação inválida.'], 422);
}

$update = db()->prepare(
    'UPDATE matches
     SET home_score = ?, away_score = ?, score_data = ?, status = ?, winner_team_id = ?, version = version + 1, cancel_reason = ?, cancelled_by = ?
     WHERE id = ?'
);
$update->execute([
    $homeScore,
    $awayScore,
    json_encode($scoreData, JSON_UNESCAPED_UNICODE),
    $status,
    $winnerTeamId,
    $action === 'cancel' ? $reason : ($match['cancel_reason'] ?? null),
    $action === 'cancel' ? (int) $user['id'] : ($match['cancelled_by'] ?? null),
    $matchId,
]);

function score_action_description(string $action, array $data, array $match, int $oldHomeScore, int $oldAwayScore, int $newHomeScore, int $newAwayScore, string $oldStatus, string $newStatus): string
{
    $team = $data['team'] ?? '';
    $teamName = $team === 'home'
        ? ($match['home_name'] ?? 'Time da casa')
        : ($team === 'away' ? ($match['away_name'] ?? 'Time visitante') : 'Equipe');
    $amount = (int) ($data['amount'] ?? 0);

    $description = 'Alterou a partida.';
    if ($action === 'assign_numbers') {
        $description = 'Definiu a numeração dos jogadores.';
    } elseif ($action === 'start') {
        $description = 'Iniciou a partida.';
    } elseif ($action === 'finish') {
        $description = 'Encerrou a partida.';
    } elseif ($action === 'adjust') {
        $description = $match['modality'] === 'quiz'
            ? 'Alterou pontuação do quiz em ' . ($amount > 0 ? '+' : '') . $amount . ' ponto(s).'
            : 'Alterou placar de ' . $teamName . ' em ' . ($amount > 0 ? '+' : '') . $amount . '.';
    } elseif ($action === 'save_cube_attempts') {
        $description = 'Registrou tentativas do Cubo Mágico para o jogador #' . (int) ($data['player_id'] ?? 0) . '.';
    } elseif ($action === 'volleyball_point') {
        $description = 'Alterou ponto do set de ' . $teamName . ' em ' . ($amount > 0 ? '+' : '') . $amount . '.';
    } elseif ($action === 'volleyball_set') {
        $description = $amount < 0 ? 'Removeu set de ' . $teamName . '.' : 'Adicionou set para ' . $teamName . '.';
    } elseif ($action === 'wo') {
        $description = 'Registrou W.O. envolvendo ' . $teamName . '.';
    } elseif ($action === 'set_winner') {
        $description = 'Definiu ' . $teamName . ' como vencedor.';
    } elseif ($action === 'cancel') {
        $description = 'Cancelou a partida. Motivo: ' . trim((string) ($data['reason'] ?? ''));
    }

    return $description . ' Antes: ' . $oldHomeScore . ' x ' . $oldAwayScore . ' (' . match_status_label($oldStatus) . '). Depois: ' . $newHomeScore . ' x ' . $newAwayScore . ' (' . match_status_label($newStatus) . ').';
}

$description = score_action_description(
    $action,
    $data,
    $match,
    (int) $match['home_score'],
    (int) $match['away_score'],
    $homeScore,
    $awayScore,
    (string) $match['status'],
    (string) $status
);

$log = db()->prepare(
        'INSERT INTO score_logs
            (match_id, user_id, action, description, old_home_score, old_away_score, new_home_score, new_away_score, old_status, new_status, old_score_data, new_score_data, request_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
$log->execute([
        $matchId,
        $user['id'],
        $action,
        (function_exists("mb_substr") ? mb_substr($description, 0, 255) : substr($description, 0, 255)),
        (int) $match['home_score'],
        (int) $match['away_score'],
        $homeScore,
        $awayScore,
        $match['status'],
        $status,
        json_encode($oldScoreData, JSON_UNESCAPED_UNICODE),
        json_encode($scoreData, JSON_UNESCAPED_UNICODE),
        $requestId,
    ]);

if (in_array($action, ['finish', 'wo', 'set_winner'], true) && in_array($match['stage'], ['preliminar', 'dezesseis_avos', 'oitavas', 'quartas', 'semifinal'], true)) {
    maybe_generate_next_knockout_stage((int) $match['event_id'], $match['modality'], $match['stage'], $match['gender'] ?? 'masculino');
}

// Automatically calculate class points when finals complete
if (in_array($action, ['finish', 'wo', 'set_winner'], true) && $match['stage'] === 'final') {
    calculate_class_points((int) $match['event_id'], $match['modality'], $match['gender']);
}
if (in_array($action, ['finish', 'wo', 'set_winner'], true) && $match['stage'] === 'preliminar') {
    calculate_class_points((int) $match['event_id'], $match['modality'], $match['gender']);
}
if (in_array($action, ['finish', 'wo', 'set_winner'], true) && $match['stage'] === 'grupo') {
    calculate_class_points((int) $match['event_id'], $match['modality'], $match['gender']);
}

$messages = [
    'assign_numbers' => 'Numeração salva.',
    'start' => 'Partida iniciada.',
    'finish' => 'Partida encerrada.',
    'adjust' => 'Placar atualizado.',
    'save_cube_attempts' => 'Tentativas salvas.',
    'volleyball_point' => 'Ponto atualizado.',
    'volleyball_set' => 'Set atualizado.',
    'wo' => 'W.O. registrado.',
    'set_winner' => 'Vencedor definido.',
    'await_result' => 'Partida aguardando resultado.',
    'resume' => 'Partida retomada.',
    'cancel' => 'Partida cancelada.',
];

db()->commit();
score_cache_forget($matchId);
public_cache_forget_all();

$payload = fetch_match_payload($matchId);
$payload['last_action_message'] = $messages[$action] ?? 'Alteração salva.';
json_response($payload);
