<?php

/* -----------------------
   UTILITIES
   ------------------------ */

if (!function_exists("abort")) {
    function abort(int $status, string $body = ""): never
    {
        new Sapling\Core\Response($body, $status)->send();
        exit();
    }
}
if (!function_exists("base_dir")) {
    function base_dir(): string
    {
        $here = realpath(__DIR__) ?: __DIR__;

        if (str_contains($here, DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR)) {
            return dirname($here, 4);
        }

        return dirname($here);
    }
}

if (!function_exists("dd")) {
    function dd(...$vars): never
    {
        var_dump(...$vars);
        exit();
    }
}

if (!function_exists("e")) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
}

if (!function_exists("env")) {
    function env(string $key): mixed
    {
        return Sapling\Core\Environment::get($key);
    }
}

if (!function_exists("input")) {
    function input(string $key): mixed
    {
        return $_POST[$key] ?? null;
    }
}

if (!function_exists("query")) {
    function query(string $key): mixed
    {
        return $_GET[$key] ?? null;
    }
}

/* -----------------------
   SESSION
   ------------------------ */

if (!function_exists("session_init")) {
    function session_init(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (!session_start()) {
            return false;
        }

        $_SESSION["_flash"]["old"] = $_SESSION["_flash"]["new"] ?? [];
        $_SESSION["_flash"]["new"] = [];

        return true;
    }
}

if (!function_exists("csrf")) {
    function csrf(?string $token = null): string|bool
    {
        if (!session_init()) {
            return func_num_args() === 0 ? "" : false;
        }

        if (empty($_SESSION["_csrf"])) {
            $_SESSION["_csrf"] = bin2hex(random_bytes(32));
        }

        if (func_num_args() === 0) {
            return $_SESSION["_csrf"];
        }

        return hash_equals($_SESSION["_csrf"], $token ?? "");
    }
}

if (!function_exists("flash")) {
    function flash(string $key, string $value = ""): ?string
    {
        if (!session_init()) {
            return null;
        }

        if (func_num_args() > 1) {
            return $_SESSION["_flash"]["new"][$key] = $value;
        } else {
            return $_SESSION["_flash"]["old"][$key] ?? null;
        }
    }
}
