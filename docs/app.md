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
- [Method reference](#method-reference)

## Introduction

`Sapling\App` ties an app together. It holds the [container](container.md) that builds your classes, the [routes](routing.md) and [commands](commands.md) they answer, and the [middleware](middleware.md) around them. The same object answers web requests and runs commands, depending on how PHP was started.

An app lives for one request and has no static state, so each test builds its own.

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

`App::boot()` takes the app's folder and its [configuration](config.md), and sets up what Sapling itself needs. In the rest of the file, you register services such as the database, scan `app/` for your classes, and add the middleware every request goes through. Services registered as closures are only built when something asks for them, so a request that never touches the database never connects.

### What booting sets up

`App::boot()` reads a few settings from the config:

- `debug` turns on [debug mode](errors.md#debug-mode).
- `timezone` sets PHP's default timezone. An unknown name throws a `DateInvalidTimeZoneException`.
- `session_lifetime` sets how long the [session](sessions.md) lasts.
- `app_key` is passed to [`Crypto`](encryption.md), and the `cors_*` settings to [`Cors` and `Csrf`](middleware.md).

It also adds the `migrations:run`, `migrations:new` and `routes:list` commands. Every app, booted or not, has [templates](templates.md) from `views/` and the `commands:list` command.

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

It expects one class per file, named after its path as PSR-4 does, so `app/Notes/Show.php` holds `App\Notes\Show`. Classes without attributes are skipped, and the rest are registered in name order.

Only files that start with a capital letter and have no other dot are read as classes. Anything else, such as `helpers.php` or `list.tmpl.php`, is skipped, so `app/` can hold plain functions and [templates](templates.md#templates-next-to-their-classes) too. Load function files through Composer, as Sapling's own helpers are:

```json
"autoload": {
    "psr-4": { "App\\": "app/" },
    "files": ["app/helpers.php"]
}
```

There's no cache, so every request scans. That takes about 0.2 ms for a dozen classes.

> [!WARNING]
> A file in `app/` that fails to load breaks every request. Lint the app before deploying, for example with `find app -name '*.php' -exec php -l {} \;`.

### Adding one class

For a class outside the scanned folder, `add()` registers it on its own:

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

Because the app is a plain object, a test can load `bootstrap.php` and send requests straight through it. `handle()` returns the response instead of sending it:

```php
$app = require __DIR__ . '/../bootstrap.php';

$response = $app->handle(new Request('POST', '/notes', input: ['body' => 'Buy milk']));

expect($response->status)->toBe(302);
```

`handle()` never throws. A 404, a crash or any other error comes back as the response a visitor would get.

> [!NOTE]
> Send one request per app, as the server does. A second request through the same app shares the first one's objects.

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
