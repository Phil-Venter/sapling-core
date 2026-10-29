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

Sapling sorts what goes wrong into two kinds. An error is an `HttpException` for something the visitor caused, such as asking for a page that doesn't exist, and becomes a response with its status. A crash is anything else thrown, usually a bug, and is logged and answered with a 500, which shows a stack trace only in debug mode.

## Errors the visitor causes

When the visitor asks for something the app can't give, `abort()` stops the request with an error status from anywhere in the app:

```php
if ($note === null) abort(404, 'That note does not exist.');

if ($note['user_id'] !== $userId) abort(403);
```

Without a message, the response uses the standard phrase for the status, such as Forbidden. The router throws the same kind of error for a 404 or a 405. Either way, the error passes back out through the middleware [as a response](middleware.md#answering-early), and gets the [security headers](middleware.md#security-headers) like any other.

> [!WARNING]
> The message is shown to the visitor.

### Sending headers with an error

Some statuses come with a header, such as `Retry-After` for a 503, which `abort()` takes as its third argument:

```php
abort(503, 'Down for maintenance.', ['Retry-After' => '600']);
```

## Showing your own error page

By default the error goes back as plain text. `onError()` sets what answers it instead, as a class, an object or a closure that takes the exception and the request:

```php
use Sapling\Http\HttpException;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Template;

$app->onError(function (HttpException $e, Request $request) use ($app): Response {
    $view = $app->container->get(Template::class);
    $html = $view->render('error', ['status' => $e->status, 'message' => $e->getMessage()]);

    return Response::html($html, $e->status);
});
```

The exception's headers, such as `Allow` on a 405, are added to the handler's response, unless the handler set one of the same name.

### Answering in JSON

The handler also gets the request, which lets it answer paths under `/api/` with JSON:

```php
use Sapling\Http\HttpException;
use Sapling\Http\Request;
use Sapling\Http\Response;

$app->onError(function (HttpException $e, Request $request): Response {
    if (str_starts_with($request->path, '/api/')) {
        return Response::json(['error' => $e->getMessage()], $e->status);
    }

    return Response::error($e);
});
```

## Crashes

Any other exception is a crash. It's logged, and outside debug mode your error handler answers it as an `HttpException` with status 500, whose `getPrevious()` returns the crash. The default handler sends a plain-text "Internal Server Error", which is also what the visitor gets when the handler itself crashes.

### Debug mode

While you're working, set `debug` to true in [`config.php`](config.md), as the skeleton does when `.env` has `APP_ENV=dev`. In debug mode, a crash skips your error handler and shows the code around the error and a stack trace, with the frames outside your app folded away.

### Warnings and notices

Inside a request or a command, PHP's warnings and notices throw an `ErrorException` and crash like any other bug. Reading an undefined array key crashes, for example, instead of giving `null`.

A missing file makes `file_get_contents()` throw before a `=== false` check can run, so check with `is_file()` first. Warnings in `bootstrap.php` and `config.php` behave as PHP's usually do, since both run before Sapling turns warnings into exceptions.

Deprecation notices don't throw, and PHP reports them as usual. Fatal errors, such as running out of memory, are left to PHP too.

## Logging

Sapling writes one line per crash or 5xx `HttpException` with PHP's `error_log()`. When the error was thrown inside Sapling or a package, the log line also names the line of your own code that led there:

```text
GET /notes/7 failed: RuntimeException: File not found: /srv/app/files/7.pdf in /srv/app/vendor/sapling/core/src/Http/Response.php:55 from /srv/app/app/Notes/Download.php:21
```

### Where the log goes

By default, PHP sends the lines to the web server's log. A command [prints a crash](commands.md#exit-codes-and-errors) to standard error, and only writes a log line when `error_log` is set. For a file per day, set `error_log` in `bootstrap.php`:

```php
ini_set('error_log', __DIR__ . '/logs/app-' . gmdate('Y-m-d') . '.log');
```

## Method reference

| Method | What it does |
| --- | --- |
| `abort($status, $message = '', $headers = [], $previous = null)` | Throws an `HttpException`, which becomes the response |
| `$e->status`, `$e->headers` | The status, and the headers Sapling adds to the response |
| `new HttpException($status, $message = '', $headers = [], $previous = null)` | An error status. Without a message, it uses the standard phrase, such as Not Found |
| `$app->onError($handler)` | Sets what turns an `HttpException`, or a crash outside debug mode, into a response |
| `Response::error($e)` | The default answer, in plain text with the error's status and message |
