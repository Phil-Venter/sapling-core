# The app

- [Introduction](#introduction)
- [Booting the app](#booting-the-app)
    - [What booting sets up](#what-booting-sets-up)
- [Running the app](#running-the-app)
- [Registering actions and commands](#registering-actions-and-commands)
    - [Scanning a folder](#scanning-a-folder)
    - [Adding one class](#adding-one-class)
- [One-file apps](#one-file-apps)
- [Testing the whole app](#testing-the-whole-app)
    - [An app for each test](#an-app-for-each-test)
    - [Checking a flash value](#checking-a-flash-value)
- [Method reference](#method-reference)

## Introduction

`Sapling\App` holds the [container](container.md) that builds your classes, the [routes](routing.md) and [commands](commands.md) they answer, and the [middleware](middleware.md) around them. The same object answers web requests and runs commands, depending on how PHP was started.

An app lives for one request and has no static state. Each test builds its own.

## Booting the app

Every app is built in one place, `bootstrap.php`. The web server, `bin/console` and your tests all load it:

```php
use Sapling\App;
use Sapling\Config;
use Sapling\Db;
use Sapling\Http\Middleware\Csrf;
use Sapling\Http\Middleware\SecurityHeaders;

$config = Config::load(
    configFile: __DIR__ . '/config.php',
    envFile: __DIR__ . '/.env',
);

$app = App::boot(__DIR__, $config);
$app->container->set(Db::class, fn() => Db::sqlite($config->get('db_file')));
$app->scan(__DIR__ . '/app', 'App');

$app->middleware(SecurityHeaders::class);
$app->middleware(Csrf::class);

return $app;
```

`App::boot()` takes the app's folder and its [configuration](config.md), and sets up what Sapling needs. In the rest of the file, you register services such as the database, scan `app/` for your classes, and add the middleware every request goes through. Services registered as closures are only built when something asks for them.

### What booting sets up

`App::boot()` reads a few settings from the config:

- `debug` turns on [debug mode](errors.md#debug-mode).
- `timezone` sets PHP's default timezone. An unknown name throws a `DateInvalidTimeZoneException`.
- `session_lifetime` sets how long the [session](sessions.md) lasts.
- `app_key` is passed to [`Crypto`](encryption.md), `content_policy` to [`SecurityHeaders`](middleware.md#security-headers), and the `cors_*` settings to [`Cors` and `Csrf`](middleware.md).
- `trusted_proxies` lists the proxies whose `X-Forwarded-For` gives [`$request->ip`](requests.md#accessing-the-request).

It also adds the `migrations:new`, `migrations:run` and `migrations:list` commands. Every app, booted or not, has [templates](templates.md) from `views/` and the `commands:list` and `routes:list` commands.

## Running the app

Both entry points are two lines. `public/index.php` and `bin/console` each load `bootstrap.php` and call `run()`:

```php
require __DIR__ . '/../vendor/autoload.php';

(require __DIR__ . '/../bootstrap.php')->run();
```

`run()` checks how PHP was started. Behind a web server, it reads the request from PHP's globals, passes it through the [request lifecycle](requests.md#the-request-lifecycle) and sends the response. From the terminal, it runs the named [command](commands.md) and exits with its code.

## Registering actions and commands

Sapling finds your classes through their attributes. `#[Route]` makes a class an action, `#[Middleware]` wraps its routes, and `#[Command]` makes it a command.

### Scanning a folder

`scan()` registers every class in a folder and its subfolders:

```php
$app->scan(__DIR__ . '/app', 'App');
```

It expects one class per file, named after its path as PSR-4 does. `app/Notes/Show.php` holds `App\Notes\Show`. Classes without attributes are skipped, and the rest are registered in name order.

Only files that start with a capital letter and have no other dot are read as classes. Anything else, such as `helpers.php` or `list.tmpl.php`, is skipped. `app/` can hold plain functions and [templates](templates.md#templates-next-to-their-classes) too. Load function files through Composer, as Sapling's own helpers are:

```json
"autoload": {
    "psr-4": { "App\\": "app/" },
    "files": ["app/helpers.php"]
}
```

There's no cache. Every request scans, taking about 0.2 ms for a dozen classes.

> [!WARNING]
> A file in `app/` that fails to load breaks every request. The [production checklist](production.md) has a command that lints it before you deploy.

### Adding one class

For a class outside the scanned folder, `add()` registers it:

```php
$app->add(HealthCheck::class);
```

## One-file apps

Small scripts don't need `bootstrap.php`. `new App()` builds a bare app with no config, and routes and commands can be added as closures:

```php
$app = new App(__DIR__);

$app->router->add('GET', '/hello/:name', function (Request $request): Response {
    return Response::text("Hello {$request->param('name')->string()}");
});

$app->commands->add('greet', function (Console $console): int {
    $console->line('Hello');

    return Console::SUCCESS;
}, 'Say hello');

$app->run();
```

A bare app has none of what [booting sets up](#what-booting-sets-up). Pass `true` as the second argument to turn on debug mode.

## Testing the whole app

A test can load `bootstrap.php` and send requests straight through it. `handle()` returns the response instead of sending it:

```php
$app = require __DIR__ . '/../bootstrap.php';

$response = $app->handle(new Request('POST', '/notes', input: ['body' => 'Buy milk']));

expect($response->status)->toBe(302);
```

The examples use [Pest](https://pestphp.com). The skeleton comes with it and a `composer test` script. In an app made without the skeleton, add it with `composer require --dev pestphp/pest`.

`handle()` never throws. A 404, a crash or any other error comes back as the response a visitor would get.

To act as a signed-in visitor, set the session before the request. The action sees the same values, since the app shares one `Session`:

```php
$app->container->get(Session::class)->set('user_id', 7);

$response = $app->handle(new Request('GET', '/settings'));
```

PHP keeps that session for the rest of the test run. [The helper below](#an-app-for-each-test) empties it for each test.

The session cookie never shows up on the response from `handle()`, since PHP sends it itself.

To answer for another service, such as a payment provider, [fake its replies](http-client.md#faking-replies-in-tests).

> [!NOTE]
> Send one request per app, as the server does. A second request through the same app shares the first one's objects, so a [`set()` in middleware](middleware.md#passing-data-to-the-action) can throw.

### An app for each test

The skeleton's `tests/Pest.php` looks like this. Its `testApp()` helper empties the session, loads the app, and gives it an in-memory SQLite database with the migrations run:

```php
use Sapling\App;
use Sapling\Db;
use Sapling\Migrations;

pest()->beforeEach(function () {
    error_reporting(E_ALL);
});

function testApp(): App
{
    $_SESSION = [];

    $app = require __DIR__ . '/../bootstrap.php';
    $db = Db::sqlite(':memory:');
    new Migrations($db, $app->path('database/migrations'))->run();
    $app->container->set(Db::class, $db);

    return $app;
}
```

> [!NOTE]
> PHPUnit lowers `error_reporting()` while a test runs, which hides the [warnings](errors.md#warnings-and-notices) Sapling turns into crashes.

### Checking a flash value

To check what an action flashed, read it from a new app, as the next request would:

```php
$app = testApp();
$app->handle(new Request('POST', '/notes', input: ['body' => 'Buy milk']));

$next = require __DIR__ . '/../bootstrap.php';

expect($next->container->get(Session::class)->getFlash('notice')->string())->toBe('Saved.');
```

Load `bootstrap.php` directly here. `testApp()` would empty the session, and the flash value with it.

## Method reference

| Method | What it does |
| --- | --- |
| `App::boot($basePath, $config)` | Builds an app from its config, as described in [What booting sets up](#what-booting-sets-up) |
| `new App($basePath, $debug = false)` | Builds a bare app, for tests and one-file apps |
| `scan($dir, $namespace)` | Registers every class in the folder and its subfolders by its attributes, skipping classes without them |
| `add($class)` | Registers one class by its `#[Route]`, `#[Middleware]` and `#[Command]` attributes. Throws a `LogicException` when it has no route or command |
| `middleware($middleware, $prefix = '')` | Adds middleware around every request, or every request under `$prefix` |
| `onError($handler)` | Sets what turns an `HttpException`, or a crash outside debug mode, into a response, see [errors](errors.md) |
| `run()` | Answers the current request and sends it, or runs a command from the terminal |
| `handle($request)` | Returns the `Response` for a `Request`, even for an error or a crash. It never throws |
| `runCommand($console)` | Runs a command and returns its exit code |
| `path($relative = '')` | Returns an absolute path inside the app, such as `path('views')` |
| `$app->container` | The [container](container.md) |
| `$app->router` | The router, see [routing](routing.md) |
| `$app->commands` | The commands, see [commands](commands.md) |
| `$app->basePath`, `$app->debug` | The app's folder, and whether debug mode is on |
