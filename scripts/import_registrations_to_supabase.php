<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

function importFail(string $message): never
{
    fwrite(STDERR, 'ERRO: ' . $message . PHP_EOL);
    exit(1);
}

function translateRegistrationSql(string $mysql): array
{
    if (!str_contains($mysql, 'Importacao das inscricoes do formulario de Interclasse')) {
        importFail('O arquivo não é um SQL de inscrições reconhecido.');
    }

    if (preg_match('/^\s*(DROP|TRUNCATE|ALTER|CREATE|GRANT|REVOKE)\b/im', $mysql, $match)) {
        importFail('Comando não permitido no arquivo: ' . strtoupper($match[1]));
    }

    preg_match_all('/^\s*(?:INSERT INTO|UPDATE|DELETE FROM)\s+`?([a-z_]+)`?/im', $mysql, $writeMatches);
    $allowedTables = ['classes', 'events', 'event_modalities', 'teams', 'players', 'matches'];
    foreach (array_unique($writeMatches[1]) as $table) {
        if (!in_array(strtolower($table), $allowedTables, true)) {
            importFail('Escrita não permitida na tabela: ' . $table);
        }
    }

    if (!preg_match(
        "/INSERT INTO `events`[\s\S]*?SELECT\s+'((?:[^']|'')+)'\s*,/",
        $mysql,
        $eventMatch
    )) {
        importFail('Não foi possível identificar o nome do evento.');
    }
    $eventName = str_replace("''", "'", $eventMatch[1]);

    $sql = preg_replace('/^SET NAMES .*?;\s*$/mi', '', $mysql);
    $sql = preg_replace('/^SET FOREIGN_KEY_CHECKS\s*=.*?;\s*$/mi', '', $sql);
    $sql = preg_replace('/^USE\s+`[^`]+`;\s*$/mi', '', $sql);
    $sql = preg_replace('/^START TRANSACTION;\s*$/mi', '', $sql);
    $sql = preg_replace('/^COMMIT;\s*$/mi', '', $sql);
    $sql = str_replace('`', '', $sql);

    $sql = str_replace(
        'ON DUPLICATE KEY UPDATE name = VALUES(name)',
        'ON CONFLICT (name) DO NOTHING',
        $sql
    );
    $sql = str_replace(
        'ON DUPLICATE KEY UPDATE score_type = VALUES(score_type), set_best_of = VALUES(set_best_of), set_points = VALUES(set_points), quarters_count = VALUES(quarters_count), max_players_per_team = VALUES(max_players_per_team)',
        'ON CONFLICT (event_id, modality) DO UPDATE SET score_type = EXCLUDED.score_type, set_best_of = EXCLUDED.set_best_of, set_points = EXCLUDED.set_points, quarters_count = EXCLUDED.quarters_count, max_players_per_team = EXCLUDED.max_players_per_team',
        $sql
    );

    $sql = str_replace(
        "JSON_OBJECT('winner', CAST(NULL AS CHAR), 'bracket_home_player_id', @home_player_id, 'bracket_away_player_id', @away_player_id)",
        "jsonb_build_object('winner', NULL, 'bracket_home_player_id', @home_player_id, 'bracket_away_player_id', @away_player_id)",
        $sql
    );
    $sql = str_replace(
        "JSON_OBJECT('player_attempts', JSON_OBJECT(), 'player_averages', JSON_OBJECT(), 'bracket_home_player_id', @home_player_id, 'bracket_away_player_id', @away_player_id)",
        "jsonb_build_object('player_attempts', '{}'::jsonb, 'player_averages', '{}'::jsonb, 'bracket_home_player_id', @home_player_id, 'bracket_away_player_id', @away_player_id)",
        $sql
    );

    $variableMap = [
        'evento_interclasse_id' => 'event_id',
        'time_id' => 'team_id',
        'home_team_id' => 'home_team_id',
        'away_team_id' => 'away_team_id',
        'home_player_id' => 'home_player_id',
        'away_player_id' => 'away_player_id',
        'winner_team_id' => 'winner_team_id',
    ];

    $sql = preg_replace_callback(
        '/SET\s+@([a-z_]+)\s*:=\s*(.*?);/is',
        static function (array $match) use ($variableMap): string {
            $variable = strtolower($match[1]);
            if (!isset($variableMap[$variable])) {
                importFail('Variável SQL não suportada: @' . $variable);
            }
            $column = $variableMap[$variable];
            $expression = trim($match[2]);
            if ($column === 'event_id') {
                // O arquivo recebido possui dois nomes de evento diferentes.
                // Se a segunda busca não achar nada, mantém o evento criado.
                $expression = 'COALESCE(' . $expression . ', event_id)';
            }
            return "UPDATE _registration_import_context SET {$column} = {$expression};";
        },
        $sql
    );

    $sql = preg_replace_callback(
        '/@([a-z_]+)/i',
        static function (array $match) use ($variableMap): string {
            $variable = strtolower($match[1]);
            if (!isset($variableMap[$variable])) {
                importFail('Referência a variável SQL não suportada: @' . $variable);
            }
            return '(SELECT ' . $variableMap[$variable] . ' FROM _registration_import_context)';
        },
        $sql
    );

    if (preg_match('/\b(ON DUPLICATE KEY|JSON_OBJECT)\b/i', $sql, $unsupported)) {
        importFail('Construção MySQL não convertida: ' . $unsupported[1]);
    }

    $contextSql = <<<'SQL'
CREATE TEMP TABLE _registration_import_context (
    event_id integer,
    team_id integer,
    home_team_id integer,
    away_team_id integer,
    home_player_id integer,
    away_player_id integer,
    winner_team_id integer
) ON COMMIT DROP;
INSERT INTO _registration_import_context DEFAULT VALUES;
SQL;

    return [$contextSql . PHP_EOL . $sql, $eventName];
}

$options = getopt('', ['file:', 'help']);
if (isset($options['help']) || !isset($options['file'])) {
    echo 'Uso: php scripts/import_registrations_to_supabase.php --file=inscricoes.sql' . PHP_EOL;
    exit(isset($options['help']) ? 0 : 1);
}

$path = (string) $options['file'];
$mysql = file_get_contents($path);
if ($mysql === false) {
    importFail('Não foi possível ler o arquivo: ' . $path);
}

[$sql, $eventName] = translateRegistrationSql($mysql);
$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
    importFail('A conexão ativa não é PostgreSQL.');
}

$eventCheck = $pdo->prepare('SELECT id FROM events WHERE name = ? ORDER BY id LIMIT 1');
$eventCheck->execute([$eventName]);
if ($eventCheck->fetchColumn() !== false) {
    importFail('O evento já existe. A importação foi cancelada para não substituir equipes, jogadores ou partidas.');
}

try {
    $pdo->beginTransaction();
    $pdo->exec($sql);

    $eventCheck->execute([$eventName]);
    $eventId = (int) $eventCheck->fetchColumn();
    if ($eventId <= 0) {
        throw new RuntimeException('O evento não foi criado.');
    }

    $counts = [];
    foreach (['event_modalities', 'teams', 'matches'] as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $counts[$table] = (int) $stmt->fetchColumn();
    }
    $playersStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM players p JOIN teams t ON t.id = p.team_id WHERE t.event_id = ?'
    );
    $playersStmt->execute([$eventId]);
    $counts['players'] = (int) $playersStmt->fetchColumn();

    $nullTeamsStmt = $pdo->prepare(
        'SELECT id, modality, stage, sort_order, home_team_id, away_team_id, status, winner_team_id
         FROM matches
         WHERE event_id = ? AND (home_team_id IS NULL OR away_team_id IS NULL)
         ORDER BY sort_order, id'
    );
    $nullTeamsStmt->execute([$eventId]);
    $matchesWithoutTeams = $nullTeamsStmt->fetchAll();

    $expected = [
        'event_modalities' => 5,
        'teams' => 59,
        'players' => 326,
        'matches' => 61,
    ];
    foreach ($expected as $entity => $expectedCount) {
        if (($counts[$entity] ?? -1) !== $expectedCount) {
            throw new RuntimeException(
                "Validação falhou para {$entity}: esperado {$expectedCount}, encontrado "
                . ($counts[$entity] ?? 0) . '.'
            );
        }
    }
    $invalidMatchesWithoutTeams = array_values(array_filter(
        $matchesWithoutTeams,
        static function (array $match): bool {
            $hasExactlyOneTeam = ($match['home_team_id'] === null) !== ($match['away_team_id'] === null);
            $isCompletedBye = $match['status'] === 'encerrada' && $match['winner_team_id'] !== null;
            return !$hasExactlyOneTeam || !$isCompletedBye;
        }
    ));
    if ($invalidMatchesWithoutTeams !== []) {
        throw new RuntimeException(
            'Existem partidas inválidas sem equipes vinculadas: '
            . json_encode($invalidMatchesWithoutTeams, JSON_UNESCAPED_UNICODE)
        );
    }

    $pdo->commit();

    echo "Importação concluída no evento #{$eventId}: {$eventName}" . PHP_EOL;
    foreach ($counts as $entity => $count) {
        echo "{$entity}={$count}" . PHP_EOL;
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    importFail($exception->getMessage());
}
