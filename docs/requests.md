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

Every action receives the incoming request as a `Sapling\Http\Request` and returns a `Sapling\Http\Response`. Reading input always gives you a typed value with a default, and a form, JSON or XML body is read the same way.

## The request lifecycle

Before looking at the two objects, it helps to know the path a request takes through the app:

1. The web server runs `public/index.php`, which loads [`bootstrap.php`](app.md#booting-the-app).
2. `$app->run()` builds a `Request` from PHP's globals. It tidies the path and applies a form's [`_method`](#sending-put-patch-and-delete-from-forms).
3. The middleware from `$app->middleware()` runs, in the order it was added.
4. The [router](routing.md#how-routes-match) finds the action, or answers with a 404 or 405.
5. The action's [`#[Middleware]`](routing.md#route-middleware) runs.
6. The [container](container.md) builds the action, and `__invoke()` is called with the request.
7. The response passes back out through each middleware.
8. `run()` sends the response, without the body for a HEAD request.

Errors take the same route back. An `HttpException`, such as one from `abort()`, becomes a response where it's thrown and passes back out through the middleware, while any other exception becomes a 500. [When things go wrong](errors.md) covers both.

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
        $body = $request->input('body')->string();

        // ...

        return Response::redirect('/notes');
    }
}
```

Besides input, the request says where it came from. `$request->method` is the HTTP method, `$request->path` is the path without its query string, and `$request->ip` is the address that connected. The path is tidied before routing, so `//notes/` arrives as `/notes`.

> [!NOTE]
> Behind a proxy or load balancer, `$request->ip` is the proxy's address.

## Retrieving input

Whatever part of the request you read, you get a [`Value`](values.md) back, which you then read as the type you need, with a default for when it's missing:

```php
$page = $request->query('page')->int(1);
```

This also protects you from crafted input. If someone sends `body[]=x` where you expect text, `string()` gives the default rather than an array.

### Route parameters

Parameters named in the route, such as `:id`, are read with `param()`, and `$request->params` holds them all:

```php
$id = $request->param('id')->int();
```

### The query string

```php
$search = $request->query('q')->string();
$tags = $request->query('tags')->array();   // ?tags[]=php&tags[]=sql
```

### Body fields

`input()` reads a field from the request body, whichever format it was sent in:

```php
$title = $request->input('title')->string();
$remember = $request->input('remember')->bool();
$city = $request->input('address')->get('city')->string();
```

The body is decoded by its `Content-Type` the first time you read it. That includes form-encoded PUT, PATCH and DELETE requests, which PHP itself leaves alone. `input()` only reads the body, never the query string.

> [!WARNING]
> A JSON or XML body that doesn't decode stops the request with a 400.

### The whole body

When you need more than one field, such as a JSON list or a SOAP envelope, `data()` returns the whole decoded body. `input('title')` is short for `data()->get('title')`:

```php
$items = $request->data()->array();   // a JSON list
$price = $request->data()->get('soap:Body', 'Price')->string();
```

A body of any other type, such as plain text, comes back as a string, and `$request->body` holds the raw body.

### Headers and cookies

Headers and cookies are read the same way. Header names are case-insensitive:

```php
$accept = $request->header('Accept')->string();
$theme = $request->cookie('theme')->string('light');
```

Sapling only writes the session's cookie. To set another, add it with `withHeader('Set-Cookie', '…')`.

### Uploaded files

Uploads don't come through `Value`. `$request->files` holds them exactly as PHP's `$_FILES` does:

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

Every action returns a `Response`. The static helpers cover the common cases and set the `Content-Type` for you:

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

Responses can't be changed once they're made. `withHeader()` returns a copy with the header set instead, replacing any header of the same name:

```php
return Response::json($note)->withHeader('Cache-Control', 'no-store');
```

> [!NOTE]
> On PHP 8.5, ignoring the result of `withHeader()` is an error.

### Redirects

`redirect()` sends a 302 unless you pass another status:

```php
return Response::redirect('/notes');
return Response::redirect('/notes/7', 303);
```

### Error responses

For an error, you don't build a response at all. `abort()` stops the request with an error status from anywhere, including middleware and templates:

```php
abort_if($note === null, 404, 'That note does not exist.');
```

[When things go wrong](errors.md) explains how the error is rendered, and how to show your own error page.

## File downloads

`Response::file()` sends a file as a download. It streams from disk, so large files don't fill memory:

```php
return Response::file($path, 'report.pdf');
```

### Serving large files

Streaming still keeps a PHP worker busy until the download finishes. For large or popular files, it's better to let the web server send them. Hard-link the file into a random folder under `public/` and redirect to it:

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

A hard link is instant and uses no extra space, but both folders must be on the same disk. Anyone with the link can download the file until it's deleted, so clear old folders from cron:

```sh
find public/downloads -mindepth 1 -maxdepth 1 -type d -mmin +60 -exec rm -rf {} +
```

## Reading a response

Responses aren't only written. Middleware reads what the action returned, and tests read what `handle()` gives back. Both can use `status`, `headers` and `body`, and two methods mirror the request's. `data()` decodes the body by its `Content-Type`, and `header()` reads a header case-insensitively:

```php
$response = $app->handle(new Request('GET', '/api/notes/7'));

$title = $response->data()->get('title')->string();
$location = $response->header('location')->string();
```

An empty body reads as a missing value, and a JSON or XML body that doesn't decode throws, as [`Data`](data.md) does. Replies from [`HttpClient`](http-client.md) are responses too.

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
| `Response::fromHttpException($e)` | Returns plain text with the exception's status and message |
| `new Response($body, $status = 200, $headers = [])` | Returns any other response |
| `withHeader($name, $value)` | Returns a copy with the header set, replacing one of the same name |
| `data()` | Returns the body decoded by its `Content-Type`, as a `Value` |
| `header($name)` | Returns a header, case-insensitive, as a `Value`. A header sent more than once is a list |
| `send($body = true)` | Sends the response. `App::run()` does this for you |
| `$response->status`, `headers`, `body` | Readable. They change only through `withHeader()` |
