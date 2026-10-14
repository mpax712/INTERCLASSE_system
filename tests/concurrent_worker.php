<?php
require_once __DIR__ . '/../services/GameScoreService.php';
$schema = getenv('TEST_SCHEMA') ?: '';
if (!preg_match('/^interclasse_game_stage_[a-z0-9_]+$/', $schema)) exit(2);
db()->exec('SET search_path TO ' . $schema . ', public');
$id = (int) ($argv[1] ?? 0); $version = (int) ($argv[2] ?? -1);
$admin = db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
start_app_session(); $_SESSION['user_id'] = (int) $admin;
try {
    game_apply_score_action($id, ['action' => 'start', 'expected_version' => $version, 'request_id' => bin2hex(random_bytes(16))], current_user());
    echo "ok\n";
} catch (GameScoreException $error) { echo $error->httpStatus . "\n"; }
