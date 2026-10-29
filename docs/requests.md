# Requests and responses

- [Introduction](#introduction)
- [The request lifecycle](#the-request-lifecycle)
- [Accessing the request](#accessing-the-request)
- [Retrieving input](#retrieving-input)
    - [Route parameters](#route-parameters)
    - [The query string](#the-query-string)
    - [Body fields](#body-fields)
    - [The whole body](#the-whole-body)
    - [Headers and cookies](#headers-and-cookies)
    - [Uploaded files](#uploaded-files)
- [Sending PUT, PATCH and DELETE from forms](#sending-put-patch-and-delete-from-forms)
- [Creating responses](#creating-responses)
    - [Attaching headers](#attaching-headers)
    - [Setting cookies](#setting-cookies)
    - [Redirects](#redirects)
    - [Error responses](#error-responses)
- [File downloads](#file-downloads)
    - [Serving large files](#serving-large-files)
- [Reading a response](#reading-a-response)
- [Method reference](#method-reference)
    - [Request](#request)
    - [Response](#response)

## Introduction

Every action receives the incoming request as a `Sapling\Http\Request` and returns a `Sapling\Http\Response`. Whatever you read from the request, apart from uploads, comes back as a typed value with a default, whether it was in the URL, a header, or a form, JSON or XML body.

## The request lifecycle

A request takes this path through the app:

1. The web server runs `public/index.php`, which loads [`bootstrap.php`](app.md#booting-the-app).
2. `$app->run()` builds a `Request` from PHP's globals. It tidies the path and applies a form's [`_method`](#sending-put-patch-and-delete-from-forms).
3. The middleware from `$app->middleware()` runs, in the order it was added.
4. The [router](routing.md#how-routes-match) finds the action, or answers with a 404 or 405.
5. The action's [`#[Middleware]`](routing.md#route-middleware) runs.
6. The [container](container.md) builds the action, and `__invoke()` is called with the request.
7. The response passes back out through each middleware.
8. `run()` sends the response, without the body for a HEAD request.

An `HttpException`, such as one from `abort()`, becomes a response where it's thrown, and any other exception becomes a 500, as [When things go wrong](errors.md) explains.

## Accessing the request

An action gets the request by declaring a `Request` parameter on `__invoke()`:

```php
use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('POST', '/notes')]
final class StoreNote
{
    public function __invoke(Request $request): Response
    {
        $body = $request->data('body')->string();

        // ...

        return Response::redirect('/notes');
    }
}
```

The request also says where it came from. `$request->method` holds the HTTP method and `$request->ip` the visitor's address. `$request->path` holds the path without its query string, tidied before routing so that `//notes/` reads as `/notes`.

> [!NOTE]
> Behind a proxy or load balancer, `$request->ip` is the proxy's address until you list the proxy in `trusted_proxies`. Sapling then reads the visitor's address from `X-Forwarded-For`, which it only trusts from a listed proxy. Behind more than one, such as a CDN in front of a load balancer, list them all, each by its exact address. A range such as `10.0.0.0/16` never matches. `['*']` trusts whichever proxy connects, for one whose address changes, and takes the address that proxy added.

## Retrieving input

Every part of the request apart from uploads comes back as a [`Value`](values.md), which you read as the type you need, with a default for when it's missing:

```php
$page = $request->query('page')->int(1);
```

Crafted input can't change the type, so `string()` gives the default when someone sends `body[]=x` where you expect text. For nested data, `param()`, `query()`, `data()` and `cookie()` take more keys, as [`Value::get()`](values.md#nested-data) does, and with no key they return everything.

### Route parameters

[Route parameters](routing.md#route-parameters), such as `:id`, are read with `param()`, and `$request->params` holds them all.

### The query string

```php
$search = $request->query('q')->string();
$page = $request->query('page')->int(1);
```

Without a name, `query()` returns the whole query string, which lets a link to the next page keep the current filters:

```php
$next = '/notes?' . Data::toForm([...$request->query()->rawArray(), 'page' => $page + 1]);
```

### Body fields

`data()` reads a field from the request body, whether it was sent as JSON, XML or a form:

```php
$title = $request->data('title')->string();
$remember = $request->data('remember')->bool();
$city = $request->data('address', 'city')->string();
```

The body is decoded by its `Content-Type` the first time you read it, even for a form-encoded PUT, PATCH or DELETE, which PHP itself leaves alone. `data()` never falls back to the query string, and a JSON or XML body that doesn't decode stops the request with a 400.

### The whole body

Without a name, `data()` returns the whole decoded body, such as a JSON list or a SOAP envelope, for when you need more than one field:

```php
$items = $request->data()->items();   // each item of a JSON list as a Value
$price = $request->data()->get('soap:Body', 'Price')->string();
```

A body of any other type, such as plain text, comes back as a string. The raw body is always in `$request->body`.

### Headers and cookies

Headers and cookies are read the same way, and header names are case-insensitive:

```php
$accept = $request->header('Accept')->string();
$theme = $request->cookie('theme')->string('light');
```

A cookie is set on the response instead, as [Setting cookies](#setting-cookies) shows.

### Uploaded files

Uploads are the one part of the request that isn't a `Value`. `$request->files` holds them as PHP's `$_FILES` does:

```php
$avatar = $request->files['avatar'] ?? null;
```

## Sending PUT, PATCH and DELETE from forms

HTML forms can only send GET and POST. To send another method, add a hidden `_method` field to a POST form, and Sapling treats the request as that method:

```html
<form method="post" action="/notes/7">
    <input type="hidden" name="_method" value="DELETE">
    <button>Delete</button>
</form>
```

Only PUT, PATCH and DELETE are accepted, and any other value leaves the request as a POST.

## Creating responses

Every action returns a `Response`. The static helpers cover the common cases and set the `Content-Type`:

```php
return Response::html($html);
return Response::text('Not found', 404);
return Response::json(['saved' => true], 201);
```

For anything else, build the response yourself:

```php
return new Response($xml, 200, ['Content-Type' => 'application/xml']);
```

### Attaching headers

A response can't be changed once it's made, so `withHeader()` returns a copy with the header set, replacing any header of the same name:

```php
return Response::json($note)->withHeader('Cache-Control', 'no-store');
```

> [!NOTE]
> On PHP 8.5, ignoring the result of `withHeader()` raises a warning, which Sapling turns into a crash.

### Setting cookies

Sapling only writes the session's cookie, and you set any other with a `Set-Cookie` header. Since a second `withHeader()` replaces the first, set several cookies with a list:

```php
return $response->withHeader('Set-Cookie', ['theme=dark; Path=/', 'lang=en; Path=/']);
```

### Redirects

`redirect()` sends a 302 unless you pass another status:

```php
return Response::redirect('/notes');
return Response::redirect('/notes/7', 303);
```

> [!WARNING]
> `redirect()` sends the browser to any URL it's given. When the visitor names the target, as with a `?next=` page after signing in, that can be another site.

To keep the target on your own site, accept only a path:

```php
$next = $request->query('next')->string('/');

// Browsers read //evil.example as another site, and /\evil.example or a tab or newline in the path can turn into it.
if (!str_starts_with($next, '/') || str_starts_with($next, '//') || strpbrk($next, "\\\t\r\n") !== false) {
    $next = '/';
}

return Response::redirect($next);
```

### Error responses

[`abort()`](errors.md#errors-the-visitor-causes) stops the request with an error status from anywhere, middleware and templates included.

## File downloads

`Response::file()` sends a file as a download, streaming it from disk instead of loading it into memory:

```php
return Response::file($path, 'report.pdf');
```

A missing file throws a `RuntimeException`, which is a crash. When a file can go missing, such as one a database row points at, check it first with `if (!is_file($path)) abort(404);` to answer with a 404 instead.

### Serving large files

Streaming still keeps a PHP worker busy until the download finishes, and a download behind a login check also holds the [session lock](sessions.md#using-the-session) until it ends. For large or popular files, let the web server send them. This function hard-links the file into a random folder under `public/` and returns its URL:

```php
function publish(string $file, string $public): string
{
    $folder = 'downloads/' . bin2hex(random_bytes(16));
    mkdir("{$public}/{$folder}", 0o755, true);
    link($file, "{$public}/{$folder}/" . basename($file));

    return "/{$folder}/" . rawurlencode(basename($file));
}
```

The action [asks for `App`](container.md#asking-for-what-you-need) in its constructor, as `private App $app`, to find `public/`, and redirects to the URL:

```php
return Response::redirect(publish($path, $this->app->path('public')));
```

A hard link is instant and uses no extra space, but both folders must be on the same disk. Anyone with the URL can download the file until its folder is deleted, so clear old folders from cron:

```sh
find public/downloads -mindepth 1 -maxdepth 1 -type d -mmin +60 -exec rm -rf {} +
```

## Reading a response

Middleware reads the response the action returned, and tests read the one `handle()` gives back. Besides `status`, `headers` and `body`, a response has `data()` and `header()`, which work as they do on the request:

```php
$response = $app->handle(new Request('GET', '/api/notes/7'));

$title = $response->data('title')->string();
$location = $response->header('location')->string();
```

An empty body reads as a missing value, and a JSON or XML body that doesn't decode throws, as [`Data`](data.md) does.

## Method reference

### Request

| Method | What it returns |
| --- | --- |
| `param(...$keys)` | A `:name` parameter from the route path, as a `Value` |
| `query(...$keys)` | A query string value, as a `Value` |
| `data(...$keys)` | A field from the body, decoded by its `Content-Type` as JSON, XML or a form, as a `Value` |
| `header($name)` | A header, case-insensitive, as a `Value` |
| `cookie(...$keys)` | A cookie, as a `Value`. Like `param()`, `query()` and `data()`, more keys read deeper and no key gives all of them |
| `$request->method`, `$request->path` | The method, and the path without its query string |
| `$request->params` | Every route parameter, by name |
| `$request->body` | The raw body |
| `$request->files` | Uploaded files, as PHP's `$_FILES` holds them |
| `$request->ip` | The visitor's address, or the proxy's until the proxy is listed in `trusted_proxies` |
| `new Request($method, $path, ...)` | A request built by hand, for tests |

### Response

| Method | What it does |
| --- | --- |
| `Response::html($html, $status = 200)` | Returns an HTML response |
| `Response::text($text, $status = 200)` | Returns a plain text response |
| `Response::json($data, $status = 200)` | Returns JSON. Throws `JsonException` when the data can't be encoded |
| `Response::redirect($url, $status = 302)` | Returns a redirect |
| `Response::file($path, $name = null)` | Returns a download, read from disk while it's sent. Throws `RuntimeException` when the file is missing |
| `Response::error($e)` | Returns plain text with an `HttpException`'s status and message, for an [error handler](errors.md#showing-your-own-error-page) |
| `header($name)` | Returns a header, case-insensitive, as a `Value`. One set to a list, such as several `Set-Cookie` values, is read with `rawArray()` |
| `withHeader($name, $value)` | Returns a copy with the header set, replacing one of the same name |
| `data(...$keys)` | Returns a field from the body decoded by its `Content-Type`, as a `Value`. More keys read deeper, and no key gives the whole body |
| `send($body = true)` | Sends the response. `App::run()` calls it |
| `$response->status`, `headers`, `body`, `file` | Read-only. `headers` keeps each name as it was set, and `file` holds a download's path |
| `new Response($body, $status = 200, $headers = [])` | Builds any other response |
