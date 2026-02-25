<?php

namespace Sapling\Core;

class Template
{
    protected static $modifiers = [];

    public static function set(string $name, callable $fn): void
    {
        self::$modifiers[$name] = \Closure::fromCallable($fn);
    }

    public static function render(string|\Stringable $template, iterable|object|string|null $vars = null): string
    {
        if (is_null($vars)) {
            return preg_replace("/{{[^}}]+\s*}}/", "", $template);
        }

        if (is_string($vars) || $vars instanceof \Stringable) {
            return self::render($template, ["content" => (string) $vars]);
        }

        if (is_array($vars) && array_is_list($vars)) {
            return implode("", array_map(fn($v) => self::render($template, $v), $vars));
        }

        self::baseModifiers();

        $fn = function (array $match) use ($vars): string {
            [$key, $modifier] = array_map("trim", explode(":", trim($match[1]), 2) + [1 => ""]);
            $value = get_by_list($vars, array_map("trim", explode(".", $key)), "");
            return array_key_exists($modifier, self::$modifiers)
                ? self::$modifiers[$modifier]($value)
                : htmlspecialchars($value, encoding: 'UTF-8');
        };

        return preg_replace_callback("/{{\s*([^}}]+)\s*}}/", $fn, $template);
    }

    protected static function baseModifiers(): void
    {
        if (!array_key_exists('url', self::$modifiers)) {
            self::$modifiers['url'] = static fn($value) => urlencode($value);
        }

        if (!array_key_exists('unsafe', self::$modifiers)) {
            self::$modifiers['unsafe'] = static fn($value) => $value;
        }
    }
}
