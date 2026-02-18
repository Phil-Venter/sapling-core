<?php

namespace Sapling\Core;

class Database
{
    protected const DEFAULT_NAME = "default";

    protected static array $pdoMapping = [];
    protected static array $pdoResolved = [];

    public static function set(\Closure|\PDO $pdo, ?string $name = null): void
    {
        $name ??= static::DEFAULT_NAME;
        unset(self::$pdoResolved[$name]);
        self::$pdoMapping[$name] = fn() => static::setup(value($pdo));
    }

    public static function get(?string $name = null): \PDO
    {
        $name ??= static::DEFAULT_NAME;
        return match (true) {
            isset(self::$pdoResolved[$name]) => self::$pdoResolved[$name],
            isset(self::$pdoMapping[$name]) => (self::$pdoResolved[$name] = value(self::$pdoMapping[$name])),
            default => throw new \InvalidArgumentException("Database connection '$name' does not exist."),
        };
    }

    public static function close(?string $name = null, bool $forget = false): void
    {
        $name ??= static::DEFAULT_NAME;
        unset(self::$pdoResolved[$name]);
        if ($forget) {
            unset(self::$pdoMapping[$name]);
        }
    }

    protected static function setup(\PDO $pdo): \PDO
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if (\is_callable([static::class, $driver])) {
            static::{$driver}($pdo);
        }

        return $pdo;
    }

    protected static function sqlite(\PDO $pdo): void
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
