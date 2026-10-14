<?php

/**
 * Mantém as consultas legadas do projeto funcionando no PostgreSQL.
 *
 * O código antigo usa aspas duplas para literais, uma extensão aceita pelo
 * MySQL. No PostgreSQL elas representam identificadores. A conversão é feita
 * apenas quando a conexão ativa é PostgreSQL.
 */
final class PostgresCompatiblePDO extends PDO
{
    private bool $isPostgres;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        $this->isPostgres = str_starts_with($dsn, 'pgsql:');
        parent::__construct($dsn, $username, $password, $options ?? []);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->normalizeSql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = $this->normalizeSql($query);
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->normalizeSql($statement));
    }

    public function lastInsertId(?string $name = null): string|false
    {
        if (!$this->isPostgres || $name !== null) {
            return parent::lastInsertId($name);
        }

        $statement = parent::query('SELECT LASTVAL()');
        return $statement === false ? false : (string) $statement->fetchColumn();
    }

    private function normalizeSql(string $sql): string
    {
        if (!$this->isPostgres) {
            return $this->normalizeMysqlSql($sql);
        }
        if (!str_contains($sql, '"')) {
            return $sql;
        }

        $result = '';
        $length = strlen($sql);
        $inSingleQuote = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'" && !$inSingleQuote) {
                $inSingleQuote = true;
                $result .= $char;
                continue;
            }

            if ($char === "'" && $inSingleQuote) {
                if ($i + 1 < $length && $sql[$i + 1] === "'") {
                    $result .= "''";
                    $i++;
                    continue;
                }
                $inSingleQuote = false;
                $result .= $char;
                continue;
            }

            if ($char === '"' && !$inSingleQuote) {
                $literal = '';
                $i++;
                while ($i < $length && $sql[$i] !== '"') {
                    if ($sql[$i] === '\\' && $i + 1 < $length) {
                        $i++;
                    }
                    $literal .= $sql[$i];
                    $i++;
                }
                $result .= "'" . str_replace("'", "''", $literal) . "'";
                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    /** Translate the PostgreSQL expressions used by the application for MariaDB. */
    private function normalizeMysqlSql(string $sql): string
    {
        $sql = preg_replace('/INTERVAL "(\d+) day"/i', 'INTERVAL $1 DAY', $sql);
        $sql = preg_replace_callback(
            '/array_position\(ARRAY\[([^\]]+)\],\s*([\w.]+)::text\)/i',
            static fn(array $m): string => 'NULLIF(FIELD(' . $m[2] . ', ' . $m[1] . '), 0)',
            $sql
        );
        $sql = preg_replace(
            '/string_agg\(modality::text,\s*"," ORDER BY (NULLIF\(FIELD\([^\n]+?\), 0\))\)/i',
            'GROUP_CONCAT(modality ORDER BY $1 SEPARATOR ",")',
            $sql
        );
        $sql = preg_replace_callback(
            '/([\w.]+)\s*->>\s*"([\w]+)"/',
            static fn(array $m): string => "JSON_UNQUOTE(JSON_EXTRACT(" . $m[1] . ", '$." . $m[2] . "'))",
            $sql
        );
        $sql = preg_replace('/AS INTEGER\)/i', 'AS SIGNED)', $sql);
        $sql = preg_replace(
            '/ON CONFLICT \(event_id, modality, gender, group_name\)\s+DO UPDATE SET teams_advancing = EXCLUDED\.teams_advancing/i',
            'ON DUPLICATE KEY UPDATE teams_advancing = VALUES(teams_advancing)',
            $sql
        );
        return $sql;
    }
}
