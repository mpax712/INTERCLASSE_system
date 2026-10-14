<?php

require_once __DIR__ . '/../includes/match_helpers.php';

function game_players(int $eventId, string $modality): array
{
    $stmt = db()->prepare('SELECT p.id, p.name, p.team_id, p.group_name, t.class_name, t.name AS team_name
        FROM players p JOIN teams t ON t.id = p.team_id
        WHERE t.event_id = ? AND t.modality = ? ORDER BY t.class_name, p.name, p.id');
    $stmt->execute([$eventId, $modality]);
    return $stmt->fetchAll();
}

function game_node_for_player(array $player): array
{
    return ['player_id' => (int) $player['id'], 'team_id' => (int) $player['team_id'], 'match_id' => null];
}

function game_create_match(int $eventId, string $modality, string $stage, ?string $group, array $home, array $away, int $order, ?string $place = null): int
{
    $score = $modality === 'clash_royale' ? ['battles' => []] : ['decided_by' => null, 'penalties' => null];
    $insert = db()->prepare('INSERT INTO matches
        (event_id, modality, gender, stage, group_name, home_team_id, away_team_id,
         home_player_id, away_player_id, home_source_match_id, away_source_match_id,
         score_data, rules_snapshot, place, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([
        $eventId, $modality, 'masculino', $stage, $group,
        $home['team_id'] ?? null, $away['team_id'] ?? null,
        $home['player_id'] ?? null, $away['player_id'] ?? null,
        $home['match_id'] ?? null, $away['match_id'] ?? null,
        json_encode($score, JSON_UNESCAPED_UNICODE),
        json_encode(game_event_rules($eventId, $modality), JSON_UNESCAPED_UNICODE),
        $place, $order,
    ]);
    return (int) db()->lastInsertId();
}

function game_generate_groups(int $eventId, string $modality): int
{
    if ($modality !== 'fifa' || bracket_type_for_event_modality($eventId, $modality) !== 'groups') {
        throw new RuntimeException('Esta modalidade não usa fase de grupos.');
    }
    db()->beginTransaction();
    try {
        $lock = db()->prepare('SELECT id FROM event_modalities WHERE event_id = ? AND modality = ? FOR UPDATE');
        $lock->execute([$eventId, $modality]);
        if (!$lock->fetch()) {
            throw new RuntimeException('Modalidade não pertence ao evento.');
        }
        $existing = db()->prepare('SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ?');
        $existing->execute([$eventId, $modality]);
        if ((int) $existing->fetchColumn() > 0) {
            throw new RuntimeException('Esta modalidade já possui partidas.');
        }
        $players = game_players($eventId, $modality);
        if (count($players) < 2 || count($players) > 32) {
            throw new RuntimeException('A chave aceita de 2 a 32 alunos.');
        }
        $groupCount = max(1, (int) ceil(count($players) / 4));
        if ($groupCount > 26) {
            throw new RuntimeException('O limite é 26 grupos.');
        }
        $groups = array_fill(0, $groupCount, []);
        foreach ($players as $index => $player) {
            $groupIndex = $index % $groupCount;
            $groups[$groupIndex][] = $player;
            $update = db()->prepare('UPDATE players SET group_name = ? WHERE id = ?');
            $update->execute([chr(65 + $groupIndex), $player['id']]);
        }
        $created = 0;
        foreach ($groups as $index => $groupPlayers) {
            for ($i = 0; $i < count($groupPlayers); $i++) {
                for ($j = $i + 1; $j < count($groupPlayers); $j++) {
                    game_create_match($eventId, $modality, 'grupo', chr(65 + $index), game_node_for_player($groupPlayers[$i]), game_node_for_player($groupPlayers[$j]), ++$created);
                }
            }
        }
        db()->commit();
        return $created;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
}

function game_generate_bracket(int $eventId, string $modality, ?array $qualifiedPlayers = null): int
{
    if (!game_is_new_modality($modality)) {
        throw new RuntimeException('Modalidade não suportada.');
    }
    db()->beginTransaction();
    try {
        $lock = db()->prepare('SELECT id, bracket_type FROM event_modalities WHERE event_id = ? AND modality = ? FOR UPDATE');
        $lock->execute([$eventId, $modality]);
        $config = $lock->fetch();
        if (!$config) throw new RuntimeException('Modalidade não pertence ao evento.');
        $existing = db()->prepare("SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ? AND stage IN ('dezesseis_avos','oitavas','quartas','semifinal','final')");
        $existing->execute([$eventId, $modality]);
        if ((int) $existing->fetchColumn() > 0) throw new RuntimeException('O chaveamento já foi criado.');
        if ($config['bracket_type'] === 'groups') {
            $groupMatches = db()->prepare("SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ? AND stage = 'grupo'");
            $groupMatches->execute([$eventId, $modality]);
            if ((int) $groupMatches->fetchColumn() === 0) {
                throw new RuntimeException('Gere as partidas de grupo antes do mata-mata.');
            }
            $pending = db()->prepare("SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ? AND stage = 'grupo' AND status NOT IN ('encerrada','wo','cancelada')");
            $pending->execute([$eventId, $modality]);
            if ((int) $pending->fetchColumn() > 0) throw new RuntimeException('Finalize as partidas de grupo antes do mata-mata.');
            if ($qualifiedPlayers === null) {
                $qualifiedPlayers = [];
                $players = game_players($eventId, $modality);
                $groupNames = array_unique(array_filter(array_column($players, 'group_name')));
                foreach ($groupNames as $group) {
                    foreach (array_slice(game_group_standings($eventId, $modality, $group), 0, 2) as $row) {
                        $qualifiedPlayers[] = $row['player_id'];
                    }
                }
            }
        }
        $players = game_players($eventId, $modality);
        if ($qualifiedPlayers !== null) {
            $allowed = array_fill_keys(array_map('intval', $qualifiedPlayers), true);
            $players = array_values(array_filter($players, static fn(array $p): bool => isset($allowed[(int) $p['id']])));
        }
        if (count($players) < 2 || count($players) > 32) throw new RuntimeException('O chaveamento aceita de 2 a 32 alunos.');
        shuffle($players);
        $capacity = 2;
        while ($capacity < count($players)) $capacity *= 2;
        $byeCount = $capacity - count($players);
        $nodes = [];
        $playerIndex = 0;
        for ($pair = 0; $pair < $capacity / 2; $pair++) {
            $nodes[] = game_node_for_player($players[$playerIndex++]);
            $nodes[] = $pair < $byeCount ? [] : game_node_for_player($players[$playerIndex++]);
        }
        $created = 0;
        $roundSize = $capacity;
        while ($roundSize >= 2) {
            $stage = match ($roundSize) {32 => 'dezesseis_avos', 16 => 'oitavas', 8 => 'quartas', 4 => 'semifinal', default => 'final'};
            $next = [];
            for ($i = 0; $i < count($nodes); $i += 2) {
                $home = $nodes[$i];
                $away = $nodes[$i + 1];
                if (!$home && !$away) { $next[] = []; continue; }
                if (!$home || !$away) { $next[] = $home ?: $away; continue; }
                $matchId = game_create_match($eventId, $modality, $stage, null, $home, $away, 500 + $created);
                $created++;
                $next[] = ['match_id' => $matchId];
            }
            $nodes = $next;
            $roundSize /= 2;
        }
        db()->commit();
        return $created;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
}

function game_group_standings(int $eventId, string $modality, ?string $group = null): array
{
    $players = game_players($eventId, $modality);
    $rows = [];
    foreach ($players as $player) {
        if ($group !== null && $player['group_name'] !== $group) continue;
        $rows[(int) $player['id']] = ['player_id' => (int) $player['id'], 'player_name' => $player['name'], 'class_name' => $player['class_name'], 'group_name' => $player['group_name'], 'P' => 0, 'J' => 0, 'V' => 0, 'E' => 0, 'D' => 0, 'GP' => 0, 'GC' => 0, 'SG' => 0];
    }
    $sql = "SELECT home_player_id, away_player_id, home_score, away_score, status, rules_snapshot FROM matches WHERE event_id = ? AND modality = ? AND stage = 'grupo' AND status IN ('encerrada','wo')";
    $params = [$eventId, $modality];
    if ($group !== null) { $sql .= ' AND group_name = ?'; $params[] = $group; }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $headToHeadMatches = [];
    foreach ($stmt->fetchAll() as $match) {
        $h = (int) $match['home_player_id']; $a = (int) $match['away_player_id'];
        if (!isset($rows[$h], $rows[$a])) continue;
        $hs = (int) $match['home_score']; $as = (int) $match['away_score'];
        $matchRules = game_match_rules(['event_id' => $eventId, 'modality' => $modality, 'rules_snapshot' => $match['rules_snapshot']]);
        $rows[$h]['J']++; $rows[$a]['J']++;
        $rows[$h]['GP'] += $hs; $rows[$h]['GC'] += $as;
        $rows[$a]['GP'] += $as; $rows[$a]['GC'] += $hs;
        if ($hs > $as) { $rows[$h]['V']++; $rows[$a]['D']++; $rows[$h]['P'] += $matchRules['win_points']; $rows[$a]['P'] += $matchRules['loss_points']; $headToHeadMatches[] = [$h, $a, $matchRules['win_points'], $matchRules['loss_points']]; }
        elseif ($as > $hs) { $rows[$a]['V']++; $rows[$h]['D']++; $rows[$a]['P'] += $matchRules['win_points']; $rows[$h]['P'] += $matchRules['loss_points']; $headToHeadMatches[] = [$h, $a, $matchRules['loss_points'], $matchRules['win_points']]; }
        else { $rows[$h]['E']++; $rows[$a]['E']++; $rows[$h]['P'] += $matchRules['draw_points']; $rows[$a]['P'] += $matchRules['draw_points']; $headToHeadMatches[] = [$h, $a, $matchRules['draw_points'], $matchRules['draw_points']]; }
    }
    foreach ($rows as &$row) $row['SG'] = $row['GP'] - $row['GC'];
    unset($row);
    $tieGroups = [];
    foreach ($rows as $row) $tieGroups[$row['P'] . ':' . $row['SG'] . ':' . $row['GP']][] = $row['player_id'];
    foreach ($tieGroups as $members) {
        if (count($members) < 2) continue;
        $memberSet = array_fill_keys($members, true);
        foreach ($headToHeadMatches as [$home, $away, $homePoints, $awayPoints]) {
            if (isset($memberSet[$home], $memberSet[$away])) {
                $rows[$home]['direct_points'] = ($rows[$home]['direct_points'] ?? 0) + $homePoints;
                $rows[$away]['direct_points'] = ($rows[$away]['direct_points'] ?? 0) + $awayPoints;
            }
        }
    }
    $rows = array_values($rows);
    usort($rows, static function (array $a, array $b): int {
        foreach (['P','SG','GP'] as $key) if ($a[$key] !== $b[$key]) return $b[$key] <=> $a[$key];
        if (($a['direct_points'] ?? 0) !== ($b['direct_points'] ?? 0)) return ($b['direct_points'] ?? 0) <=> ($a['direct_points'] ?? 0);
        return ($b['V'] <=> $a['V']) ?: ($a['player_id'] <=> $b['player_id']);
    });
    return $rows;
}

function game_advance_winner(array $match): void
{
    $winnerId = (int) ($match['winner_player_id'] ?? 0);
    if (!$winnerId) return;
    $winnerTeam = $winnerId === (int) $match['home_player_id'] ? (int) $match['home_team_id'] : (int) $match['away_team_id'];
    foreach (['home', 'away'] as $side) {
        $stmt = db()->prepare("SELECT id, status FROM matches WHERE {$side}_source_match_id = ? FOR UPDATE");
        $stmt->execute([$match['id']]);
        $next = $stmt->fetch();
        if (!$next) continue;
        if ($next['status'] !== 'agendada') throw new RuntimeException('Partida posterior já iniciada.');
        $update = db()->prepare("UPDATE matches SET {$side}_player_id = ?, {$side}_team_id = ?, version = version + 1 WHERE id = ?");
        $update->execute([$winnerId, $winnerTeam, $next['id']]);
    }
}

function game_knockout_standings(int $eventId, string $modality): array
{
    $rows = [];
    foreach (game_players($eventId, $modality) as $player) {
        $rows[(int) $player['id']] = ['player_id' => (int) $player['id'], 'player_name' => $player['name'], 'class_name' => $player['class_name'], 'P' => 0, 'J' => 0, 'V' => 0, 'D' => 0, 'GP' => 0, 'GC' => 0, 'SG' => 0, 'stage_rank' => 0];
    }
    $stmt = db()->prepare("SELECT stage, home_player_id, away_player_id, winner_player_id, home_score, away_score FROM matches WHERE event_id = ? AND modality = ? AND stage <> 'grupo' AND status IN ('encerrada','wo')");
    $stmt->execute([$eventId, $modality]);
    $stages = ['dezesseis_avos' => 1, 'oitavas' => 2, 'quartas' => 3, 'semifinal' => 4, 'final' => 5];
    foreach ($stmt->fetchAll() as $match) {
        $home = (int) $match['home_player_id']; $away = (int) $match['away_player_id'];
        if (!isset($rows[$home], $rows[$away])) continue;
        foreach ([$home, $away] as $id) { $rows[$id]['J']++; $rows[$id]['stage_rank'] = max($rows[$id]['stage_rank'], $stages[$match['stage']] ?? 0); }
        $rows[$home]['GP'] += (int) $match['home_score']; $rows[$home]['GC'] += (int) $match['away_score'];
        $rows[$away]['GP'] += (int) $match['away_score']; $rows[$away]['GC'] += (int) $match['home_score'];
        $winner = (int) $match['winner_player_id'];
        if ($winner) { $rows[$winner]['V']++; $loser = $winner === $home ? $away : $home; $rows[$loser]['D']++; }
        if ($match['stage'] === 'final' && $winner) $rows[$winner]['stage_rank'] = 6;
    }
    foreach ($rows as &$row) $row['SG'] = $row['GP'] - $row['GC'];
    unset($row);
    $rows = array_values($rows);
    usort($rows, static fn(array $a, array $b): int => ($b['stage_rank'] <=> $a['stage_rank']) ?: ($b['V'] <=> $a['V']) ?: ($b['SG'] <=> $a['SG']) ?: ($b['GP'] <=> $a['GP']) ?: ($a['player_id'] <=> $b['player_id']));
    return $rows;
}

function game_recalculate_class_points(int $eventId, string $modality): void
{
    $finished = db()->prepare("SELECT rules_snapshot FROM matches WHERE event_id = ? AND modality = ? AND stage = 'final' AND status IN ('encerrada','wo') ORDER BY id DESC LIMIT 1");
    $finished->execute([$eventId, $modality]);
    $finalSnapshot = $finished->fetchColumn();
    $complete = $finalSnapshot !== false;
    $old = db()->prepare('SELECT cs.id FROM class_standings cs JOIN class_modality_points cmp ON cmp.class_standings_id = cs.id WHERE cs.event_id = ? AND cmp.modality = ?');
    $old->execute([$eventId, $modality]);
    $touched = array_map('intval', array_column($old->fetchAll(), 'id'));
    $delete = db()->prepare('DELETE FROM class_modality_points WHERE modality = ? AND class_standings_id IN (SELECT id FROM class_standings WHERE event_id = ?)');
    $delete->execute([$modality, $eventId]);
    if ($complete) {
        $awardRules = game_match_rules(['event_id' => $eventId, 'modality' => $modality, 'rules_snapshot' => $finalSnapshot]);
        $byClass = [];
        foreach (game_knockout_standings($eventId, $modality) as $index => $row) {
            if ($row['stage_rank'] === 0) continue;
            $class = trim((string) $row['class_name']) ?: 'Sem turma';
            $points = [1 => $awardRules['class_points_first'], 2 => $awardRules['class_points_second'], 3 => $awardRules['class_points_third']][($index + 1)] ?? $awardRules['class_points_other'];
            $byClass[$class]['points'] = ($byClass[$class]['points'] ?? 0) + $points;
            $byClass[$class]['position'] = min($byClass[$class]['position'] ?? PHP_INT_MAX, $index + 1);
        }
        foreach ($byClass as $class => $data) {
            $select = db()->prepare('SELECT id FROM class_standings WHERE event_id = ? AND class_name = ?');
            $select->execute([$eventId, $class]);
            $id = $select->fetchColumn();
            if (!$id) {
                $insert = db()->prepare('INSERT INTO class_standings (event_id, class_name, total_points) VALUES (?, ?, 0)');
                $insert->execute([$eventId, $class]);
                $id = db()->lastInsertId();
            }
            $insert = db()->prepare('INSERT INTO class_modality_points (class_standings_id, modality, gender, position, points) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$id, $modality, 'masculino', $data['position'], $data['points']]);
            $touched[] = (int) $id;
        }
    }
    foreach (array_unique($touched) as $id) {
        $update = db()->prepare('UPDATE class_standings SET total_points = (SELECT COALESCE(SUM(points), 0) FROM class_modality_points WHERE class_standings_id = ?) WHERE id = ?');
        $update->execute([$id, $id]);
    }
}
