<?php

namespace Sapling\Core;

final class Response
{
    private const array MULTILINE_HEADERS = [
        'set-cookie',
        'www-authenticate',
        'proxy-authenticate',
        'warning',
        'link',
    ];

    public function __construct(
        private(set) string|\Stringable $body = "",
        private(set) int $status = 200,
        private(set) array $headers = []
    ) {
        $this->headers += [
            "Content-Type" => "text/html; charset=utf-8",
            "X-Content-Type-Options" => "nosniff",
        ];
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $key => $value) {
                $key = trim((string) $key);

                if ($key === '' || $value === '' || $value === null || $value === []) {
                    continue;
                }

                if (!in_array(strtolower($key), self::MULTILINE_HEADERS, true)) {
                    header(sprintf("%s: %s", $key, implode(", ", (array) $value)), true);
                    continue;
                }

                foreach ((array) $value as $v) {
                    header(sprintf("%s: %s", $key, $v), false);
                }
            }
        }

        if (($_SERVER["REQUEST_METHOD"] ?? "") === "HEAD") {
            return;
        }

        if ($this->status >= 200 && !in_array($this->status, [204, 205, 304], true)) {
            echo $this->body;
        }
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

    public static function exception(\Throwable $exception, array $headers = []): self
    {
        $code = (int) $exception->getCode();
        $status = ($code >= 400 && $code <= 599) ? $code : 500;

        if (Environment::get("APP_ENV") !== "dev") {
            $headers += ["Content-Type" => "text/plain; charset=utf-8"];
            return new self("Internal Server Error", $status, $headers);
        }

        $args = [
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        ];

        $body = e(sprintf("%s: %s\n\nin %s:%d\n\n%s", ...$args));
        return new self("<pre>{$body}</pre>", $status, $headers);
    }
}
