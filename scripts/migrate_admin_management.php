<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';

function migrate_admin_management(PDO $pdo): void
{
    $previous = $pdo instanceof PostgresCompatiblePDO ? $pdo->includeArchived : false;
    if ($pdo instanceof PostgresCompatiblePDO) $pdo->includeArchived = true;
    try {
    $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $json = $pg ? 'JSONB' : 'JSON';
    $stamp = $pg ? 'TIMESTAMP' : 'DATETIME(6)';
    $exists = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = '
        . ($pg ? 'current_schema()' : 'DATABASE()') . ' AND table_name=? AND column_name=?');
    $columns = [];
    foreach (['events','classes','teams','players','matches'] as $table) {
        $columns[$table] = ['archived_at'=>"$stamp NULL", 'archived_by'=>'INT NULL'];
    }
    $columns['matches'] += ['review_state'=>"VARCHAR(20) NOT NULL DEFAULT 'ready'",
        'qualification_sources'=>"$json NULL", 'participant_snapshot'=>"$json NULL"];
    $columns['event_modalities'] = ['structure_revision'=>'INT NOT NULL DEFAULT 0'];
    foreach ($columns as $table=>$definitions) foreach ($definitions as $column=>$type) {
        $exists->execute([$table,$column]);
        if (!$exists->fetchColumn()) $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_operation_previews (
        id VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, created_at $stamp NOT NULL,
        expires_at $stamp NOT NULL, plan_json $json NOT NULL)");
    // Deliberately independent of entity FKs: purging a match must not erase its audit.
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_operations (
        id VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, created_at $stamp NOT NULL,
        operation VARCHAR(40) NOT NULL, reason VARCHAR(500) NOT NULL,
        changes_json $json NOT NULL, impact_json $json NOT NULL)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_sessions (id VARCHAR(64) PRIMARY KEY,payload TEXT NOT NULL,expires_at BIGINT NOT NULL)');
    $pdo->exec('CREATE OR REPLACE VIEW active_classes AS SELECT * FROM classes WHERE archived_at IS NULL');
    $pdo->exec('CREATE OR REPLACE VIEW active_teams AS SELECT t.* FROM teams t
        LEFT JOIN classes c ON c.name=t.class_name WHERE t.archived_at IS NULL AND (c.id IS NULL OR c.archived_at IS NULL)');
    $pdo->exec('CREATE OR REPLACE VIEW active_players AS SELECT p.* FROM players p
        JOIN active_teams t ON t.id=p.team_id WHERE p.archived_at IS NULL');
    $pdo->exec("CREATE OR REPLACE VIEW active_matches AS SELECT * FROM matches WHERE archived_at IS NULL AND review_state='ready'");
    if($pg){
        foreach(['admin_operations','admin_operation_previews','app_sessions'] as $table)$pdo->exec("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
        foreach($pdo->query("SELECT rolname FROM pg_roles WHERE rolname IN ('anon','authenticated')")->fetchAll(PDO::FETCH_COLUMN) as $role)$pdo->exec("REVOKE ALL ON admin_operations,admin_operation_previews,app_sessions,active_matches,active_players,active_teams,active_classes FROM $role");
    }
    // Add immutable historical labels, without changing any pre-existing field.
    $teams = $pdo->query('SELECT id,name,class_name FROM teams')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    $players = $pdo->query('SELECT id,name,team_id FROM players')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    $update = $pdo->prepare('UPDATE matches SET participant_snapshot=? WHERE id=?');
    foreach ($pdo->query('SELECT * FROM matches WHERE participant_snapshot IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $match) {
        $snapshot = [];
        $score=json_decode($match['score_data']??'{}',true)??[];
        foreach (['home','away'] as $side) {
            $team = $teams[$match[$side.'_team_id']] ?? [];
            $playerId=$match[$side.'_player_id']??$score['bracket_'.$side.'_player_id']??null;
            $player = $players[$playerId] ?? [];
            $snapshot[$side] = ['player_id'=>$playerId, 'team_id'=>$match[$side.'_team_id'],
                'name'=>$player['name'] ?? $team['name'] ?? null, 'class_name'=>$team['class_name'] ?? null];
        }
        $update->execute([json_encode($snapshot, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$match['id']]);
    }
    if ($pdo instanceof PostgresCompatiblePDO) $pdo->activeViewsEnabled = true;
    } finally { if ($pdo instanceof PostgresCompatiblePDO) $pdo->includeArchived = $previous; }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    migrate_admin_management(db());
    echo "Migração administrativa concluída. Resultados e inscrições preservados.\n";
}
