<?php

require_once __DIR__ . '/PostgresCompatiblePDO.php';

function config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        date_default_timezone_set($config['app']['timezone'] ?? 'America/Sao_Paulo');
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dbConfig = config()['db'];
        $driver = $dbConfig['driver'] ?? 'pgsql';
        if (($driver === 'pgsql' && ($dbConfig['password'] ?? '') === '') || ($dbConfig['password'] ?? '') === '[YOUR-PASSWORD]') {
            throw new RuntimeException('Defina DATABASE_URL ou DB_PASSWORD com a senha do banco Supabase.');
        }

        if ($driver === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $dbConfig['host'],
                (int) ($dbConfig['port'] ?? 5432),
                $dbConfig['database'],
                $dbConfig['sslmode'] ?? 'require'
            );
        } elseif ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $dbConfig['host'],
                (int) ($dbConfig['port'] ?? 3306),
                $dbConfig['database'],
                $dbConfig['charset'] ?? 'utf8mb4'
            );
        } else {
            throw new RuntimeException('Driver de banco não suportado: ' . $driver);
        }

        $pdo = new PostgresCompatiblePDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}
