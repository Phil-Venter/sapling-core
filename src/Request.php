<?php

namespace Sapling\Core;

final class Request
{
    private bool $bodyParsed = false;
    private array $normalisedBody = [];

    public function __construct(
        private(set) string $method,
        private(set) string $uri,
        private(set) array $headers,
        private(set) string $body,
        private(set) array $params = []
    ) {}

    public static function fromGlobals(): self
    {
        $method = \strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");
        $method = $method === "HEAD" ? "GET" : $method;
        if ($method === "POST" && isset($_POST["_method"])) {
            $override = \strtoupper(\trim((string) $_POST["_method"]));
            if (\in_array($override, ["PUT", "PATCH", "DELETE"], true)) {
                $method = $override;
            }
        }

        $uri = \parse_url($_SERVER["REQUEST_URI"] ?? "/", \PHP_URL_PATH) ?: "/";
        $uri = normalize_path($uri);

        $headers = \function_exists("getallheaders") ? getallheaders() : [];
        $headers = \array_change_key_case($headers, \CASE_LOWER);

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
        return $this->normalisedBody[$key] ?? ($_POST[$key] ?? $default);
    }

    private function parseBody(): void
    {
        if ($this->bodyParsed) {
            return;
        }

        $this->bodyParsed = true;

        $raw = \trim($this->body);
        if ($raw === "") {
            return;
        }

        $contentType = $this->headers["content-type"] ?? ($_SERVER["CONTENT_TYPE"] ?? "");
        $mimeType = \strtolower(\trim(\strtok($contentType, ";")));

        if ($mimeType === "application/json" || \str_ends_with($mimeType, "+json")) {
            try {
                $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                $this->normalisedBody = \is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $this->normalisedBody = [];
            }
        }
    }
}
