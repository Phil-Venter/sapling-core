<?php

namespace Sapling\Core;

/* -----------------------
   Environment
   ------------------------ */

function env_get(string $key, mixed $default): mixed
{
    $value = getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? null;
    }

    if ($value === null) {
        return $default;
    }

    $value = trim((string) $value);
    $lower = strtolower($value);
    return match(true) {
        $lower === "true" => true,
        $lower === "false" => false,
        $lower === "null" || $lower === "" => $default,
        is_numeric($value) => preg_match("/[e\.]/i", $value) ? (float) $value : (int) $value,
        default => $value,
    };
}

/* -----------------------
   Request & Routing
   ------------------------ */

function normalize_path(string $path): string
{
    $path = preg_replace("#/+#", "/", $path);
    $path = rtrim($path, "/") . "/";
    return "/" . ltrim($path, "/");
}

/* -----------------------
   Template
   ------------------------ */

function render_template(string|\Stringable $template, iterable|object|string|null $vars): string
{
    if (is_null($vars)) {
        return preg_replace('/{([^}]+)\s*}/', '', $template);
    }

    if (is_string($vars) || $vars instanceof \Stringable) {
        return render_template($template, ["content" => (string) $vars]);
    }

    if (is_array($vars) && array_is_list($vars)) {
        return implode("", array_map(fn($v) => render_template($template, $v), $vars));
    }

    $fn = function (array $match) use ($vars): string {
        [$key, $modifier] = array_map("trim", explode(":", trim($match[1]), 2) + [1 => ""]);
        $raw = get_by_list($vars, array_map("trim", explode(".", $key)), "");
        return match ($modifier) {
            "unsafe" => $raw,
            "url" => urlencode($raw),
            default => htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"),
        };
    };

    return preg_replace_callback("/{\s*([^}]+)\s*}/", $fn, $template);
}

/* -----------------------
   Miscellaneous
   ------------------------ */

function get_by_list(mixed $value, array $list, mixed $default): mixed
{
    if ($list === []) {
        return $value;
    }

    if ($value instanceof \Traversable) {
        $value = iterator_to_array($value);
    }

    $key = array_shift($list);
    if (is_array($value) && array_key_exists($key, $value)) {
        return get_by_list($value[$key], $list, $default);
    }

    if (is_object($value) && property_exists($value, $key)) {
        try {
            return get_by_list($value->$key, $list, $default);
        } catch (\Error) {
            return $default;
        }
    }

    return $default;
}

function value(mixed $value, mixed ...$args): mixed
{
    return $value instanceof \Closure ? $value(...$args) : $value;
}
