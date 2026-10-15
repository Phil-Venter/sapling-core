# Helpers

- [Introduction](#introduction)
- [Escaping HTML](#escaping-html)
- [HTML attributes](#html-attributes)
- [Stopping with an error](#stopping-with-an-error)
- [Checking for empty values](#checking-for-empty-values)
- [Dates and times](#dates-and-times)
- [Unique ids](#unique-ids)
- [Values or closures](#values-or-closures)
- [Debugging](#debugging)
- [Replacing a helper](#replacing-a-helper)
- [Function reference](#function-reference)

## Introduction

Sapling has a few global functions, such as `e()` and `abort()`. They need no app.

## Escaping HTML

`e()` escapes a value for HTML, including quotes. Backed enums print their value:

```php
<p><?= e($note['body']) ?></p>
<span class="status"><?= e($note['status']) ?></span>
```

> [!WARNING]
> Nothing is escaped automatically. Use `e()`, or `attrs()` for attributes.

## HTML attributes

`attrs()` builds escaped attributes and skips empty ones:

```php
<li <?= attrs(['class' => ['note', 'pinned'], 'data-id' => 7, 'hidden' => true, 'title' => null]) ?>>

// <li class="note pinned" data-id="7" hidden>
```

`true` gives a bare attribute. `null`, `false` and empty values are skipped. Arrays are joined with spaces. `attr()` builds a single attribute.

## Stopping with an error

`abort()` throws an `HttpException`, and `abort_if()` does when its condition is true:

```php
abort_if($note === null, 404, 'That note does not exist.');
abort(403);
```

See [Errors the visitor causes](errors.md#errors-the-visitor-causes).

## Checking for empty values

`blank()` is true for `null`, whitespace-only strings, and empty arrays and `Countable`s:

```php
blank('  ');     // true
blank([]);       // true
blank('0');      // false
blank(0);        // false
blank(false);    // false
```

Unlike `empty()`, `'0'`, `0` and `false` aren't blank. Read a [`Value`](values.md) as a type first. Passing one throws a `LogicException`:

```php
$email = $console->arg(0)->string();

if (blank($email)) {
    $console->error('Usage: php bin/console users:add <email>');

    return Console::FAILURE;
}
```

## Dates and times

`datetime()` returns a `DateTimeImmutable` from a date string, a timestamp or another date:

```php
$now = datetime();
$meeting = datetime('2026-10-15 09:00', 'Africa/Johannesburg');
$created = datetime($row['created_at'], 'UTC');
```

The result is in the given timezone, or the [`timezone` setting](config.md#settings-sapling-reads). A string without its own zone is read in that timezone.

An unknown timezone throws a `DateInvalidTimeZoneException`, and an unreadable date a `DateMalformedStringException`. Catch them for visitor input:

```php
try {
    $start = datetime($request->input('start')->string(), 'Africa/Johannesburg');
} catch (DateMalformedStringException) {
    return Response::text('That start date is not a date.', 422);
}
```

## Unique ids

`uuid()` returns a version 7 UUID, which sorts by creation time.

## Values or closures

`value()` returns a value, or calls it when it's a closure:

```php
value('Hello');                                   // 'Hello'
value(fn(string $name) => "Hello {$name}", 'Ann');   // 'Hello Ann'
```

## Debugging

`dd()` dumps its values and stops:

```php
dd($request, $note);
```

Don't commit it.

## Replacing a helper

A helper is skipped when a function with that name already exists. Define your own before `vendor/autoload.php` to replace it. Sapling's own code never calls the helpers.

> [!WARNING]
> Packages can define the same names first. Laravel's `illuminate/support` has its own `e()`, `blank()` and `value()`, and Laravel's `abort()` turns a 404 into a 500 here.

## Function reference

| Function | What it does |
| --- | --- |
| `abort($status, $message = '', $headers = [])` | Throws an `HttpException`, which becomes the response |
| `abort_if($condition, $status, $message = '', $headers = [])` | Throws like `abort()` when the condition is true |
| `attr($key, $value)` | Returns one escaped HTML attribute. `true` gives a bare attribute, and `null`, `false` or an empty value gives `null` |
| `attrs($attributes)` | Returns several HTML attributes, leaving out the empty ones. An array value is joined with spaces |
| `blank($value)` | Returns whether the value is `null`, a blank string or an empty array or Countable. `'0'`, `0` and `false` aren't blank. Throws on a `Value` |
| `datetime($value = null, $timezone = null)` | Returns a `DateTimeImmutable` in `$timezone`, a name or a `DateTimeZone`, or in the default timezone. A date string without its own zone is read in `$timezone` |
| `dd($value, ...$more)` | Dumps the values and stops |
| `e($value)` | Escapes a value for HTML. A backed enum prints its value |
| `uuid()` | Returns a version 7 UUID, which sorts by creation time |
| `value($value, ...$args)` | Returns the value, or the closure's result when it's a closure |
