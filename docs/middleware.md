# Middleware

- [Introduction](#introduction)
- [Writing middleware](#writing-middleware)
    - [Answering early](#answering-early)
    - [Passing data to the action](#passing-data-to-the-action)
- [Registering middleware](#registering-middleware)
    - [For part of the site](#for-part-of-the-site)
    - [The order middleware runs in](#the-order-middleware-runs-in)
- [Built-in middleware](#built-in-middleware)
    - [Security headers](#security-headers)
    - [Cross-site request protection](#cross-site-request-protection)
    - [CORS](#cors)
    - [Recommended order](#recommended-order)
- [Method reference](#method-reference)

## Introduction

Middleware runs around your actions, seeing the request on the way in and the response on the way out, and it can answer without calling the action at all. A login check or a header every response needs goes here instead of in each action.

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

Middleware doesn't have to call `$next`. Returning its own response stops the request there, as a login check does:

```php
use Closure;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Http\Session;

final class RequireLogin
{
    public function __construct(private Session $session) {}

    public function __invoke(Request $request, Closure $next): Response
    {
        if ($this->session->get('user_id')->int() === 0) {
            return Response::redirect('/login');
        }

        return $next($request);
    }
}
```

Calling `abort()` stops the request too, and the middleware around the code that called it sees the `HttpException` as a response rather than an exception. A crash arrives the same way, as a 500.

### Passing data to the action

An action is built after its middleware has run, so middleware can put an object in the [container](container.md) with `set()` for the action to ask for in its constructor. This `RequireLogin` passes the signed-in user on:

```php
use Closure;
use Sapling\Container;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Http\Session;

final class RequireLogin
{
    public function __construct(
        private Session $session,
        private Container $container,
    ) {}

    public function __invoke(Request $request, Closure $next): Response
    {
        $id = $this->session->get('user_id')->int();

        if ($id === 0) {
            return Response::redirect('/login');
        }

        $user = new CurrentUser($id);
        $this->container->set(CurrentUser::class, $user);

        return $next($request);
    }
}
```

`CurrentUser` is a class of your own:

```php
final readonly class CurrentUser
{
    public function __construct(public int $id) {}
}
```

An action behind `RequireLogin`, added with `$app->middleware(RequireLogin::class, prefix: '/admin')` or `#[Middleware(RequireLogin::class)]`, gets it with `__construct(private CurrentUser $user)`.

## Registering middleware

Middleware is added in `bootstrap.php`, which the server and your tests both load. `$app->middleware()` adds it around every request and takes a class name, an object or a closure:

```php
$app->middleware(NoIndex::class);

$app->middleware(new Cors(['https://app.example']));

$app->middleware(function (Request $request, Closure $next): Response {
    return $next($request)->withHeader('Cache-Control', 'no-store');
});
```

### For part of the site

A `prefix` limits middleware to a path and the paths below it. `/api` covers `/api/notes` but not `/apis`:

```php
$app->middleware(Cors::class, prefix: '/api');
```

### The order middleware runs in

The first middleware added with `$app->middleware()` is outermost, an action's [`#[Middleware]`](routing.md#route-middleware) runs inside all of it, and the action is only built after its middleware has run, as the [request lifecycle](requests.md#the-request-lifecycle) shows.

## Built-in middleware

Sapling comes with three, in `Sapling\Http\Middleware`. A new app's `bootstrap.php` already adds `SecurityHeaders` and `Csrf`, while `Cors` is only needed when other sites call your app.

| Middleware | What it does | Settings |
| --- | --- | --- |
| `SecurityHeaders` | Sets the browser security headers and a content policy | `content_policy` |
| `Csrf` | Refuses writes that a browser sent from another site | `cors_origins`, whose sites listed by name can also write |
| `Cors` | Lets sites on other domains call the app from a browser | `cors_origins`, `cors_credentials` |

### Security headers

`SecurityHeaders` sends `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and a content policy. The policy only lets scripts come from files on your own site, while styles, images and fonts can also come from any HTTPS site, such as a CDN. Inline styles are allowed, `<style>` blocks and `style` attributes included.

> [!WARNING]
> Inline scripts are blocked, and browsers only report it in the console. The policy stops `<script>` blocks, `onclick` attributes, and libraries such as Alpine.js that evaluate strings.

To loosen the policy, set `content_policy` in `config.php`. Each directive replaces the default of the same name and leaves the rest:

```php
'content_policy' => ['script-src' => "'self' https://unpkg.com"],
```

`SecurityHeaders::POLICY` holds the defaults, and an action that sets one of these headers itself, such as its own policy, keeps it.

### Cross-site request protection

`Csrf` answers with a 403 when a browser sends a POST, PUT, PATCH or DELETE from another site. Browsers say where a request comes from in the `Sec-Fetch-Site` header, or in `Origin` when they're older, so your forms need no token.

Scripts and servers send neither header, and their requests pass unless they carry the session cookie. A request with the cookie but neither header could be a forged one from a browser too old to send them.

> [!NOTE]
> Every browser since 2023 sends `Sec-Fetch-Site`, and a page can't fake it. Only the same origin can write. A form on a subdomain is same-site but not same-origin, and needs the subdomain listed in `cors_origins`.

### CORS

`Cors` lets the sites in `cors_origins` call your app from a browser, answering the browser's preflight requests and adding the CORS headers to responses:

```php
// config.php
'cors_origins' => ['https://app.example'],
'cors_credentials' => true,
```

Write each site as browsers send it, in lowercase and without a trailing slash, or use `*` for any site. With `*`, other sites can only read, because `Csrf` compares `Origin` exactly and lets through writes from sites listed by name. `cors_credentials` lets the listed sites send cookies, which browsers don't allow with `*`. A setting that could never work, such as `*` with `cors_credentials`, throws an `InvalidArgumentException` the first time `Cors` runs.

The [session cookie](sessions.md#introduction) is SameSite=Lax, and browsers leave it off a `fetch()` from another domain. With `cors_credentials`, only a site on a subdomain of your own, such as `app.example.com` calling `api.example.com`, gets the visitor's session.

### Recommended order

Used together, the built-in middleware goes first, then your own:

```php
$app->middleware(SecurityHeaders::class);                 // headers on every response, refusals included
$app->middleware(Cors::class, prefix: '/api');            // answers preflights before anything refuses them
$app->middleware(Csrf::class);                            // refuses cross-site writes before your code runs
$app->middleware(RequireLogin::class, prefix: '/admin');  // then your own
```

> [!WARNING]
> Put login checks after `Cors`. A preflight carries no cookie or token, so a login check in front of `Cors` refuses it, and the browser then blocks the real request.

## Method reference

| Method | What it does |
| --- | --- |
| `$app->middleware($middleware, $prefix = '')` | Adds middleware around every request, or every request under `$prefix`. Takes a class name, an object or a closure |
| `#[Middleware($class)]` | Runs middleware around one class's routes. Repeat it for more, first outermost |
| `SecurityHeaders::POLICY` | The default content policy |
| `new SecurityHeaders($policy = [])` | Sets the security headers and a content policy. Each directive in `$policy` replaces the default of the same name. `App::boot()` registers it with `content_policy` |
| `new Csrf($trustedOrigins = [])` | Refuses cross-site writes, except from the sites listed. `App::boot()` registers it with `cors_origins` |
| `new Cors($origins, $allowCredentials = false)` | Lets the sites listed call the app from a browser. `App::boot()` registers it with `cors_origins` and `cors_credentials` |
