<?php

global $SAPLING_PDO_OBJECTS;
$SAPLING_PDO_OBJECTS = [];

/* -----------------------
   Path Handling
   ------------------------ */

if (!function_exists("from_base_dir")) {
    function from_base_dir(string $path = ""): string
    {
        $ds = DIRECTORY_SEPARATOR;

        $dir = realpath(__DIR__) ?: __DIR__;

        $path = str_replace(["\\", "/"], $ds, $path);
        $path = ltrim($path, $ds);

        if (str_contains($dir, $ds . "vendor" . $ds)) {
            return dirname($dir, 4) . $ds . $path;
        }

        return dirname($dir) . $ds . $path;
    }
}

/* -----------------------
   Environment
   ------------------------ */

if (!function_exists("register_exception_handlers")) {
    function register_exception_handlers(): void
    {
        set_error_handler(function (int $severity, string $message, string $file, int $line) {
            if (error_reporting() & $severity) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }

            return false;
        });

        set_exception_handler(function (Throwable $e) {
            Sapling\Core\Response::exception($e)->send();
            exit();
        });

        register_shutdown_function(function () {
            if (!($err = error_get_last())) {
                return;
            }

            if (!in_array($err["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }

            Sapling\Core\Response::exception(new \ErrorException($err["message"], 0, $err["type"], $err["file"], $err["line"]))->send();
            exit();
        });
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

if (!function_exists("env")) {
    function env(string $key, mixed $default = null): mixed
    {
        return Sapling\Core\env_get($key, $default);
    }
}

if (!function_exists("register_pdo")) {
    function register_pdo(PDO $pdo, ?string $name = null, ?callable $resolver = null): void
    {
        global $SAPLING_PDO_OBJECTS;
        if (is_callable($resolver)) {
            $resolver($pdo);
        }
        $SAPLING_PDO_OBJECTS[$name ?? 'default'] = $pdo;
    }
}

if (!function_exists("pdo")) {
    function pdo(?string $name = null): ?PDO
    {
        global $SAPLING_PDO_OBJECTS;
        return $SAPLING_PDO_OBJECTS[$name ?? 'default'] ?? null;
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
   Template
   ------------------------ */

if (!function_exists("e")) {
    function e(mixed $value, ?string $dateFormat = null): string
    {
        return Sapling\Core\escape($value, $dateFormat);
    }
}

if (!function_exists("render")) {
    function render(string $template, iterable|object $vars = [], ?string $dateFormat = null): string
    {
        return Sapling\Core\render_template($template, $vars, $dateFormat);
    }
}

/* -----------------------
   Response
   ------------------------ */

if (!function_exists("abort_if")) {
    function abort_if(mixed $condition, Closure|Sapling\Core\Response $res)
    {
        if (value($condition)) {
            value($res)->send();
            exit();
        }
    }
}

if (!function_exists("finish_request")) {
    function finish_request(Sapling\Core\Response $res): void
    {
        $res->send(true);
    }
}

if (!function_exists("redirect")) {
    function redirect(string $to, int $status = 302, array $headers = []): Sapling\Core\Response
    {
        return Sapling\Core\Response::redirect($to, $status, $headers);
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

if (!function_exists("get_by_path")) {
    function get_by_path(object|iterable $value, string|\Stringable $path, mixed $default = null): mixed
    {
        return Sapling\Core\get_by_list($value, array_map("trim", explode(".", (string) $path)), $default);
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
        if ($callback !== null) {
            $callback($value);
            return $value;
        }

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
}
