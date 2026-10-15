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

`Sapling\Container` builds your classes from their constructor types and shares one instance of each. An action that asks for `Db` in its constructor gets it. Most classes need no registration.

## Asking for what you need

Type-hint what a class needs in its constructor:

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

Neither class is registered. Actions, middleware and commands are built the same way.

Each class is built once and shared. Actions and middleware are shared too, so don't keep request data in their properties.

## Registering services

Register a class the container can't build from types alone, such as a database connection, with `set()` in `bootstrap.php`:

```php
$app->container->set(Db::class, fn() => Db::sqlite($config->get('db_file')));
```

The closure runs the first time the class is needed. `set()` also takes a built object, and replaces anything set before.

### Interfaces

Register an implementation under the interface's name. The closure receives the container:

```php
$app->container->set(Mailer::class, function (Container $c): Mailer {
    $config = $c->get(Config::class);

    return new SmtpMailer($config->get('smtp_url'));
});
```

## Settings in constructors

`#[Config]` fills a constructor parameter straight from a [setting](config.md):

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

A missing setting uses the parameter's default, or throws a `LogicException` when there isn't one.

> [!TIP]
> Mark secrets `#[SensitiveParameter]` to keep them out of stack traces.

## How a class is built

Each constructor parameter gets, in order of preference:

1. The setting named by `#[Config]`, or the parameter's default when the setting is missing.
2. The container's instance of its class or interface type.
3. Its default value.

Anything else, such as a `string` with no default, throws a `LogicException` naming the parameter and class.

> [!NOTE]
> A class type always comes from the container, even when it's nullable or has a default. `?Mailer $mailer = null` still needs a `Mailer`.

## Getting a class by hand

`get()` returns the shared instance of a class, for use in `bootstrap.php`, tests and one-file apps:

```php
$db = $app->container->get(Db::class);
```

In your own classes, use the constructor instead.

## Method reference

| Method | What it does |
| --- | --- |
| `set($id, $value)` | Registers an object, or a closure that builds it on first use. Replaces anything set before |
| `get($class)` | Returns the shared instance of the class, building it the first time |
| `#[Config($key)]` | Fills a constructor parameter from a setting |
