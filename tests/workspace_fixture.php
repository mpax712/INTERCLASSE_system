<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/match_helpers.php';
$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
    || !preg_match('/^interclasse_async_test_[a-z0-9_]+$/', (string) $pdo->query('SELECT DATABASE()')->fetchColumn())) {
    throw new RuntimeException('Use somente uma cópia isolada chamada interclasse_async_test_*.');
}

$adminId = (int) $pdo->query('SELECT id FROM users WHERE role = "admin" LIMIT 1')->fetchColumn();
$classes = $pdo->query('SELECT id, name FROM classes ORDER BY id LIMIT 4')->fetchAll();
if (!$adminId || count($classes) < 4) {
    throw new RuntimeException('A cópia de teste precisa de um administrador e quatro turmas.');
}

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO events (name, created_by, start_date, status, place)
        VALUES (?, ?, CURRENT_DATE, "inscricoes", "Teste AJAX")');
    $insert->execute(['Teste de navegação assíncrona', $adminId]);
    $eventId = (int) $pdo->lastInsertId();
    $modality = $pdo->prepare('INSERT INTO event_modalities
        (event_id, modality, score_type, max_players_per_team, bracket_type) VALUES (?, ?, ?, ?, ?)');
    foreach ([['handbol', 'gols', 11, 'groups'], ['fifa', 'gols', 2, 'groups'], ['clash_royale', 'batalhas', 2, 'simple']] as $definition) {
        $modality->execute(array_merge([$eventId], $definition));
    }
    $team = $pdo->prepare('INSERT INTO teams (event_id, modality, name, class_name, gender, group_name) VALUES (?, ?, ?, ?, ?, ?)');
    $player = $pdo->prepare('INSERT INTO players (team_id, name) VALUES (?, ?)');
    foreach (['masculino', 'feminino'] as $gender) {
        foreach (array_slice($classes, 0, 2) as $class) {
            $team->execute([$eventId, 'handbol', $class['name'] . ' ' . $gender, $class['name'], $gender, 'A']);
            $player->execute([(int) $pdo->lastInsertId(), 'Jogador ' . $class['name'] . ' ' . $gender]);
        }
    }
    foreach (['fifa', 'clash_royale'] as $game) {
        foreach ($classes as $index => $class) {
            $team->execute([$eventId, $game, $class['name'], $class['name'], 'masculino', '']);
            $player->execute([(int) $pdo->lastInsertId(), 'Participante ' . ($index + 1)]);
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
public_cache_forget_all();
start_app_session();
$_SESSION['user_id'] = $adminId;
$_SESSION['last_activity_at'] = time();
$sessionId = session_id();
session_write_close();
echo json_encode(['eventId' => $eventId, 'sessionId' => $sessionId], JSON_THROW_ON_ERROR) . PHP_EOL;
