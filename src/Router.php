<?php

namespace Sapling\Core;

class Router
{
    private(set) bool $matched = false;

    public function __construct(private Request $request) {}

    public function route(string $method, string $pattern, callable $handler): void
    {
        if ($this->matched || strtoupper($method) !== $this->request->method) {
            return;
        }

        $pattern = normalize_path($pattern);
        if (!str_contains($pattern, ":")) {
            if ($this->request->uri !== $pattern) {
                return;
            }

            $this->invoke($handler, $this->request);
            return;
        }

        $regex = "#^/" . implode("/", array_map(
            static fn(string $segment): string => str_starts_with($segment, ":")
                ? "(?P<" . substr($segment, 1) . ">[^/]+)"
                : preg_quote($segment, "#"),
            explode("/", substr($pattern, 1)),
        )) . "$#";

        if (!preg_match($regex, $this->request->uri, $matches)) {
            return;
        }

        $params = array_map("rawurldecode", array_filter($matches, "is_string", ARRAY_FILTER_USE_KEY));
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
