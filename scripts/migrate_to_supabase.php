<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

const TABLE_ORDER = [
    'classes',
    'users',
    'user_modalities',
    'events',
    'event_modalities',
    'event_group_advancement',
    'teams',
    'players',
    'matches',
    'score_logs',
    'occurrences',
    'suggestions',
    'class_standings',
    'class_modality_points',
];

const LEGACY_COLUMNS = [
    'classes' => ['id', 'name', 'created_at'],
    'users' => ['id', 'name', 'email', 'password_hash', 'role', 'class_id', 'representative_gender', 'created_at'],
    'user_modalities' => ['id', 'user_id', 'modality', 'created_at'],
    'events' => ['id', 'name', 'description', 'created_by', 'start_date', 'end_date', 'place', 'status', 'group_count', 'best_thirds_count', 'teams_advancing_per_group', 'advancement_mode', 'roster_edit_until', 'archived', 'created_at'],
    'event_modalities' => ['id', 'event_id', 'modality', 'score_type', 'set_best_of', 'set_points', 'quarters_count', 'max_players_per_team', 'created_at'],
    'event_group_advancement' => ['id', 'event_id', 'modality', 'gender', 'group_name', 'teams_advancing', 'created_at', 'updated_at'],
    'teams' => ['id', 'event_id', 'modality', 'name', 'class_name', 'gender', 'group_name', 'representative_id', 'representative_name', 'created_at'],
    'players' => ['id', 'team_id', 'user_id', 'name', 'email', 'birth_date', 'jersey_number', 'created_at'],
    'matches' => ['id', 'event_id', 'modality', 'stage', 'group_name', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'score_data', 'gender', 'status', 'starts_at', 'place', 'winner_team_id', 'sort_order', 'created_at'],
    'score_logs' => ['id', 'match_id', 'user_id', 'action', 'description', 'old_home_score', 'old_away_score', 'new_home_score', 'new_away_score', 'old_status', 'new_status', 'old_score_data', 'new_score_data', 'created_at'],
    'occurrences' => ['id', 'event_id', 'match_id', 'team_id', 'player_id', 'target_type', 'title', 'description', 'action_text', 'ineligible_next_game', 'points_deducted', 'created_by', 'created_at'],
    'suggestions' => ['id', 'user_id', 'type', 'title', 'description', 'status', 'admin_note', 'created_at', 'updated_at'],
    'class_standings' => ['id', 'event_id', 'class_name', 'total_points', 'created_at', 'updated_at'],
    'class_modality_points' => ['id', 'class_standings_id', 'modality', 'gender', 'position', 'points', 'created_at'],
];

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, 'ERRO: ' . $message . PHP_EOL);
    exit($code);
}

function decodeMysqlString(string $value): string
{
    $decoded = '';
    $length = strlen($value);
    $escapes = [
        '0' => "\0",
        'n' => "\n",
        'r' => "\r",
        't' => "\t",
        'b' => "\x08",
        'Z' => "\x1a",
        "'" => "'",
        '"' => '"',
        '\\' => '\\',
    ];

    for ($i = 0; $i < $length; $i++) {
        if ($value[$i] === '\\' && $i + 1 < $length) {
            $i++;
            $decoded .= $escapes[$value[$i]] ?? $value[$i];
            continue;
        }
        $decoded .= $value[$i];
    }

    return str_replace("\0", '', $decoded);
}

function parseMysqlValue(string $token): mixed
{
    $token = trim($token);
    if (strcasecmp($token, 'NULL') === 0) {
        return null;
    }
    if (strlen($token) >= 2 && $token[0] === "'" && $token[strlen($token) - 1] === "'") {
        return decodeMysqlString(substr($token, 1, -1));
    }
    if (preg_match('/^-?\d+$/', $token)) {
        return (int) $token;
    }
    if (is_numeric($token)) {
        return (float) $token;
    }

    return $token;
}

/** @return list<list<mixed>> */
function parseMysqlTuples(string $payload): array
{
    $rows = [];
    $tuple = '';
    $depth = 0;
    $quoted = false;
    $escaped = false;
    $length = strlen($payload);

    for ($i = 0; $i < $length; $i++) {
        $char = $payload[$i];
        if ($quoted) {
            $tuple .= $char;
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === "'") {
                $quoted = false;
            }
            continue;
        }

        if ($char === "'") {
            $quoted = true;
            if ($depth > 0) {
                $tuple .= $char;
            }
            continue;
        }
        if ($char === '(') {
            $depth++;
            if ($depth > 1) {
                $tuple .= $char;
            }
            continue;
        }
        if ($char === ')') {
            $depth--;
            if ($depth === 0) {
                $tokens = [];
                $token = '';
                $tokenQuoted = false;
                $tokenEscaped = false;
                $tupleLength = strlen($tuple);
                for ($j = 0; $j < $tupleLength; $j++) {
                    $tupleChar = $tuple[$j];
                    if ($tokenQuoted) {
                        $token .= $tupleChar;
                        if ($tokenEscaped) {
                            $tokenEscaped = false;
                        } elseif ($tupleChar === '\\') {
                            $tokenEscaped = true;
                        } elseif ($tupleChar === "'") {
                            $tokenQuoted = false;
                        }
                    } elseif ($tupleChar === "'") {
                        $tokenQuoted = true;
                        $token .= $tupleChar;
                    } elseif ($tupleChar === ',') {
                        $tokens[] = parseMysqlValue($token);
                        $token = '';
                    } else {
                        $token .= $tupleChar;
                    }
                }
                $tokens[] = parseMysqlValue($token);
                $rows[] = $tokens;
                $tuple = '';
            } else {
                $tuple .= $char;
            }
            continue;
        }
        if ($depth > 0) {
            $tuple .= $char;
        }
    }

    if ($quoted || $depth !== 0) {
        fail('O dump possui uma instrução INSERT incompleta ou inválida.');
    }

    return $rows;
}

/** @return array<string, array{columns: list<string>, rows: list<list<mixed>>}> */
function readMysqlDump(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fail('Não foi possível ler o dump: ' . $path);
    }

    $data = [];
    $pattern = '/^INSERT INTO `([a-zA-Z0-9_]+)`(?:\s*\(([^\r\n]+)\))?\s+VALUES\s*(.*?);[ \t]*\r?$/ms';
    if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
        return $data;
    }

    foreach ($matches as $match) {
        $table = $match[1];
        if (!in_array($table, TABLE_ORDER, true)) {
            continue;
        }

        if (($match[2] ?? '') !== '') {
            preg_match_all('/`([a-zA-Z0-9_]+)`/', $match[2], $columnMatches);
            $columns = $columnMatches[1];
        } else {
            $columns = LEGACY_COLUMNS[$table] ?? [];
        }
        if ($columns === []) {
            fail("Não há mapa de colunas para a tabela {$table}.");
        }

        $rows = parseMysqlTuples($match[3]);
        foreach ($rows as $row) {
            if (count($row) !== count($columns)) {
                fail("Quantidade de valores incompatível na tabela {$table}.");
            }
        }

        if (!isset($data[$table])) {
            $data[$table] = ['columns' => $columns, 'rows' => []];
        } elseif ($data[$table]['columns'] !== $columns) {
            fail("O dump usa conjuntos de colunas diferentes para {$table}.");
        }
        array_push($data[$table]['rows'], ...$rows);
    }

    return $data;
}

/**
 * Converte colunas de versões antigas para o modelo atual.
 *
 * @param array<string, array{columns: list<string>, rows: list<list<mixed>>}> $data
 * @return array<string, array{columns: list<string>, rows: list<list<mixed>>}>
 */
function normalizeLegacyData(array $data): array
{
    if (!isset($data['users'])) {
        return $data;
    }

    $columns = $data['users']['columns'];
    $legacyIndex = array_search('representative_modality', $columns, true);
    if ($legacyIndex === false) {
        return $data;
    }

    $userIdIndex = array_search('id', $columns, true);
    if ($userIdIndex === false) {
        fail('A tabela users legada não possui a coluna id.');
    }

    $derivedModalities = [];
    foreach ($data['users']['rows'] as &$row) {
        $modality = $row[$legacyIndex] ?? null;
        if ($modality !== null && $modality !== '') {
            $derivedModalities[] = [$row[$userIdIndex], $modality];
        }
        array_splice($row, $legacyIndex, 1);
    }
    unset($row);
    array_splice($columns, $legacyIndex, 1);
    $data['users']['columns'] = $columns;

    if ($derivedModalities !== []) {
        if (!isset($data['user_modalities'])) {
            $data['user_modalities'] = [
                'columns' => ['user_id', 'modality'],
                'rows' => [],
            ];
        }
        if ($data['user_modalities']['columns'] !== ['user_id', 'modality']) {
            fail('Não foi possível combinar representative_modality com user_modalities.');
        }
        array_push($data['user_modalities']['rows'], ...$derivedModalities);
    }

    return $data;
}

function targetHasData(PDO $pdo): bool
{
    foreach (TABLE_ORDER as $table) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0) {
            return true;
        }
    }
    return false;
}

/** @param array<string, array{columns: list<string>, rows: list<list<mixed>>}> $data */
function validateTargetColumns(PDO $pdo, array $data): void
{
    $statement = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = ?"
    );

    foreach ($data as $table => $tableData) {
        $statement->execute([$table]);
        $targetColumns = $statement->fetchAll(PDO::FETCH_COLUMN);
        $unknownColumns = array_diff($tableData['columns'], $targetColumns);
        if ($unknownColumns !== []) {
            fail(
                "Coluna(s) do dump sem correspondência em {$table}: "
                . implode(', ', $unknownColumns)
            );
        }
    }
}

function resetIdentity(PDO $pdo, string $table): void
{
    $pdo->exec(
        "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), "
        . "COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM {$table}"
    );
}

$options = getopt('', ['dump:', 'schema-only', 'dry-run', 'force', 'help']);
if (isset($options['help'])) {
    out('Uso: php scripts/migrate_to_supabase.php [--dump=arquivo.sql] [--schema-only] [--dry-run] [--force]');
    out('O destino é lido de DATABASE_URL ou das variáveis DB_* do projeto.');
    exit(0);
}

$dumpPath = $options['dump'] ?? (__DIR__ . '/../database/InscricaoInterclassed.sql');
$schemaOnly = isset($options['schema-only']);
$force = isset($options['force']);
$data = $schemaOnly ? [] : normalizeLegacyData(readMysqlDump($dumpPath));
$sourceTotal = array_sum(array_map(static fn (array $item): int => count($item['rows']), $data));

if (isset($options['dry-run'])) {
    foreach (TABLE_ORDER as $table) {
        out(sprintf('%-28s %d registro(s)', $table, count($data[$table]['rows'] ?? [])));
    }
    out("Dump validado: {$sourceTotal} registro(s) prontos para migração.");
    exit(0);
}

try {
    $pdo = db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
        fail('A conexão de destino precisa usar o driver pgsql.');
    }
    $pdo->query('SELECT 1');
    out('Conexão TLS com o Supabase: OK');

    $schema = file_get_contents(__DIR__ . '/../database/supabase_schema.sql');
    if ($schema === false) {
        fail('Arquivo database/supabase_schema.sql não encontrado.');
    }
    $pdo->exec($schema);
    out('Esquema PostgreSQL: OK');

    if ($schemaOnly) {
        out('Migração concluída em modo --schema-only.');
        exit(0);
    }
    if ($sourceTotal === 0) {
        fail('O dump não contém registros INSERT para migrar.');
    }

    validateTargetColumns($pdo, $data);
    out('Compatibilidade das colunas do dump: OK');

    $targetHasData = targetHasData($pdo);
    if ($targetHasData && !$force) {
        fail('O destino já contém dados. Revise-o ou execute novamente com --force para substituí-los.');
    }

    $pdo->beginTransaction();
    if ($targetHasData && $force) {
        $reverseOrder = array_reverse(TABLE_ORDER);
        $pdo->exec('TRUNCATE TABLE ' . implode(', ', $reverseOrder) . ' RESTART IDENTITY CASCADE');
        out('Dados anteriores do destino removidos por --force (transação ativa).');
    }
    $migrated = [];
    foreach (TABLE_ORDER as $table) {
        $tableData = $data[$table] ?? null;
        if ($tableData === null || $tableData['rows'] === []) {
            $migrated[$table] = 0;
            continue;
        }

        $columns = $tableData['columns'];
        foreach ($columns as $column) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $column)) {
                fail("Nome de coluna inválido no dump: {$column}");
            }
        }
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $pdo->prepare(
            "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})"
        );
        foreach ($tableData['rows'] as $row) {
            $insert->execute($row);
        }
        resetIdentity($pdo, $table);
        $migrated[$table] = count($tableData['rows']);
        out(sprintf('%-28s %d registro(s)', $table, $migrated[$table]));
    }
    $pdo->commit();

    $targetTotal = 0;
    foreach ($migrated as $table => $expected) {
        $actual = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        if ($actual !== $expected) {
            fail("Validação falhou em {$table}: esperado {$expected}, encontrado {$actual}.");
        }
        $targetTotal += $actual;
    }
    out("Migração validada: {$targetTotal} registro(s) no Supabase.");
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail($exception->getMessage());
}
