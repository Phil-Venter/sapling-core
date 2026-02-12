<?php

namespace Sapling\Core;

/* -----------------------
   Environment
   ------------------------ */

function env_get(string $key, mixed $default): mixed
{
    $value = \getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? null;
    }

    if ($value === null) {
        return $default;
    }

    $value = \trim((string) $value);
    $lower = \strtolower($value);

    if ($lower === "true") {
        return true;
    }

    if ($lower === "false") {
        return false;
    }

    if ($lower === "null" || $lower === "") {
        return $default;
    }

    if (\is_numeric($value)) {
        return \preg_match("/[e\.]/i", $value) ? (float) $value : (int) $value;
    }

    return $value;
}

/* -----------------------
   Template
   ------------------------ */

function escape(mixed $value, ?string $dateFormat): string
{
    $value = value($value);

    $value = match (true) {
        \is_null($value) => "",
        \is_float($value), \is_int($value) => (string) $value,
        \is_bool($value) => $value ? "true" : "false",
        \is_string($value) => $value,
        $value instanceof \DateTimeInterface => $value->format($dateFormat ?? \DATE_ATOM),
        $value instanceof \BackedEnum => (string) $value->value,
        $value instanceof \UnitEnum => $value->name,
        $value instanceof \Stringable => (string) $value,
        default => throw new \InvalidArgumentException("Invalid value type"),
    };

    return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, "UTF-8");
}

function render_template(string|\Stringable $template, iterable|object $vars, ?string $dateFormat): string
{
    return \preg_replace_callback(
        "/{{\s*([^}]+)\s*}}/",
        function (array $m) use ($vars, $dateFormat): string {
            $token = \trim($m[1]);
            [$key, $modifier] = \array_map("trim", \explode(":", $token, 2) + [1 => ""]);

            $raw = get_by_list($vars, \array_map("trim", \explode(".", $key)), "");

            return match ($modifier) {
                "unsafe" => (string) value($raw),
                "url" => \urlencode((string) value($raw)),
                default => escape($raw, $dateFormat),
            };
        },
        (string) $template,
    );
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
        $value = \iterator_to_array($value);
    }

    $key = \array_shift($list);

    if (\is_array($value) && \array_key_exists($key, $value)) {
        return get_by_list($value[$key], $list, $default);
    }

    if (\is_object($value) && isset($value->$key) && \property_exists($value, $key)) {
        return get_by_list($value->$key, $list, $default);
    }

    return $default;
}

function value(mixed $value, mixed ...$args): mixed
{
    return $value instanceof \Closure ? $value(...$args) : $value;
}
