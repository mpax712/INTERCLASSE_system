<?php

// Copies the active PostgreSQL tables into an isolated schema for integration tests.
require_once __DIR__ . '/../includes/db.php';

$schema = getenv('TEST_SCHEMA') ?: '';
if (!preg_match('/^interclasse_game_stage_[a-z0-9_]+$/', $schema)) {
    fwrite(STDERR, "Set TEST_SCHEMA to an interclasse_game_stage_* name.\n");
    exit(2);
}
$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $pdo->query('SELECT current_schema()')->fetchColumn() !== 'public') {
    fwrite(STDERR, "Run this script against the public PostgreSQL schema.\n");
    exit(2);
}
$exists = $pdo->prepare('SELECT 1 FROM pg_namespace WHERE nspname = ?');
$exists->execute([$schema]);
if ($exists->fetchColumn()) {
    fwrite(STDERR, "The test schema already exists; refusing to replace it.\n");
    exit(2);
}
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
if (count($tables) < 10) throw new RuntimeException('Expected at least ten application tables.');
$pdo->beginTransaction();
try {
    $pdo->exec('CREATE SCHEMA ' . $schema);
    foreach ($tables as $table) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) throw new RuntimeException('Unsafe table name.');
        $target = $schema . '.' . $table;
        $source = 'public.' . $table;
        $pdo->exec("CREATE TABLE $target (LIKE $source INCLUDING ALL)");
        $pdo->exec("INSERT INTO $target SELECT * FROM $source");
        $sequence = $pdo->query("SELECT pg_get_serial_sequence('" . $target . "', 'id')")->fetchColumn();
        if ($sequence) {
            $maxId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM $target")->fetchColumn();
            $set = $pdo->prepare('SELECT setval(?::regclass, ?, ' . ($maxId > 0 ? 'TRUE' : 'FALSE') . ')');
            $set->execute([$sequence, max(1, $maxId)]);
        }
    }
    $pdo->commit();
    echo "Created $schema with " . count($tables) . " tables.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
