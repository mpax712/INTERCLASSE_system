<?php

require __DIR__ . '/_bootstrap.php';

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : null;
$date = $_GET['date'] ?? null;
$limit = max(1, min(200, (int) ($_GET['limit'] ?? 100)));

if (!$eventId && !$date) {
    json_response(['error' => 'Informe event_id ou date para consultar jogos.'], 422);
}

$cacheKey = 'games:' . ($eventId ?: 'all') . ':' . ($date ?: 'any') . ':' . $limit;
$cached = public_cache_read($cacheKey);
if ($cached !== null) {
    json_response($cached);
}

$params = [];
$sql = 'SELECT m.id, m.event_id, m.modality, m.gender, m.stage, m.group_name,
            m.home_team_id, m.away_team_id, m.home_player_id, m.away_player_id,
            m.home_score, m.away_score, m.score_data, m.winner_team_id, m.winner_player_id, m.participant_snapshot,
            m.status, m.starts_at, m.place, m.sort_order, m.version,
            e.name AS event_name, COALESCE(hp.name, ht.name) AS home_name, COALESCE(ap.name, at.name) AS away_name
        FROM matches m
        JOIN events e ON e.id = m.event_id
        LEFT JOIN teams ht ON ht.id = m.home_team_id
        LEFT JOIN teams at ON at.id = m.away_team_id
        LEFT JOIN players hp ON hp.id = m.home_player_id
        LEFT JOIN players ap ON ap.id = m.away_player_id
        WHERE 1=1';

if ($eventId) {
    $sql .= ' AND m.event_id = ?';
    $params[] = $eventId;
}

if ($date) {
    $sql .= ' AND CAST(m.starts_at AS DATE) = ?';
    $params[] = $date;
}

$sql .= ' ORDER BY m.starts_at, m.sort_order, m.id LIMIT ' . $limit;
$stmt = db()->prepare($sql);
$stmt->execute($params);

$payload = ['data' => array_map('match_historical_labels', $stmt->fetchAll())];
public_cache_write($cacheKey, $payload, 60);
json_response($payload);
