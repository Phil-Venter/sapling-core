<?php

namespace Sapling\Core;

final class Router
{
    public function __construct(public string $method, public string $path) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");
        $method = $method === "HEAD" ? "GET" : $method;

        if ($method === "POST" && isset($_POST["_method"])) {
            $override = strtoupper(trim((string) $_POST["_method"]));
            if (in_array($override, ["PUT", "PATCH", "DELETE"], true)) {
                $method = $override;
            }
        }

        $path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
        return new self($method, self::normalizePath($path));
    }

    public function route(string $method, string $pattern, callable $handler): void
    {
        if (strtoupper($method) !== $this->method) {
            return;
        }

        $pattern = self::normalizePath($pattern);
        if (!str_contains($pattern, "{")) {
            if ($this->path !== $pattern) {
                return;
            }

            $this->invoke($handler)->send();
            exit();
        }

        $pattern = preg_replace('#\{\s+([a-zA-Z_]\w*)\s+\}#', '%%$1%%', $pattern);
        $quoted = preg_quote($pattern, '#');
        $regex = preg_replace('#%%([a-zA-Z_]\w*)%%#', '(?P<$1>[^/]+)', $quoted);

        if (!preg_match("#^{$regex}$#", $this->path, $matches)) {
            return;
        }

        $args = [];
        foreach ($matches as $k => $v) {
            if (is_string($k)) {
                $args[$k] = rawurldecode($v);
            }
        }

        $this->invoke($handler, $args)->send();
        exit();
    }

    private static function normalizePath(string $path): string
    {
        $path = preg_replace("#/+#", "/", $path);
        $path = rtrim($path, "/") . "/";
        return "/" . ltrim($path, "/");
    }

    private function invoke(callable $handler, array $args = []): Response
    {
        try {
            $res = $handler(...$args);
        } catch (\Throwable $e) {
            return Response::exception($e);
        }

        if ($res instanceof Response) {
            return $res;
        }

        if (is_string($res) || $res instanceof \Stringable) {
            return Response::ok($res);
        }

        return Response::noContent();
    }

    /* -----------------------
        COMMON METHODS
    ------------------------ */

    public function get(string $pattern, callable $handler): void
    {
        $this->route("GET", $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->route("POST", $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->route("PUT", $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->route("PATCH", $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->route("DELETE", $pattern, $handler);
    }
}
