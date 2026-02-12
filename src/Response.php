<?php

namespace Sapling\Core;

final class Response implements \Stringable
{
    private const array MULTILINE_HEADERS = [
        "set-cookie",
        "www-authenticate",
        "proxy-authenticate",
        "warning",
        "link",
    ];

    public function __construct(
        private(set) string|\Stringable $body = "",
        private(set) int $status = 200,
        private(set) array $headers = [],
    ) {
        $this->headers += [
            "Content-Type" => "text/html; charset=utf-8",
            "X-Content-Type-Options" => "nosniff",
        ];
    }

    public function send(bool $flush = false): void
    {
        $isHead = ($_SERVER["REQUEST_METHOD"] ?? null) === "HEAD";

        if (!\headers_sent()) {
            \http_response_code($this->status);

            foreach ($this->headers as $key => $values) {
                $key = \trim($key);
                if (
                    $key === ""
                    || \strpbrk($key, "\r\n") !== false
                    || !\preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $key)
                ) {
                    continue;
                }

                $values = \array_values(\array_filter(
                    (array) $values,
                    static fn($v) => \strpbrk((string) $v, "\r\n") === false
                ));

                if ($values === []) {
                    continue;
                } elseif (!\in_array(\strtolower($key), self::MULTILINE_HEADERS, true)) {
                    \header("{$key}: " . \implode(", ", \array_map("strval", $values)), true);
                    continue;
                }

                foreach ($values as $v) {
                    \header("{$key}: " . (string) $v, false);
                }
            }
        }

        if (!$flush) {
            if (!$isHead && $this->status >= 200 && !\in_array($this->status, [204, 205, 304], true)) {
                echo $this->body;
            }
            return;
        }

        if (\session_status() === \PHP_SESSION_ACTIVE) {
            \session_write_close();
        }

        \ignore_user_abort(true);

        if (\function_exists("fastcgi_finish_request")) {
            if (!$isHead) {
                echo $this->body;
            }
            \fastcgi_finish_request();
            return;
        }

        if ($this->body === "") {
            $this->body = " ";
        }

        if (!\headers_sent()) {
            \header("Connection: close", true);
            \header("Content-Length: " . strlen($this->body));
        }

        echo $this->body;
        @\ob_flush();
        \flush();
    }

    public function __toString(): string
    {
        return (string) $this->body;
    }

    /* -----------------------
       COMMON STATUSES
    ------------------------ */

    public static function ok(string|\Stringable $body, array $headers = []): self
    {
        return new self($body, 200, $headers);
    }

    public static function created(string|\Stringable $body, array $headers = []): self
    {
        return new self($body, 201, $headers);
    }

    public static function noContent(array $headers = []): self
    {
        return new self("", 204, $headers);
    }

    public static function redirect(string $to, int $status = 302, array $headers = []): self
    {
        if (isset($_SERVER["HTTP_HX_REQUEST"]) && $_SERVER["HTTP_HX_REQUEST"] === "true") {
            return self::ok("", ["HX-Redirect" => $to] + $headers);
        }

        return new self("", $status, ["Location" => $to] + $headers);
    }

    public static function badRequest(string|\Stringable $body, array $headers = []): self
    {
        return new self($body, 400, $headers);
    }

    public static function unauthorized(string|\Stringable $body, array $headers = []): self
    {
        return new self($body, 401, $headers);
    }

    public static function forbidden(string|\Stringable $body, array $headers = []): self
    {
        return new self($body, 403, $headers);
    }

    public static function notFound(string|\Stringable $body, array $headers = []): self
    {
        return new self($body, 404, $headers);
    }

    /* -----------------------
       UTILITY
    ------------------------ */

    public static function exception(\Throwable $exception, array $headers = []): self
    {
        $code = (int) $exception->getCode();
        $status = $code >= 400 && $code <= 599 ? $code : 500;

        if (env_get("APP_ENV", null) !== "dev") {
            $headers += ["Content-Type" => "text/plain; charset=utf-8"];
            return new self("Internal Server Error", $status, $headers);
        }

        $args = [$exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), $exception->getTraceAsString()];

        $body = escape(\sprintf("%s: %s\n\nin %s:%d\n\n%s", ...$args), null);
        return new self("<pre>{$body}</pre>", $status, $headers);
    }
}
