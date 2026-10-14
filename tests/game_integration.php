<?php

require_once __DIR__ . '/../services/GameScoreService.php';

$schema = getenv('TEST_SCHEMA') ?: '';
if (!preg_match('/^interclasse_game_stage_[a-z0-9_]+$/', $schema)) {
    fwrite(STDERR, "Set TEST_SCHEMA to an isolated interclasse_game_stage_* schema.\n");
    exit(2);
}
db()->exec('SET search_path TO ' . $schema . ', public');
$adminId = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$adminId) throw new RuntimeException('Stage clone needs an admin.');
start_app_session();
$_SESSION['user_id'] = $adminId;
$user = current_user();

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}

function make_action(int $matchId, string $action, int $version, array $extra = []): array
{
    return game_apply_score_action($matchId, array_merge(['action' => $action, 'expected_version' => $version, 'request_id' => bin2hex(random_bytes(16))], $extra), current_user());
}

function get_match(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM matches WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$eventStmt = db()->prepare("INSERT INTO events (name, created_by, start_date, status) VALUES (?, ?, CURRENT_DATE, 'em_andamento')");
$eventStmt->execute(['Stage Game Integration ' . bin2hex(random_bytes(4)), $adminId]);
$eventId = (int) db()->lastInsertId();
try {
    $insertModality = db()->prepare('INSERT INTO event_modalities (event_id, modality, score_type, max_players_per_team, bracket_type, rules_json) VALUES (?, ?, ?, ?, ?, ?)');
    $insertTeam = db()->prepare('INSERT INTO teams (event_id, modality, name, class_name, gender, group_name) VALUES (?, ?, ?, ?, ?, ?)');
    $insertPlayer = db()->prepare('INSERT INTO players (team_id, name) VALUES (?, ?)');
    foreach (['fifa','clash_royale'] as $modality) {
        $insertModality->execute([$eventId, $modality, score_type_for_modality($modality), 2, default_bracket_type_for_modality($modality), json_encode(validate_game_rules($modality, []))]);
        foreach (['1ADM','2ADM'] as $class) {
            $insertTeam->execute([$eventId, $modality, $class, $class, 'masculino', '']);
            $teamId = (int) db()->lastInsertId();
            for ($i=1; $i<=2; $i++) $insertPlayer->execute([$teamId, $modality . ' ' . $class . ' ' . $i]);
        }
    }
    check(count(game_players($eventId, 'fifa')) === 4, 'individual enrollment');
    try { game_generate_bracket($eventId, 'fifa'); throw new RuntimeException('Expected FIFA group prerequisite'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'Gere as partidas de grupo antes do mata-mata.', 'FIFA knockout requires generated groups'); }
    check(game_generate_groups($eventId, 'fifa') === 6, 'FIFA group schedule');
    $groupStmt = db()->prepare("SELECT id FROM matches WHERE event_id = ? AND modality = 'fifa' AND stage = 'grupo' ORDER BY id");
    $groupStmt->execute([$eventId]);
    $groupIds = array_map('intval', array_column($groupStmt->fetchAll(), 'id'));
    $first = $groupIds[0];
    make_action($first, 'start', 0);
    check(get_match($first)['status'] === 'em_andamento', 'start');
    $request = ['action' => 'adjust', 'expected_version' => 1, 'request_id' => bin2hex(random_bytes(16)), 'team' => 'home', 'amount' => 1];
    game_apply_score_action($first, $request, $user);
    game_apply_score_action($first, $request, $user);
    check((int) get_match($first)['home_score'] === 1 && (int) get_match($first)['version'] === 2, 'duplicate request applied once');
    try { make_action($first, 'adjust', 1, ['team' => 'home', 'amount' => 1]); throw new RuntimeException('Expected version conflict'); }
    catch (GameScoreException $error) { check($error->httpStatus === 409, 'stale version rejected'); }
    make_action($first, 'finish', 2);
    check((int) get_match($first)['winner_player_id'] > 0, 'FIFA winner saved');
    foreach (array_slice($groupIds, 1) as $id) { make_action($id, 'start', 0); make_action($id, 'finish', 1); }
    $standing = game_group_standings($eventId, 'fifa', 'A');
    check(count($standing) === 4 && $standing[0]['P'] >= 3, 'FIFA standings and ties');
    check(game_generate_bracket($eventId, 'fifa') >= 1, 'FIFA knockout generated');
    $finalStmt = db()->prepare("SELECT id FROM matches WHERE event_id = ? AND modality = 'fifa' AND stage = 'final' LIMIT 1");
    $finalStmt->execute([$eventId]);
    $finalId = (int) $finalStmt->fetchColumn();
    make_action($finalId, 'start', (int) get_match($finalId)['version']);
    try { make_action($finalId, 'finish', (int) get_match($finalId)['version']); throw new RuntimeException('Expected penalty validation'); }
    catch (GameScoreException $error) { check($error->httpStatus === 422, 'FIFA knockout draw requires penalties'); }
    make_action($finalId, 'finish', (int) get_match($finalId)['version'], ['home_penalties' => 5, 'away_penalties' => 4]);
    check((int) get_match($finalId)['winner_player_id'] > 0, 'penalty winner and finalization');
    $points = db()->prepare("SELECT COALESCE(SUM(points),0) FROM class_modality_points WHERE modality = 'fifa' AND class_standings_id IN (SELECT id FROM class_standings WHERE event_id = ?)");
    $points->execute([$eventId]);
    check((int) $points->fetchColumn() >= 18, 'class points aggregated');
    check(game_generate_bracket($eventId, 'clash_royale') >= 2, 'Clash bracket with bye');
    $semifinal = db()->prepare("SELECT id FROM matches WHERE event_id = ? AND modality = 'clash_royale' AND stage = 'semifinal' ORDER BY id");
    $semifinal->execute([$eventId]);
    foreach ($semifinal->fetchAll(PDO::FETCH_COLUMN) as $semiId) {
        $semiId = (int) $semiId;
        make_action($semiId, 'start', 0);
        make_action($semiId, 'record_battle', 1, ['home_crowns' => 2, 'away_crowns' => 1, 'winner' => 'home']);
        make_action($semiId, 'record_battle', 2, ['home_crowns' => 1, 'away_crowns' => 0, 'winner' => 'home']);
        make_action($semiId, 'finish', 3);
    }
    $clashFinal = db()->prepare("SELECT * FROM matches WHERE event_id = ? AND modality = 'clash_royale' AND stage = 'final' LIMIT 1");
    $clashFinal->execute([$eventId]);
    $final = $clashFinal->fetch();
    check((int) $final['home_player_id'] > 0 && (int) $final['away_player_id'] > 0, 'winner advanced to final');
    make_action((int) $final['id'], 'wo', (int) $final['version'], ['team' => 'home']);
    check(get_match((int) $final['id'])['status'] === 'wo', 'Clash W.O.');
    $clashVersion = (int) get_match((int) $final['id'])['version'];
    make_action((int) $final['id'], 'correct', $clashVersion, ['reason' => 'Conferência da súmula', 'battles' => [
        ['home_crowns' => 2, 'away_crowns' => 1, 'winner' => 'home'],
        ['home_crowns' => 3, 'away_crowns' => 0, 'winner' => 'home'],
    ]]);
    check(get_match((int) $final['id'])['status'] === 'encerrada' && (int) get_match((int) $final['id'])['home_score'] === 2, 'administrative correction recalculates result');
    $semiStmt = db()->prepare("SELECT id, version FROM matches WHERE event_id = ? AND modality = 'clash_royale' AND stage = 'semifinal' ORDER BY id LIMIT 1");
    $semiStmt->execute([$eventId]);
    $completedSemi = $semiStmt->fetch();
    try { make_action((int) $completedSemi['id'], 'correct', (int) $completedSemi['version'], ['reason' => 'Revisão após final', 'battles' => [['home_crowns' => 1, 'away_crowns' => 0, 'winner' => 'home'], ['home_crowns' => 2, 'away_crowns' => 0, 'winner' => 'home']]]); throw new RuntimeException('Expected downstream block'); }
    catch (GameScoreException $error) { check($error->httpStatus === 409, 'correction blocked after downstream started'); }
    try { make_action($first, 'correct', (int) get_match($first)['version'], ['reason' => 'Revisão após chave', 'home_score' => 2, 'away_score' => 0]); throw new RuntimeException('Expected group correction block'); }
    catch (GameScoreException $error) { check($error->httpStatus === 409, 'group correction blocked after knockout generation'); }

    $updateRules = db()->prepare('UPDATE event_modalities SET rules_json = ? WHERE event_id = ? AND modality = ?');
    $updateRules->execute([json_encode(['best_of' => 5]), $eventId, 'clash_royale']);
    check(game_match_rules(get_match((int) $final['id']))['best_of'] === 3, 'existing match keeps rule snapshot');
    $clashPlayers = game_players($eventId, 'clash_royale');
    $extraId = game_create_match($eventId, 'clash_royale', 'final', null, game_node_for_player($clashPlayers[0]), game_node_for_player($clashPlayers[1]), 9999);
    check(game_match_rules(get_match($extraId))['best_of'] === 5, 'new match gets updated rule snapshot');
    make_action($extraId, 'start', 0);
    $before = get_match($extraId);
    $logsBefore = db()->query('SELECT COUNT(*) FROM score_logs WHERE match_id = ' . $extraId)->fetchColumn();
    try { make_action($extraId, 'record_battle', 1, ['home_crowns' => 4, 'away_crowns' => 0, 'winner' => 'home']); throw new RuntimeException('Expected invalid crowns'); }
    catch (GameScoreException $error) { check($error->httpStatus === 422, 'invalid battle rejected'); }
    check(get_match($extraId)['version'] === $before['version'] && db()->query('SELECT COUNT(*) FROM score_logs WHERE match_id = ' . $extraId)->fetchColumn() === $logsBefore, 'invalid action leaves score and audit unchanged');
    make_action($extraId, 'cancel', 1, ['reason' => 'Partida avulsa cancelada']);
    check(get_match($extraId)['status'] === 'cancelada' && get_match($extraId)['cancel_reason'] === 'Partida avulsa cancelada', 'cancellation stores reason');

    $fifaPlayers = game_players($eventId, 'fifa');
    $rollbackId = game_create_match($eventId, 'fifa', 'final', null, game_node_for_player($fifaPlayers[0]), game_node_for_player($fifaPlayers[1]), 10001);
    make_action($rollbackId, 'start', 0);
    $functionName = $schema . '.reject_test_log';
    db()->exec('CREATE OR REPLACE FUNCTION ' . $functionName . "() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.match_id = $rollbackId AND NEW.action = 'adjust' THEN RAISE EXCEPTION 'forced audit failure'; END IF; RETURN NEW; END \$\$");
    db()->exec('CREATE TRIGGER reject_test_log BEFORE INSERT ON score_logs FOR EACH ROW EXECUTE FUNCTION ' . $functionName . '()');
    try { make_action($rollbackId, 'adjust', 1, ['team' => 'home', 'amount' => 1]); throw new RuntimeException('Expected audit failure'); }
    catch (PDOException $error) { check((int) get_match($rollbackId)['home_score'] === 0 && (int) get_match($rollbackId)['version'] === 1, 'failed audit rolls back score'); }
    db()->exec('DROP TRIGGER reject_test_log ON score_logs');
    db()->exec('DROP FUNCTION ' . $functionName . '()');
    make_action($rollbackId, 'await_result', 1);
    check(get_match($rollbackId)['status'] === 'aguardando_resultado', 'awaiting result state');
    make_action($rollbackId, 'resume', 2);
    check(get_match($rollbackId)['status'] === 'em_andamento', 'resume state');

    $byeEventStmt = db()->prepare("INSERT INTO events (name, created_by, start_date, status) VALUES (?, ?, CURRENT_DATE, 'em_andamento')");
    $byeEventStmt->execute(['Stage byes ' . bin2hex(random_bytes(3)), $adminId]);
    $byeEventId = (int) db()->lastInsertId();
    $insertModality->execute([$byeEventId, 'clash_royale', 'batalhas', 32, 'simple', json_encode(validate_game_rules('clash_royale', []))]);
    for ($i = 1; $i <= 5; $i++) {
        $insertTeam->execute([$byeEventId, 'clash_royale', 'Turma ' . $i, 'Turma ' . $i, 'masculino', '']);
        $insertPlayer->execute([(int) db()->lastInsertId(), 'Aluno ' . $i]);
    }
    check(game_generate_bracket($byeEventId, 'clash_royale') === 4, 'five-player bracket generates four matches');
    $stageCounts = db()->prepare("SELECT stage, COUNT(*) AS n FROM matches WHERE event_id = ? GROUP BY stage");
    $stageCounts->execute([$byeEventId]);
    $byStage = array_column($stageCounts->fetchAll(), 'n', 'stage');
    check((int) ($byStage['quartas'] ?? 0) === 1 && (int) ($byStage['semifinal'] ?? 0) === 2 && (int) ($byStage['final'] ?? 0) === 1, 'byes stay in the first round');
} finally {
    $delete = db()->prepare('DELETE FROM events WHERE id = ?');
    $delete->execute([$eventId]);
}
