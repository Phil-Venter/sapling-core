# The app

- [Introduction](#introduction)
- [Booting the app](#booting-the-app)
    - [What booting sets up](#what-booting-sets-up)
- [Answering web requests](#answering-web-requests)
- [Running commands](#running-commands)
- [Registering actions and commands](#registering-actions-and-commands)
    - [Scanning a folder](#scanning-a-folder)
    - [Adding one class](#adding-one-class)
- [One-file apps](#one-file-apps)
- [Testing the whole app](#testing-the-whole-app)
- [Method reference](#method-reference)

## Introduction

`Sapling\App` holds the [container](container.md), the [routes](routing.md), the [commands](commands.md) and the [middleware](middleware.md). From a web server it answers the request, and from the terminal it runs a command.

The app is one object with no static state, so a test can create as many as it likes.

## Booting the app

`bootstrap.php` builds the app and returns it. `public/index.php`, `bin/console` and your tests all load it:

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

`App::boot()` takes the app's folder and its [configuration](config.md). Services and [middleware](middleware.md) are registered here too, so tests run the same middleware as the server.

> [!TIP]
> Register services as closures, as above. A closure only runs when something asks for the service.

### What booting sets up

`App::boot()` reads these settings from the config:

- `debug` turns on [debug mode](errors.md#debug-mode).
- `timezone` sets PHP's default timezone. An unknown name throws a `DateInvalidTimeZoneException`.
- `session_lifetime` sets how long the [session](sessions.md) lasts.
- `app_key` is passed to [`Crypto`](encryption.md), and the `cors_*` settings to [`Cors` and `Csrf`](middleware.md).

It also adds the `migrations:run`, `migrations:new` and `routes:list` commands. Every app, booted or not, has [templates](templates.md) from `views/` and the `commands:list` command.

## Answering web requests

`public/index.php` loads the app and calls `run()`:

```php
require __DIR__ . '/../vendor/autoload.php';

(require __DIR__ . '/../bootstrap.php')->run();
```

`run()` reads the request from PHP's globals and sends the response. The [request lifecycle](requests.md#the-request-lifecycle) covers what happens in between.

## Running commands

`bin/console` does the same:

```php
require __DIR__ . '/../vendor/autoload.php';

(require __DIR__ . '/../bootstrap.php')->run();
```

From the terminal, `run()` runs the named command and exits with its code. See [Commands](commands.md).

## Registering actions and commands

`#[Route]` makes a class an action, `#[Middleware]` wraps its routes, and `#[Command]` makes it a command.

### Scanning a folder

`scan()` registers every class in a folder and its subfolders:

```php
$app->scan(__DIR__ . '/app', 'App');
```

Each file holds a class named after its path, as PSR-4 expects, so `app/Notes/Show.php` holds `App\Notes\Show`. Classes without attributes are skipped. Classes are registered in name order.

There's no cache. Every request scans, which takes about 0.2 ms for a dozen classes.

> [!WARNING]
> A file in `app/` that fails to load breaks every request. Lint the app before deploying, as the starter app's `composer lint` does.

### Adding one class

`add()` registers a single class, such as one outside the scanned folder:

```php
$app->add(HealthCheck::class);
```

## One-file apps

`new App()` builds a bare app with no config. Add routes and commands with closures:

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

A bare app has none of what [booting sets up](#what-booting-sets-up). Pass `true` as the second argument for debug mode.

## Testing the whole app

`handle()` returns the response without sending it:

```php
$app = require __DIR__ . '/../bootstrap.php';

$response = $app->handle(new Request('POST', '/notes', input: ['body' => 'Buy milk']));

expect($response->status)->toBe(302);
```

`handle()` never throws. Errors come back as the response a visitor would get.

> [!NOTE]
> Build a new app for each test. The container shares the classes it builds, including actions and the session.

## Method reference

| Method | What it does |
| --- | --- |
| `App::boot($basePath, $config)` | Builds an app from its config, as described in [What booting sets up](#what-booting-sets-up) |
| `new App($basePath, $debug = false)` | Builds a bare app, for tests and one-file apps |
| `scan($dir, $namespace)` | Registers every class in the folder and its subfolders by its attributes |
| `add($class)` | Registers one class by its `#[Route]`, `#[Middleware]` and `#[Command]` attributes |
| `middleware($middleware, $prefix = '')` | Adds middleware around every request, or every request under `$prefix` |
| `httpExceptionHandler($handler)` | Sets what turns an `HttpException` into a response, see [errors](errors.md) |
| `run()` | Answers the current request and sends it, or runs a command from the terminal |
| `handle($request)` | Returns the `Response` for a `Request`. An error becomes a response too, so it never throws |
| `runCommand($console)` | Runs a command and returns its exit code |
| `path($relative = '')` | Returns an absolute path inside the app, such as `path('views')` |
| `$app->container` | The [container](container.md) |
| `$app->router` | The router, see [routing](routing.md) |
| `$app->commands` | The commands, see [commands](commands.md) |
| `$app->basePath`, `$app->debug` | The app's folder, and whether debug mode is on |
