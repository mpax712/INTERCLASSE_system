<?php

$databaseUrl = trim(html_entity_decode((string) getenv('DATABASE_URL'), ENT_QUOTES | ENT_HTML5));
$databaseUrlConfig = [];

if ($databaseUrl !== '') {
    $parts = parse_url($databaseUrl);
    if ($parts === false || !isset($parts['host'])) {
        throw new RuntimeException('DATABASE_URL inválida. Use uma URL PostgreSQL com a senha codificada.');
    }

    parse_str($parts['query'] ?? '', $query);
    $databaseUrlConfig = [
        'driver' => in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true) ? 'pgsql' : ($parts['scheme'] ?? 'pgsql'),
        'host' => $parts['host'],
        'port' => $parts['port'] ?? 5432,
        'database' => isset($parts['path']) ? ltrim(rawurldecode($parts['path']), '/') : 'postgres',
        'username' => isset($parts['user']) ? rawurldecode($parts['user']) : '',
        'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
        'sslmode' => $query['sslmode'] ?? 'require',
    ];
}

$config = [
    'db' => [
        'driver' => getenv('DB_DRIVER') ?: 'pgsql',
        'host' => getenv('DB_HOST') ?: 'aws-1-sa-east-1.pooler.supabase.com',
        'port' => (int) (getenv('DB_PORT') ?: 5432),
        'database' => getenv('DB_DATABASE') ?: 'postgres',
        'username' => getenv('DB_USERNAME') ?: 'postgres.qligtwmuqawnsjhjklcr',
        'password' => getenv('DB_PASSWORD') ?: '',
        'sslmode' => getenv('DB_SSLMODE') ?: 'require',
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ],
    'app' => [
        'name' => getenv('APP_NAME') ?: 'Interclasse Escolar',
        'base_url' => getenv('APP_BASE_URL') ?: '',
        'secure_cookies' => filter_var(getenv('APP_SECURE_COOKIES') ?: (getenv('VERCEL')==='1'), FILTER_VALIDATE_BOOLEAN),
        'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
        'auto_schema_updates' => filter_var(getenv('APP_AUTO_SCHEMA_UPDATES') ?: false, FILTER_VALIDATE_BOOLEAN),
        'timezone' => getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo',
        'diagnostic_key' => getenv('APP_DIAGNOSTIC_KEY') ?: '12345',
    ],
];

if ($databaseUrlConfig !== []) {
    $config['db'] = array_replace($config['db'], $databaseUrlConfig);
}

$localConfigPath = __DIR__ . '/config.local.php';

if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

return $config;
