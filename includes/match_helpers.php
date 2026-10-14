<?php

require_once __DIR__ . '/helpers.php';

function default_score_data(string $modality): array
{
    if ($modality === 'volei') {
        return [
            'sets' => [
                ['home' => 0, 'away' => 0],
            ],
            'home_sets' => 0,
            'away_sets' => 0,
        ];
    }

    if ($modality === 'basquete') {
        return [
            'quarters' => [
                ['home' => 0, 'away' => 0],
                ['home' => 0, 'away' => 0],
                ['home' => 0, 'away' => 0],
                ['home' => 0, 'away' => 0],
            ],
        ];
    }

    if ($modality === 'quiz') {
        return ['scores' => []];
    }

    if ($modality === 'cubo_magico') {
        return [
            'player_attempts' => [],
            'player_averages' => [],
        ];
    }

    if (is_individual_modality($modality)) {
        return ['winner' => null];
    }

    return ['home' => 0, 'away' => 0];
}

function decode_score_data(?string $json, string $modality): array
{
    if (!$json) {
        return default_score_data($modality);
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : default_score_data($modality);
}

function match_score_text(array $match): string
{
    if (($match['modality'] ?? '') === 'clash_royale') {
        return (int) ($match['home_score'] ?? 0) . ' x ' . (int) ($match['away_score'] ?? 0) . ' batalhas';
    }
    if (($match['modality'] ?? '') === 'fifa') {
        $text = (int) ($match['home_score'] ?? 0) . ' x ' . (int) ($match['away_score'] ?? 0);
        $data = decode_score_data($match['score_data'] ?? null, 'fifa');
        if (is_array($data['penalties'] ?? null)) {
            $text .= ' · pênaltis ' . (int) ($data['penalties']['home'] ?? 0) . ' x ' . (int) ($data['penalties']['away'] ?? 0);
        }
        return $text;
    }
    if ($match['modality'] === 'quiz') {
        if ($match['status'] === 'encerrada') {
            return 'Quiz finalizado';
        }
        return 'Pendente';
    }

    if ($match['modality'] === 'cubo_magico') {
        if ($match['status'] === 'encerrada') {
            return 'Cubo Mágico finalizado';
        }
        if ($match['status'] === 'em_andamento') {
            return 'Registre tentativas e média';
        }
        return 'Pendente';
    }

    if (is_individual_modality($match['modality'])) {
        if ($match['status'] === 'encerrada') {
            $winnerTeamId = (int) $match['winner_team_id'];
            if ($winnerTeamId === (int) $match['home_team_id']) {
                return 'Vencedor: ' . ($match['home_name'] ?? 'Time da Casa');
            } elseif ($winnerTeamId === (int) $match['away_team_id']) {
                return 'Vencedor: ' . ($match['away_name'] ?? 'Time Visitante');
            }
        }
        return 'Pendente';
    }

    if ($match['modality'] === 'volei') {
        $data = decode_score_data($match['score_data'] ?? null, 'volei');
        $sets = ((int) ($data['home_sets'] ?? $match['home_score'])) . ' x ' . ((int) ($data['away_sets'] ?? $match['away_score'])) . ' sets';
        $currentSet = end($data['sets']);

        if (is_array($currentSet)) {
            return $sets . ' | set atual ' . (int) $currentSet['home'] . ' x ' . (int) $currentSet['away'];
        }

        return $sets;
    }

    return (int) $match['home_score'] . ' x ' . (int) $match['away_score'];
}

function can_manage_event(int $eventId): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    if ($user['role'] === 'admin') {
        return true;
    }

    $stmt = db()->prepare('SELECT id FROM events WHERE id = ? AND created_by = ?');
    $stmt->execute([$eventId, $user['id']]);

    return $user['role'] === 'professor' && (bool) $stmt->fetch();
}

function can_manage_event_structure(int $eventId): bool
{
    return can_manage_event($eventId);
}

function can_operate_modality(int $eventId, string $modality): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    if ($user['role'] === 'admin') {
        return true;
    }

    if ($user['role'] !== 'professor') {
        return false;
    }

    $modalities = $user['modalities'] ?? [];
    return in_array($modality, $modalities, true);
}

function can_operate_match(int $eventId, ?string $modality = null): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    if ($user['role'] === 'admin') {
        return true;
    }

    if ($modality === null) {
        return false;
    }

    return can_operate_modality($eventId, $modality);
}

function can_view_private_roster(int $eventId, ?int $representativeId = null): bool
{
    $user = current_user();

    if (!$user) {
        return false;
    }

    return can_manage_event($eventId)
        || ($representativeId !== null && $representativeId > 0 && (int) $user['id'] === $representativeId);
}

function can_edit_team_roster(int $eventId, ?int $representativeId = null): bool
{
    return can_view_private_roster($eventId, $representativeId);
}

function ensure_occurrences_schema(bool $force = false): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $ensured = true;
        return;
    }

    if (!$force && !auto_schema_updates_enabled()) {
        $ensured = true;
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS occurrences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            match_id INT NOT NULL,
            team_id INT NOT NULL,
            player_id INT NULL,
            target_type ENUM("player", "team") NOT NULL,
            title VARCHAR(160) NOT NULL,
            description TEXT NOT NULL,
            action_text TEXT NULL,
            ineligible_next_game TINYINT(1) NOT NULL DEFAULT 0,
            points_deducted INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_occurrences_match (match_id),
            INDEX idx_occurrences_team (team_id),
            INDEX idx_occurrences_event (event_id),
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ensured = true;
}

function score_logs_schema_exists(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = db()->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?"
        );
        $stmt->execute(['score_logs']);
        $exists = (bool) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        error_log('[Schema] Falha ao verificar score_logs: ' . $exception->getMessage());
        $exists = false;
    }

    return $exists;
}

function ensure_score_logs_schema(bool $force = false): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $ensured = true;
        return;
    }

    if (!$force && !auto_schema_updates_enabled()) {
        $ensured = true;
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS score_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            match_id INT NOT NULL,
            user_id INT NULL,
            action VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            old_home_score INT NULL,
            old_away_score INT NULL,
            new_home_score INT NULL,
            new_away_score INT NULL,
            old_status VARCHAR(40) NULL,
            new_status VARCHAR(40) NULL,
            old_score_data JSON NULL,
            new_score_data JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_score_logs_match_id (match_id),
            INDEX idx_score_logs_user_id (user_id),
            INDEX idx_score_logs_created_at (created_at),
            FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $columns = [
        'description' => 'ALTER TABLE score_logs ADD description VARCHAR(255) NULL AFTER action',
        'old_home_score' => 'ALTER TABLE score_logs ADD old_home_score INT NULL AFTER description',
        'old_away_score' => 'ALTER TABLE score_logs ADD old_away_score INT NULL AFTER old_home_score',
        'new_home_score' => 'ALTER TABLE score_logs ADD new_home_score INT NULL AFTER old_away_score',
        'new_away_score' => 'ALTER TABLE score_logs ADD new_away_score INT NULL AFTER new_home_score',
        'old_status' => 'ALTER TABLE score_logs ADD old_status VARCHAR(40) NULL AFTER new_away_score',
        'new_status' => 'ALTER TABLE score_logs ADD new_status VARCHAR(40) NULL AFTER old_status',
    ];

    foreach ($columns as $column => $sql) {
        $stmt = db()->query("SHOW COLUMNS FROM score_logs LIKE " . db()->quote($column));
        if (!$stmt->fetch()) {
            db()->exec($sql);
        }
    }

    $ensured = true;
}

function ensure_suggestions_schema(bool $force = false): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $ensured = true;
        return;
    }

    if (!$force && !auto_schema_updates_enabled()) {
        $ensured = true;
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS suggestions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            type ENUM("bug", "melhoria", "outro") NOT NULL DEFAULT "melhoria",
            title VARCHAR(160) NOT NULL,
            description TEXT NOT NULL,
            status ENUM("nova", "em_analise", "resolvida", "arquivada") NOT NULL DEFAULT "nova",
            admin_note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_suggestions_status (status),
            INDEX idx_suggestions_user_id (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ensured = true;
}

function get_team_occurrence_deductions(int $eventId, string $modality, ?string $groupName = null, ?string $gender = 'masculino'): array
{
    ensure_occurrences_schema();

    $params = [$eventId, $modality];
    $sql = 'SELECT o.team_id, COALESCE(SUM(o.points_deducted), 0) AS deducted
            FROM occurrences o
            JOIN teams t ON t.id = o.team_id
            WHERE o.event_id = ? AND t.modality = ? AND o.target_type = "team" AND o.points_deducted > 0';

    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND t.gender = ?';
        $params[] = $gender;
    }

    if ($groupName !== null) {
        $sql .= ' AND t.group_name = ?';
        $params[] = $groupName;
    }

    $sql .= ' GROUP BY o.team_id';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $deductions = [];
    foreach ($stmt->fetchAll() as $row) {
        $deductions[(int) $row['team_id']] = (int) $row['deducted'];
    }

    return $deductions;
}

function get_match_team_alert_occurrences(int $matchId): array
{
    ensure_occurrences_schema();

    $stmt = db()->prepare(
        'SELECT o.id, o.team_id, o.title, o.description, o.action_text, o.ineligible_next_game, o.created_at,
                t.name AS team_name, p.name AS player_name
         FROM occurrences o
         JOIN teams t ON t.id = o.team_id
         LEFT JOIN players p ON p.id = o.player_id
         JOIN matches current_match ON current_match.id = ?
         WHERE o.target_type = "player"
           AND o.ineligible_next_game = 1
           AND o.event_id = current_match.event_id
           AND o.team_id IN (current_match.home_team_id, current_match.away_team_id)
           AND current_match.status IN ("agendada", "em_andamento")
           AND o.match_id <> current_match.id
         ORDER BY o.created_at DESC'
    );
    $stmt->execute([$matchId]);

    return $stmt->fetchAll();
}

function get_event_group_names(int $eventId, ?string $modality = null): array
{
    $maxTeamsPerGroup = 4;

    if ($modality) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM teams WHERE event_id = ? AND modality = ?');
        $stmt->execute([$eventId, $modality]);
        $teamCount = (int) $stmt->fetchColumn();

        $groupCount = group_count_for_team_count($teamCount, $maxTeamsPerGroup);
    } else {
        $stmt = db()->prepare('SELECT group_count FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        $groupCount = (int) ($stmt->fetchColumn() ?: 2);
        $groupCount = max(1, $groupCount);
    }

    $groups = [];
    for ($i = 0; $i < $groupCount; $i++) {
        $groups[] = chr(65 + $i);
    }

    return $groups;
}

function group_count_for_team_count(int $teamCount, int $maxTeamsPerGroup = 4): int
{
    if ($teamCount <= 1) {
        return 1;
    }

    return max(1, (int) ceil($teamCount / $maxTeamsPerGroup));
}

function auto_assign_group_name(int $eventId, string $modality, ?string $gender = 'masculino'): string
{
    if (!uses_grouping($modality)) {
        return '';
    }

    $maxTeamsPerGroup = 4;
    
    // Get current team counts per group
    $sql = 'SELECT group_name, COUNT(*) AS cnt FROM teams WHERE event_id = ? AND modality = ?';
    $params = [$eventId, $modality];
    
    // Only filter by gender if it's a non-mixed modality
    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND gender = ?';
        $params[] = $gender;
    }
    
    $sql .= ' GROUP BY group_name ORDER BY group_name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['group_name']] = (int) $row['cnt'];
    }
    
    $totalTeams = array_sum($counts);

    $requiredGroups = group_count_for_team_count($totalTeams + 1, $maxTeamsPerGroup);

    for ($i = 0; $i < $requiredGroups; $i++) {
        $group = chr(65 + $i);
        if (!isset($counts[$group])) {
            $counts[$group] = 0;
        }
    }
    
    // Sort groups by letter to maintain order
    ksort($counts);
    
    // Find the group with the minimum count (round-robin distribution)
    $minCount = PHP_INT_MAX;
    $selectedGroup = 'A';
    
    foreach ($counts as $groupName => $count) {
        if ($count < $minCount) {
            $minCount = $count;
            $selectedGroup = $groupName;
        }
    }
    
    if ($minCount >= $maxTeamsPerGroup) {
        $selectedGroup = chr(65 + count($counts));
    }
    
    return $selectedGroup;
}

function assign_team_groups(int $eventId, string $modality, ?string $gender = 'masculino'): void
{
    if (!uses_grouping($modality)) {
        $stmt = db()->prepare('UPDATE teams SET group_name = "" WHERE event_id = ? AND modality = ?');
        $stmt->execute([$eventId, $modality]);
        return;
    }

    $sql = 'SELECT id, group_name FROM teams WHERE event_id = ? AND modality = ?';
    $params = [$eventId, $modality];
    
    // Only filter by gender if it's a non-mixed modality
    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND gender = ?';
        $params[] = $gender;
    }
    
    $sql .= ' ORDER BY created_at, id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $teams = $stmt->fetchAll();

    $teamCount = count($teams);
    if ($teamCount === 0) {
        return;
    }

    $maxTeamsPerGroup = 4;
    $groupCount = group_count_for_team_count($teamCount, $maxTeamsPerGroup);
    $groups = [];
    for ($i = 0; $i < $groupCount; $i++) {
        $groups[] = chr(65 + $i);
    }

    $groupCounts = array_fill_keys($groups, 0);
    $update = db()->prepare('UPDATE teams SET group_name = ? WHERE id = ?');

    foreach ($teams as $team) {
        $selectedGroup = $groups[0];
        foreach ($groups as $group) {
            if ($groupCounts[$group] < $groupCounts[$selectedGroup]) {
                $selectedGroup = $group;
            }
        }

        $groupCounts[$selectedGroup]++;

        if (($team['group_name'] ?? '') !== $selectedGroup) {
            $update->execute([$selectedGroup, (int) $team['id']]);
        }
    }
}

function order_matches_with_rest(array $pairs): array
{
    $ordered = [];
    $remaining = $pairs;
    $lastTeams = [];

    while ($remaining) {
        $chosenIndex = 0;

        foreach ($remaining as $index => $pair) {
            if (!in_array($pair[0], $lastTeams, true) && !in_array($pair[1], $lastTeams, true)) {
                $chosenIndex = $index;
                break;
            }
        }

        $chosen = $remaining[$chosenIndex];
        $ordered[] = $chosen;
        $lastTeams = $chosen;
        array_splice($remaining, $chosenIndex, 1);
    }

    return $ordered;
}

function generate_group_matches(int $eventId, string $modality, ?string $baseDate = null, ?string $place = null, ?string $gender = 'masculino'): int
{
    if (!uses_grouping($modality)) {
        return 0;
    }

    if (over_limit_teams($eventId, $modality, $gender)) {
        return 0;
    }

    $ungroupedSql = 'SELECT COUNT(*) FROM teams WHERE event_id = ? AND modality = ? AND group_name = ""';
    $ungroupedParams = [$eventId, $modality];
    if ($gender !== null && !is_mixed_modality($modality)) {
        $ungroupedSql .= ' AND gender = ?';
        $ungroupedParams[] = $gender;
    }
    $ungroupedStmt = db()->prepare($ungroupedSql);
    $ungroupedStmt->execute($ungroupedParams);
    if ((int) $ungroupedStmt->fetchColumn() === 0) {
        assign_team_groups($eventId, $modality, $gender);
    }

    // Define minimum players per modality
    $minPlayersByModality = [
        'volei' => 5,
        'handbol' => 6,
    ];

    $minPlayers = $minPlayersByModality[$modality] ?? 0;

    // Check player counts and mark insufficient teams as W.O.
    if ($minPlayers > 0) {
        $sql = 'SELECT id, name FROM teams WHERE event_id = ? AND modality = ?';
        $params = [$eventId, $modality];
        
        // Only filter by gender if it's a non-mixed modality
        if ($gender !== null && !is_mixed_modality($modality)) {
            $sql .= ' AND gender = ?';
            $params[] = $gender;
        }
        
        $sql .= ' ORDER BY name';
        $teamsStmt = db()->prepare($sql);
        $teamsStmt->execute($params);
        $teams = $teamsStmt->fetchAll();

        foreach ($teams as $team) {
            $teamId = (int) $team['id'];
            
            // Count players for this team
            $playerCountStmt = db()->prepare('SELECT COUNT(*) FROM players WHERE team_id = ?');
            $playerCountStmt->execute([$teamId]);
            $playerCount = (int) $playerCountStmt->fetchColumn();

            // If insufficient players, mark all their matches as W.O.
            if ($playerCount < $minPlayers) {
                $sql = 'UPDATE matches SET status = "wo", home_score = 0, away_score = 0 
                        WHERE event_id = ? AND modality = ? AND stage = "grupo" 
                        AND (home_team_id = ? OR away_team_id = ?) AND status = "agendada"';
                $params = [$eventId, $modality, $teamId, $teamId];
                
                // Only filter by gender if it's a non-mixed modality
                if ($gender !== null && !is_mixed_modality($modality)) {
                    $sql .= ' AND gender = ?';
                    $params[] = $gender;
                }
                
                $updateStmt = db()->prepare($sql);
                $updateStmt->execute($params);
            }
        }
    }

    $sql = 'SELECT DISTINCT group_name FROM teams WHERE event_id = ? AND modality = ? AND group_name <> ""';
    $params = [$eventId, $modality];
    
    // Only filter by gender if it's a non-mixed modality
    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND gender = ?';
        $params[] = $gender;
    }
    
    $sql .= ' ORDER BY group_name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $groups = $stmt->fetchAll();
    $created = 0;
    $sortOrder = 1;
    $nextStart = $baseDate ? strtotime($baseDate) : null;

    foreach ($groups as $group) {
        $sql = 'SELECT id FROM teams WHERE event_id = ? AND modality = ? AND group_name = ?';
        $params = [$eventId, $modality, $group['group_name']];
        
        // Only filter by gender if it's a non-mixed modality
        if ($gender !== null && !is_mixed_modality($modality)) {
            $sql .= ' AND gender = ?';
            $params[] = $gender;
        }
        
        $sql .= ' ORDER BY name';
        $teamsStmt = db()->prepare($sql);
        $teamsStmt->execute($params);
        $teamIds = array_map('intval', array_column($teamsStmt->fetchAll(), 'id'));
        $pairs = [];

        for ($i = 0; $i < count($teamIds); $i++) {
            for ($j = $i + 1; $j < count($teamIds); $j++) {
                $pairs[] = [$teamIds[$i], $teamIds[$j]];
            }
        }

        $orderedPairs = order_matches_with_rest($pairs);

        foreach ($orderedPairs as $pair) {
            $sql = 'SELECT id FROM matches WHERE event_id = ? AND modality = ? AND stage = "grupo" AND home_team_id = ? AND away_team_id = ?';
            $params = [$eventId, $modality, $pair[0], $pair[1]];
            
            // Only filter by gender if it's a non-mixed modality
            if ($gender !== null && !is_mixed_modality($modality)) {
                $sql .= ' AND gender = ?';
                $params[] = $gender;
            }
            
            $existsStmt = db()->prepare($sql);
            $existsStmt->execute($params);

            if ($existsStmt->fetch()) {
                continue;
            }

            $startsAt = $nextStart ? date('Y-m-d H:i:s', $nextStart) : null;
            $insert = db()->prepare(
                'INSERT INTO matches (event_id, modality, gender, stage, group_name, home_team_id, away_team_id, score_data, starts_at, place, sort_order)
                 VALUES (?, ?, ?, "grupo", ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $eventId,
                $modality,
                $gender,
                $group['group_name'],
                $pair[0],
                $pair[1],
                json_encode(default_score_data($modality), JSON_UNESCAPED_UNICODE),
                $startsAt,
                $place,
                $sortOrder++,
            ]);

            $created++;

            if ($nextStart) {
                $nextStart = strtotime('+40 minutes', $nextStart);
            }
        }
    }

    return $created;
}

function generate_round_robin_matches(int $eventId, string $modality, ?string $baseDate = null, ?string $place = null, ?string $gender = 'masculino'): int
{
    if (over_limit_teams($eventId, $modality, $gender)) {
        return 0;
    }

    if (in_array($modality, ['quiz', 'cubo_magico'], true)) {
        $exists = db()->prepare(
            'SELECT id FROM matches
             WHERE event_id = ? AND modality = ? AND stage = "grupo"
             AND home_team_id IS NULL AND away_team_id IS NULL
             LIMIT 1'
        );
        $exists->execute([$eventId, $modality]);
        if ($exists->fetch()) {
            return 0;
        }

        $teamsStmt = db()->prepare('SELECT id FROM teams WHERE event_id = ? AND modality = ? ORDER BY name');
        $teamsStmt->execute([$eventId, $modality]);
        $teamIds = array_map('intval', array_column($teamsStmt->fetchAll(), 'id'));

        if (!$teamIds) {
            return 0;
        }

        if ($modality === 'quiz' && count($teamIds) < 2) {
            return 0;
        }

        $scoreData = default_score_data($modality);
        $scoreData['collective_match'] = true;
        $scoreData['team_ids'] = $teamIds;

        if ($modality === 'cubo_magico') {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $playersStmt = db()->prepare("SELECT id FROM players WHERE team_id IN ($placeholders) ORDER BY name");
            $playersStmt->execute($teamIds);
            $scoreData['player_ids'] = array_map('intval', array_column($playersStmt->fetchAll(), 'id'));

            if (count($scoreData['player_ids']) < 2) {
                return 0;
            }
        }

        $startsAt = $baseDate ? date('Y-m-d H:i:s', strtotime($baseDate)) : null;
        $stmt = db()->prepare(
            'INSERT INTO matches (event_id, modality, gender, stage, group_name, home_team_id, away_team_id, score_data, starts_at, place, sort_order)
             VALUES (?, ?, ?, "grupo", "", NULL, NULL, ?, ?, ?, 100)'
        );
        $stmt->execute([
            $eventId,
            $modality,
            $gender,
            json_encode($scoreData, JSON_UNESCAPED_UNICODE),
            $startsAt,
            $place,
        ]);

        return 1;
    }

    $sql = 'SELECT id FROM teams WHERE event_id = ? AND modality = ?';
    $params = [$eventId, $modality];

    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND gender = ?';
        $params[] = $gender;
    }

    $sql .= ' ORDER BY name';
    $teamsStmt = db()->prepare($sql);
    $teamsStmt->execute($params);
    $teamIds = array_map('intval', array_column($teamsStmt->fetchAll(), 'id'));

    if (count($teamIds) < 2) {
        return 0;
    }

    $pairs = [];
    for ($i = 0; $i < count($teamIds); $i++) {
        for ($j = $i + 1; $j < count($teamIds); $j++) {
            $pairs[] = [$teamIds[$i], $teamIds[$j]];
        }
    }

    $orderedPairs = order_matches_with_rest($pairs);
    $created = 0;
    $nextStart = $baseDate ? strtotime($baseDate) : null;

    foreach ($orderedPairs as $pair) {
        $existsSql = 'SELECT id FROM matches
            WHERE event_id = ? AND modality = ? AND stage = "grupo"
            AND ((home_team_id = ? AND away_team_id = ?) OR (home_team_id = ? AND away_team_id = ?))';
        $existsParams = [$eventId, $modality, $pair[0], $pair[1], $pair[1], $pair[0]];
        if ($gender !== null && !is_mixed_modality($modality)) {
            $existsSql .= ' AND gender = ?';
            $existsParams[] = $gender;
        }
        $exists = db()->prepare($existsSql);
        $exists->execute($existsParams);
        if ($exists->fetch()) {
            continue;
        }

        $startsAt = null;
        if ($nextStart) {
            $startsAt = date('Y-m-d H:i:s', $nextStart);
            $nextStart += 60 * 60;
        }

        $stmt = db()->prepare(
            'INSERT INTO matches (event_id, modality, gender, stage, group_name, home_team_id, away_team_id, score_data, starts_at, place, sort_order)
             VALUES (?, ?, ?, "grupo", "", ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventId,
            $modality,
            $gender,
            $pair[0],
            $pair[1],
            json_encode(default_score_data($modality), JSON_UNESCAPED_UNICODE),
            $startsAt,
            $place,
            100 + $created,
        ]);
        $created++;
    }

    return $created;
}

function calculate_standings(int $eventId, string $modality, ?string $groupName = null, ?string $gender = 'masculino'): array
{
    static $requestCache = [];

    $cacheKey = implode('|', [
        $eventId,
        $modality,
        $groupName ?? '*',
        $gender ?? '*',
    ]);

    if (isset($requestCache[$cacheKey])) {
        return $requestCache[$cacheKey];
    }

    $params = [$eventId, $modality];
    $sql = 'SELECT m.id, m.home_team_id, m.away_team_id, m.home_score, m.away_score, m.group_name, m.sort_order
            FROM matches m
            WHERE m.event_id = ? AND m.modality = ? AND m.stage = "grupo" AND m.status IN ("encerrada", "wo")';

    // Only filter by gender if it's a non-mixed modality
    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND m.gender = ?';
        $params[] = $gender;
    }

    if ($groupName !== null) {
        $sql .= ' AND m.group_name = ?';
        $params[] = $groupName;
    } elseif (uses_grouping($modality)) {
        $sql .= ' AND m.group_name <> ""';
    }

    $sql .= ' ORDER BY m.group_name, m.sort_order, m.id';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $matches = $stmt->fetchAll();

    $sql = 'SELECT id, name, class_name, group_name FROM teams WHERE event_id = ? AND modality = ?';
    $params = [$eventId, $modality];
    
    // Only filter by gender if it's a non-mixed modality
    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND gender = ?';
        $params[] = $gender;
    }
    
    if ($groupName !== null) {
        $sql .= ' AND group_name = ?';
        $params[] = $groupName;
    } elseif (uses_grouping($modality)) {
        $sql .= ' AND group_name <> ""';
    }
    
    $sql .= ' ORDER BY group_name, name';
    $teamsStmt = db()->prepare($sql);
    $teamsStmt->execute($params);
    $standings = [];

    foreach ($teamsStmt->fetchAll() as $team) {
        $standings[$team['id']] = [
            'team_id' => (int) $team['id'],
            'team_name' => $team['name'],
            'class_name' => $team['class_name'] ?? $team['name'],
            'group_name' => $team['group_name'],
            'P' => 0,
            'J' => 0,
            'V' => 0,
            'D' => 0,
            'GP' => 0,
            'GC' => 0,
            'SG' => 0,
            'occurrence_deductions' => 0,
        ];
    }

    foreach ($matches as $match) {
        if (!$match['home_team_id'] || !$match['away_team_id']) {
            continue;
        }

        $homeId = (int) $match['home_team_id'];
        $awayId = (int) $match['away_team_id'];
        $homeScore = (int) $match['home_score'];
        $awayScore = (int) $match['away_score'];

        foreach ([$homeId, $awayId] as $teamId) {
            if (!isset($standings[$teamId])) {
                continue;
            }
            $standings[$teamId]['J']++;
        }

        $standings[$homeId]['GP'] += $homeScore;
        $standings[$homeId]['GC'] += $awayScore;
        $standings[$awayId]['GP'] += $awayScore;
        $standings[$awayId]['GC'] += $homeScore;

        if ($homeScore > $awayScore) {
            $standings[$homeId]['V']++;
            $standings[$awayId]['D']++;
            $standings[$homeId]['P'] += 3;
        } elseif ($awayScore > $homeScore) {
            $standings[$awayId]['V']++;
            $standings[$homeId]['D']++;
            $standings[$awayId]['P'] += 3;
        } else {
            $standings[$homeId]['P']++;
            $standings[$awayId]['P']++;
        }
    }

    foreach ($standings as &$row) {
        $row['SG'] = $row['GP'] - $row['GC'];
    }
    unset($row);

    foreach (get_team_occurrence_deductions($eventId, $modality, $groupName, $gender) as $teamId => $pointsDeducted) {
        if (!isset($standings[$teamId])) {
            continue;
        }

        $standings[$teamId]['occurrence_deductions'] = $pointsDeducted;
        $standings[$teamId]['P'] = max(0, $standings[$teamId]['P'] - $pointsDeducted);
    }

    $directConfrontations = build_direct_confrontation_map($matches);
    usort($standings, function (array $a, array $b) use ($directConfrontations): int {
        return compare_standing_rows_with_direct_map($a, $b, $directConfrontations);
    });

    $requestCache[$cacheKey] = $standings;

    return $standings;
}

function calculate_cubo_magico_standings(int $eventId): array
{
    $matchStmt = db()->prepare(
        'SELECT id, status, score_data FROM matches
         WHERE event_id = ? AND modality = ?
         AND (stage = "individual" OR stage = "grupo")
         ORDER BY
            CASE WHEN status IN ("encerrada", "wo") THEN 0 ELSE 1 END,
            CASE WHEN stage = "individual" THEN 0 ELSE 1 END,
            id DESC
         LIMIT 1'
    );
    $matchStmt->execute([$eventId, 'cubo_magico']);
    $match = $matchStmt->fetch();

    if (!$match) {
        return [];
    }

    $scoreData = decode_score_data($match['score_data'] ?? null, 'cubo_magico');
    $averages = $scoreData['player_averages'] ?? [];
    $attempts = $scoreData['player_attempts'] ?? [];

    $playersStmt = db()->prepare(
        'SELECT p.id, p.name, t.name AS team_name, t.class_name
         FROM players p
         JOIN teams t ON t.id = p.team_id
         WHERE t.event_id = ? AND t.modality = ?
         ORDER BY p.name'
    );
    $playersStmt->execute([$eventId, 'cubo_magico']);
    $players = $playersStmt->fetchAll();

    $rows = [];
    foreach ($players as $player) {
        $playerId = (int) $player['id'];
        $playerAttempts = isset($attempts[$playerId]) ? $attempts[$playerId] : [];
        $row = [
            'player_name' => $player['name'],
            'class_name' => $player['class_name'],
            'team_name' => $player['team_name'],
            'average' => isset($averages[$playerId]) ? (float) $averages[$playerId] : null,
            'attempt_text' => !$playerAttempts ? '<span class="text-muted">sem tentativas</span>' : implode(', ', array_map(fn($value) => number_format((float) $value, 2, ',', '.') . 's', $playerAttempts)),
        ];

        $rows[] = $row;
    }

    usort($rows, function (array $a, array $b): int {
        $avgA = $a['average'];
        $avgB = $b['average'];

        if ($avgA === null && $avgB === null) {
            return strcmp($a['player_name'], $b['player_name']);
        }
        if ($avgA === null) {
            return 1;
        }
        if ($avgB === null) {
            return -1;
        }
        if ($avgA !== $avgB) {
            return $avgA < $avgB ? -1 : 1;
        }
        return strcmp($a['player_name'], $b['player_name']);
    });

    return $rows;
}

function calculate_quiz_collective_standings(int $eventId): array
{
    $matchStmt = db()->prepare(
        'SELECT score_data, status, winner_team_id
         FROM matches
         WHERE event_id = ? AND modality = "quiz" AND stage = "grupo"
         AND home_team_id IS NULL AND away_team_id IS NULL
         ORDER BY CASE WHEN status IN ("encerrada", "wo") THEN 0 ELSE 1 END, id DESC
         LIMIT 1'
    );
    $matchStmt->execute([$eventId]);
    $match = $matchStmt->fetch();
    if (!$match) {
        return [];
    }

    $scoreData = decode_score_data($match['score_data'] ?? null, 'quiz');
    if (empty($scoreData['collective_match'])) {
        return [];
    }

    $teamIds = array_filter(array_map('intval', $scoreData['team_ids'] ?? []));
    if (!$teamIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $teamsStmt = db()->prepare(
        "SELECT id, name, class_name, group_name
         FROM teams
         WHERE event_id = ? AND modality = 'quiz' AND id IN ($placeholders)
         ORDER BY name"
    );
    $teamsStmt->execute(array_merge([$eventId], $teamIds));

    $scores = [];
    foreach (($scoreData['scores'] ?? []) as $teamId => $score) {
        $scores[(int) $teamId] = (int) $score;
    }
    $winnerTeamId = (int) ($match['winner_team_id'] ?? 0);
    $finished = in_array($match['status'] ?? '', ['encerrada', 'wo'], true);
    $rows = [];

    foreach ($teamsStmt->fetchAll() as $team) {
        $teamId = (int) $team['id'];
        $score = (int) ($scores[$teamId] ?? 0);
        $rows[] = [
            'team_id' => $teamId,
            'team_name' => $team['name'],
            'class_name' => $team['class_name'] ?? $team['name'],
            'group_name' => $team['group_name'] ?? '',
            'P' => $score,
            'J' => $finished ? 1 : 0,
            'V' => $finished && $winnerTeamId === $teamId ? 1 : 0,
            'D' => $finished && $winnerTeamId !== 0 && $winnerTeamId !== $teamId ? 1 : 0,
            'GP' => $score,
            'GC' => 0,
            'SG' => $score,
            'occurrence_deductions' => 0,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        if ($a['P'] !== $b['P']) {
            return $b['P'] <=> $a['P'];
        }

        return strcmp($a['team_name'], $b['team_name']);
    });

    return $rows;
}

function calculate_knockout_standings(int $eventId, string $modality, string $gender = 'masculino'): array
{
    $stages = ['preliminar', 'dezesseis_avos', 'oitavas', 'quartas', 'semifinal', 'final'];
    $stagePlaceholders = implode(',', array_fill(0, count($stages), '?'));
    $params = array_merge([$eventId, $modality], $stages);
    $sql = "SELECT m.*
            FROM matches m
            WHERE m.event_id = ? AND m.modality = ?
            AND m.stage IN ($stagePlaceholders)
            AND m.status IN ('encerrada', 'wo')";

    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND m.gender = ?';
        $params[] = $gender;
    }

    $sql .= ' ORDER BY m.sort_order, m.id';
    $matchesStmt = db()->prepare($sql);
    $matchesStmt->execute($params);
    $matches = $matchesStmt->fetchAll();

    if (!$matches) {
        return [];
    }

    $teamsStmt = db()->prepare(
        'SELECT id, name, class_name, group_name
         FROM teams
         WHERE event_id = ? AND modality = ?'
    );
    $teamsStmt->execute([$eventId, $modality]);
    $teamsById = [];
    foreach ($teamsStmt->fetchAll() as $team) {
        $teamsById[(int) $team['id']] = $team;
    }

    $playersStmt = db()->prepare(
        'SELECT p.id, p.name, p.team_id, t.class_name, t.group_name
         FROM players p
         JOIN teams t ON t.id = p.team_id
         WHERE t.event_id = ? AND t.modality = ?'
    );
    $playersStmt->execute([$eventId, $modality]);
    $playersById = [];
    foreach ($playersStmt->fetchAll() as $player) {
        $playersById[(int) $player['id']] = $player;
    }

    $rows = [];
    $ensureRow = static function (?int $teamId, ?int $playerId) use (&$rows, $teamsById, $playersById): ?string {
        if (!$teamId && !$playerId) {
            return null;
        }

        $key = $playerId ? 'player:' . $playerId : 'team:' . $teamId;
        if (isset($rows[$key])) {
            return $key;
        }

        $team = $teamId && isset($teamsById[$teamId]) ? $teamsById[$teamId] : [];
        $player = $playerId && isset($playersById[$playerId]) ? $playersById[$playerId] : [];
        $className = trim((string) ($player['class_name'] ?? $team['class_name'] ?? ''));

        $rows[$key] = [
            'team_id' => (int) ($teamId ?: ($player['team_id'] ?? 0)),
            'team_name' => (string) ($player['name'] ?? $team['name'] ?? 'Participante'),
            'class_name' => $className !== '' ? $className : 'Sem turma',
            'group_name' => (string) ($player['group_name'] ?? $team['group_name'] ?? ''),
            'P' => 0,
            'J' => 0,
            'V' => 0,
            'D' => 0,
            'GP' => 0,
            'GC' => 0,
            'SG' => 0,
            'occurrence_deductions' => 0,
            'last_stage_order' => 0,
        ];

        return $key;
    };

    $stageOrder = [
        'preliminar' => 1,
        'dezesseis_avos' => 2,
        'oitavas' => 3,
        'quartas' => 4,
        'semifinal' => 5,
        'final' => 6,
    ];

    foreach ($matches as $match) {
        $scoreData = decode_score_data($match['score_data'] ?? null, $modality);
        $homeTeamId = (int) ($match['home_team_id'] ?? 0);
        $awayTeamId = (int) ($match['away_team_id'] ?? 0);
        $homePlayerId = (int) ($scoreData['bracket_home_player_id'] ?? 0);
        $awayPlayerId = (int) ($scoreData['bracket_away_player_id'] ?? 0);
        $homeKey = $ensureRow($homeTeamId ?: null, $homePlayerId ?: null);
        $awayKey = $ensureRow($awayTeamId ?: null, $awayPlayerId ?: null);

        if (!$homeKey || !$awayKey) {
            continue;
        }

        $winnerTeamId = (int) ($match['winner_team_id'] ?? 0);
        $winnerKey = null;
        if ($winnerTeamId > 0) {
            $winnerKey = $winnerTeamId === $homeTeamId ? $homeKey : ($winnerTeamId === $awayTeamId ? $awayKey : null);
        } elseif ((int) $match['home_score'] !== (int) $match['away_score']) {
            $winnerKey = (int) $match['home_score'] > (int) $match['away_score'] ? $homeKey : $awayKey;
        }

        $rows[$homeKey]['J']++;
        $rows[$awayKey]['J']++;
        $rows[$homeKey]['last_stage_order'] = max($rows[$homeKey]['last_stage_order'], $stageOrder[$match['stage']] ?? 0);
        $rows[$awayKey]['last_stage_order'] = max($rows[$awayKey]['last_stage_order'], $stageOrder[$match['stage']] ?? 0);

        if ($winnerKey === $homeKey) {
            $rows[$homeKey]['V']++;
            $rows[$homeKey]['P'] += 3;
            $rows[$awayKey]['D']++;
        } elseif ($winnerKey === $awayKey) {
            $rows[$awayKey]['V']++;
            $rows[$awayKey]['P'] += 3;
            $rows[$homeKey]['D']++;
        }
    }

    $rows = array_values($rows);
    usort($rows, static function (array $a, array $b): int {
        if ($a['last_stage_order'] !== $b['last_stage_order']) {
            return $b['last_stage_order'] <=> $a['last_stage_order'];
        }
        if ($a['P'] !== $b['P']) {
            return $b['P'] <=> $a['P'];
        }
        if ($a['V'] !== $b['V']) {
            return $b['V'] <=> $a['V'];
        }
        if ($a['D'] !== $b['D']) {
            return $a['D'] <=> $b['D'];
        }

        return strcmp($a['team_name'], $b['team_name']);
    });

    foreach ($rows as &$row) {
        unset($row['last_stage_order']);
    }
    unset($row);

    return $rows;
}

function apply_final_semifinal_third_place_rule(int $eventId, string $modality, string $gender, array $standings): array
{
    if (empty($standings)) {
        return $standings;
    }

    $finalStmt = db()->prepare(
        'SELECT home_team_id, away_team_id, home_score, away_score, winner_team_id
         FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "final"
         AND status IN ("encerrada", "wo")
         ORDER BY sort_order DESC, id DESC
         LIMIT 1'
    );
    $finalStmt->execute([$eventId, $modality, $gender]);
    $final = $finalStmt->fetch();

    if (!$final) {
        return $standings;
    }

    $homeTeamId = (int) ($final['home_team_id'] ?? 0);
    $awayTeamId = (int) ($final['away_team_id'] ?? 0);
    $winnerTeamId = (int) ($final['winner_team_id'] ?? 0);

    if (!in_array($winnerTeamId, [$homeTeamId, $awayTeamId], true)) {
        if ((int) ($final['home_score'] ?? 0) === (int) ($final['away_score'] ?? 0)) {
            return $standings;
        }
        $winnerTeamId = (int) $final['home_score'] > (int) $final['away_score'] ? $homeTeamId : $awayTeamId;
    }

    $runnerUpTeamId = $winnerTeamId === $homeTeamId ? $awayTeamId : $homeTeamId;
    if ($winnerTeamId <= 0 || $runnerUpTeamId <= 0) {
        return $standings;
    }

    $semifinalStmt = db()->prepare(
        'SELECT home_team_id, away_team_id, home_score, away_score, winner_team_id
         FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "semifinal"
         AND status IN ("encerrada", "wo")
         ORDER BY sort_order, id'
    );
    $semifinalStmt->execute([$eventId, $modality, $gender]);
    $semifinals = $semifinalStmt->fetchAll();

    $semifinalOpponent = static function (int $teamId) use ($semifinals): ?int {
        foreach ($semifinals as $semifinal) {
            $homeTeamId = (int) ($semifinal['home_team_id'] ?? 0);
            $awayTeamId = (int) ($semifinal['away_team_id'] ?? 0);
            if ($teamId !== $homeTeamId && $teamId !== $awayTeamId) {
                continue;
            }

            $winnerTeamId = (int) ($semifinal['winner_team_id'] ?? 0);
            if (!in_array($winnerTeamId, [$homeTeamId, $awayTeamId], true)) {
                if ((int) ($semifinal['home_score'] ?? 0) === (int) ($semifinal['away_score'] ?? 0)) {
                    return null;
                }
                $winnerTeamId = (int) $semifinal['home_score'] > (int) $semifinal['away_score'] ? $homeTeamId : $awayTeamId;
            }

            if ($winnerTeamId !== $teamId) {
                return null;
            }

            return $teamId === $homeTeamId ? $awayTeamId : $homeTeamId;
        }

        return null;
    };

    $thirdPlaceTeamId = $semifinalOpponent($winnerTeamId);
    $fourthPlaceTeamId = $semifinalOpponent($runnerUpTeamId);
    $orderedTeamIds = [$winnerTeamId, $runnerUpTeamId, $thirdPlaceTeamId, $fourthPlaceTeamId];

    if (count(array_filter($orderedTeamIds)) !== 4 || count(array_unique($orderedTeamIds)) !== 4) {
        return $standings;
    }

    $rowsByTeamId = [];
    foreach ($standings as $row) {
        $rowsByTeamId[(int) ($row['team_id'] ?? 0)] = $row;
    }

    $orderedRows = [];
    foreach ($orderedTeamIds as $teamId) {
        if (!isset($rowsByTeamId[$teamId])) {
            return $standings;
        }

        $orderedRows[] = $rowsByTeamId[$teamId];
        unset($rowsByTeamId[$teamId]);
    }

    foreach ($standings as $row) {
        $teamId = (int) ($row['team_id'] ?? 0);
        if (isset($rowsByTeamId[$teamId])) {
            $orderedRows[] = $row;
            unset($rowsByTeamId[$teamId]);
        }
    }

    return $orderedRows;
}

function compare_standing_rows(array $a, array $b, array $matches): int
{
    // Prioritize points (P) first
    if ($a['P'] !== $b['P']) {
        return $b['P'] <=> $a['P'];
    }

    // Then goal difference (SG)
    if ($a['SG'] !== $b['SG']) {
        return $b['SG'] <=> $a['SG'];
    }

    // Then goals for (GP)
    if ($a['GP'] !== $b['GP']) {
        return $b['GP'] <=> $a['GP'];
    }

    // Then direct confrontation
    $direct = direct_confrontation_result($a['team_id'], $b['team_id'], $matches);
    if ($direct !== 0) {
        return $direct;
    }

    // Then wins (V)
    if ($a['V'] !== $b['V']) {
        return $b['V'] <=> $a['V'];
    }

    // Finally alphabetical by team name
    return strcmp($a['team_name'], $b['team_name']);
}

function compare_standing_rows_with_direct_map(array $a, array $b, array $directConfrontations): int
{
    if ($a['P'] !== $b['P']) {
        return $b['P'] <=> $a['P'];
    }

    if ($a['SG'] !== $b['SG']) {
        return $b['SG'] <=> $a['SG'];
    }

    if ($a['GP'] !== $b['GP']) {
        return $b['GP'] <=> $a['GP'];
    }

    $direct = $directConfrontations[(int) $a['team_id']][(int) $b['team_id']] ?? 0;
    if ($direct !== 0) {
        return $direct;
    }

    if ($a['V'] !== $b['V']) {
        return $b['V'] <=> $a['V'];
    }

    return strcmp($a['team_name'], $b['team_name']);
}

function build_direct_confrontation_map(array $matches): array
{
    $map = [];

    foreach ($matches as $match) {
        $home = (int) ($match['home_team_id'] ?? 0);
        $away = (int) ($match['away_team_id'] ?? 0);

        if ($home <= 0 || $away <= 0) {
            continue;
        }

        $homeScore = (int) ($match['home_score'] ?? 0);
        $awayScore = (int) ($match['away_score'] ?? 0);

        if ($homeScore === $awayScore) {
            $map[$home][$away] = $map[$home][$away] ?? 0;
            $map[$away][$home] = $map[$away][$home] ?? 0;
            continue;
        }

        $winner = $homeScore > $awayScore ? $home : $away;
        $map[$home][$away] = $winner === $home ? -1 : 1;
        $map[$away][$home] = $winner === $away ? -1 : 1;
    }

    return $map;
}

function direct_confrontation_result(int $teamA, int $teamB, array $matches): int
{
    foreach ($matches as $match) {
        $home = (int) $match['home_team_id'];
        $away = (int) $match['away_team_id'];

        if (!(($home === $teamA && $away === $teamB) || ($home === $teamB && $away === $teamA))) {
            continue;
        }

        $homeScore = (int) $match['home_score'];
        $awayScore = (int) $match['away_score'];

        if ($homeScore === $awayScore) {
            return 0;
        }

        $winner = $homeScore > $awayScore ? $home : $away;
        return $winner === $teamA ? -1 : 1;
    }

    return 0;
}

function generate_knockout_matches(int $eventId, string $modality, string $gender = 'masculino'): int
{
    if (!group_stage_is_complete($eventId, $modality, $gender)) {
        return 0;
    }

    $groupsStmt = db()->prepare('SELECT DISTINCT group_name FROM teams WHERE event_id = ? AND modality = ? AND gender = ? AND group_name <> "" ORDER BY group_name');
    $groupsStmt->execute([$eventId, $modality, $gender]);
    $groups = array_column($groupsStmt->fetchAll(), 'group_name');
    $qualified = [];
    $thirds = [];

    $eventStmt = db()->prepare('SELECT best_thirds_count, teams_advancing_per_group, advancement_mode FROM events WHERE id = ?');
    $eventStmt->execute([$eventId]);
    $eventSettings = $eventStmt->fetch();
    $bestThirdsCount = (int) ($eventSettings['best_thirds_count'] ?? 0);
    $teamsAdvancingPerGroup = (int) ($eventSettings['teams_advancing_per_group'] ?? 2);
    $advancementMode = ($eventSettings['advancement_mode'] ?? 'global') === 'per_group' ? 'per_group' : 'global';
    $advancementByGroup = [];

    if ($advancementMode === 'per_group') {
        $advancementStmt = db()->prepare(
            'SELECT group_name, teams_advancing
             FROM event_group_advancement
             WHERE event_id = ? AND modality = ? AND gender = ?'
        );
        $advancementStmt->execute([$eventId, $modality, $gender]);
        foreach ($advancementStmt->fetchAll() as $row) {
            $advancementByGroup[$row['group_name']] = max(0, (int) $row['teams_advancing']);
        }
    }

    foreach ($groups as $group) {
        $rows = calculate_standings($eventId, $modality, $group, $gender);
        $groupAdvancing = $advancementMode === 'per_group'
            ? (int) ($advancementByGroup[$group] ?? 0)
            : $teamsAdvancingPerGroup;

        foreach (array_slice($rows, 0, $groupAdvancing) as $row) {
            $qualified[] = $row['team_id'];
        }

        if ($advancementMode === 'global' && isset($rows[$teamsAdvancingPerGroup])) {
            $thirds[] = $rows[$teamsAdvancingPerGroup];
        }
    }

    if ($advancementMode === 'global') {
        usort($thirds, fn ($a, $b) => compare_standing_rows($a, $b, []));
        foreach (array_slice($thirds, 0, $bestThirdsCount) as $third) {
            $qualified[] = $third['team_id'];
        }

        $repechageStmt = db()->prepare(
            'SELECT winner_team_id FROM matches
             WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "repescagem"
             AND status IN ("encerrada", "wo") AND winner_team_id IS NOT NULL
             ORDER BY sort_order, id'
        );
        $repechageStmt->execute([$eventId, $modality, $gender]);
        foreach ($repechageStmt->fetchAll() as $row) {
            $qualified[] = (int) $row['winner_team_id'];
        }
    }

    $qualified = array_values(array_unique($qualified));
    if (count($qualified) < 2) {
        return 0;
    }

    $qualifiedCount = count($qualified);
    $stage = $qualifiedCount >= 8 ? 'quartas' : ($qualifiedCount >= 3 ? 'semifinal' : 'final');
    $preliminaryTeamId = null;
    $preliminaryForStage = null;
    if ($qualifiedCount % 2 === 1) {
        $preliminaryTeamId = array_pop($qualified);
        $preliminaryForStage = knockout_stage_for_participant_count((int) (count($qualified) / 2));
    }
    $created = 0;

    $pairs = knockout_pair_items_avoiding_same_group(
        $eventId,
        $modality,
        $gender,
        $stage,
        $qualified,
        static fn ($teamId): int => (int) $teamId
    );

    foreach ($pairs as $pair) {
        $homeTeamId = (int) $pair[0];
        $awayTeamId = (int) $pair[1];

        $exists = db()->prepare('SELECT id FROM matches WHERE event_id = ? AND modality = ? AND gender = ? AND stage = ? AND home_team_id = ? AND away_team_id = ?');
        $exists->execute([$eventId, $modality, $gender, $stage, $homeTeamId, $awayTeamId]);
        if ($exists->fetch()) {
            continue;
        }

        $stmt = db()->prepare(
            'INSERT INTO matches (event_id, modality, gender, stage, home_team_id, away_team_id, score_data, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventId,
            $modality,
            $gender,
            $stage,
            $homeTeamId,
            $awayTeamId,
            json_encode(default_score_data($modality), JSON_UNESCAPED_UNICODE),
            500 + ($created * 2),
        ]);
        $created++;
    }

    if ($preliminaryTeamId !== null && $preliminaryForStage !== null) {
        create_preliminary_shell($eventId, $modality, $gender, $stage, $preliminaryForStage, (int) $preliminaryTeamId, null);
        $created++;
    }

    return $created;
}

function generate_repechage_matches(int $eventId, string $modality, string $gender = 'masculino'): int
{
    if (!group_stage_is_complete($eventId, $modality, $gender)) {
        return 0;
    }

    $groupsStmt = db()->prepare('SELECT DISTINCT group_name FROM teams WHERE event_id = ? AND modality = ? AND gender = ? AND group_name <> "" ORDER BY group_name');
    $groupsStmt->execute([$eventId, $modality, $gender]);
    $groups = array_column($groupsStmt->fetchAll(), 'group_name');
    $thirds = [];

    foreach ($groups as $group) {
        $rows = calculate_standings($eventId, $modality, $group, $gender);
        if (isset($rows[2])) {
            $thirds[] = $rows[2];
        }
    }

    usort($thirds, fn ($a, $b) => compare_standing_rows($a, $b, []));
    $created = 0;

    for ($i = 0; $i < count($thirds); $i += 2) {
        if (!isset($thirds[$i + 1])) {
            break;
        }

        $homeTeamId = (int) $thirds[$i]['team_id'];
        $awayTeamId = (int) $thirds[$i + 1]['team_id'];
        $exists = db()->prepare(
            'SELECT id FROM matches
             WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "repescagem"
             AND ((home_team_id = ? AND away_team_id = ?) OR (home_team_id = ? AND away_team_id = ?))'
        );
        $exists->execute([$eventId, $modality, $gender, $homeTeamId, $awayTeamId, $awayTeamId, $homeTeamId]);

        if ($exists->fetch()) {
            continue;
        }

        $stmt = db()->prepare(
            'INSERT INTO matches (event_id, modality, gender, stage, home_team_id, away_team_id, score_data, sort_order)
             VALUES (?, ?, ?, "repescagem", ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventId,
            $modality,
            $gender,
            $homeTeamId,
            $awayTeamId,
            json_encode(default_score_data($modality), JSON_UNESCAPED_UNICODE),
            400 + $i,
        ]);
        $created++;
    }

    return $created;
}

function teams_played_in_group_stage(int $eventId, string $modality, string $gender, int $teamA, int $teamB): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "grupo"
         AND ((home_team_id = ? AND away_team_id = ?) OR (home_team_id = ? AND away_team_id = ?))'
    );
    $stmt->execute([$eventId, $modality, $gender, $teamA, $teamB, $teamB, $teamA]);
    return (int) $stmt->fetchColumn() > 0;
}

function knockout_team_group_map(int $eventId, string $modality, string $gender, array $teamIds): array
{
    $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds))));
    if (!$teamIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = db()->prepare(
        'SELECT id, group_name
         FROM teams
         WHERE event_id = ? AND modality = ? AND gender = ? AND id IN (' . $placeholders . ')'
    );
    $stmt->execute(array_merge([$eventId, $modality, $gender], $teamIds));

    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $groups[(int) $row['id']] = (string) ($row['group_name'] ?? '');
    }

    return $groups;
}

function knockout_pair_items_avoiding_same_group(
    int $eventId,
    string $modality,
    string $gender,
    string $stage,
    array $items,
    callable $teamIdResolver
): array {
    $items = array_values($items);
    if (count($items) < 2) {
        return [];
    }

    if ($stage === 'final') {
        $pairs = [];
        for ($i = 0; $i < count($items); $i += 2) {
            if (isset($items[$i + 1])) {
                $pairs[] = [$items[$i], $items[$i + 1]];
            }
        }
        return $pairs;
    }

    $teamIdsByIndex = [];
    foreach ($items as $index => $item) {
        $teamIdsByIndex[$index] = (int) $teamIdResolver($item);
    }
    $groupsByTeamId = knockout_team_group_map($eventId, $modality, $gender, $teamIdsByIndex);

    $pairPenalty = static function (int $leftIndex, int $rightIndex) use ($teamIdsByIndex, $groupsByTeamId): int {
        $leftGroup = $groupsByTeamId[$teamIdsByIndex[$leftIndex]] ?? '';
        $rightGroup = $groupsByTeamId[$teamIdsByIndex[$rightIndex]] ?? '';

        return $leftGroup !== '' && $leftGroup === $rightGroup ? 1 : 0;
    };

    $bestPairs = null;

    if (count($items) <= 8) {
        $bestPenalty = PHP_INT_MAX;
        $search = function (array $remainingIndexes, array $pairs, int $penalty) use (&$search, &$bestPairs, &$bestPenalty, $pairPenalty): void {
            if ($penalty >= $bestPenalty) {
                return;
            }

            if (count($remainingIndexes) < 2) {
                $bestPairs = $pairs;
                $bestPenalty = $penalty;
                return;
            }

            $first = array_shift($remainingIndexes);
            foreach ($remainingIndexes as $position => $second) {
                $nextRemaining = $remainingIndexes;
                unset($nextRemaining[$position]);
                $nextPairs = $pairs;
                $nextPairs[] = [$first, $second];
                $search(array_values($nextRemaining), $nextPairs, $penalty + $pairPenalty($first, $second));
            }
        };

        $search(array_keys($items), [], 0);
    } else {
        $remainingIndexes = array_keys($items);
        $bestPairs = [];

        while (count($remainingIndexes) >= 2) {
            $first = array_shift($remainingIndexes);
            $bestPosition = array_key_first($remainingIndexes);
            $bestPenalty = PHP_INT_MAX;

            foreach ($remainingIndexes as $position => $candidate) {
                $penalty = $pairPenalty($first, $candidate);
                if ($penalty < $bestPenalty) {
                    $bestPenalty = $penalty;
                    $bestPosition = $position;
                }
                if ($penalty === 0) {
                    break;
                }
            }

            $second = $remainingIndexes[$bestPosition];
            unset($remainingIndexes[$bestPosition]);
            $remainingIndexes = array_values($remainingIndexes);
            $bestPairs[] = [$first, $second];
        }
    }

    $pairs = [];
    foreach ($bestPairs ?? [] as $pair) {
        $pairs[] = [$items[$pair[0]], $items[$pair[1]]];
    }

    return $pairs;
}

function group_stage_is_complete(int $eventId, string $modality, string $gender = 'masculino'): bool
{
    $stmt = db()->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status NOT IN ("encerrada", "wo", "cancelada") THEN 1 ELSE 0 END) AS pending
         FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "grupo" AND group_name <> ""'
    );
    $stmt->execute([$eventId, $modality, $gender]);
    $row = $stmt->fetch();

    return (int) ($row['total'] ?? 0) > 0 && (int) ($row['pending'] ?? 0) === 0;
}

function finals_are_complete(int $eventId, string $modality, string $gender = 'masculino'): bool
{
    $stmt = db()->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status NOT IN ("encerrada", "wo") THEN 1 ELSE 0 END) AS pending
         FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "final"'
    );
    $stmt->execute([$eventId, $modality, $gender]);
    $row = $stmt->fetch();

    if ((int) ($row['total'] ?? 0) > 0) {
        return (int) ($row['pending'] ?? 0) === 0;
    }

    $preliminaryStmt = db()->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status NOT IN ("encerrada", "wo") THEN 1 ELSE 0 END) AS pending
         FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "preliminar"
         AND score_data ->> "preliminary_for_stage" = "final"'
    );
    $preliminaryStmt->execute([$eventId, $modality, $gender]);
    $preliminaryRow = $preliminaryStmt->fetch();

    return (int) ($preliminaryRow['total'] ?? 0) > 0 && (int) ($preliminaryRow['pending'] ?? 0) === 0;
}

function round_robin_is_complete(int $eventId, string $modality, string $gender = 'masculino'): bool
{
    $params = [$eventId, $modality];
    $sql = 'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status NOT IN ("encerrada", "wo", "cancelada") THEN 1 ELSE 0 END) AS pending
         FROM matches
         WHERE event_id = ? AND modality = ? AND stage = "grupo"';

    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND gender = ?';
        $params[] = $gender;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return (int) ($row['total'] ?? 0) > 0 && (int) ($row['pending'] ?? 0) === 0;
}

function modality_standings_are_complete(int $eventId, string $modality, string $gender = 'masculino'): bool
{
    if ($modality === 'cubo_magico') {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM matches
             WHERE event_id = ? AND modality = ? AND (stage = "individual" OR stage = "grupo")
             AND status IN ("encerrada", "wo")'
        );
        $stmt->execute([$eventId, $modality]);
        return (int) $stmt->fetchColumn() > 0;
    }

    if (bracket_type_for_event_modality($eventId, $modality) === 'round_robin') {
        return round_robin_is_complete($eventId, $modality, $gender);
    }

    return finals_are_complete($eventId, $modality, $gender);
}

function calculate_cubo_magico_class_points(int $eventId, string $gender = 'masculino'): void
{
    $matchStmt = db()->prepare(
        'SELECT score_data FROM matches
         WHERE event_id = ? AND modality = ? AND (stage = "individual" OR stage = "grupo") AND gender = ? AND status IN ("encerrada", "wo")
         ORDER BY CASE WHEN stage = "individual" THEN 0 ELSE 1 END, id DESC
         LIMIT 1'
    );
    $matchStmt->execute([$eventId, 'cubo_magico', $gender]);
    $match = $matchStmt->fetch();
    if (!$match) {
        return;
    }

    $scoreData = decode_score_data($match['score_data'] ?? null, 'cubo_magico');
    $averages = $scoreData['player_averages'] ?? [];

    $playersStmt = db()->prepare(
        'SELECT p.id, p.name, t.class_name
         FROM players p
         JOIN teams t ON t.id = p.team_id
         WHERE t.event_id = ? AND t.modality = ?'
    );
    $playersStmt->execute([$eventId, 'cubo_magico']);
    $players = $playersStmt->fetchAll();
    if (!$players) {
        return;
    }

    foreach ($players as &$player) {
        $player['average'] = isset($averages[$player['id']]) ? (float) $averages[$player['id']] : null;
    }
    unset($player);

    usort($players, function (array $a, array $b): int {
        if ($a['average'] === null && $b['average'] === null) {
            return strcmp($a['name'], $b['name']);
        }
        if ($a['average'] === null) {
            return 1;
        }
        if ($b['average'] === null) {
            return -1;
        }
        if ($a['average'] !== $b['average']) {
            return $a['average'] < $b['average'] ? -1 : 1;
        }
        return strcmp($a['name'], $b['name']);
    });

    $pointsByPosition = [1 => 10, 2 => 8, 3 => 6];
    $classResults = [];

    foreach ($players as $index => $player) {
        $position = $index + 1;
        $points = $pointsByPosition[$position] ?? 2;
        $className = trim((string) ($player['class_name'] ?? ''));
        if ($className === '') {
            $className = 'Sem turma';
        }

        if (!isset($classResults[$className])) {
            $classResults[$className] = [
                'points' => 0,
                'best_position' => $position,
            ];
        }

        $classResults[$className]['points'] += $points;
        $classResults[$className]['best_position'] = min($classResults[$className]['best_position'], $position);
    }

    foreach ($classResults as $className => $result) {
        $points = $result['points'];
        $position = $result['best_position'];

        $stmt = db()->prepare('SELECT id FROM class_standings WHERE event_id = ? AND class_name = ?');
        $stmt->execute([$eventId, $className]);
        $classStanding = $stmt->fetch();

        if (!$classStanding) {
            $insert = db()->prepare('INSERT INTO class_standings (event_id, class_name, total_points) VALUES (?, ?, ?)');
            $insert->execute([$eventId, $className, $points]);
            $classStandingId = db()->lastInsertId();
        } else {
            $classStandingId = (int) $classStanding['id'];
        }

        $checkStmt = db()->prepare(
            'SELECT id FROM class_modality_points WHERE class_standings_id = ? AND modality = ? AND gender = ?'
        );
        $checkStmt->execute([$classStandingId, 'cubo_magico', $gender]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $update = db()->prepare(
                'UPDATE class_modality_points SET position = ?, points = ? WHERE id = ?'
            );
            $update->execute([$position, $points, (int) $existing['id']]);
        } else {
            $insert = db()->prepare(
                'INSERT INTO class_modality_points (class_standings_id, modality, gender, position, points) VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([$classStandingId, 'cubo_magico', $gender, $position, $points]);
        }

        $recalcStmt = db()->prepare(
            'UPDATE class_standings SET total_points = (
                SELECT COALESCE(SUM(points), 0) FROM class_modality_points WHERE class_standings_id = ?
            ) WHERE id = ?'
        );
        $recalcStmt->execute([$classStandingId, $classStandingId]);
    }
}

function save_class_points_from_standings(int $eventId, string $modality, string $gender, array $standings): void
{
    $pointsByPosition = [
        1 => 10,
        2 => 8,
        3 => 6,
        4 => 2,
    ];

    foreach ($standings as $index => $row) {
        $position = $index + 1;
        $points = $pointsByPosition[$position] ?? 2;
        $className = trim((string) ($row['class_name'] ?? $row['team_name'] ?? ''));
        if ($className === '') {
            $className = 'Sem turma';
        }

        $stmt = db()->prepare('SELECT id FROM class_standings WHERE event_id = ? AND class_name = ?');
        $stmt->execute([$eventId, $className]);
        $classStanding = $stmt->fetch();

        if (!$classStanding) {
            $insert = db()->prepare('INSERT INTO class_standings (event_id, class_name, total_points) VALUES (?, ?, ?)');
            $insert->execute([$eventId, $className, $points]);
            $classStandingId = (int) db()->lastInsertId();
        } else {
            $classStandingId = (int) $classStanding['id'];
        }

        $checkStmt = db()->prepare(
            'SELECT id FROM class_modality_points WHERE class_standings_id = ? AND modality = ? AND gender = ?'
        );
        $checkStmt->execute([$classStandingId, $modality, $gender]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $update = db()->prepare('UPDATE class_modality_points SET position = ?, points = ? WHERE id = ?');
            $update->execute([$position, $points, (int) $existing['id']]);
        } else {
            $insert = db()->prepare(
                'INSERT INTO class_modality_points (class_standings_id, modality, gender, position, points) VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([$classStandingId, $modality, $gender, $position, $points]);
        }

        $recalcStmt = db()->prepare(
            'UPDATE class_standings SET total_points = (
                SELECT COALESCE(SUM(points), 0) FROM class_modality_points WHERE class_standings_id = ?
            ) WHERE id = ?'
        );
        $recalcStmt->execute([$classStandingId, $classStandingId]);
    }
}

function calculate_class_points(int $eventId, string $modality, string $gender = 'masculino'): void
{
    if ($modality === 'cubo_magico') {
        $checkStmt = db()->prepare(
            'SELECT COUNT(*) FROM matches
             WHERE event_id = ? AND modality = ? AND (stage = "individual" OR stage = "grupo") AND gender = ? AND status IN ("encerrada", "wo")'
        );
        $checkStmt->execute([$eventId, $modality, $gender]);
        if ((int) $checkStmt->fetchColumn() === 0) {
            return;
        }

        calculate_cubo_magico_class_points($eventId, $gender);
        return;
    }

    if (bracket_type_for_event_modality($eventId, $modality) === 'round_robin') {
        if (!round_robin_is_complete($eventId, $modality, $gender)) {
            return;
        }

        $standings = $modality === 'quiz'
            ? calculate_quiz_collective_standings($eventId)
            : calculate_standings($eventId, $modality, null, $gender);

        if ($standings) {
            save_class_points_from_standings($eventId, $modality, $gender, $standings);
        }

        return;
    }

    // Verificar se as finais estão completas antes de calcular pontos
    if (!finals_are_complete($eventId, $modality, $gender)) {
        return;
    }

    // Obter a classificação mata-mata
    $standings = calculate_standings($eventId, $modality, null, $gender);
    if (empty($standings)) {
        $standings = calculate_knockout_standings($eventId, $modality, $gender);
    }
    $standings = apply_final_semifinal_third_place_rule($eventId, $modality, $gender, $standings);

    // Se não houver classificação disponível, não fazer nada
    if (empty($standings)) {
        return;
    }

    // Pontuação por posição
    $pointsByPosition = [
        1 => 10,
        2 => 8,
        3 => 6,
        4 => 2,
    ];

    // Para cada posição na classificação
    foreach ($standings as $index => $row) {
        $position = $index + 1;
        $points = $pointsByPosition[$position] ?? 2; // 4º lugar ou abaixo ganha 2 pontos
        $className = trim((string) ($row['class_name'] ?? $row['team_name'] ?? ''));
        if ($className === '') {
            $className = 'Sem turma';
        }
        $teamId = (int) $row['team_id'];

        // Verificar se o time teve algum W.O. nesta modalidade/gênero
        $woCheck = db()->prepare(
            'SELECT COUNT(*) FROM matches 
             WHERE event_id = ? AND modality = ? AND gender = ? 
             AND (home_team_id = ? OR away_team_id = ?) AND status = "wo"'
        );
        $woCheck->execute([$eventId, $modality, $gender, $teamId, $teamId]);
        $hasWo = (int) $woCheck->fetchColumn() > 0;

        // Se teve W.O. e está em 4º lugar ou abaixo, perde os 2 pontos
        if ($hasWo && $position >= 4) {
            $points = 0;
        }

        // Buscar ou criar class_standings para esta turma
        $stmt = db()->prepare('SELECT id FROM class_standings WHERE event_id = ? AND class_name = ?');
        $stmt->execute([$eventId, $className]);
        $classStanding = $stmt->fetch();

        if (!$classStanding) {
            $insert = db()->prepare('INSERT INTO class_standings (event_id, class_name, total_points) VALUES (?, ?, ?)');
            $insert->execute([$eventId, $className, $points]);
            $classStandingId = db()->lastInsertId();
        } else {
            $classStandingId = (int) $classStanding['id'];
        }

        // Verificar se já existe registro para esta modalidade/gênero
        $checkStmt = db()->prepare(
            'SELECT id FROM class_modality_points WHERE class_standings_id = ? AND modality = ? AND gender = ?'
        );
        $checkStmt->execute([$classStandingId, $modality, $gender]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Atualizar registro existente
            $update = db()->prepare(
                'UPDATE class_modality_points SET position = ?, points = ? WHERE id = ?'
            );
            $update->execute([$position, $points, (int) $existing['id']]);
        } else {
            // Criar novo registro
            $insert = db()->prepare(
                'INSERT INTO class_modality_points (class_standings_id, modality, gender, position, points) VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([$classStandingId, $modality, $gender, $position, $points]);
        }

        // Recalcular total de pontos da turma
        $recalcStmt = db()->prepare(
            'UPDATE class_standings SET total_points = (
                SELECT COALESCE(SUM(points), 0) FROM class_modality_points WHERE class_standings_id = ?
            ) WHERE id = ?'
        );
        $recalcStmt->execute([$classStandingId, $classStandingId]);
    }
}

function get_class_standings(int $eventId): array
{
    $modalitiesStmt = db()->prepare('SELECT modality FROM event_modalities WHERE event_id = ?');
    $modalitiesStmt->execute([$eventId]);
    foreach ($modalitiesStmt->fetchAll(PDO::FETCH_COLUMN) as $modality) {
        $genders = is_mixed_modality((string) $modality) ? ['masculino'] : ['masculino', 'feminino'];
        foreach ($genders as $gender) {
            calculate_class_points($eventId, (string) $modality, $gender);
        }
    }
    // Primeiro, obter todas as turmas que têm times no evento
    $classesStmt = db()->prepare(
        'SELECT DISTINCT class_name FROM teams WHERE event_id = ? AND class_name IS NOT NULL ORDER BY class_name'
    );
    $classesStmt->execute([$eventId]);
    $classes = $classesStmt->fetchAll();

    // Garantir que todas as turmas tenham registro na classificação geral
    foreach ($classes as $class) {
        $className = $class['class_name'];
        $stmt = db()->prepare('SELECT id FROM class_standings WHERE event_id = ? AND class_name = ?');
        $stmt->execute([$eventId, $className]);
        $classStanding = $stmt->fetch();

        if (!$classStanding) {
            $insert = db()->prepare('INSERT INTO class_standings (event_id, class_name, total_points) VALUES (?, ?, 0)');
            $insert->execute([$eventId, $className]);
        }
    }

    // Agora buscar a classificação geral
    $stmt = db()->prepare(
        'SELECT cs.*,
            ' . (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? 'GROUP_CONCAT(CASE WHEN cmp.id IS NOT NULL THEN CONCAT(cmp.modality, "-", cmp.gender, ": ", cmp.position, "º (", cmp.points, " pts)") END ORDER BY cmp.modality, cmp.gender SEPARATOR " | ")'
                : 'string_agg(CONCAT(cmp.modality, "-", cmp.gender, ": ", cmp.position, "º (", cmp.points, " pts)"), " | " ORDER BY cmp.modality, cmp.gender) FILTER (WHERE cmp.id IS NOT NULL)') . ' AS details
        FROM class_standings cs
        LEFT JOIN class_modality_points cmp ON cmp.class_standings_id = cs.id
        WHERE cs.event_id = ?
        GROUP BY cs.id
        ORDER BY cs.total_points DESC, cs.class_name'
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function bracket_score_data_with_players(string $modality, ?int $homePlayerId, ?int $awayPlayerId): array
{
    $data = default_score_data($modality);

    if ($homePlayerId) {
        $data['bracket_home_player_id'] = $homePlayerId;
    }

    if ($awayPlayerId) {
        $data['bracket_away_player_id'] = $awayPlayerId;
    }

    return $data;
}

function bracket_winner_player_id(array $match, int $winnerTeamId): ?int
{
    $data = decode_score_data($match['score_data'] ?? null, $match['modality']);

    if ((int) ($match['home_team_id'] ?? 0) === $winnerTeamId && !empty($data['bracket_home_player_id'])) {
        return (int) $data['bracket_home_player_id'];
    }

    if ((int) ($match['away_team_id'] ?? 0) === $winnerTeamId && !empty($data['bracket_away_player_id'])) {
        return (int) $data['bracket_away_player_id'];
    }

    return null;
}

function knockout_next_stage(string $stage): ?string
{
    return [
        'dezesseis_avos' => 'oitavas',
        'oitavas' => 'quartas',
        'quartas' => 'semifinal',
        'semifinal' => 'final',
    ][$stage] ?? null;
}

function knockout_stage_for_participant_count(int $participantCount): ?string
{
    if ($participantCount >= 32) {
        return 'dezesseis_avos';
    }
    if ($participantCount >= 16) {
        return 'oitavas';
    }
    if ($participantCount >= 8) {
        return 'quartas';
    }
    if ($participantCount >= 4) {
        return 'semifinal';
    }
    if ($participantCount >= 2) {
        return 'final';
    }
    if ($participantCount === 1) {
        return 'final';
    }

    return null;
}

function knockout_sort_order_base(string $stage): int
{
    return [
        'preliminar' => 600,
        'dezesseis_avos' => 500,
        'oitavas' => 600,
        'quartas' => 700,
        'semifinal' => 800,
        'final' => 900,
    ][$stage] ?? 900;
}

function create_preliminary_shell(
    int $eventId,
    string $modality,
    string $gender,
    string $fromStage,
    string $forStage,
    int $reservedTeamId,
    ?int $reservedPlayerId,
    ?string $place = null
): int {
    $existingStmt = db()->prepare(
        'SELECT id FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "preliminar"
         AND score_data ->> "preliminary_from_stage" = ?
         AND score_data ->> "preliminary_for_stage" = ?
         LIMIT 1'
    );
    $existingStmt->execute([$eventId, $modality, $gender, $fromStage, $forStage]);
    if ($existing = $existingStmt->fetch()) {
        return (int) $existing['id'];
    }

    $scoreData = bracket_score_data_with_players($modality, $reservedPlayerId, null);
    $scoreData['preliminary_from_stage'] = $fromStage;
    $scoreData['preliminary_for_stage'] = $forStage;
    $scoreData['preliminary_reserved_team_id'] = $reservedTeamId;
    if ($reservedPlayerId) {
        $scoreData['preliminary_reserved_player_id'] = $reservedPlayerId;
    }

    $stmt = db()->prepare(
        'INSERT INTO matches (event_id, modality, gender, stage, home_team_id, away_team_id, score_data, place, sort_order)
         VALUES (?, ?, ?, "preliminar", ?, NULL, ?, ?, ?)'
    );
    $stmt->execute([
        $eventId,
        $modality,
        $gender,
        $reservedTeamId,
        json_encode($scoreData, JSON_UNESCAPED_UNICODE),
        $place,
        knockout_sort_order_base('preliminar'),
    ]);

    return (int) db()->lastInsertId();
}

function fill_preliminary_opponent(array $preliminaryMatch, array $opponentMatch): bool
{
    if (!empty($preliminaryMatch['away_team_id'])) {
        return false;
    }

    $opponentTeamId = (int) ($opponentMatch['winner_team_id'] ?? 0);
    if ($opponentTeamId <= 0) {
        return false;
    }

    $scoreData = decode_score_data($preliminaryMatch['score_data'] ?? null, $preliminaryMatch['modality']);
    $opponentPlayerId = bracket_winner_player_id($opponentMatch, $opponentTeamId);
    if ($opponentPlayerId) {
        $scoreData['bracket_away_player_id'] = $opponentPlayerId;
    }
    $scoreData['preliminary_opponent_source_match_id'] = (int) $opponentMatch['id'];

    $stmt = db()->prepare(
        'UPDATE matches
         SET away_team_id = ?, score_data = ?
         WHERE id = ? AND away_team_id IS NULL'
    );
    $stmt->execute([
        $opponentTeamId,
        json_encode($scoreData, JSON_UNESCAPED_UNICODE),
        (int) $preliminaryMatch['id'],
    ]);

    return $stmt->rowCount() > 0;
}

function create_next_stage_from_winner_matches(int $eventId, string $modality, string $gender, string $nextStage, array $winnerMatches): int
{
    if (count($winnerMatches) < 2) {
        return 0;
    }

    $existingStmt = db()->prepare('SELECT COUNT(*) AS total FROM matches WHERE event_id = ? AND modality = ? AND gender = ? AND stage = ?');
    $existingStmt->execute([$eventId, $modality, $gender, $nextStage]);
    if ((int) $existingStmt->fetch()['total'] > 0) {
        return 0;
    }

    $created = 0;
    $baseOrder = knockout_sort_order_base($nextStage);

    $pairs = knockout_pair_items_avoiding_same_group(
        $eventId,
        $modality,
        $gender,
        $nextStage,
        $winnerMatches,
        static fn (array $match): int => (int) ($match['winner_team_id'] ?? 0)
    );

    foreach ($pairs as $pair) {
        $homeMatch = $pair[0];
        $awayMatch = $pair[1];
        $homeTeamId = (int) $homeMatch['winner_team_id'];
        $awayTeamId = (int) $awayMatch['winner_team_id'];
        $homePlayerId = bracket_winner_player_id($homeMatch, $homeTeamId);
        $awayPlayerId = bracket_winner_player_id($awayMatch, $awayTeamId);

        $stmt = db()->prepare(
            'INSERT INTO matches (event_id, modality, gender, stage, home_team_id, away_team_id, score_data, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventId,
            $modality,
            $gender,
            $nextStage,
            $homeTeamId,
            $awayTeamId,
            json_encode(bracket_score_data_with_players($modality, $homePlayerId, $awayPlayerId), JSON_UNESCAPED_UNICODE),
            $baseOrder + ($created * 2),
        ]);
        $created++;
    }

    return $created;
}

function maybe_generate_next_stage_after_preliminary(array $preliminaryMatch): int
{
    if (!in_array($preliminaryMatch['status'] ?? '', ['encerrada', 'wo'], true) || empty($preliminaryMatch['winner_team_id'])) {
        return 0;
    }

    $scoreData = decode_score_data($preliminaryMatch['score_data'] ?? null, $preliminaryMatch['modality']);
    $fromStage = (string) ($scoreData['preliminary_from_stage'] ?? '');
    $forStage = (string) ($scoreData['preliminary_for_stage'] ?? '');
    $opponentSourceMatchId = (int) ($scoreData['preliminary_opponent_source_match_id'] ?? 0);
    $reservedSourceMatchId = (int) ($scoreData['preliminary_reserved_source_match_id'] ?? 0);
    if ($fromStage === '' || $forStage === '' || $opponentSourceMatchId <= 0) {
        return 0;
    }

    $eventId = (int) $preliminaryMatch['event_id'];
    $modality = (string) $preliminaryMatch['modality'];
    $gender = (string) ($preliminaryMatch['gender'] ?? 'masculino');

    $existingStmt = db()->prepare('SELECT COUNT(*) AS total FROM matches WHERE event_id = ? AND modality = ? AND gender = ? AND stage = ?');
    $existingStmt->execute([$eventId, $modality, $gender, $forStage]);
    if ((int) $existingStmt->fetch()['total'] > 0) {
        return 0;
    }

    $winnersStmt = db()->prepare(
        'SELECT id, event_id, modality, gender, home_team_id, away_team_id, winner_team_id, score_data
         FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = ?
         AND status IN ("encerrada", "wo") AND winner_team_id IS NOT NULL
         AND id NOT IN (?, ?)
         ORDER BY sort_order, id'
    );
    $winnersStmt->execute([$eventId, $modality, $gender, $fromStage, $opponentSourceMatchId, $reservedSourceMatchId]);
    $winnerMatches = $winnersStmt->fetchAll();
    array_unshift($winnerMatches, $preliminaryMatch);

    return create_next_stage_from_winner_matches($eventId, $modality, $gender, $forStage, $winnerMatches);
}

function maybe_generate_next_knockout_stage(int $eventId, string $modality, string $stage, string $gender = 'masculino'): int
{
    if ($stage === 'preliminar') {
        $stmt = db()->prepare(
            'SELECT id, event_id, modality, gender, home_team_id, away_team_id, winner_team_id, status, score_data
             FROM matches
             WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "preliminar"
             ORDER BY sort_order DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$eventId, $modality, $gender]);
        $preliminaryMatch = $stmt->fetch();

        return $preliminaryMatch ? maybe_generate_next_stage_after_preliminary($preliminaryMatch) : 0;
    }

    $nextStage = knockout_next_stage($stage);

    if ($nextStage === null) {
        return 0;
    }

    $pendingStmt = db()->prepare(
        'SELECT COUNT(*) AS total FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = ?
         AND status NOT IN ("encerrada", "wo")'
    );
    $pendingStmt->execute([$eventId, $modality, $gender, $stage]);

    if ((int) $pendingStmt->fetch()['total'] > 0) {
        return 0;
    }

    $existingStmt = db()->prepare('SELECT COUNT(*) AS total FROM matches WHERE event_id = ? AND modality = ? AND gender = ? AND stage = ?');
    $existingStmt->execute([$eventId, $modality, $gender, $nextStage]);

    if ((int) $existingStmt->fetch()['total'] > 0) {
        return 0;
    }

    $winnersStmt = db()->prepare(
        'SELECT id, event_id, modality, gender, home_team_id, away_team_id, winner_team_id, score_data
         FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = ?
         AND status IN ("encerrada", "wo") AND winner_team_id IS NOT NULL
         ORDER BY sort_order, id'
    );
    $winnersStmt->execute([$eventId, $modality, $gender, $stage]);
    $finishedMatches = $winnersStmt->fetchAll();

    $preliminaryStmt = db()->prepare(
        'SELECT id, event_id, modality, gender, home_team_id, away_team_id, score_data
         FROM matches
         WHERE event_id = ? AND modality = ? AND gender = ? AND stage = "preliminar"
         AND score_data ->> "preliminary_from_stage" = ?
         AND score_data ->> "preliminary_for_stage" = ?
         LIMIT 1'
    );
    $preliminaryStmt->execute([$eventId, $modality, $gender, $stage, $nextStage]);
    $preliminaryMatch = $preliminaryStmt->fetch();

    if ($preliminaryMatch) {
        if ($finishedMatches) {
            fill_preliminary_opponent($preliminaryMatch, $finishedMatches[0]);
        }
        return 0;
    }

    if (count($finishedMatches) < 2) {
        return 0;
    }

    if (count($finishedMatches) % 2 === 1) {
        $reservedMatch = array_shift($finishedMatches);
        $opponentMatch = array_shift($finishedMatches);
        $reservedTeamId = (int) $reservedMatch['winner_team_id'];
        $opponentTeamId = (int) $opponentMatch['winner_team_id'];
        $reservedPlayerId = bracket_winner_player_id($reservedMatch, $reservedTeamId);
        $opponentPlayerId = bracket_winner_player_id($opponentMatch, $opponentTeamId);
        $scoreData = bracket_score_data_with_players($modality, $reservedPlayerId, $opponentPlayerId);
        $scoreData['preliminary_from_stage'] = $stage;
        $scoreData['preliminary_for_stage'] = $nextStage;
        $scoreData['preliminary_reserved_team_id'] = $reservedTeamId;
        $scoreData['preliminary_reserved_source_match_id'] = (int) $reservedMatch['id'];
        $scoreData['preliminary_opponent_source_match_id'] = (int) $opponentMatch['id'];

        $stmt = db()->prepare(
            'INSERT INTO matches (event_id, modality, gender, stage, home_team_id, away_team_id, score_data, sort_order)
             VALUES (?, ?, ?, "preliminar", ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventId,
            $modality,
            $gender,
            $reservedTeamId,
            $opponentTeamId,
            json_encode($scoreData, JSON_UNESCAPED_UNICODE),
            knockout_sort_order_base('preliminar'),
        ]);

        return 0;
    }

    return create_next_stage_from_winner_matches($eventId, $modality, $gender, $nextStage, $finishedMatches);
}
