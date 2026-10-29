# Configuration

- [Introduction](#introduction)
- [The config file](#the-config-file)
    - [Converting `.env` values](#converting-env-values)
- [The environment file](#the-environment-file)
    - [How it's read](#how-its-read)
    - [In production](#in-production)
- [Reading settings](#reading-settings)
    - [Missing settings](#missing-settings)
- [Settings Sapling reads](#settings-sapling-reads)
- [Method reference](#method-reference)

## Introduction

An app's settings live in one PHP file, `config.php`, where each one can have a default and a comment. Secrets and anything that differs between servers come from `.env` or the server's environment, and stay out of git.

`config.php` is the only code that reads `.env` and the environment, and `Sapling\Config` holds the settings it returns.

## The config file

`config.php` is plain PHP that gets the variables from `.env` and the environment in `$env` and returns a flat array of settings. Giving every setting a default keeps `.env` down to what differs between servers:

```php
return [
    'debug' => ($env['APP_ENV'] ?? 'production') === 'dev',
    'app_key' => $env['APP_KEY'] ?? '',
    'weather_api_key' => $env['WEATHER_API_KEY'] ?? '',
    'timezone' => 'UTC',
    'db_file' => __DIR__ . '/database/app.db',
    'session_lifetime' => 60 * 60 * 24 * 14,
];
```

### Converting `.env` values

Values from `.env` are always strings. A setting that needs another type, such as an `int` for `session_lifetime` or a `bool` for `cors_credentials`, is converted in `config.php`:

```php
'session_lifetime' => (int) ($env['SESSION_LIFETIME'] ?? 60 * 60 * 24 * 14),
'cors_credentials' => ($env['CORS_CREDENTIALS'] ?? '') === 'true',
```

A setting of the wrong type throws a `TypeError` when the app boots or first uses it, as it does for a [`#[Config]`](container.md#settings-in-constructors) parameter.

## The environment file

`.env` holds the secrets and the values that differ between machines:

```sh
APP_ENV=dev
WEATHER_API_KEY="a long secret"
```

A new app from the [skeleton](../README.md#getting-started) gets one for development, with a fresh `APP_KEY`, and ignores it in git.

### How it's read

- Lines are `KEY=value`, and lines starting with `#` are comments.
- Surrounding quotes are removed. In an unquoted value, ` #` starts a comment, which makes `KEY=abc # note` read as `abc` while `KEY=a#b` stays `a#b`.
- A line without `=` throws.
- Real environment variables win over `.env`, which is optional.
- Values only reach `config.php`, never `getenv()` or `$_ENV`.

### In production

On a server, set every variable, `APP_ENV` included, as a real environment variable instead of deploying `.env`. A real variable also wins over the same one in a `.env` shipped by mistake.

> [!NOTE]
> php-fpm hides environment variables by default (`clear_env = yes`). Pass them with `env[NAME]` in the pool config. Commands run from cron or a shell [need them too](commands.md#running-commands).

## Reading settings

Most classes get their settings in the constructor with [`#[Config]`](container.md#settings-in-constructors). Elsewhere, such as in `bootstrap.php`, ask the `Config` object with `get()`:

```php
$debug = $config->get('debug');
```

### Missing settings

`get()` throws a `LogicException` that names a missing setting. When a setting is optional, pass a default:

```php
$lifetime = $config->get('session_lifetime', 0);
```

## Settings Sapling reads

[`App::boot()`](app.md#what-booting-sets-up) reads these settings:

| Setting | What it does | When it's missing |
| --- | --- | --- |
| `debug` | Turns on [debug mode](errors.md#debug-mode), which shows a stack trace for a crash | Off |
| `timezone` | PHP's default timezone, such as `UTC` or `Africa/Johannesburg`. An unknown name throws a `DateInvalidTimeZoneException` | php.ini decides |
| `session_lifetime` | Seconds the [session](sessions.md#how-long-a-session-lasts) cookie and an idle session last | php.ini decides |
| `app_key` | The key [`Crypto`](encryption.md) encrypts with | `Crypto` throws when it's first used |
| `cors_origins` | Sites that can call the app from a browser, for [`Cors` and `Csrf`](middleware.md#built-in-middleware) | None |
| `cors_credentials` | Whether those sites can send cookies | No |
| `content_policy` | Directives that replace [`SecurityHeaders`](middleware.md#security-headers)' defaults | None |
| `trusted_proxies` | The exact addresses of the proxies whose `X-Forwarded-For` to read. [`$request->ip`](requests.md#accessing-the-request) is the visitor's address, or the proxy's until the proxy is listed here. `['*']` trusts whichever proxy connects | None |

## Method reference

| Method | What it does |
| --- | --- |
| `Config::load($configFile, $envFile)` | Runs `config.php` with `$env`, built from `.env` and the real environment |
| `get($key)` | Returns a setting. Throws `LogicException` when it's missing |
| `get($key, $default)` | Returns a setting, or `$default` when it's missing |
| `new Config($values)` | Holds settings from an array, as in tests |
