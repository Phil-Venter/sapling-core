# When things go wrong

- [Introduction](#introduction)
- [Errors the visitor causes](#errors-the-visitor-causes)
    - [Sending headers with an error](#sending-headers-with-an-error)
- [Showing your own error page](#showing-your-own-error-page)
    - [Answering in JSON](#answering-in-json)
- [Crashes](#crashes)
    - [Debug mode](#debug-mode)
    - [Warnings and notices](#warnings-and-notices)
- [Logging](#logging)
    - [Where the log goes](#where-the-log-goes)
- [Method reference](#method-reference)

## Introduction

Sapling sorts what goes wrong into two kinds. An error is an `HttpException`, something the visitor caused, such as asking for a page that doesn't exist. It becomes a response with that status. A crash is anything else thrown, usually a bug, and it's logged and answered with a 500.

Either way, your actions and middleware need no `try` and `catch`, and outside debug mode a visitor never sees a stack trace.

## Errors the visitor causes

When an action or middleware finds the visitor asked for something it can't give, `abort()` stops the request with an error status, from anywhere:

```php
abort_if($note === null, 404, 'That note does not exist.');

abort_if($note['user_id'] !== $userId, 403);
```

Without a message, the response uses the standard phrase for the status, such as Forbidden. The router throws the same kind of error for a 404 or a 405. The error then passes back out through the middleware as a response, so error pages get the [security headers](middleware.md#security-headers) like any other.

> [!WARNING]
> The message is shown to the visitor, so keep internal details, like another exception's message, out of it.

### Sending headers with an error

Some statuses come with a header, such as `Retry-After` for a 503. The third argument adds them:

```php
abort(503, 'Down for maintenance.', ['Retry-After' => '600']);
```

## Showing your own error page

By default the error goes back as plain text, which is fine for an API but bare for a website. `onError()` sets what answers it instead, as a class, an object or a closure that takes the exception and the request:

```php
$app->onError(function (HttpException $e, Request $request) use ($app): Response {
    $view = $app->container->get(Template::class);
    $html = $view->render('error', ['status' => $e->status, 'message' => $e->getMessage()]);

    return Response::html($html, $e->status);
});
```

The exception's headers, like `Allow` on a 405, are added to the handler's response. A header the handler sets wins.

### Answering in JSON

The handler also gets the request, so one handler can answer in more than one way, such as JSON for paths under `/api/`:

```php
$app->onError(function (HttpException $e, Request $request): Response {
    if (str_starts_with($request->path, '/api/')) {
        return Response::json(['error' => $e->getMessage()], $e->status);
    }

    return Response::error($e);
});
```

## Crashes

Any other exception is a crash. It's logged, and the visitor gets a plain-text 500 "Internal Server Error". A crash skips your error handler, and the middleware doesn't add its headers to it.

### Debug mode

While you're working, set `debug` in the [config](config.md). A crash then shows the code around the error and a stack trace, with the frames outside your app folded away.

### Warnings and notices

Inside a request or a command, PHP's warnings and notices throw an `ErrorException` and crash like any other bug. Reading an undefined array key crashes, for example, instead of giving `null`.

`file_get_contents()` on a missing file throws, so a `=== false` check after it never runs. Check with `is_file()` first. `bootstrap.php` and `config.php` run before this applies, so warnings there behave as PHP's usually do.

Deprecation notices don't throw, so a PHP upgrade can't take your pages down. PHP still reports them. Fatal errors, such as running out of memory, are left to PHP.

## Logging

Sapling writes one line per crash or 5xx response with PHP's `error_log()`. When the error was thrown inside Sapling or a package, the line also names the line of your own code that led there, which is usually where the fix belongs:

```text
GET /notes/7 failed: RuntimeException: File not found: /srv/app/files/7.pdf in /srv/app/vendor/sapling/core/src/Http/Response.php:72 from /srv/app/app/Notes/Download.php:21
```

### Where the log goes

By default, PHP sends the lines to the web server's log, or to the terminal for commands. For a file per day instead, set `error_log` in `bootstrap.php`:

```php
ini_set('error_log', __DIR__ . '/logs/app-' . gmdate('Y-m-d') . '.log');
```

Old files can then be cleared from cron with `find logs -name 'app-*.log' -mtime +14 -delete`. For log levels or other destinations, use Monolog.

## Method reference

| Method | What it does |
| --- | --- |
| `abort($status, $message = '', $headers = [])` | Throws an `HttpException` |
| `abort_if($condition, $status, ...)` | Throws one when the condition is true |
| `new HttpException($status, $message = '', $headers = [], $previous = null)` | An error status. Without a message, it uses the standard phrase, such as Not Found |
| `$e->status`, `$e->headers` | The status, and the headers Sapling adds to the response |
| `$app->onError($handler)` | Sets what turns an `HttpException` into a response |
| `Response::error($e)` | The default answer, in plain text with the error's status and message |
