# Helpers

- [Introduction](#introduction)
- [Escaping HTML](#escaping-html)
- [Stopping with an error](#stopping-with-an-error)
- [Checking for empty values](#checking-for-empty-values)
- [Dates and times](#dates-and-times)
- [Dumping values](#dumping-values)
- [Replacing a helper](#replacing-a-helper)
- [Function reference](#function-reference)

## Introduction

Sapling has a few global functions for common jobs, such as escaping a value or stopping with an error, and they work without an app.

## Escaping HTML

`e()` escapes a value for HTML, and `attrs()` builds escaped attributes, as [Printing values](templates.md#printing-values) shows. Read a [`Value`](values.md#reading-a-value) as a type first, as in `e($request->query('q')->string())`.

## Stopping with an error

`abort()` stops the request with an [error status](errors.md#errors-the-visitor-causes):

```php
if ($note === null) abort(404, 'That note does not exist.');
```

## Checking for empty values

`blank()` answers whether a value is empty, meaning `null`, a string of only whitespace, or an empty array or `Countable`. Unlike `empty()`, it treats `'0'`, `0` and `false` as real values:

```php
blank('  ');     // true
blank([]);       // true
blank('0');      // false
blank(0);        // false
blank(false);    // false
```

As with `e()`, read a [`Value`](values.md#reading-a-value) as a type first:

```php
$email = $console->arg(0)->string();

if (blank($email)) {
    throw new UsageException('Usage: php bin/console users:add <email>');
}
```

## Dates and times

`datetime()` returns a `DateTimeImmutable` from a date string, a timestamp or another date, in the timezone you ask for:

```php
$now = datetime();
$meeting = datetime('2026-10-15 09:00', 'Africa/Johannesburg');
$created = datetime($row['created_at'], 'UTC');
```

When you don't pass a timezone, it uses the [`timezone` setting](config.md#settings-sapling-reads). Either way, a date string without a zone of its own is read in that timezone.

An unknown timezone throws a `DateInvalidTimeZoneException`, and a blank string or a date PHP can't read throws a `DateMalformedStringException`. Catch them where a visitor supplies the value:

```php
try {
    $start = datetime($request->data('start')->string(), 'Africa/Johannesburg');
} catch (DateMalformedStringException) {
    return Response::text('That start date is not a date.', 422);
}
```

## Dumping values

`dd()` dumps its values and stops:

```php
dd($request, $note);
```

## Replacing a helper

Each helper is skipped when a function with its name already exists, which lets an app replace one by defining its own before `vendor/autoload.php` loads. Sapling's classes never call the helpers, though `attrs()` calls `e()` and `blank()`, and uses your versions of them.

## Function reference

| Function | What it does |
| --- | --- |
| `e($value)` | Escapes a value for HTML. A backed enum prints its value. Throws on a `Value` |
| `attrs($attributes)` | Returns escaped HTML attributes. `true` gives a bare attribute, `null`, `false` or an empty value leaves one out, and an array value is joined with spaces |
| `abort($status, $message = '', $headers = [], $previous = null)` | Throws an `HttpException`, which becomes the response |
| `blank($value)` | Returns whether the value is `null`, a blank string or an empty array or Countable. `'0'`, `0` and `false` aren't blank. Throws on a `Value` |
| `value($raw)` | Returns `new Value($raw)`, for [wrapping your own data](values.md#wrapping-your-own-data) |
| `datetime($value = null, $timezone = null)` | Returns a `DateTimeImmutable` in `$timezone`, a name or a `DateTimeZone`, or in the default timezone. A date string without its own zone is read in `$timezone`, and a blank one throws |
| `dd($value, ...$more)` | Dumps the values and stops |
