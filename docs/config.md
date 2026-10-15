# Configuration

- [Introduction](#introduction)
- [The config file](#the-config-file)
- [The environment file](#the-environment-file)
    - [How it's read](#how-its-read)
- [Reading settings](#reading-settings)
    - [Missing settings](#missing-settings)
- [Settings Sapling reads](#settings-sapling-reads)
- [Configuration in production](#configuration-in-production)
- [Method reference](#method-reference)

## Introduction

`Sapling\Config` holds the settings that `config.php` returns. `config.php` is the only file that reads `.env` and the environment.

## The config file

`config.php` returns a flat array. `$env` holds the variables from `.env` and the environment:

```php
return [
    'debug' => ($env['APP_ENV'] ?? 'production') === 'dev',
    'app_key' => $env['APP_KEY'] ?? '',
    'timezone' => 'UTC',
    'db_file' => __DIR__ . '/database/app.db',
    'session_lifetime' => 60 * 60 * 24 * 14,
];
```

> [!TIP]
> Give every setting a default, so `.env` only holds what differs between servers. A missing `APP_ENV` then means production.

## The environment file

`.env` holds secrets and per-machine values:

```sh
APP_ENV=dev
API_KEY="a long secret"
```

`vendor/bin/sapling init` writes one with a fresh `APP_KEY` and adds it to `.gitignore`. Keep it out of git.

### How it's read

- Lines are `KEY=value`. Lines starting with `#` are comments. Values are always strings.
- Surrounding quotes are removed. In unquoted values, ` #` starts a comment, so `KEY=abc # note` is `abc`, but `KEY=a#b` is `a#b`.
- A line without `=` throws.
- Real environment variables win over `.env`, which is optional.
- Values only reach `config.php`, never `getenv()` or `$_ENV`.

## Reading settings

Use [`#[Config]`](container.md#settings-in-constructors) in a constructor:

```php
public function __construct(#[Config('api_key'), SensitiveParameter] private string $apiKey) {}
```

Or call `get()`:

```php
$debug = $config->get('debug');
```

### Missing settings

`get()` throws a `LogicException` for a missing setting, to catch typos. Pass a default when it may be missing:

```php
$lifetime = $config->get('session_lifetime', 0);
```

## Settings Sapling reads

| Setting | What it does | When it's missing |
| --- | --- | --- |
| `debug` | Shows a stack trace for a crash | Off |
| `timezone` | PHP's default timezone, such as `UTC` or `Africa/Johannesburg` | php.ini decides |
| `session_lifetime` | Seconds the session cookie and an idle session last | php.ini decides |
| `app_key` | The key [`Crypto`](encryption.md) encrypts with | `Crypto` throws when it's first used |
| `cors_origins` | Sites that may call the app from a browser, for [`Cors` and `Csrf`](middleware.md) | None |
| `cors_credentials` | Whether those sites may send cookies | No |
| `cors_expose_headers` | Response headers their scripts may read | None |

## Configuration in production

Set real environment variables in production and don't deploy `.env`.

> [!NOTE]
> php-fpm hides environment variables by default (`clear_env = yes`). Pass them with `env[NAME]` in the pool config.

## Method reference

| Method | What it does |
| --- | --- |
| `Config::load($configFile, $envFile)` | Runs `config.php` with `$env`, built from `.env` and the real environment |
| `new Config($values)` | Holds settings from an array, as in tests |
| `get($key)` | Returns a setting. Throws `LogicException` when it's missing |
| `get($key, $default)` | Returns a setting, or `$default` when it's missing |
