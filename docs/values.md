# Values

- [Introduction](#introduction)
- [Where values come from](#where-values-come-from)
- [Reading a value](#reading-a-value)
    - [Strings](#strings)
    - [Numbers](#numbers)
    - [Booleans](#booleans)
    - [Arrays](#arrays)
- [Nested data](#nested-data)
- [Missing and required values](#missing-and-required-values)
- [Wrapping your own data](#wrapping-your-own-data)
- [Method reference](#method-reference)

## Introduction

Data from outside the app is loosely typed. A form field can turn out to be an array, a query parameter is always a string, and JSON can hold anything. Checking each one by hand means a cast or an `is_string()` at every read, and forgetting one means a crafted `body[]=x` reaches a `string` parameter and becomes a 500.

`Sapling\Value` does those checks in one place. It wraps a piece of outside data and reads it as the type you ask for, and a value that's missing or of the wrong type gives a default instead.

## Where values come from

Everything in Sapling that hands you outside data returns a `Value`:

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

Call the method for the type you need, right where you read the value. Each one takes a default, which always has the right type because it goes on the typed method, and nothing throws:

```php
$id = $request->param('id')->int();
$page = $request->query('page')->int(1);
$theme = $request->cookie('theme')->string('light');
$days = $console->option('days')->int(30);
$count = $db->value($sql)->int();
```

A `Value` itself isn't a string, so `e($request->input('q'))` and `"Hello {$request->param('name')}"` throw an `Error`. Call `string()` first.

### Strings

`string()` returns strings as they are and casts numbers and `Stringable` objects. A backed enum reads as its value, so `$session->get('role')->string()` gives `'admin'` for `Role::Admin`. Booleans, arrays and other objects give the default.

### Numbers

`int()` and `float()` use PHP's `filter_var()`, so spaces around a number are fine but trailing text isn't:

```php
new Value('12')->int();      // 12
new Value(' 12 ')->int();    // 12
new Value('12abc')->int();   // 0
new Value('1.5')->int();     // 0
new Value('1.5')->float();   // 1.5
```

`filter_var()` doesn't treat `'007'` or `'1e3'` as whole numbers either, so read codes with leading zeros, such as zip codes, with `string()`. An int-backed enum reads as its value.

### Booleans

`bool()` reads `'1'`, `'true'`, `'on'` and `'yes'` as `true`, and `'0'`, `'false'`, `'off'`, `'no'` and `''` as `false`. Anything else gives the default. An unticked checkbox sends nothing, so it reads as `false`:

```php
$remember = $request->input('remember')->bool();
```

### Arrays

`array()` returns an array, such as the list `tags[]=php&tags[]=sql` sends, or the default for anything else:

```php
$tags = $request->query('tags')->array();
```

## Nested data

Decoded JSON and XML are often nested. `get()` reads an array key or a public property as another `Value`, and more keys read deeper:

```php
$name = $reply->data()->get('profile', 'name')->string();
$first = $request->input('items')->get(0, 'sku')->string();
```

A missing key at any level gives an empty `Value`. Private and protected properties count as missing.

## Missing and required values

A default keeps your code safe, but it can't tell you whether a value was sent at all. `string()` gives `''` both for an empty field and a missing one. When the difference matters, `$value->raw` holds the value as it arrived, or `null` when it's missing:

```php
$editing = $request->param('id')->raw !== null;
```

For the same reason, a default can hide a required field that was left out. Sapling has no validation layer, so check required fields yourself after reading them:

```php
$body = $request->input('body')->string();

if (blank($body)) {
    return Response::text('A note needs some text.', 422);
}
```

## Wrapping your own data

`Value` isn't limited to what Sapling hands you. Wrap any loosely typed data in it, rather than reaching in with `?? ''` at every level:

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
