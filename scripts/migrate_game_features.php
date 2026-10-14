<?php

// Idempotent additive migration. Run against a copy before the active database.
require_once __DIR__ . '/../includes/db.php';

$pdo = db();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'mysql') {
    require_once __DIR__ . '/../includes/match_helpers.php';
    ensure_interclasse_schema_updates(true);
    ensure_score_logs_schema(true);
}
$columnExists = static function (string $table, string $column) use ($pdo, $driver): bool {
    $sql = $driver === 'pgsql'
        ? 'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
        : 'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
};

$columns = [
    'event_modalities' => [
        'rules_json' => $driver === 'pgsql' ? 'JSONB NULL' : 'JSON NULL',
    ],
    'players' => ['group_name' => 'VARCHAR(10) NULL'],
    'matches' => [
        'home_player_id' => 'INT NULL',
        'away_player_id' => 'INT NULL',
        'winner_player_id' => 'INT NULL',
        'home_source_match_id' => 'INT NULL',
        'away_source_match_id' => 'INT NULL',
        'rules_snapshot' => $driver === 'pgsql' ? 'JSONB NULL' : 'JSON NULL',
        'version' => 'INT NOT NULL DEFAULT 0',
        'cancel_reason' => 'VARCHAR(240) NULL',
        'cancelled_by' => 'INT NULL',
    ],
    'score_logs' => ['request_id' => 'VARCHAR(64) NULL'],
];

foreach ($columns as $table => $definitions) {
    foreach ($definitions as $column => $definition) {
        if (!$columnExists($table, $column)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            echo "$table.$column added\n";
        }
    }
}

if ($driver === 'mysql') {
    $expandEnum = static function (string $table, string $column, array $additional) use ($pdo): void {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        $type = (string) ($info['Type'] ?? '');
        if (!str_starts_with($type, 'enum(')) return;
        $current = str_getcsv(substr($type, 5, -1), ',', "'", '\\');
        $values = array_values(array_unique(array_merge($current, $additional)));
        if (count($values) === count($current)) return;
        $enum = implode(',', array_map($pdo->quote(...), $values));
        $null = ($info['Null'] ?? 'NO') === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $info['Default'] !== null ? ' DEFAULT ' . $pdo->quote((string) $info['Default']) : '';
        $pdo->exec("ALTER TABLE `$table` MODIFY `$column` ENUM($enum) $null$default");
    };
    foreach (['event_modalities', 'teams', 'matches', 'class_modality_points'] as $table) {
        $expandEnum($table, 'modality', ['fifa', 'clash_royale']);
    }
    $expandEnum('event_modalities', 'score_type', ['batalhas']);
    $expandEnum('matches', 'stage', ['grupo', 'repescagem', 'preliminar', 'dezesseis_avos', 'oitavas', 'quartas', 'semifinal', 'final', 'individual']);
    $expandEnum('matches', 'status', ['aguardando_resultado', 'cancelada']);
}

if ($driver === 'pgsql') {
    $foreignKeys = [
        'matches_home_player_id_fkey' => 'FOREIGN KEY (home_player_id) REFERENCES players(id) ON DELETE SET NULL',
        'matches_away_player_id_fkey' => 'FOREIGN KEY (away_player_id) REFERENCES players(id) ON DELETE SET NULL',
        'matches_winner_player_id_fkey' => 'FOREIGN KEY (winner_player_id) REFERENCES players(id) ON DELETE SET NULL',
        'matches_home_source_match_id_fkey' => 'FOREIGN KEY (home_source_match_id) REFERENCES matches(id) ON DELETE SET NULL',
        'matches_away_source_match_id_fkey' => 'FOREIGN KEY (away_source_match_id) REFERENCES matches(id) ON DELETE SET NULL',
        'matches_cancelled_by_fkey' => 'FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL',
    ];
    $constraintExists = $pdo->prepare("SELECT 1 FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = current_schema() AND t.relname = 'matches' AND c.conname = ?");
    foreach ($foreignKeys as $name => $definition) {
        $constraintExists->execute([$name]);
        if (!$constraintExists->fetchColumn()) $pdo->exec("ALTER TABLE matches ADD CONSTRAINT $name $definition");
    }
}

$indexes = $driver === 'pgsql' ? [
    'CREATE INDEX IF NOT EXISTS idx_matches_player_home ON matches (home_player_id)',
    'CREATE INDEX IF NOT EXISTS idx_matches_player_away ON matches (away_player_id)',
    'CREATE INDEX IF NOT EXISTS idx_matches_source_home ON matches (home_source_match_id)',
    'CREATE INDEX IF NOT EXISTS idx_matches_source_away ON matches (away_source_match_id)',
    'CREATE INDEX IF NOT EXISTS idx_matches_event_filters ON matches (event_id, modality, gender, stage, status, starts_at, id)',
    'CREATE UNIQUE INDEX IF NOT EXISTS uniq_score_logs_request ON score_logs (match_id, request_id)',
] : [
    'idx_matches_player_home' => 'CREATE INDEX idx_matches_player_home ON matches (home_player_id)',
    'idx_matches_player_away' => 'CREATE INDEX idx_matches_player_away ON matches (away_player_id)',
    'idx_matches_source_home' => 'CREATE INDEX idx_matches_source_home ON matches (home_source_match_id)',
    'idx_matches_source_away' => 'CREATE INDEX idx_matches_source_away ON matches (away_source_match_id)',
    'idx_matches_event_filters' => 'CREATE INDEX idx_matches_event_filters ON matches (event_id, modality, gender, stage, status, starts_at, id)',
    'uniq_score_logs_request' => 'CREATE UNIQUE INDEX uniq_score_logs_request ON score_logs (match_id, request_id)',
];

foreach ($indexes as $name => $sql) {
    if ($driver === 'mysql') {
        $check = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $check->execute([str_contains($name, 'score_logs') ? 'score_logs' : 'matches', $name]);
        if ($check->fetchColumn()) {
            continue;
        }
    }
    $pdo->exec($sql);
}

echo "Game feature migration complete ($driver).\n";
