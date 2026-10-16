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

An app's settings live in one PHP file, `config.php`, where each one can have a default and a comment. Secrets and anything that differs between servers come from `.env` or the server's environment, and stay out of git.

`config.php` is the only code that reads `.env` and the environment. `Sapling\Config` holds what it returns, so the rest of the app never needs to know where a value came from.

## The config file

`config.php` returns a flat array of settings. It's plain PHP, and `$env` holds the variables from `.env` and the environment. Giving every setting a default keeps `.env` down to what differs between servers:

```php
return [
    'debug' => ($env['APP_ENV'] ?? 'production') === 'dev',
    'app_key' => $env['APP_KEY'] ?? '',
    'timezone' => 'UTC',
    'db_file' => __DIR__ . '/database/app.db',
    'session_lifetime' => 60 * 60 * 24 * 14,
];
```

## The environment file

`.env` holds the secrets and the values that differ between machines:

```sh
APP_ENV=dev
API_KEY="a long secret"
```

A new app from `composer create-project sapling/skeleton` gets one for development, with a fresh `APP_KEY`, and ignores it in git.

### How it's read

- Lines are `KEY=value`. Lines starting with `#` are comments. Values are always strings.
- Surrounding quotes are removed. In unquoted values, ` #` starts a comment, so `KEY=abc # note` is `abc`, but `KEY=a#b` is `a#b`.
- A line without `=` throws.
- Real environment variables win over `.env`, which is optional.
- Values only reach `config.php`, never `getenv()` or `$_ENV`.

## Reading settings

Most classes get their settings in the constructor with [`#[Config]`](container.md#settings-in-constructors):

```php
public function __construct(#[Config('api_key'), SensitiveParameter] private string $apiKey) {}
```

Elsewhere, such as in `bootstrap.php`, ask the `Config` object with `get()`:

```php
$debug = $config->get('debug');
```

### Missing settings

A missing setting is usually a typo, so `get()` throws a `LogicException` that names it. When a setting is optional, pass a default:

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

## Configuration in production

On a server, set real environment variables instead of deploying `.env`. A `.env` shipped by mistake then can't switch production into debug mode.

> [!NOTE]
> php-fpm hides environment variables by default (`clear_env = yes`). Pass them with `env[NAME]` in the pool config.

## Method reference

| Method | What it does |
| --- | --- |
| `Config::load($configFile, $envFile)` | Runs `config.php` with `$env`, built from `.env` and the real environment |
| `new Config($values)` | Holds settings from an array, as in tests |
| `get($key)` | Returns a setting. Throws `LogicException` when it's missing |
| `get($key, $default)` | Returns a setting, or `$default` when it's missing |
