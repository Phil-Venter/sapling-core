<?php

namespace Sapling\Core;

class Request
{
    protected array $normalisedBody;

    public function __construct(
        protected(set) string $method,
        protected(set) string $uri,
        protected(set) array $headers,
        protected(set) string $body,
        protected(set) array $params = []
    ) {}

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

        $uri = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
        $uri = normalize_path($uri);

        $headers = function_exists("getallheaders") ? getallheaders() : [];
        $headers = array_change_key_case($headers, CASE_LOWER);

        $body = file_get_contents("php://input") ?: "";

        return new self($method, $uri, $headers, $body);
    }

    public function withParams(array $params): self
    {
        $that = clone $this;
        $that->params = array_merge($that->params, $params);
        return $that;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? ($_GET[$key] ?? $default);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $this->parseBody();
        return $this->normalisedBody[$key] ?? $default;
    }

    protected function parseBody(): void
    {
        if (isset($this->normalisedBody)) {
            return;
        }

        $data = $_POST ?? [];
        if ($data !== []) {
            $this->normalisedBody = $data;
            return;
        }

        if ($this->body === "") {
            $this->normalisedBody = [];
            return;
        }

        $type = strtolower(trim(explode(";", $this->headers["content-type"] ?? "", 2)[0]));
        if ($type === "application/json" || str_ends_with($type, "+json")) {
            try {
                $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
                $this->normalisedBody = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $this->normalisedBody = [];
            }
            return;
        }

        if ($type === "application/x-www-form-urlencoded") {
            parse_str($this->body, $parsed);
            $this->normalisedBody = is_array($parsed) ? $parsed : [];
            return;
        }

        $this->normalisedBody = [];
    }
}
