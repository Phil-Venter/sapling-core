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
   Environment
   ------------------------ */

if (!function_exists("env")) {
    function env(string $key, mixed $default = null): mixed
    {
        return Sapling\Core\env_get($key, $default);
    }
}

if (!function_exists("load_env")) {
    function load_env(string $path, bool $override = false): void
    {
        foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === "" || $line[0] === "#" || strpos($line, "=") === false) {
                continue;
            }

            [$key, $val] = explode("=", $line, 2);
            $key = trim($key);
            $val = trim($val);

            if ($key === "") {
                continue;
            }

            if ($val !== "" && preg_match('/^(["\']).*\1$/', $val)) {
                $val = substr($val, 1, -1);
            } else {
                $hashPos = strpos($val, "#");
                if ($hashPos !== false) {
                    $val = rtrim(substr($val, 0, $hashPos));
                }
            }

            if (!$override && getenv($key) !== false) {
                continue;
            }

            putenv($key . "=" . $val);
            $_ENV[$key] = $val;
        }
    }
}

/* -----------------------
   Session
   ------------------------ */

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

/* -----------------------
   Request
   ------------------------ */

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
        return Sapling\Core\escape($value);
    }
}

if (!function_exists("render_template")) {
    function render_template(string $template, iterable|object $vars = [], ?string $format = null): string
    {
        return Sapling\Core\render_template($template, $vars, $format);
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
        $res->send(true);
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
        } catch (Throwable $e) {
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

if (!function_exists("iterator_get")) {
    function iterator_get(string|\Stringable $key, iterable $arr, mixed $default = null): array
    {
        return Sapling\Core\iterator_get($key, $arr, $default);
    }
}

if (!function_exists("object_get")) {
    function object_get(string|\Stringable $key, object $obj, mixed $default = null): mixed
    {
        return Sapling\Core\object_get($key, $obj, $default);
    }
}

if (!function_exists("value")) {
    function value(mixed $value, mixed ...$args): mixed
    {
        return Sapling\Core\value($value, ...$args);
    }
}

if (!function_exists("tap")) {
    function tap(mixed $value, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            if (!is_object($value)) {
                throw new InvalidArgumentException("Value must be an object when no callback is provided");
            }

            return new class ($value) {
                public function __construct(private object $target) {}
                public function __call(string $method, array $args): object
                {
                    $this->target->$method(...$args);
                    return $this->target;
                }
            };
        }

        $callback($value);
        return $value;
    }
}
