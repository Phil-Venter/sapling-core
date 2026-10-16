# Helpers

- [Introduction](#introduction)
- [Escaping HTML](#escaping-html)
    - [Attributes](#attributes)
- [Stopping with an error](#stopping-with-an-error)
- [Checking for empty values](#checking-for-empty-values)
- [Dates and times](#dates-and-times)
- [Ids, closures and debugging](#ids-closures-and-debugging)
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

A [`Value`](values.md) has to be read as a type first, since the object itself never looks empty, so `blank()` throws a `LogicException` when it's given one:

```php
$email = $console->arg(0)->string();

if (blank($email)) {
    $console->error('Usage: php bin/console users:add <email>');

    return Console::FAILURE;
}
```

## Dates and times

`datetime()` returns a `DateTimeImmutable` from a date string, a timestamp or another date, in the timezone you ask for:

```php
$now = datetime();
$meeting = datetime('2026-10-15 09:00', 'Africa/Johannesburg');
$created = datetime($row['created_at'], 'UTC');
```

Without a timezone it uses the [`timezone` setting](config.md#settings-sapling-reads), and a date string without its own zone is read in whichever timezone applies.

Input from visitors can be wrong. An unknown timezone throws a `DateInvalidTimeZoneException`, and a date PHP can't read throws a `DateMalformedStringException`, so catch them where a visitor supplies the value:

```php
try {
    $start = datetime($request->input('start')->string(), 'Africa/Johannesburg');
} catch (DateMalformedStringException) {
    return Response::text('That start date is not a date.', 422);
}
```

## Ids, closures and debugging

`uuid()` returns a version 7 UUID, which sorts by the time it was made, so it suits database keys.

`value()` returns a value as it is, or calls it first when it's a closure:

```php
value('Hello');                                      // 'Hello'
value(fn(string $name) => "Hello {$name}", 'Ann');   // 'Hello Ann'
```

`dd()` dumps its values and stops, which helps while you're working, but shouldn't be committed:

```php
dd($request, $note);
```

## Replacing a helper

Each helper is skipped when a function with its name already exists, so an app can replace one by defining its own before `vendor/autoload.php` loads. Sapling's own code never calls the helpers, so replacing one never changes how Sapling behaves.

> [!WARNING]
> A package can define the same names first. Laravel's `illuminate/support` has its own `e()`, `blank()` and `value()`, and Laravel's `abort()` turns a 404 into a 500 here.

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
