# The container

- [Introduction](#introduction)
- [Asking for what you need](#asking-for-what-you-need)
- [Registering services](#registering-services)
    - [Interfaces](#interfaces)
    - [Replacing Sapling's services](#replacing-saplings-services)
- [Settings in constructors](#settings-in-constructors)
- [How a class is built](#how-a-class-is-built)
- [Getting a class by hand](#getting-a-class-by-hand)
- [Method reference](#method-reference)

## Introduction

`Sapling\Container` builds your classes from their constructors' parameter types and hands out one shared instance of each. An action that asks for `Db` in its constructor gets it, and most classes never need to be registered.

## Asking for what you need

A class lists what it needs as typed constructor parameters, and the container fills them in:

```php
use Sapling\Attributes\Route;
use Sapling\Db;
use Sapling\Template;

final class NoteRepository
{
    public function __construct(private Db $db) {}
}

#[Route('GET', '/notes')]
final class ListNotes
{
    public function __construct(private NoteRepository $notes, private Template $view) {}

    // ...
}
```

Neither class is registered anywhere. The container builds `NoteRepository` because `ListNotes` asks for it, and `Db` because `NoteRepository` does. Actions, middleware and commands are all built this way.

Each class is built once and then shared, and everything that asks for `Db` during a request gets the same connection.

A class can ask for the `App` itself too, such as to find a folder with [`path()`](app.md#method-reference).

## Registering services

Some classes can't be built from types alone, such as a database connection, which needs to know where the database is. Register those in `bootstrap.php` with `set()`:

```php
$app->container->set(Db::class, fn(): Db => Db::sqlite($config->get('db_file')));
```

The closure runs once, the first time the class is needed. `set()` also takes a built object, which is how middleware [passes data to the action](middleware.md#passing-data-to-the-action). Setting a class again replaces it, unless the container has already handed it out, in which case `set()` throws a `LogicException`.

### Interfaces

The container can't guess which class to use for an interface. Register the one you want under the interface's name, with a closure that receives the container:

```php
$app->container->set(Mailer::class, function (Container $c): Mailer {
    $config = $c->get(Config::class);

    return new SmtpMailer($config->get('smtp_url'));
});
```

### Replacing Sapling's services

Sapling registers its own services, such as `Template`, `Session` and `Csrf`, the same way. To replace one, call `set()` in `bootstrap.php` after `App::boot()`, above any line that gets it:

```php
$app->container->set(Template::class, fn(): Template => new Template(__DIR__ . '/templates'));
```

## Settings in constructors

`#[Config]` names the [setting](config.md) a parameter gets, since the container can't find one by its type:

```php
use Sapling\Attributes\Config;
use Sapling\Client\HttpClient;
use SensitiveParameter;

final class Weather
{
    public function __construct(
        private HttpClient $http,
        #[Config('weather_api_key'), SensitiveParameter] private string $apiKey,
        #[Config('weather_units')] private string $units = 'metric',
    ) {}
}
```

When the setting is missing, the parameter gets its default, and without one the container throws a `LogicException`. A setting of the wrong type [throws a `TypeError`](config.md#converting-env-values).

## How a class is built

Each constructor parameter gets the first of these that applies:

1. The setting named by `#[Config]`, or the parameter's default when the setting is missing.
2. The container's instance of its class or interface type.
3. Its default value.

A parameter none of them covers, such as a `string` with no default, throws a `LogicException` that names the parameter and the class. So do two classes that need each other, directly or through others, and the message lists the loop.

> [!NOTE]
> A class type always comes from the container, even when it's nullable or has a default. `?Mailer $mailer = null` still needs a `Mailer`.

## Getting a class by hand

Outside your classes, in `bootstrap.php`, tests and one-file apps, `get()` returns the shared instance of a class:

```php
$db = $app->container->get(Db::class);
```

Inside your own classes, ask for it in the constructor instead.

## Method reference

| Method | What it does |
| --- | --- |
| `get($class)` | Returns the shared instance of the class, building it the first time |
| `set($id, $value)` | Registers an object, or a closure that builds it on first use. Replaces an earlier `set()` of the same class. Throws a `LogicException` once the class has been handed out |
| `#[Config($key)]` | Fills a constructor parameter from a setting |
