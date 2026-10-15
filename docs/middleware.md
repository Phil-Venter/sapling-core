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

Some work applies to many routes at once, such as checking a login, adding headers or refusing cross-site requests. Middleware keeps that code out of each action. It runs around your actions, seeing the request on the way in and the response on the way out, and it can answer without calling the action at all.

## Writing middleware

Middleware is a class whose `__invoke()` takes the request and `$next`, a closure that runs the rest of the app and returns its response. This one adds a header to every response it sees:

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

Like an action, middleware gets its constructor arguments from the [container](container.md).

### Answering early

Middleware doesn't have to call `$next`. Returning a response of its own stops the request there, which is how a login check works:

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

Calling `abort()` stops the request too. The `HttpException` reaches the middleware around it as a response, so middleware never has to catch one.

## Registering middleware

Middleware is added in `bootstrap.php`, so the server and your tests run the same layers. `$app->middleware()` adds it around every request, and takes a class name, an object or a closure:

```php
$app->middleware(NoIndex::class);

$app->middleware(new SecurityHeaders(['script-src' => "'self' https://unpkg.com"]));

$app->middleware(function (Request $request, Closure $next): Response {
    return $next($request)->withHeader('Cache-Control', 'no-store');
});
```

### For part of the site

A `prefix` limits middleware to a path and the paths below it, so `/api` covers `/api/notes` but not `/apis`:

```php
$app->middleware(Cors::class, prefix: '/api');
```

### For one action

For a single action, [`#[Middleware]`](routing.md#route-middleware) on the class runs middleware around its routes only:

```php
#[Route('GET', '/admin/users')]
#[Middleware(RequireAdmin::class)]
final class ListUsers
```

## The order middleware runs in

With middleware coming from two places, the order matters. For each request it is:

1. Middleware from `$app->middleware()`, in the order it was added. The first is outermost.
2. Routing, so 404s and 405s pass through the middleware above.
3. The action's `#[Middleware]`, top to bottom.
4. The action. Its constructor runs only now, after any login check.

## Built-in middleware

| Middleware | What it does | Settings |
| --- | --- | --- |
| `SecurityHeaders` | Sets the browser security headers and a content policy | Policy directives, passed to its constructor |
| `Csrf` | Refuses writes that a browser sent from another site | `cors_origins`, which may write too |
| `Cors` | Lets sites on other domains call the app from a browser | `cors_origins`, `cors_credentials` |

Sapling comes with three, in `Sapling\Http\Middleware`. A new app's `bootstrap.php` already adds `SecurityHeaders` and `Csrf`, while `Cors` is only needed when other sites call your app.

### Security headers

`SecurityHeaders` sends `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and a content policy. The policy lets scripts come only from your own site, while styles, images and fonts may also come from any HTTPS site, so a CSS framework from a CDN still works.

> [!WARNING]
> Inline code is blocked, and browsers only report it in the console. That includes `<script>` and `<style>` blocks, `style` and `onclick` attributes, and libraries such as Alpine.js that evaluate strings.

To loosen the policy, pass the directives you want. Each replaces the default of the same name and leaves the rest:

```php
$app->middleware(new SecurityHeaders(['script-src' => "'self' https://unpkg.com"]));
```

`SecurityHeaders::POLICY` holds the defaults.

### Cross-site request protection

`Csrf` refuses a POST, PUT, PATCH or DELETE that a browser sent from another site, with a 403. Browsers say where a request comes from in the `Sec-Fetch-Site` header, or in `Origin` when they're older, so your forms need no token.

Scripts and servers send neither header, so their requests pass, unless they carry the session cookie as a forged browser request would. Sites listed in `cors_origins` may write too, so a front end on another domain only has to be listed once.

### CORS

`Cors` lets the sites in `cors_origins` call your app from a browser. It answers the browser's preflight requests and adds the CORS headers to responses:

```php
// config.php
'cors_origins' => ['https://app.example'],
'cors_credentials' => true,
```

Write each site as browsers send it, in lowercase and without a trailing slash, or use `*` for any site. `cors_credentials` lets those sites send cookies, which browsers only allow for listed sites, not `*`. A setting that could never work throws an `InvalidArgumentException` the first time `Cors` runs.

### Recommended order

Used together, the built-in middleware goes first, then your own:

```php
$app->middleware(SecurityHeaders::class);       // headers on every response, refusals included
$app->middleware(Cors::class, prefix: '/api');  // answers preflights before anything refuses them
$app->middleware(Csrf::class);                  // refuses cross-site writes before your code runs
$app->middleware(RequireLogin::class);          // then your own
```

> [!WARNING]
> Put login checks after `Cors`. A preflight carries no cookie or token, so a login check in front of `Cors` refuses it, and the browser then blocks the real request.
