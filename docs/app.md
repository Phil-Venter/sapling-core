# The app

- [Introduction](#introduction)
- [Booting the app](#booting-the-app)
    - [What booting sets up](#what-booting-sets-up)
- [Registering actions and commands](#registering-actions-and-commands)
    - [Scanning a folder](#scanning-a-folder)
    - [Adding one class](#adding-one-class)
- [Running the app](#running-the-app)
- [One-file apps](#one-file-apps)
- [Method reference](#method-reference)

## Introduction

`Sapling\App` holds the [container](container.md) that builds your classes, the [routes](routing.md) and [commands](commands.md) that lead to them, and the [middleware](middleware.md) around them. The same object answers web requests and runs commands, depending on how PHP was started.

An app lives for one request and has no static state, and each test [builds its own](testing.md#an-app-for-each-test).

## Booting the app

Every app is built in one place, `bootstrap.php`, which the web server, `bin/console` and your tests all load:

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
$app->container->set(Db::class, fn(): Db => Db::sqlite($config->get('db_file')));
$app->scan(__DIR__ . '/app', 'App');

$app->middleware(SecurityHeaders::class);
$app->middleware(Csrf::class);

return $app;
```

`App::boot()` takes the app's folder and its [configuration](config.md), and sets up what Sapling needs. In the rest of the file, you register services such as the database, scan `app/` for your classes, and add the middleware every request goes through.

### What booting sets up

`App::boot()` applies the [settings Sapling reads](config.md#settings-sapling-reads). It turns on debug mode when `debug` is true, sets PHP's timezone when `timezone` is set, and puts the `Config` in the container, along with [`Session`](sessions.md), [`Crypto`](encryption.md) and the [built-in middleware](middleware.md#built-in-middleware), each built from its settings when first used. It also adds the `migrations:new`, `migrations:run` and `migrations:list` commands.

Every app, booted or not, has [templates](templates.md) from `views/` and the `commands:list` and `routes:list` commands.

## Registering actions and commands

Sapling finds your classes through their attributes. `#[Route]` makes a class an action, `#[Middleware]` wraps its routes, and `#[Command]` makes it a command.

### Scanning a folder

`scan()` registers every class in a folder and its subfolders:

```php
$app->scan(__DIR__ . '/app', 'App');
```

It expects one class per file, named after its path as PSR-4 does, such as `App\Notes\Show` in `app/Notes/Show.php`. Classes without attributes are skipped, and the rest are registered in name order.

Only files whose names start with a capital letter and have no dot before `.php` are read as classes, which leaves `app/` free to hold files of plain functions, such as `helpers.php`, and [templates](templates.md#templates-next-to-their-classes), such as `list.tmpl.php`. Load function files through Composer, as Sapling's own helpers are:

```json
"autoload": {
    "psr-4": { "App\\": "app/" },
    "files": ["app/helpers.php"]
}
```

There's no cache, and every request scans again, which takes about 0.2 ms for a dozen classes.

> [!WARNING]
> A file in `app/` that fails to load breaks every request. The [production checklist](production.md) has a command that lints it before you deploy.

### Adding one class

For a class outside the scanned folder, `add()` registers it:

```php
$app->add(HealthCheck::class);
```

## Running the app

Both entry points, `public/index.php` and `bin/console`, are two lines that load `bootstrap.php` and call `run()`:

```php
require __DIR__ . '/../vendor/autoload.php';

(require __DIR__ . '/../bootstrap.php')->run();
```

`run()` checks how PHP was started. Behind a web server, it reads the request from PHP's globals, passes it through the [request lifecycle](requests.md#the-request-lifecycle) and sends the response. From the terminal, it runs the named [command](commands.md) and exits with its code.

## One-file apps

Small scripts don't need `bootstrap.php`. `new App()` builds a bare app with no config, and routes and commands can be added as closures:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Sapling\App;
use Sapling\Cli\Console;
use Sapling\Http\Request;
use Sapling\Http\Response;

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

To try it, install Sapling in an empty folder with `composer require sapling/core:dev-main`, save the file there as `app.php`, serve it with `php -S localhost:8080 app.php`, and run its commands with `php app.php greet`.

A bare app has none of what [booting sets up](#what-booting-sets-up), though `true` as the second argument turns on debug mode.

## Method reference

| Method | What it does |
| --- | --- |
| `App::boot($basePath, $config)` | Builds an app from its config, as described in [What booting sets up](#what-booting-sets-up) |
| `scan($dir, $namespace)` | Registers every class in the folder and its subfolders by its attributes, skipping classes without them |
| `add($class)` | Registers one class by its `#[Route]`, `#[Middleware]` and `#[Command]` attributes |
| `middleware($middleware, $prefix = '')` | Adds [middleware](middleware.md#registering-middleware) around every request, or every request under `$prefix` |
| `onError($handler)` | Sets the [error handler](errors.md#showing-your-own-error-page), which turns an `HttpException`, or a crash outside debug mode, into a response |
| `run()` | Answers the current request and sends the response, or runs a command from the terminal |
| `handle($request)` | Returns the `Response` for a `Request`, even for an error or a crash, as in [tests](testing.md#sending-a-request). It never throws |
| `runCommand($console)` | Runs a command and returns its exit code, as in [tests](testing.md#running-a-command) |
| `path($relative = '')` | Returns an absolute path inside the app, such as `path('views')` |
| `$app->container` | The [container](container.md) |
| `$app->router` | The [router](routing.md) |
| `$app->commands` | The [commands](commands.md) |
| `$app->basePath`, `$app->debug` | The app's folder, and whether debug mode is on |
| `new App($basePath, $debug = false)` | Builds a bare app, for tests and one-file apps |
