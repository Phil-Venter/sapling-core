# Middleware

- [Introduction](#introduction)
- [Writing middleware](#writing-middleware)
    - [Answering early](#answering-early)
- [Registering middleware](#registering-middleware)
    - [For part of the site](#for-part-of-the-site)
    - [For one action](#for-one-action)
- [The order middleware runs in](#the-order-middleware-runs-in)
- [Built-in middleware](#built-in-middleware)
    - [Security headers](#security-headers)
    - [Cross-site request protection](#cross-site-request-protection)
    - [CORS](#cors)
    - [Recommended order](#recommended-order)

## Introduction

Middleware runs around your actions. It sees the request on the way in and the response on the way out, and can answer without calling the action. Use it for logins, headers and other checks that cover many routes.

## Writing middleware

Middleware is a class whose `__invoke()` takes the request and `$next`. Call `$next` to run the rest of the app:

```php
use Closure;
use Sapling\Http\Request;
use Sapling\Http\Response;

final class NoIndex
{
    public function __invoke(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $response->withHeader('X-Robots-Tag', 'noindex');
    }
}
```

Its constructor arguments come from the [container](container.md).

### Answering early

Return a response without calling `$next`, or call `abort()`:

```php
final class RequireLogin
{
    public function __construct(private Session $session) {}

    public function __invoke(Request $request, Closure $next): Response
    {
        if ($this->session->get('user_id')->raw === null) {
            return Response::redirect('/login');
        }

        return $next($request);
    }
}
```

An `HttpException` reaches the middleware around it as a response, so middleware doesn't need to catch it.

## Registering middleware

Add middleware for every request in `bootstrap.php`, so tests get it too:

```php
$app->middleware(NoIndex::class);
```

It also takes an object or a closure:

```php
$app->middleware(new SecurityHeaders(['script-src' => "'self' https://unpkg.com"]));

$app->middleware(function (Request $request, Closure $next): Response {
    return $next($request)->withHeader('Cache-Control', 'no-store');
});
```

### For part of the site

`prefix` limits middleware to a path and the paths below it. `/api` covers `/api/notes` but not `/apis`:

```php
$app->middleware(Cors::class, prefix: '/api');
```

### For one action

```php
#[Route('GET', '/admin/users')]
#[Middleware(RequireAdmin::class)]
final class ListUsers
```

## The order middleware runs in

1. Middleware from `$app->middleware()`, in the order it was added. The first is outermost.
2. Routing, so 404s and 405s pass through the middleware above.
3. The action's `#[Middleware]`, top to bottom.
4. The action. Its constructor runs only now, after any login check.

## Built-in middleware

| Middleware | What it does | Settings |
| --- | --- | --- |
| `SecurityHeaders` | Sets the browser security headers and a content policy | Policy directives, passed to its constructor |
| `Csrf` | Refuses writes that a browser sent from another site | `cors_origins`, which may write too |
| `Cors` | Lets sites on other domains call the app from a browser | `cors_origins`, `cors_credentials`, `cors_expose_headers` |

They're in `Sapling\Http\Middleware`. A new app's `bootstrap.php` adds `SecurityHeaders` and `Csrf`.

### Security headers

`SecurityHeaders` sends `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and a content policy. Scripts may only come from your site. Styles, images and fonts may also come from any HTTPS site, so a CSS framework from a CDN works.

> [!WARNING]
> Inline code is blocked, and browsers only report it in the console. That includes `<script>` and `<style>` blocks, `style` and `onclick` attributes, and libraries such as Alpine.js that evaluate strings.

Directives you pass replace the default of the same name:

```php
$app->middleware(new SecurityHeaders(['script-src' => "'self' https://unpkg.com"]));
```

`SecurityHeaders::POLICY` holds the defaults.

### Cross-site request protection

`Csrf` answers a POST, PUT, PATCH or DELETE from another site with a 403. It reads the browser's `Sec-Fetch-Site` header, or `Origin` in older browsers, so forms need no token.

Requests with neither header, such as from scripts, pass unless they carry the session cookie. Sites in `cors_origins` may write too.

### CORS

`Cors` lets the sites in `cors_origins` call the app from a browser. It answers preflight requests and adds the CORS headers:

```php
// config.php
'cors_origins' => ['https://app.example'],
'cors_credentials' => true,
'cors_expose_headers' => ['Location'],
```

Write sites in lowercase, without a trailing slash, or use `*` for any site. `cors_credentials` allows cookies and can't be used with `*`. `cors_expose_headers` lists the headers scripts may read.

An invalid setting throws an `InvalidArgumentException` the first time `Cors` runs.

### Recommended order

```php
$app->middleware(SecurityHeaders::class);       // headers on every response, refusals included
$app->middleware(Cors::class, prefix: '/api');  // answers preflights before anything refuses them
$app->middleware(Csrf::class);                  // refuses cross-site writes before your code runs
$app->middleware(RequireLogin::class);          // then your own
```

> [!WARNING]
> Put login checks after `Cors`. A preflight carries no cookie or token, and a refused preflight blocks the real request.
