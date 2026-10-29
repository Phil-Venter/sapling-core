# Sapling

Sapling is a small PHP framework for prototypes, one-off projects and internal tools. It has no runtime dependencies, and you can read the whole core, about 2,300 lines of code, in a day.

Each action or command is one class in one file, with an attribute that declares its route or command name:

```php
namespace App;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/hello/:name')]
final class Hello
{
    public function __invoke(Request $request): Response
    {
        return Response::text("Hello {$request->param('name')->string()}");
    }
}
```

## Status

Sapling hasn't been tested in production environments yet. Use it at your own risk.

Sapling was built with AI assistance, and its author reviewed all of the code.

## Getting started

Start a new app from the skeleton:

```sh
composer create-project sapling/skeleton:dev-main my-app
cd my-app
composer serve
```

It has the four files a Sapling app needs (`bootstrap.php`, `config.php`, `public/index.php` and `bin/console`), an `app/` folder for your actions, a `tests/` folder that `composer test` runs with Pest, and a `.env` for development with a fresh `APP_KEY`. Errors are plain text until you [set an error handler](docs/errors.md). A small app can also [fit in one file](docs/app.md#one-file-apps), without the skeleton.

To add your first action, save the `Hello` class above as `app/Hello.php` and open `http://localhost:8080/hello/Ann`. The guides below are in reading order, starting with the app.

## Why Sapling

I like Symfony, Laravel and Slim, and I use them. For a prototype or a slapped-together internal tool, they bring more dogma and ceremony than the project needs.

I've been fiddling with my own micro frameworks since early 2023, and Sapling is roughly my tenth. Some of the earlier ones were quick experiments I never came back to. Others got too clever, with interfaces nobody needed, a template compiler, a pile of global helpers, or packages and a build step just to boot.

## Design

- Actions, middleware and commands are classes with a single `__invoke` method. Closures work too, for small apps.
- Sapling only checks what PHP can't. When you make a mistake, PHP's own error usually names the class at fault.
- No static state, magic methods, generated code or caches. The few global functions are small helpers that work without an app.
- Settings live in `config.php`, secrets in `.env`, and the wiring and middleware in `bootstrap.php`, which the web server, commands and tests all load.

## Guides

| Guide | What's in it |
| --- | --- |
| [The app](docs/app.md) | `App`, `bootstrap.php`, `public/index.php`, `boot()` and `scan()` |
| [Configuration](docs/config.md) | `config.php`, `.env` and the settings Sapling reads |
| [The container](docs/container.md) | How classes get what their constructors ask for, and `#[Config]` |
| [Routing and actions](docs/routing.md) | Actions, `#[Route]`, `#[Middleware]`, the router and how routes match |
| [Requests and responses](docs/requests.md) | The request lifecycle, `Request`, `Response` and downloads |
| [Values](docs/values.md) | Reading outside data safely with `Value`, from requests, commands, sessions and replies |
| [Middleware](docs/middleware.md) | Writing middleware, and the built-in `SecurityHeaders`, `Csrf` and `Cors` |
| [When things go wrong](docs/errors.md) | `HttpException`, crashes, the error handler and logging |
| [Sessions](docs/sessions.md) | Session values, flash values, signing in and out, and how long a session lasts |
| [Templates](docs/templates.md) | Partials, components, layouts and escaping |
| [htmx](docs/htmx.md) | Fragments, redirects, errors, scripts and sessions with htmx |
| [Database](docs/database.md) | `Db`, SQLite and migrations |
| [Encrypting secrets](docs/encryption.md) | `Crypto` and `app_key` |
| [Commands](docs/commands.md) | `#[Command]`, `Console` and the built-in commands |
| [Talking to other services](docs/http-client.md) | `HttpClient`, `ClientRequest` and `ClientResponse`, one request at a time or several at once |
| [Converting data](docs/data.md) | JSON, XML and form data with `Data` |
| [Helpers](docs/helpers.md) | `abort()`, `e()`, `attrs()` and the other global functions |
| [Testing](docs/testing.md) | Requests, signing in, flash values, faked services and commands in tests |
| [Running in production](docs/production.md) | A checklist for the server |

## Not included, on purpose

There's no ORM, query builder, validation, caching or route groups, and there are no events, queues, long-running workers, mail or PSR interfaces. When your app needs one of these, you write the code for it. Without route groups, every route spells out its full path and you can grep for it.

## Requirements

PHP 8.4 or newer. On PHP 8.5, Sapling also catches an ignored result from `withHeader()`.

Some parts need extensions that most PHP installs already have:

| Part | Extension |
| --- | --- |
| `Db` | `pdo`, plus the driver for your database (`pdo_sqlite` for `Db::sqlite()`) |
| `Session` and `Csrf` | `session` |
| `Value` | `filter` |
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

Core never calls the global helpers, since an app can replace them.

Tests mirror `src/`, as `tests/Http/RouterTest.php` does for `src/Http/Router.php`, with one `expect()` per line.

The analyzer skips the tests, since it can't follow Pest's closures. `analyzer-baseline.toml` lists the findings in `src/` that have been reviewed, and only new ones are reported. After a fix, regenerate it with `vendor/bin/mago analyze --generate-baseline` and diff it before committing.

Each guide in `docs/` has a table of contents, an introduction, sections named after tasks, and a method reference. Update a guide in the same change as the behaviour it describes. Code for a job Sapling leaves to apps, such as retrying a request, is a short example in the guide it belongs to.

## License

Sapling is 0BSD. You can use it for anything. See `LICENSE`.
