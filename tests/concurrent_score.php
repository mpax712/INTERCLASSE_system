<?php
require_once __DIR__ . '/../includes/db.php';
$schema = getenv('TEST_SCHEMA') ?: '';
$eventId = (int) (getenv('TEST_EVENT_ID') ?: 0);
if (!preg_match('/^interclasse_game_stage_[a-z0-9_]+$/', $schema) || !$eventId) exit(2);
db()->exec('SET search_path TO ' . $schema . ', public');
$stmt = db()->prepare("SELECT id, version FROM matches WHERE event_id = ? AND modality = 'clash_royale' AND stage = 'quartas' AND status = 'agendada' ORDER BY id LIMIT 1");
$stmt->execute([$eventId]); $match = $stmt->fetch();
if (!$match) throw new RuntimeException('Need a scheduled Clash quarterfinal.');
$processes = [];
foreach ([1,2] as $unused) {
    $processes[] = proc_open([PHP_BINARY, __DIR__ . '/concurrent_worker.php', (string) $match['id'], (string) $match['version']], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    $streams[] = $pipes;
}
$results = [];
foreach ($processes as $i => $process) {
    $results[] = trim(stream_get_contents($streams[$i][1]));
    $errors[] = stream_get_contents($streams[$i][2]);
    fclose($streams[$i][1]); fclose($streams[$i][2]);
    proc_close($process);
}
sort($results);
$logs = db()->prepare("SELECT COUNT(*) FROM score_logs WHERE match_id = ? AND action = 'start'");
$logs->execute([$match['id']]);
echo json_encode(['results' => $results, 'start_logs' => (int) $logs->fetchColumn(), 'errors' => $errors]), "\n";
if ($results !== ['409','ok']) exit(1);
