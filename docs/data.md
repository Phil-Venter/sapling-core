# Converting data

- [Introduction](#introduction)
- [JSON](#json)
- [Forms and query strings](#forms-and-query-strings)
- [XML](#xml)
- [Decoding by content type](#decoding-by-content-type)
- [Method reference](#method-reference)

## Introduction

`Sapling\Data` converts arrays to and from JSON and form strings, with a pair of static methods for each format that work without an app. Broken JSON throws.

## JSON

`toJson()` and `fromJson()` turn arrays into JSON and back:

```php
$json = Data::toJson(['name' => 'Ann', 'city' => 'Cape Town']);
$user = Data::fromJson($json);
```

`toJson()` leaves slashes and Unicode as they are, and `fromJson()` decodes objects as arrays. Both throw a `JsonException` when the data can't be encoded or decoded.

> [!NOTE]
> Because objects become arrays, `{}` decodes to `[]`, which encodes as a list. Use `new stdClass()` where another service needs `{}`.

## Forms and query strings

A URL-encoded form body and a query string are the same format, and one pair of methods covers both:

```php
$query = Data::toForm(['q' => 'notes', 'page' => 2]); // q=notes&page=2
$fields = Data::fromForm('a=1&b[]=2&b[]=3');          // ['a' => '1', 'b' => ['2', '3']]
```

## XML

`Data` leaves XML to PHP's SimpleXML, which needs the `simplexml` extension, and `decode()` gives an XML body back as a string. `simplexml_load_string()` reads it into objects, and `(string)` turns an element into its text:

```php
$feed = simplexml_load_string($reply->body);

$titles = [];
foreach ($feed->channel->item as $item) {
    $titles[] = (string) $item->title;
}
```

Broken XML raises a warning, which Sapling turns into a crash in an action or a command.

## Decoding by content type

When a body could be in any of these formats, `decode()` picks the right one from its `Content-Type`:

```php
$data = Data::decode($body, 'application/json; charset=utf-8');
```

A `+json` suffix counts, as in `application/problem+json`. An empty body gives `null`, and any other type gives the body back as it is. `data()` on requests, responses and replies from other services decodes the same way and returns a `Value`. For data you decode yourself, where a key you expect may be missing, [wrap it in a `Value`](values.md#wrapping-your-own-data) to read nested keys safely.

## Method reference

| Method | What it does |
| --- | --- |
| `toJson($data)` | Returns JSON, with slashes and Unicode as they are. Throws `JsonException` when it can't |
| `fromJson($json)` | Decodes JSON, with objects as arrays. Throws `JsonException` when it's invalid |
| `toForm($data)` | Returns a URL-encoded form body, the same format as a query string |
| `fromForm($form)` | Decodes a URL-encoded form body |
| `decode($body, $contentType)` | Decodes JSON or a form by its `Content-Type`. Any other type gives the body as it is |
