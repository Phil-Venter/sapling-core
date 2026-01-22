<?php

namespace Sapling\Core;

final class Template
{
    private static array $map = [];

    public static function setGlobal(string $key, string $value): void
    {
        self::$map[$key] = $value;
    }

    public static function render(string $template, array|object $vars = []): string
    {
        $map = self::$map;

        foreach (self::flatten($vars) as $key => $value) {
            $map["unsafe:$key"] = $value;
            $map["url:$key"] = rawurlencode($value);
            $map[$key] = e($value);
        }

        $map["dump"] = sprintf("<pre>%s</pre>", e(print_r($map, true)));

        return preg_replace_callback(
            "/{{\s*([A-Za-z0-9_.:-]+)\s*}}/",
            fn($match) => $map[$match[1]] ?? "<!-- {$match[0]} -->",
            $template
        );
    }

    private static function flatten(array|object $data, string $prefix = ""): array
    {
        $vars = [];

        if (is_object($data)) {
            if ($data instanceof \Traversable) {
                $data = iterator_to_array($data);
            } else {
                $data = get_object_vars($data);
            }
        }

        foreach ($data as $key => $val) {
            $key = trim((string) $key);

            if ($key === "") {
                continue;
            }

            $val = match (true) {
                $val === null => "",
                $val instanceof \BackedEnum => (string) $val->value,
                $val instanceof \UnitEnum => $val->name,
                $val instanceof \DateTimeInterface => $val->format("j M Y"),
                $val instanceof \Stringable, is_scalar($val) => (string) $val,
                default => $val,
            };

            $path = $prefix === "" ? $key : "{$prefix}.{$key}";

            if (is_array($val) || is_object($val)) {
                $vars += self::flatten($val, $path);
            } else {
                $vars[$path] = $val;
            }
        }

        return $vars;
    }
}
