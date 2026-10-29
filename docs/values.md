# Values

- [Introduction](#introduction)
- [Where values come from](#where-values-come-from)
- [Reading a value](#reading-a-value)
    - [Strings](#strings)
    - [Numbers](#numbers)
    - [Booleans](#booleans)
    - [Arrays](#arrays)
    - [Enums](#enums)
- [Nested data](#nested-data)
- [Missing and required values](#missing-and-required-values)
- [Wrapping your own data](#wrapping-your-own-data)
- [Method reference](#method-reference)

## Introduction

`Sapling\Value` wraps data from outside the app and reads it as the type you ask for. A value that's missing, or of the wrong type, gives a default instead of an error.

## Where values come from

Everything in Sapling that hands you outside data returns a `Value`:

| Method | What it holds |
| --- | --- |
| [`$request->param()`](requests.md#retrieving-input), `query()`, `data()`, `header()`, `cookie()` | A route parameter, query string value, body field, header or cookie |
| [`$console->arg()`](commands.md#input), `option()`, `ask()` | A command-line argument, option or answer |
| [`$session->get()`](sessions.md#using-the-session), `getFlash()` | A session value, or one stored for the next request |
| [`$db->value()`](database.md#fetching-a-single-value) | The first column of the first row |
| [`$response->data()`](requests.md#reading-a-response), `header()` | A response's decoded body or a header, such as in a test |
| [`$reply->data()`](http-client.md#reading-the-reply), `header()` | A reply's decoded body or a header, from another service |
| [`value($data)`](#wrapping-your-own-data), `new Value($data)` | Anything else, such as a decoded file |

## Reading a value

Call the method for the type you need, right where you read the value. Each one takes a default of that type, and nothing throws:

```php
$id = $request->param('id')->int();
$page = $request->query('page')->int(1);
$theme = $request->cookie('theme')->string('light');
$days = $console->option('days')->int(30);
$count = $db->value($sql)->int();
```

> [!WARNING]
> A `Value` isn't a string. `e($request->data('q'))` and `"Hello {$request->param('name')}"` throw an `Error`, `blank($request->data('q'))` throws a `LogicException`, and a comparison such as `$request->query('lang') === 'en'` is always false. Call `string()` first.

### Strings

`string()` returns strings as they are and casts numbers and `Stringable` objects. A backed enum reads as its value, such as `'admin'` for `Role::Admin`. Booleans, arrays and other objects give the default.

### Numbers

`int()` and `float()` read numbers with PHP's `filter_var()`, which allows spaces around a number but not trailing text:

```php
new Value('12')->int();      // 12
new Value(' 12 ')->int();    // 12
new Value('12abc')->int();   // 0
new Value('1.5')->int();     // 0
new Value('1.5')->float();   // 1.5
```

Codes with leading zeros, such as zip codes, need `string()`, since `filter_var()` doesn't treat `'007'` or `'1e3'` as whole numbers. `int()` reads an int-backed enum case as its value.

### Booleans

`bool()` reads `'1'`, `'true'`, `'on'` and `'yes'` as `true`, and `'0'`, `'false'`, `'off'`, `'no'` and `''` as `false`. Anything else gives the default. An unticked checkbox sends nothing, which reads as `false`:

```php
$remember = $request->data('remember')->bool();
```

### Arrays

`items()` returns each item of an array as a `Value`, under its key, and `[]` for anything that isn't an array. Here `tags[]=php&tags[]=sql` becomes a list of strings:

```php
$tags = array_map(fn(Value $tag): string => $tag->string(), $request->query('tags')->items());
```

`rawArray()` returns the array as it arrived, or the default for anything else. Its items aren't checked, and a visitor's `tags[][]=x` puts an array where you expect a string. Use it to pass an array on whole, as when [keeping the filters](requests.md#the-query-string) in a link to the next page.

### Enums

`enum()` reads a case of a backed enum. Pass the case to fall back on, and a value that matches no case gives it:

```php
$sort = $request->query('sort')->enum(Sort::Newest);
$priority = $request->query('priority')->enum(Priority::Normal);
```

An int-backed enum reads the value as `int()` does, and `'2'` gives the case whose value is 2.

## Nested data

`get()` reads an array key or a public property as another `Value`, and more keys read deeper. The readers that hand you a `Value`, such as `data()`, `query()` and `$session->get()`, take the same path of keys:

```php
$name = $value->get('profile', 'name')->string();
$sku = $request->data('items', 0, 'sku')->string();
```

A missing key at any level gives an empty `Value`, and no key at all gives the whole thing. Private and protected properties count as missing.

Together with [`items()`](#arrays), `get()` reads a list of records:

```php
foreach ($reply->data('items')->items() as $item) {
    $skus[] = $item->get('sku')->string();
}
```

## Missing and required values

A default can't tell you whether a value was sent, since `string()` gives `''` for an empty field and a missing one alike. When the difference matters, `$value->raw` holds the value as it arrived, or `null` when it's missing:

```php
$editing = $request->param('id')->raw !== null;
```

Sapling has no validation layer, and you check required fields yourself after reading them:

```php
$body = $request->data('body')->string();

if (blank($body)) {
    return Response::text('A note needs some text.', 422);
}
```

## Wrapping your own data

Wrap any loosely typed data in a `Value` with the [`value()`](helpers.md#function-reference) helper:

```php
$json = file_get_contents(__DIR__ . '/settings.json');
$settings = value(Data::fromJson($json));
$port = $settings->get('server', 'port')->int(8080);
```

## Method reference

| Method | What it returns |
| --- | --- |
| `string($default = '')` | A string. Numbers, `Stringable` objects and backed enums are cast, and a boolean gives the default |
| `int($default = 0)` | A whole number. `'12'` is 12, while `'12abc'` and `'1.5'` give the default |
| `float($default = 0.0)` | A number, such as 1.5 from `'1.5'` |
| `bool($default = false)` | `true` for `'1'`, `'true'`, `'on'` and `'yes'`, and `false` for `'0'`, `'false'`, `'off'`, `'no'` and `''` |
| `enum($default)` | The case of the default's enum with this value, or the default when no case has it |
| `items()` | Each item of an array as a `Value`, under its key, or `[]` when it isn't an array |
| `get(...$keys)` | An array key or public property, as a `Value`. More keys read deeper, and no key gives the same value |
| `rawArray($default = [])` | The array as it arrived, with its items unchecked, or the default for anything else |
| `$value->raw` | The value as it arrived, or `null` when it's missing. Read-only |
| `new Value($raw)` | Wraps `$raw`, where `null` means missing. A `Value` passed in is unwrapped |
