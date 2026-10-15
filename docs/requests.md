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
    - [Redirects](#redirects)
    - [Error responses](#error-responses)
- [File downloads](#file-downloads)
    - [Serving large files](#serving-large-files)
- [Reading a response](#reading-a-response)
- [Method reference](#method-reference)

## Introduction

An action receives a `Sapling\Http\Request` and returns a `Sapling\Http\Response`. Form, JSON and XML bodies are all read the same way.

## The request lifecycle

1. The web server runs `public/index.php`, which loads [`bootstrap.php`](app.md#booting-the-app).
2. `$app->run()` builds a `Request` from PHP's globals. It tidies the path and applies a form's [`_method`](#sending-put-patch-and-delete-from-forms).
3. The middleware from `$app->middleware()` runs, in the order it was added.
4. The [router](routing.md#how-routes-match) finds the action, or answers with a 404 or 405.
5. The action's [`#[Middleware]`](routing.md#route-middleware) runs.
6. The [container](container.md) builds the action, and `__invoke()` is called with the request.
7. The response passes back out through each middleware.
8. `run()` sends the response, without the body for a HEAD request.

An `HttpException`, such as from `abort()`, becomes a response where it's thrown and passes back out the same way. Any other exception becomes a 500. See [When things go wrong](errors.md).

## Accessing the request

Add a `Request` parameter to `__invoke()`:

```php
use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('POST', '/notes')]
final class StoreNote
{
    public function __invoke(Request $request): Response
    {
        $body = $request->input('body')->string();

        // ...

        return Response::redirect('/notes');
    }
}
```

`$request->method` is the HTTP method, `$request->path` the path without its query string, and `$request->ip` the address that connected. The path is tidied before routing, so `//notes/` becomes `/notes`.

> [!NOTE]
> Behind a proxy or load balancer, `$request->ip` is the proxy's address.

## Retrieving input

Every input method returns a [`Value`](values.md). Read it as the type you need, with a default:

```php
$page = $request->query('page')->int(1);
```

A crafted `body[]=x` where you expect text gives the default, not an array.

### Route parameters

```php
$id = $request->param('id')->int();
```

`$request->params` holds them all.

### The query string

```php
$search = $request->query('q')->string();
$tags = $request->query('tags')->array();   // ?tags[]=php&tags[]=sql
```

### Body fields

`input()` reads a field from the body, decoded by its `Content-Type`:

```php
$title = $request->input('title')->string();
$remember = $request->input('remember')->bool();
$city = $request->input('address')->get('city')->string();
```

The body is decoded on first read, including form-encoded PUT, PATCH and DELETE requests, which PHP leaves alone. `input()` doesn't read the query string.

> [!WARNING]
> A JSON or XML body that doesn't decode is a 400.

### The whole body

`data()` returns the decoded body. `input('title')` is short for `data()->get('title')`:

```php
$items = $request->data()->array();   // a JSON list
$price = $request->data()->get('soap:Body', 'Price')->string();
```

Other content types come back as a string. `$request->body` holds the raw body.

### Headers and cookies

Header names are case-insensitive:

```php
$accept = $request->header('Accept')->string();
$theme = $request->cookie('theme')->string('light');
```

### Uploaded files

`$request->files` is PHP's `$_FILES`:

```php
$avatar = $request->files['avatar'] ?? null;
```

## Sending PUT, PATCH and DELETE from forms

HTML forms only send GET and POST. A hidden `_method` field turns a POST into PUT, PATCH or DELETE:

```html
<form method="post" action="/notes/7">
    <input type="hidden" name="_method" value="DELETE">
    <button>Delete</button>
</form>
```

Other values are ignored.

## Creating responses

The static helpers set the `Content-Type`:

```php
return Response::html($html);
return Response::text('Not found', 404);
return Response::json(['saved' => true], 201);
```

Or build one yourself:

```php
return new Response($xml, 200, ['Content-Type' => 'application/xml']);
```

> [!WARNING]
> `Response::html()` doesn't escape anything. Use `e()` or a [template](templates.md).

### Attaching headers

`withHeader()` returns a copy with the header set, replacing any header of the same name:

```php
return Response::json($note)->withHeader('Cache-Control', 'no-store');
```

> [!NOTE]
> Responses are immutable. On PHP 8.5, ignoring the result of `withHeader()` is an error.

### Redirects

```php
return Response::redirect('/notes');
return Response::redirect('/notes/7', 303);
```

> [!TIP]
> Redirect after a successful POST, so a reload doesn't resend the form.

### Error responses

`abort()` stops the request with an error status from anywhere, including middleware and templates:

```php
abort_if($note === null, 404, 'That note does not exist.');
```

See [When things go wrong](errors.md) for rendering your own error page.

## File downloads

`Response::file()` streams a file from disk as a download:

```php
return Response::file($path, 'report.pdf');
```

> [!WARNING]
> Never send uploaded files inline. An uploaded HTML or SVG file would run as part of your site.

### Serving large files

`file()` holds a PHP worker until the download ends. For large or popular files, hard-link the file into a random folder under `public/` and redirect to it, so the web server sends it:

```php
function publish(string $file, string $public): string
{
    $folder = 'downloads/' . bin2hex(random_bytes(16));
    mkdir("{$public}/{$folder}", 0755, true);
    link($file, "{$public}/{$folder}/" . basename($file));

    return "/{$folder}/" . rawurlencode(basename($file));
}

return Response::redirect(publish($path, $this->app->path('public')));
```

Both folders must be on the same disk. Anyone with the link can download the file until it's deleted. Clear old folders from cron:

```sh
find public/downloads -mindepth 1 -maxdepth 1 -type d -mmin +60 -exec rm -rf {} +
```

## Reading a response

Middleware and tests can read a response's `status`, `headers` and `body`. `data()` decodes the body like a request's `data()`, and `header()` reads a header case-insensitively:

```php
$response = $app->handle(new Request('GET', '/api/notes/7'));

$title = $response->data()->get('title')->string();
$location = $response->header('location')->string();
```

An empty body is a missing value. A JSON or XML body that doesn't decode throws, as [`Data`](data.md) does. [`HttpClient`](http-client.md) replies are responses too.

## Method reference

### Request

| Method | What it returns |
| --- | --- |
| `param($name)` | A `:name` parameter from the route path, as a `Value` |
| `query($name)` | A query string value, as a `Value` |
| `input($name)` | A field from the body, as a `Value`. Short for `data()->get($name)` |
| `data()` | The whole body, decoded by its `Content-Type` as JSON, XML or a form, as a `Value` |
| `header($name)` | A header, case-insensitive, as a `Value` |
| `cookie($name)` | A cookie, as a `Value` |
| `$request->method`, `$request->path` | The method, and the path without its query string |
| `$request->params` | Every route parameter, by name |
| `$request->body` | The raw body |
| `$request->files` | Uploaded files, as PHP's `$_FILES` holds them |
| `$request->ip` | The address that connected |
| `new Request($method, $path, ...)` | A request built by hand, for tests |

### Response

| Method | What it does |
| --- | --- |
| `Response::html($html, $status = 200)` | Returns an HTML response |
| `Response::text($text, $status = 200)` | Returns a plain text response |
| `Response::json($data, $status = 200)` | Returns JSON. Throws `JsonException` when the data can't be encoded |
| `Response::redirect($url, $status = 302)` | Returns a redirect |
| `Response::file($path, $name = null)` | Returns a download, read from disk while it's sent |
| `Response::fromHttpException($e)` | Returns plain text with the exception's status, message and headers |
| `new Response($body, $status = 200, $headers = [])` | Returns any other response |
| `withHeader($name, $value)` | Returns a copy with the header set, replacing one of the same name |
| `data()` | Returns the body decoded by its `Content-Type`, as a `Value` |
| `header($name)` | Returns a header, case-insensitive, as a `Value`. A header sent more than once is a list |
| `send($body = true)` | Sends the response. `App::run()` does this for you |
| `$response->status`, `headers`, `body` | Readable. They change only through `withHeader()` |
