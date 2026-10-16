# The container

- [Introduction](#introduction)
- [Asking for what you need](#asking-for-what-you-need)
- [Registering services](#registering-services)
    - [Interfaces](#interfaces)
- [Settings in constructors](#settings-in-constructors)
- [How a class is built](#how-a-class-is-built)
- [Getting a class by hand](#getting-a-class-by-hand)
- [Method reference](#method-reference)

## Introduction

`Sapling\Container` builds your classes. It reads each constructor's parameter types, builds what they ask for, and hands out one shared instance of each class. An action that asks for `Db` in its constructor gets it, and most classes never need to be registered.

## Asking for what you need

A class lists what it needs as typed constructor parameters, and the container fills them in:

```php
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

Each class is built once and then shared, so everything that asks for `Db` during a request gets the same connection.

## Registering services

Some classes can't be built from types alone. A database connection needs to know where the database is, for example. Register those in `bootstrap.php` with `set()`:

```php
$app->container->set(Db::class, fn() => Db::sqlite($config->get('db_file')));
```

The closure runs once, the first time the class is needed. `set()` also takes a built object, and setting the same class again replaces it.

### Interfaces

The container can't guess which class to use for an interface, so register the one you want under the interface's name. The closure receives the container:

```php
$app->container->set(Mailer::class, function (Container $c): Mailer {
    $config = $c->get(Config::class);

    return new SmtpMailer($config->get('smtp_url'));
});
```

## Settings in constructors

Settings aren't classes, so the container can't find them by type. `#[Config]` names the [setting](config.md) a parameter should get instead:

```php
use Sapling\Attributes\Config;

final class Weather
{
    public function __construct(
        private HttpClient $http,
        #[Config('weather_api_key'), SensitiveParameter] private string $apiKey,
        #[Config('weather_units')] private string $units = 'metric',
    ) {}
}
```

When the setting is missing, the parameter's default is used, and without a default the container throws a `LogicException`.

## How a class is built

Putting that together, each constructor parameter gets the first of these that applies:

1. The setting named by `#[Config]`, or the parameter's default when the setting is missing.
2. The container's instance of its class or interface type.
3. Its default value.

A parameter none of them covers, such as a `string` with no default, throws a `LogicException` that names the parameter and the class.

> [!NOTE]
> A class type always comes from the container, even when it's nullable or has a default. `?Mailer $mailer = null` still needs a `Mailer`.

## Getting a class by hand

Outside your classes, in `bootstrap.php`, tests and one-file apps, `get()` returns the shared instance of a class:

```php
$db = $app->container->get(Db::class);
```

Inside your own classes, ask for it in the constructor instead, so the constructor shows everything the class depends on.

## Method reference

| Method | What it does |
| --- | --- |
| `set($id, $value)` | Registers an object, or a closure that builds it on first use. Replaces an earlier `set()` of the same class |
| `get($class)` | Returns the shared instance of the class, building it the first time |
| `#[Config($key)]` | Fills a constructor parameter from a setting |
