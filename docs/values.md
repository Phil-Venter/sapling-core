# Values

- [Introduction](#introduction)
- [Where values come from](#where-values-come-from)
- [Reading a value](#reading-a-value)
    - [Strings](#strings)
    - [Numbers](#numbers)
    - [Booleans](#booleans)
    - [Arrays](#arrays)
- [Defaults](#defaults)
- [Nested data](#nested-data)
- [Missing values](#missing-values)
- [Required fields](#required-fields)
- [Wrapping your own data](#wrapping-your-own-data)
- [Method reference](#method-reference)

## Introduction

`Sapling\Value` wraps data from outside the app and reads it as the type you ask for. A missing value, or one of the wrong type, gives a default.

Outside data is loosely typed. A form field can be an array and JSON can hold anything, so a crafted `body[]=x` passed to a `string` parameter would be a 500. `Value` does those checks for you.

## Where values come from

| Method | What it holds |
| --- | --- |
| `$request->param()`, `query()`, `input()`, `header()`, `cookie()` | A route parameter, query string value, body field, header or cookie |
| `$request->data()` | The whole decoded body |
| `$console->arg()`, `option()` | A command-line argument or option |
| `$session->get()`, `getFlash()` | A session value, or one stored for the next request |
| `$db->value()` | The first column of the first row |
| `$response->data()`, `header()` | A response's decoded body or a header, such as from another service |
| `new Value($data)` | Anything else, such as a decoded file |

## Reading a value

```php
$id = $request->param('id')->int();
$body = $request->input('body')->string();
$days = $console->option('days')->int(30);
$userId = $session->get('user_id')->int();
$count = $db->value($sql)->int();
```

> [!TIP]
> Read the type where you read the value, rather than passing `raw` around.

A `Value` isn't a string. `e($request->input('q'))` and `"Hello {$request->param('name')}"` throw an `Error`, so call `string()` first.

### Strings

`string()` returns strings and casts numbers and `Stringable` objects. Booleans, arrays and other objects give the default.

A backed enum reads as its value, so `$session->get('role')->string()` gives `'admin'` for `Role::Admin`, and an int-backed enum works with `int()`.

### Numbers

`int()` and `float()` use `filter_var()`. Surrounding spaces are allowed, and trailing text isn't:

```php
new Value('12')->int();      // 12
new Value(' 12 ')->int();    // 12
new Value('12abc')->int();   // 0
new Value('1.5')->int();     // 0
new Value('1.5')->float();   // 1.5
```

`'007'` and `'1e3'` also give the default. Read codes with leading zeros, such as zip codes, with `string()`.

### Booleans

`bool()` reads `'1'`, `'true'`, `'on'` and `'yes'` as `true`, and `'0'`, `'false'`, `'off'`, `'no'` and `''` as `false`. Anything else gives the default. An unticked checkbox sends nothing, so it reads as `false`:

```php
$remember = $request->input('remember')->bool();
```

### Arrays

`array()` returns an array, such as `tags[]=php&tags[]=sql`, or the default:

```php
$tags = $request->query('tags')->array();
```

## Defaults

The default goes on the typed method, so it always has the right type. Nothing throws:

```php
$page = $request->query('page')->int(1);
$theme = $request->cookie('theme')->string('light');
```

## Nested data

`get()` reads array keys and public properties:

```php
$name = $reply->data()->get('profile', 'name')->string();
$first = $request->input('items')->get(0, 'sku')->string();
```

A missing key gives an empty `Value`. Private and protected properties count as missing.

## Missing values

`$value->raw` is the value as it arrived, or `null` when it's missing. `string()` gives `''` for both, so use `raw` to tell them apart:

```php
$editing = $request->param('id')->raw !== null;
```

## Required fields

A default also hides a field that was left out. Sapling has no validation, so check required fields yourself:

```php
$body = $request->input('body')->string();

if (blank($body)) {
    return Response::text('A note needs some text.', 422);
}
```

## Wrapping your own data

```php
$json = file_get_contents(__DIR__ . '/settings.json');
$settings = new Value(Data::fromJson($json));
$port = $settings->get('server', 'port')->int(8080);
```

## Method reference

| Method | What it returns |
| --- | --- |
| `string($default = '')` | A string. Numbers, `Stringable` objects and backed enums are cast, and a boolean gives the default |
| `int($default = 0)` | A whole number, so `'12'` is 12, while `'12abc'` and `'1.5'` give the default |
| `float($default = 0.0)` | A number, so `'1.5'` is 1.5 |
| `bool($default = false)` | `true` for `'1'`, `'true'`, `'on'` and `'yes'`, and `false` for `'0'`, `'false'`, `'off'`, `'no'` and `''` |
| `array($default = [])` | An array, as sent by `tags[]=a` |
| `get($key, ...$more)` | An array key or public property, as a `Value`. More keys read deeper |
| `$value->raw` | The value as it arrived, or `null` when it's missing. Read-only |
