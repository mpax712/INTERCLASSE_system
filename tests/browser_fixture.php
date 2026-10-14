<?php
require_once __DIR__ . '/../services/GameCompetition.php';
$schema = getenv('TEST_SCHEMA') ?: '';
if (!preg_match('/^interclasse_game_stage_[a-z0-9_]+$/', $schema)) exit(2);
db()->exec('SET search_path TO ' . $schema . ', public');
$email = 'browser-stage@local.invalid';
$password = getenv('BROWSER_STAGE_PASSWORD') ?: throw new RuntimeException('Set BROWSER_STAGE_PASSWORD.');
$stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$adminId = (int) $stmt->fetchColumn();
if (!$adminId) {
    $insert = db()->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
    $insert->execute(['Administrador de teste', $email, password_hash($password, PASSWORD_DEFAULT)]);
    $adminId = (int) db()->lastInsertId();
}
$event = db()->prepare("INSERT INTO events (name, created_by, start_date, status, place) VALUES (?, ?, CURRENT_DATE, 'em_andamento', 'Laboratório de informática')");
$event->execute(['Campeonato de teste visual', $adminId]);
$eventId = (int) db()->lastInsertId();
$insertModality = db()->prepare('INSERT INTO event_modalities (event_id, modality, score_type, max_players_per_team, bracket_type, rules_json) VALUES (?, ?, ?, 32, ?, ?)');
$insertTeam = db()->prepare("INSERT INTO teams (event_id, modality, name, class_name, gender, group_name) VALUES (?, ?, ?, ?, 'masculino', '')");
$insertPlayer = db()->prepare('INSERT INTO players (team_id, name) VALUES (?, ?)');
foreach (['fifa', 'clash_royale'] as $modality) {
    $insertModality->execute([$eventId, $modality, score_type_for_modality($modality), default_bracket_type_for_modality($modality), json_encode(validate_game_rules($modality, []))]);
    foreach (['1º DS','2º DS','3º ADM','2º RH'] as $classIndex => $className) {
        $insertTeam->execute([$eventId, $modality, $className, $className]);
        $teamId = (int) db()->lastInsertId();
        for ($i = 1; $i <= 2; $i++) $insertPlayer->execute([$teamId, ['Ana','Bruno','Camila','Diego','Elisa','Felipe','Gabi','Henrique'][$classIndex * 2 + $i - 1] . ' ' . $className]);
    }
}
game_generate_groups($eventId, 'fifa');
game_generate_bracket($eventId, 'clash_royale');
echo "event_id=$eventId\nemail=$email\n";
