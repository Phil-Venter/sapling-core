<?php

namespace Sapling\Core;

final class Router
{
    private(set) bool $matched = false;

    public function __construct(private Request $request) {}

    public function route(string $method, string $pattern, callable $handler): void
    {
        if ($this->matched || \strtoupper($method) !== $this->request->method) {
            return;
        }

        $pattern = normalize_path($pattern);
        if (!\str_contains($pattern, "{")) {
            if ($this->request->uri !== $pattern) {
                return;
            }
            $this->invoke($handler, $this->request);
            return;
        }

        $normalised = \preg_replace("#\{\s*([a-zA-Z_]\w*)\s*\}#", '%%$1%%', $pattern);
        $regex = \preg_replace("#%%([a-zA-Z_]\w*)%%#", '(?P<$1>[^/]+)', \preg_quote($normalised, "#"));
        if (!\preg_match("#^{$regex}$#", $this->request->uri, $matches)) {
            return;
        }

        $params = [];
        foreach ($matches as $k => $v) {
            if (\is_string($k)) {
                $params[$k] = \rawurldecode($v);
            }
        }

        $this->invoke($handler, $this->request->withParams($params));
    }

    private function invoke(callable $handler, Request $request): void
    {
        $this->matched = true;

        try {
            $res = $handler($request);

            if ($res instanceof Response) {
                $res->send();
            } else {
                Response::noContent()->send();
            }
        } catch (\Throwable $e) {
            Response::exception($e)->send();
        }
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
