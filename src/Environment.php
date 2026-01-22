<?php

namespace Sapling\Core;

final class Environment
{
    private static array $vars = [];

    public static function get(string $key): mixed
    {
        return self::$vars[$key] ?? null;
    }

    public static function load(string $path): void
    {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, "#") || !str_contains($line, "=")) {
                continue;
            }

            [$key, $val] = explode("=", $line, 2);
            self::$vars[trim($key)] = self::autoCastString(trim($val));
        }
    }

    protected static function autoCastString(string $value): mixed
    {
        if (preg_match("/^([\"']).*\1$/", $value)) {
            return substr($value, 1, -1);
        }

        $hashPos = strpos($value, "#");

        if (false !== $hashPos) {
            $value = rtrim(substr($value, 0, $hashPos));
        }

        if (preg_match("/^([\"']).*\1$/", $value)) {
            return substr($value, 1, -1);
        }

        $lower = strtolower($value);

        return match (true) {
            $lower === "true" => true,
            $lower === "false" => false,
            $lower === "null" => null,
            $lower === "" => null,
            is_numeric($value) => preg_match("/[e\.]/", $value) ? (float) $value : (int) $value,
            default => $value,
        };
    }
}
