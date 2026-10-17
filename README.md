# Sapling

Sapling is a small PHP framework. Each action or command is one class in one file, and an attribute on the class says where it lives. It has no runtime dependencies and about 2,300 lines of code.

```php
#[Route('GET', '/ping')]
final class Ping
{
    public function __invoke(Request $request): Response
    {
        return Response::text('pong');
    }
}
```

## Status

Sapling hasn't been tested in production environments yet. Use it at your own risk.

Sapling was built with AI assistance. All of the code was reviewed by its author.

## Getting started

Start a new app from the skeleton:

```sh
composer create-project sapling/skeleton:dev-main my-app
cd my-app
composer serve
```

It has the four files a Sapling app needs (`bootstrap.php`, `config.php`, `public/index.php` and `bin/console`), an `app/` folder for your actions, and a `.env` for development with a fresh `APP_KEY`. Errors are plain text until you [set an error handler](docs/errors.md).

A Sapling app can also fit in one file:

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

Serve it with `php -S localhost:8080 app.php`, and run its commands with `php app.php greet`.

## Why Sapling

I like Symfony, Laravel and Slim, and I use them, but they're too much for the proofs of concept, one-off projects and slapped-together internal tools I keep building. There's a lot of dogma and ceremony in them that those projects don't need.

So I've been fiddling with my own micro frameworks since early 2023, and Sapling is roughly my tenth. Most of the earlier ones went one of two ways. Some were quick experiments I never came back to. The rest got too clever, with interfaces nobody needed, a template compiler, a pile of global helpers, or packages and a build step just to boot.

Sapling keeps the parts that worked and drops the rest. You can read the whole core in a day.

## How Sapling thinks

- Actions, middleware and commands are classes with a single `__invoke` method. Closures work too, for small apps.
- Sapling only checks what PHP can't. When you make a mistake, PHP's own error usually names the class at fault.
- No static state, magic methods, generated code or caches. The few global functions are small helpers that work without an app.
- Settings live in `config.php`, secrets in `.env`, and the wiring and middleware in `bootstrap.php`, which the web server, commands and tests all load.

## Guides

| Guide | What's in it |
| --- | --- |
| [The app](docs/app.md) | `App`, `bootstrap.php`, `public/index.php`, `boot()` and `scan()` |
| [The container](docs/container.md) | How classes get what their constructors ask for, and `#[Config]` |
| [Routing and actions](docs/routing.md) | Actions, `#[Route]`, `#[Middleware]`, the router and how routes match |
| [Requests and responses](docs/requests.md) | The request lifecycle, `Request`, `Response` and downloads |
| [Values](docs/values.md) | Reading outside data safely with `Value`, from requests, commands, sessions and replies |
| [Middleware](docs/middleware.md) | Writing middleware, and the built-in `SecurityHeaders`, `Csrf` and `Cors` |
| [Templates](docs/templates.md) | Partials, components, layouts and escaping |
| [htmx](docs/htmx.md) | Fragments, redirects, errors, scripts and sessions with htmx |
| [Sessions](docs/sessions.md) | Session values, flash values and the cookie |
| [Database](docs/database.md) | `Db`, SQLite and migrations |
| [Configuration](docs/config.md) | `config.php`, `.env` and the settings Sapling reads |
| [Encrypting secrets](docs/encryption.md) | `Crypto` and `app_key` |
| [Commands](docs/commands.md) | `#[Command]`, `Console` and the built-in commands |
| [Talking to other services](docs/http-client.md) | `HttpClient`, `ClientRequest` and `ClientResponse`, one request at a time or several at once |
| [Converting data](docs/data.md) | JSON, XML and form data with `Data` |
| [Helpers](docs/helpers.md) | `abort()`, `e()`, `attrs()` and the other global functions |
| [When things go wrong](docs/errors.md) | `HttpException`, crashes, the error handler and logging |
| [Running in production](docs/production.md) | A checklist for the server |

## Not included, on purpose

There's no ORM, query builder, validation or caching, and no route groups. Every route spells out its full path, so you can grep for it. There are no events, queues, long-running workers, mail or PSR interfaces either. When an app needs one of these, write the code it needs.

## Requirements

PHP 8.4 or newer. On PHP 8.5, Sapling also catches an ignored result from `withHeader()`.

Some parts need extensions that most PHP installs already have:

| Part | Extension |
| --- | --- |
| `Db` | `pdo`, plus the driver for your database (`pdo_sqlite` for `Db::sqlite()`) |
| `Session` | `session` |
| `HttpClient` | `curl` |
| XML in `Data` | `simplexml` and `xmlwriter` |
| `Crypto` | `sodium` |

## Working on Sapling

| Command | What it does |
| --- | --- |
| `composer test` | Runs the tests through the app, a real `php -S` server, and commands in a subprocess |
| `composer format` | Formats the code with [mago](https://mago.carthage.software), as set in `mago.toml` |
| `composer check` | Checks formatting, runs mago's linter and static analyzer, then the tests |
| `composer mutate` | Runs mutation testing, to find code the tests don't check (needs Xdebug or PCOV) |

Core never calls the global helpers, so an app can replace them.

Tests mirror `src/`, so `tests/Http/RouterTest.php` tests `src/Http/Router.php`. Write one `expect()` per line.

The analyzer skips the tests, since it can't follow Pest's closures. `analyzer-baseline.toml` lists reviewed findings in `src/`, so only new ones are reported. After a fix, regenerate it with `vendor/bin/mago analyze --generate-baseline` and diff it before committing.

Each guide in `docs/` has a table of contents, an introduction, sections named after tasks, and a method reference. Update a guide in the same change as the behaviour it describes.

## License

Sapling is 0BSD, so you can use it for anything. See `LICENSE`.
