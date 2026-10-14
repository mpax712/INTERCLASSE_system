<?php

require_once __DIR__ . '/modality_rules.php';

require_once __DIR__ . '/auth.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    // Keep the selected workspace when a POST handler returns to the same screen.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && in_array($_GET['page'] ?? '', ['event', 'match_manager'], true)) {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        if (($query['page'] ?? '') === $_GET['page']) {
            foreach (['modality', 'gender', 'match_q', 'match_stage', 'match_status', 'match_date', 'match_page', 'manager_q', 'manager_status', 'manager_page'] as $filter) {
                if (!isset($query[$filter]) && isset($_POST[$filter])) {
                    $query[$filter] = (string) $_POST[$filter];
                } elseif (!isset($query[$filter]) && isset($_GET[$filter])) {
                    $query[$filter] = (string) $_GET[$filter];
                }
            }
            $url = ($parts['path'] ?? 'index.php') . '?' . http_build_query($query);
        }
    }
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    start_app_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    start_app_session();
    $sentToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
        http_response_code(419);
        exit('Token CSRF inválido.');
    }
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    start_app_session();

    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $flash;
}

function validate_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function allowed_modalities(): array
{
    return array_keys(modality_definitions());
}

function modality_label(string $modality): string
{
    return modality_definition($modality)['label'] ?? ucfirst($modality);
}

function stage_label(string $stage): string
{
    return [
        'grupo' => 'Fase de grupos',
        'repescagem' => 'Repescagem',
        'preliminar' => 'Preliminar',
        'dezesseis_avos' => '16 avos de final',
        'oitavas' => 'Oitavas de final',
        'quartas' => 'Quartas de final',
        'semifinal' => 'Semifinal',
        'final' => 'Final',
    ][$stage] ?? ucfirst($stage);
}

function event_status_label(string $status): string
{
    return [
        'rascunho' => 'Evento não iniciado',
        'inscricoes' => 'Inscrições abertas',
        'em_andamento' => 'Em andamento',
        'encerrado' => 'Encerrado',
    ][$status] ?? ucfirst($status);
}

function match_status_label(string $status): string
{
    return [
        'agendada' => 'Agendada',
        'em_andamento' => 'Em andamento',
        'encerrada' => 'Encerrada',
        'wo' => 'W.O.',
        'aguardando_resultado' => 'Aguardando resultado',
        'cancelada' => 'Cancelada',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function status_pill_class(string $status): string
{
    return 'status-pill status-' . preg_replace('/[^a-z0-9_-]/i', '-', $status);
}

function role_label(string $role): string
{
    return [
        'admin' => 'Administrador',
        'professor' => 'Mesário',
        'representante' => 'Representante',
    ][$role] ?? ucfirst($role);
}

function normalize_modalities(array $modalities): array
{
    $allowed = allowed_modalities();
    $clean = [];

    foreach ($modalities as $modality) {
        $modality = (string) $modality;
        if (in_array($modality, $allowed, true)) {
            $clean[$modality] = true;
        }
    }

    return array_keys($clean);
}

function save_user_modalities(int $userId, array $modalities): void
{
    ensure_interclasse_schema_updates();

    $delete = db()->prepare('DELETE FROM user_modalities WHERE user_id = ?');
    $delete->execute([$userId]);

    $modalities = normalize_modalities($modalities);
    if (!$modalities) {
        return;
    }

    $insert = db()->prepare('INSERT INTO user_modalities (user_id, modality) VALUES (?, ?)');
    foreach ($modalities as $modality) {
        $insert->execute([$userId, $modality]);
    }
}

function user_modality_labels(array $modalities): string
{
    $modalities = normalize_modalities($modalities);
    if (!$modalities) {
        return '';
    }

    return implode(', ', array_map('modality_label', $modalities));
}

function score_type_for_modality(string $modality): string
{
    return modality_definition($modality)['score_type'] ?? 'pontos';
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return 'A definir';
    }

    return date('d/m/Y H:i', strtotime($value));
}

function is_individual_modality(string $modality): bool
{
    return (modality_definition($modality)['participant'] ?? 'team') === 'player';
}

function is_mixed_modality(string $modality): bool
{
    return (bool) (modality_definition($modality)['mixed'] ?? false);
}

function uses_grouping(string $modality): bool
{
    return (modality_definition($modality)['default_format'] ?? 'groups') === 'groups';
}

function allowed_bracket_types(): array
{
    return ['simple', 'groups', 'round_robin'];
}

function bracket_type_label(string $type): string
{
    return [
        'simple' => 'Chaveamento simples',
        'groups' => 'Fase de grupos',
        'round_robin' => 'Todos contra todos',
    ][$type] ?? 'Fase de grupos';
}

function default_bracket_type_for_modality(string $modality): string
{
    return modality_definition($modality)['default_format'] ?? 'groups';
}

function auto_schema_updates_enabled(): bool
{
    return (bool) (config()['app']['auto_schema_updates'] ?? false);
}

function public_cache_dir(): string
{
    return __DIR__ . '/../storage/cache/public';
}

function public_cache_slug(string $key): string
{
    $key = trim($key);
    $key = preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $key) ?: 'cache';
    return str_replace(':', '_', $key);
}

function public_cache_file(string $key): string
{
    return public_cache_dir() . '/' . public_cache_slug($key) . '.json';
}

function public_cache_read(string $key): ?array
{
    $file = public_cache_file($key);
    if (!is_file($file)) {
        return null;
    }

    $payload = json_decode((string) @file_get_contents($file), true);
    if (!is_array($payload) || (int) ($payload['expires_at'] ?? 0) < time()) {
        return null;
    }

    return is_array($payload['data'] ?? null) ? $payload['data'] : null;
}

function public_cache_write(string $key, array $data, int $ttl): void
{
    $dir = public_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    @file_put_contents(
        public_cache_file($key),
        json_encode(['expires_at' => time() + max(1, $ttl), 'data' => $data], JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function public_cache_forget(string $key): void
{
    $file = public_cache_file($key);
    if (is_file($file)) {
        @unlink($file);
    }
}

function public_cache_forget_prefix(string $prefix): void
{
    $dir = public_cache_dir();
    if (!is_dir($dir)) {
        return;
    }

    $pattern = $dir . '/' . public_cache_slug($prefix) . '*.json';
    foreach (glob($pattern) ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function public_cache_forget_all(): void
{
    $dir = public_cache_dir();
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function public_page_cache_key(string $page, array $params = []): string
{
    ksort($params);
    return $page . ':' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));
}

function public_page_cache_serve(string $key): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || current_user() !== null) {
        return false;
    }

    $cached = public_cache_read($key);
    if (!is_array($cached) || !isset($cached['html'])) {
        return false;
    }

    echo (string) $cached['html'];
    return true;
}

function public_page_cache_start(string $key): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || current_user() !== null) {
        return false;
    }

    ob_start();
    return true;
}

function public_page_cache_finish(string $key, int $ttl, bool $enabled): void
{
    if (!$enabled || ob_get_level() < 1) {
        return;
    }

    $html = (string) ob_get_clean();
    public_cache_write($key, ['html' => $html], $ttl);
    echo $html;
}

function public_page_cache_abort(bool $enabled): void
{
    if ($enabled && ob_get_level() > 0) {
        ob_end_flush();
    }
}

function bracket_type_for_event_modality(int $eventId, string $modality): string
{
    static $cache = [];
    $cacheKey = $eventId . ':' . $modality;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    ensure_interclasse_schema_updates();

    $stmt = db()->prepare('SELECT bracket_type FROM event_modalities WHERE event_id = ? AND modality = ? LIMIT 1');
    $stmt->execute([$eventId, $modality]);
    $type = (string) ($stmt->fetchColumn() ?: '');

    $cache[$cacheKey] = in_array($type, allowed_bracket_types(), true) ? $type : default_bracket_type_for_modality($modality);
    return $cache[$cacheKey];
}

function gender_filter_sql(string $modality): string
{
    return is_mixed_modality($modality) ? '' : ' AND gender = ?';
}

function gender_filter_params(string $modality, string $gender): array
{
    return is_mixed_modality($modality) ? [] : [$gender];
}

function get_max_players_for_modality(string $modality): int
{
    return modality_definition($modality)['player_limit'] ?? 999;
}

function default_player_limit_for_modality(string $modality): int
{
    return modality_definition($modality)['player_limit'] ?? 999;
}

function player_limit_for_event_modality(int $eventId, string $modality): int
{
    static $cache = [];
    $cacheKey = $eventId . ':' . $modality;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    ensure_interclasse_schema_updates();

    $stmt = db()->prepare('SELECT max_players_per_team FROM event_modalities WHERE event_id = ? AND modality = ? LIMIT 1');
    $stmt->execute([$eventId, $modality]);
    $limit = $stmt->fetchColumn();

    if ($limit !== false && $limit !== null && (int) $limit > 0) {
        $cache[$cacheKey] = (int) $limit;
        return $cache[$cacheKey];
    }

    $cache[$cacheKey] = default_player_limit_for_modality($modality);
    return $cache[$cacheKey];
}

function player_limit_for_team(array $team): int
{
    return player_limit_for_event_modality((int) $team['event_id'], (string) $team['modality']);
}

function over_limit_teams(int $eventId, string $modality, ?string $gender = 'masculino'): array
{
    $limit = player_limit_for_event_modality($eventId, $modality);

    if ($limit <= 0 || $limit >= 999) {
        return [];
    }

    $sql = 'SELECT t.id, t.name, t.class_name, t.group_name, COUNT(p.id) AS player_count
            FROM teams t
            LEFT JOIN players p ON p.team_id = t.id
            WHERE t.event_id = ? AND t.modality = ?';
    $params = [$eventId, $modality];

    if ($gender !== null && !is_mixed_modality($modality)) {
        $sql .= ' AND t.gender = ?';
        $params[] = $gender;
    }

    $sql .= ' GROUP BY t.id, t.name, t.class_name, t.group_name
              HAVING COUNT(p.id) > ?
              ORDER BY t.group_name, t.name';
    $params[] = $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function ensure_interclasse_schema_updates(bool $force = false): void
{
    static $checked = false;

    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $checked = true;
        return;
    }

    if (!$force && !auto_schema_updates_enabled()) {
        return;
    }

    if ($checked) {
        return;
    }

    $checked = true;

    try {
        $columnStmt = db()->query("SHOW COLUMNS FROM teams LIKE 'representative_name'");
        if (!$columnStmt->fetch()) {
            db()->exec('ALTER TABLE teams ADD representative_name VARCHAR(160) NULL AFTER representative_id');
        }

        $limitStmt = db()->query("SHOW COLUMNS FROM event_modalities LIKE 'max_players_per_team'");
        if (!$limitStmt->fetch()) {
            db()->exec('ALTER TABLE event_modalities ADD max_players_per_team INT NULL AFTER quarters_count');
        }

        $bracketTypeStmt = db()->query("SHOW COLUMNS FROM event_modalities LIKE 'bracket_type'");
        if (!$bracketTypeStmt->fetch()) {
            db()->exec("ALTER TABLE event_modalities ADD bracket_type VARCHAR(30) NOT NULL DEFAULT 'groups' AFTER max_players_per_team");
            db()->exec("UPDATE event_modalities SET bracket_type = 'simple' WHERE modality IN ('cubo_magico', 'xadrez', 'quiz')");
        }

        $advancementModeStmt = db()->query("SHOW COLUMNS FROM events LIKE 'advancement_mode'");
        if (!$advancementModeStmt->fetch()) {
            db()->exec("ALTER TABLE events ADD advancement_mode ENUM('global','per_group') NOT NULL DEFAULT 'global' AFTER teams_advancing_per_group");
        }

        db()->exec(
            'CREATE TABLE IF NOT EXISTS event_group_advancement (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NOT NULL,
                modality VARCHAR(40) NOT NULL,
                gender VARCHAR(20) NOT NULL DEFAULT "masculino",
                group_name VARCHAR(10) NOT NULL,
                teams_advancing INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_event_group_advancement (event_id, modality, gender, group_name),
                INDEX idx_event_group_advancement_event (event_id),
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS user_modalities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                modality VARCHAR(40) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_modality (user_id, modality),
                INDEX idx_user_modalities_user (user_id),
                INDEX idx_user_modalities_modality (modality),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $stageStmt = db()->query("SHOW COLUMNS FROM matches LIKE 'stage'");
        $stageColumn = $stageStmt->fetch();
        $stageType = (string) ($stageColumn['Type'] ?? '');
        if (
            strpos($stageType, "'oitavas'") === false
            || strpos($stageType, "'dezesseis_avos'") === false
            || strpos($stageType, "'preliminar'") === false
        ) {
            db()->exec(
                "ALTER TABLE matches MODIFY stage ENUM('grupo','repescagem','preliminar','dezesseis_avos','oitavas','quartas','semifinal','final','individual') NOT NULL DEFAULT 'grupo'"
            );
        }
    } catch (PDOException $exception) {
        error_log('Falha ao atualizar schema do interclasse: ' . $exception->getMessage());
    }
}

function team_representative_label(array $team): string
{
    $userName = trim((string) ($team['representative_user_name'] ?? $team['representative_name'] ?? ''));
    $manualName = trim((string) ($team['manual_representative_name'] ?? ''));

    if ($userName !== '' && $manualName !== '') {
        return $userName . ' / ' . $manualName;
    }

    if ($userName !== '') {
        return $userName;
    }

    if ($manualName !== '') {
        return $manualName;
    }

    return 'sem representante';
}
