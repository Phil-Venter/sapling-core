<?php

/* -----------------------
   Path Handling
   ------------------------ */

if (!function_exists("from_base_dir")) {
    function from_base_dir(string $path = ""): string
    {
        $here = realpath(__DIR__) ?: __DIR__;

        if (str_contains($here, DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR)) {
            return dirname($here, 4) . DIRECTORY_SEPARATOR . $path;
        }

        return dirname($here) . DIRECTORY_SEPARATOR . $path;
    }
}

/* -----------------------
   Session
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
        }

        return $_SESSION["_flash"]["old"][$key] ?? null;
    }
}

/* -----------------------
   Request
   ------------------------ */

if (!function_exists("env")) {
    function env(string $key): mixed
    {
        return Sapling\Core\Environment::get($key);
    }
}

if (!function_exists("query")) {
    function query(string $key): mixed
    {
        return $_GET[$key] ?? null;
    }
}

if (!function_exists("input")) {
    function input(string $key): mixed
    {
        return $_POST[$key] ?? null;
    }
}

/* -----------------------
   Template
   ------------------------ */

if (!function_exists("e")) {
    function e(mixed $value): string
    {
        return Sapling\Core\Template::escape($value);
    }
}

/* -----------------------
   Response
   ------------------------ */

if (!function_exists("abort")) {
    function abort(int $status, string $body = ""): never
    {
        new Sapling\Core\Response($body, $status)->send();
        exit();
    }
}

if (!function_exists("finish_request")) {
    function finish_request(Sapling\Core\Response $res): void
    {
        ob_start();
        $res->send();
        $output = ob_get_clean() ?? "";

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ignore_user_abort(true);

        if (function_exists("fastcgi_finish_request")) {
            if (($_SERVER["REQUEST_METHOD"] ?? "") !== "HEAD") {
                echo $output;
            }
            fastcgi_finish_request();
            return;
        }

        if (($_SERVER["REQUEST_METHOD"] ?? "") === "HEAD") {
            return;
        }

        if ($output === "") {
            $output = " ";
        }

        if (!headers_sent()) {
            header("Connection: close", true);
            header("Content-Length: " . strlen($output));
        }

        echo $output;
        @ob_flush();
        flush();
    }
}

/* -----------------------
   Miscellaneous
   ------------------------ */

if (!function_exists("blank")) {
    function blank(mixed $value): bool
    {
        return match (true) {
            is_null($value) => true,
            is_int($value), is_float($value), is_bool($value) => false,
            is_string($value), $value instanceof Stringable => trim((string) $value) === "",
            $value instanceof Countable => count($value) === 0,
            default => empty($value),
        };
    }
}

if (!function_exists("call")) {
    function call(callable $fn, ...$args): array
    {
        try {
            return [$fn(...$args), null];
        } catch (\Throwable $e) {
            return [null, $e];
        }
    }
}

if (!function_exists("dd")) {
    function dd(...$vars): never
    {
        var_dump(...$vars);
        exit();
    }
}

if (!function_exists("value")) {
    function value(mixed $value, mixed ...$args): mixed
    {
        return $value instanceof Closure ? $value(...$args) : $value;
    }
}

if (!function_exists("tap")) {
    function tap(mixed $value, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            assert(is_object($value));
            return new class ($value) {
                public function __construct(private object $target) {}
                public function __call(string $method, array $args): object
                {
                    assert(method_exists($this->target, $method));
                    $this->target->$method(...$args);
                    return $this->target;
                }
            };
        }

        $callback($value);
        return $value;
    }
}
