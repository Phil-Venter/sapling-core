# When things go wrong

- [Introduction](#introduction)
- [Errors the visitor causes](#errors-the-visitor-causes)
    - [Sending headers with an error](#sending-headers-with-an-error)
- [Showing your own error page](#showing-your-own-error-page)
    - [Answering in JSON](#answering-in-json)
- [Crashes](#crashes)
    - [Debug mode](#debug-mode)
- [Warnings and notices](#warnings-and-notices)
- [Errors in commands](#errors-in-commands)
- [Logging](#logging)
    - [Where the log goes](#where-the-log-goes)
- [Method reference](#method-reference)

## Introduction

Sapling turns every error into a response or an exit code. An `HttpException` is an error the visitor caused, such as a missing page. Anything else is a crash, which is logged. Visitors never see a stack trace outside debug mode.

## Errors the visitor causes

`abort()` stops a request with an error status, from anywhere:

```php
abort_if($note === null, 404, 'That note does not exist.');

abort_if($note['user_id'] !== $userId, 403);
```

Without a message, the standard phrase is used, such as Forbidden. The error passes back through the middleware as a response, so error pages get the [security headers](middleware.md#security-headers) too.

> [!WARNING]
> The message is shown to the visitor. Don't pass internal details, such as an exception's message.

> [!TIP]
> Use `abort()` for the visitor's mistakes, and let bugs crash so they're logged.

### Sending headers with an error

The third argument adds headers:

```php
abort(503, 'Down for maintenance.', ['Retry-After' => '600']);
```

## Showing your own error page

By default the error is plain text. `httpExceptionHandler()` replaces that with a class, object or closure that takes the exception and the request:

```php
$app->httpExceptionHandler(function (HttpException $e, Request $request) use ($app): Response {
    $view = $app->container->get(Template::class);
    $html = $view->render('error', ['status' => $e->status, 'message' => $e->getMessage()]);

    return new Response($html, $e->status, ['Content-Type' => 'text/html; charset=utf-8', ...$e->headers]);
});
```

> [!WARNING]
> Include `$e->headers`, or a 405 loses its `Allow` header.

### Answering in JSON

Use the request to answer differently, such as JSON for `/api/`:

```php
$app->httpExceptionHandler(function (HttpException $e, Request $request): Response {
    if (str_starts_with($request->path, '/api/')) {
        $json = Data::toJson(['error' => $e->getMessage()]);

        return new Response($json, $e->status, ['Content-Type' => 'application/json', ...$e->headers]);
    }

    return Response::fromHttpException($e);
});
```

## Crashes

Any other exception is logged and answered with a plain-text 500. It skips your error handler and your middleware.

### Debug mode

With `debug` on, a crash shows the code around the error and a stack trace instead.

> [!WARNING]
> Never enable debug mode in production.

## Warnings and notices

Inside a request or command, PHP warnings and notices throw an `ErrorException`. Reading an undefined array key crashes instead of giving `null`.

So `file_get_contents()` on a missing file throws, and a `=== false` check after it never runs. Check with `is_file()` first. `bootstrap.php` and `config.php` run before this applies.

Deprecations are only logged. Fatal errors are left to PHP.

## Errors in commands

A command that throws is logged, prints the error and exits with 1.

## Logging

Sapling writes one line per crash or 5xx response with `error_log()`. When the error came from Sapling or a package, the line also names your code that called it:

```text
GET /notes/7 failed: RuntimeException: File not found: /srv/app/files/7.pdf in /srv/app/vendor/sapling/core/src/Http/Response.php:68 from /srv/app/app/Notes/Download.php:21
```

### Where the log goes

Lines go to the web server's log, or the terminal for commands. For a file per day, set `error_log` in `bootstrap.php`:

```php
ini_set('error_log', __DIR__ . '/logs/app-' . gmdate('Y-m-d') . '.log');
```

Clear old files from cron with `find logs -name 'app-*.log' -mtime +14 -delete`. For log levels, use Monolog.

## Method reference

| Method | What it does |
| --- | --- |
| `abort($status, $message = '', $headers = [])` | Throws an `HttpException` |
| `abort_if($condition, $status, ...)` | Throws one when the condition is true |
| `new HttpException($status, $message = '', $headers = [], $previous = null)` | An error status. Without a message, it uses the standard phrase, such as Not Found |
| `$e->status`, `$e->headers` | The status and the headers to send with it |
| `$app->httpExceptionHandler($handler)` | Sets what turns an `HttpException` into a response |
| `Response::fromHttpException($e)` | The default answer, in plain text with the exception's status, message and headers |
