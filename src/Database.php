<?php

namespace Sapling\Core;

class Database
{
    protected static array $instances = [];

    public static function set(\PDO $pdo, ?string $name = null): void
    {
        self::$instances[$name ?? 'default'] = $pdo;
    }

    public static function get(?string $name = null): \PDO
    {
        return self::$instances[$name ?? 'default'];
    }

    public static function saneDefaultsForSqlitePdo(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec("PRAGMA busy_timeout = 5000");
        $pdo->exec("PRAGMA foreign_keys = ON");
        $pdo->exec("PRAGMA journal_mode = WAL");
        $pdo->exec("PRAGMA synchronous = NORMAL");
        $pdo->exec("PRAGMA cache_size = -2000");
        $pdo->exec("PRAGMA wal_autocheckpoint = 1000");
    }
}
