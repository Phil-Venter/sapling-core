# Middleware

- [Introduction](#introduction)
- [Writing middleware](#writing-middleware)
    - [Answering early](#answering-early)
    - [Passing data to the action](#passing-data-to-the-action)
- [Registering middleware](#registering-middleware)
    - [For part of the site](#for-part-of-the-site)
    - [For one action](#for-one-action)
- [The order middleware runs in](#the-order-middleware-runs-in)
- [Built-in middleware](#built-in-middleware)
    - [Security headers](#security-headers)
    - [Cross-site request protection](#cross-site-request-protection)
    - [CORS](#cors)
    - [Recommended order](#recommended-order)
- [Method reference](#method-reference)

## Introduction

Middleware runs around your actions. It sees the request on the way in and the response on the way out, and it can answer without calling the action at all. A login check or a header every response needs goes here, instead of in each action.

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
        if ($this->session->get('user_id')->raw === null) {
            return Response::redirect('/login');
        }

        return $next($request);
    }
}
```

Calling `abort()` stops the request too. The `HttpException` reaches the middleware around it as a response, not as an exception. A crash reaches it the same way, as a 500.

### Passing data to the action

An action is built after its middleware has run. Middleware can put an object in the [container](container.md) with `set()`, for the action to ask for in its constructor. This `RequireLogin` passes the signed-in user on:

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

An action behind `#[Middleware(RequireLogin::class)]` gets it with `__construct(private CurrentUser $user)`.

## Registering middleware

Middleware is added in `bootstrap.php`, which the server and your tests both load. `$app->middleware()` adds it around every request, and takes a class name, an object or a closure:

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

### For one action

For a single action, [`#[Middleware]`](routing.md#route-middleware) on the class runs middleware around its routes only:

```php
#[Route('GET', '/admin/users')]
#[Middleware(RequireAdmin::class)]
final class ListUsers
```

## The order middleware runs in

For each request, middleware runs in this order:

1. Middleware from `$app->middleware()`, in the order it was added. The first is outermost.
2. Routing. A 404 or 405 passes back through the middleware above.
3. The action's `#[Middleware]`, top to bottom.
4. The action. Its constructor runs only now, after any login check.

## Built-in middleware

Sapling comes with three, in `Sapling\Http\Middleware`. A new app's `bootstrap.php` already adds `SecurityHeaders` and `Csrf`, while `Cors` is only needed when other sites call your app.

| Middleware | What it does | Settings |
| --- | --- | --- |
| `SecurityHeaders` | Sets the browser security headers and a content policy | Policy directives, passed to its constructor |
| `Csrf` | Refuses writes that a browser sent from another site | `cors_origins`, whose sites can also write |
| `Cors` | Lets sites on other domains call the app from a browser | `cors_origins`, `cors_credentials` |

### Security headers

`SecurityHeaders` sends `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and a content policy. The policy lets scripts come only from files on your own site. Styles, images and fonts can also come from any HTTPS site, such as a CDN. Inline styles are allowed, `<style>` blocks and `style` attributes included.

> [!WARNING]
> Inline scripts are blocked, and browsers only report it in the console. That includes `<script>` blocks, `onclick` attributes, and libraries such as Alpine.js that evaluate strings. Put scripts in a file under `public/`, such as `public/app.js`.

To loosen the policy, set `content_policy` in `config.php`. Each directive replaces the default of the same name and leaves the rest:

```php
'content_policy' => ['script-src' => "'self' https://unpkg.com"],
```

`SecurityHeaders::POLICY` holds the defaults. An action can set its own policy header, and the middleware keeps it.

### Cross-site request protection

`Csrf` refuses a POST, PUT, PATCH or DELETE that a browser sent from another site, with a 403. Browsers say where a request comes from in the `Sec-Fetch-Site` header, or in `Origin` when they're older. Your forms need no token.

Scripts and servers send neither header, so their requests pass. A request with neither header but with the session cookie is refused. It could be a forged request from a browser too old to send either header.

> [!NOTE]
> Every browser since 2023 sends `Sec-Fetch-Site`, and a page can't fake it. Only the same origin can write, and a form on a subdomain is same-site but not same-origin. List the subdomain in `cors_origins`.

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
> Put login checks after `Cors`. A preflight carries no cookie or token. A login check in front of `Cors` refuses it, and the browser then blocks the real request.

## Method reference

| Method | What it does |
| --- | --- |
| `$app->middleware($middleware, $prefix = '')` | Adds middleware around every request, or every request under `$prefix`. Takes a class name, an object or a closure |
| `#[Middleware($class)]` | Runs middleware around one class's routes. Repeat it for more, first outermost |
| `new SecurityHeaders($policy = [])` | Sets the security headers and a content policy. Each directive in `$policy` replaces the default of the same name. `App::boot()` registers it with `content_policy` |
| `SecurityHeaders::POLICY` | The default content policy |
| `new Csrf($trustedOrigins = [])` | Refuses cross-site writes, except from the sites listed. `App::boot()` registers it with `cors_origins` |
| `new Cors($origins, $allowCredentials = false)` | Lets the sites listed call the app from a browser. `App::boot()` registers it with `cors_origins` and `cors_credentials` |
