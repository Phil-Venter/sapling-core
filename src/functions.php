<?php

namespace Sapling\Core;

/* -----------------------
   Environment
   ------------------------ */

function env_get(string $key, mixed $default = null): mixed
{
    $value = \getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? null;
    }

    if ($value === null) {
        return $default;
    }

    $v = \trim((string) $value);
    $lower = \strtolower($v);

    if ($lower === "true") {
        return true;
    }

    if ($lower === "false") {
        return false;
    }

    if ($lower === "null" || $lower === "") {
        return $default;
    }

    if (\is_numeric($v)) {
        return \preg_match("/[e\.]/i", $v) ? (float) $v : (int) $v;
    }

    return $v;
}

/* -----------------------
   Template
   ------------------------ */

function escape(mixed $value, ?string $format = null): string
{
    $value = value($value);
    $value = match (true) {
        \is_null($value) => "",
        \is_float($value), \is_int($value) => (string) $value,
        \is_bool($value) => $value ? "true" : "false",
        \is_string($value) => $value,
        $value instanceof \DateTimeInterface => $value->format($format ?? \DATE_ATOM),
        $value instanceof \BackedEnum => (string) $value->value,
        $value instanceof \UnitEnum => $value->name,
        $value instanceof \Stringable => (string) $value,
        default => throw new \InvalidArgumentException("Invalid value type"),
    };
    return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, "UTF-8");
}

function render_template(string $template, iterable|object $vars = [], ?string $format = null): string
{
    return \preg_replace_callback(
        "/{{\s*([^}]+)\s*}}/",
        function (array $m) use ($vars, $format): string {
            $token = \trim($m[1]);
            [$key, $modifier] = \array_map("trim", \explode(":", $token, 2) + [1 => ""]);

            $raw = match (true) {
                \is_object($vars) => object_get($key, $vars, "<!-- {$token} -->"),
                default => iterator_get($key, $vars, "<!-- {$token} -->"),
            };

            return match ($modifier) {
                "unsafe" => (string) value($raw),
                "url" => \urlencode((string) value($raw)),
                "json" => (string) \json_encode(value($raw)),
                default => escape($raw, $format),
            };
        },
        $template,
    );
}

/* -----------------------
   Miscellaneous
   ------------------------ */

function iterator_get(string|\Stringable $key, iterable $arr, mixed $default = null): mixed
{
    $key = \trim((string) $key);
    $arr = \iterator_to_array($arr);

    if ($key === "") {
        return $arr;
    }

    foreach (\explode(".", $key) as $part) {
        if (!\is_array($arr) || !\array_key_exists($part, $arr)) {
            return $default;
        }
        $arr = $arr[$part];
    }

    return $arr;
}

function object_get(string|\Stringable $key, object $obj, mixed $default = null): mixed
{
    $key = \trim((string) $key);

    if ($key === "") {
        return $obj;
    }

    foreach (\explode(".", $key) as $part) {
        if (!\is_object($obj) || !isset($obj->$part)) {
            return $default;
        }
        $obj = $obj->$part;
    }

    return $obj;
}

function value(mixed $value, mixed ...$args): mixed
{
    return $value instanceof \Closure ? $value(...$args) : $value;
}
