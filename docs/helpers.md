# Helpers

- [Introduction](#introduction)
- [Escaping HTML](#escaping-html)
    - [Attributes](#attributes)
- [Stopping with an error](#stopping-with-an-error)
- [Checking for empty values](#checking-for-empty-values)
- [Dates and times](#dates-and-times)
- [Ids and debugging](#ids-and-debugging)
- [Replacing a helper](#replacing-a-helper)
- [Function reference](#function-reference)

## Introduction

A few things happen in almost every file, such as escaping a value or stopping with an error. Sapling has short global functions for those. They need no app, so they work in templates and scripts too.

## Escaping HTML

`e()` escapes a value for HTML, quotes included, and prints a backed enum as its value:

```php
<p><?= e($note['body']) ?></p>
<span class="status"><?= e($note['status']) ?></span>
```

### Attributes

Attributes are fiddlier, since some should be left out and some have no value at all. `attrs()` builds them from an array and escapes each one:

```php
<li <?= attrs(['class' => ['note', 'pinned'], 'data-id' => 7, 'hidden' => true, 'title' => null]) ?>>

// <li class="note pinned" data-id="7" hidden>
```

`true` gives a bare attribute such as `hidden`, while `null`, `false` and empty values leave the attribute out, and an array is joined with spaces. `attr()` builds a single attribute the same way.

## Stopping with an error

When the visitor asks for something that doesn't exist or isn't theirs, `abort()` stops the request with an error status. `abort_if()` does the same when its condition is true:

```php
abort_if($note === null, 404, 'That note does not exist.');
abort(403);
```

Both throw an `HttpException`, which becomes the response. [Errors the visitor causes](errors.md#errors-the-visitor-causes) covers the details.

## Checking for empty values

`blank()` answers whether a value is empty, meaning `null`, a string of only whitespace, or an empty array or `Countable`. Unlike `empty()`, it treats `'0'`, `0` and `false` as real values:

```php
blank('  ');     // true
blank([]);       // true
blank('0');      // false
blank(0);        // false
blank(false);    // false
```

A [`Value`](values.md) never looks empty, so `blank()` throws a `LogicException` when it's given one. Read it as a type first:

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

Without a timezone it uses the [`timezone` setting](config.md#settings-sapling-reads). A date string without a zone is read in the same timezone.

An unknown timezone throws a `DateInvalidTimeZoneException`, and a blank string or a date PHP can't read throws a `DateMalformedStringException`. Catch them where a visitor supplies the value:

```php
try {
    $start = datetime($request->data('start')->string(), 'Africa/Johannesburg');
} catch (DateMalformedStringException) {
    return Response::text('That start date is not a date.', 422);
}
```

## Ids and debugging

`uuid()` returns a version 7 UUID, which sorts by the time it was made, so it suits database keys.

`dd()` dumps its values and stops:

```php
dd($request, $note);
```

## Replacing a helper

Each helper is skipped when a function with its name already exists, so an app can replace one by defining its own before `vendor/autoload.php` loads. Sapling's classes never call the helpers, though `attr()` and `attrs()` call `e()` and `blank()` and follow your versions.

> [!WARNING]
> A package can define the same names first. Laravel's `illuminate/support` has its own `e()` and `blank()`, and Laravel's `abort()` turns a 404 into a 500 here.

## Function reference

| Function | What it does |
| --- | --- |
| `e($value)` | Escapes a value for HTML. A backed enum prints its value |
| `attrs($attributes)` | Returns several HTML attributes, leaving out the empty ones. An array value is joined with spaces |
| `attr($key, $value)` | Returns one escaped HTML attribute. `true` gives a bare attribute, and `null`, `false` or an empty value gives `null` |
| `abort($status, $message = '', $headers = [])` | Throws an `HttpException`, which becomes the response |
| `abort_if($condition, $status, $message = '', $headers = [])` | Throws like `abort()` when the condition is true |
| `blank($value)` | Returns whether the value is `null`, a blank string or an empty array or Countable. `'0'`, `0` and `false` aren't blank. Throws on a `Value` |
| `datetime($value = null, $timezone = null)` | Returns a `DateTimeImmutable` in `$timezone`, a name or a `DateTimeZone`, or in the default timezone. A date string without its own zone is read in `$timezone`, and a blank one throws |
| `uuid()` | Returns a version 7 UUID, which sorts by creation time |
| `dd($value, ...$more)` | Dumps the values and stops |
