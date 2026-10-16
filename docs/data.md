# Converting data

- [Introduction](#introduction)
- [JSON](#json)
- [Forms and query strings](#forms-and-query-strings)
- [XML](#xml)
    - [How XML maps to arrays](#how-xml-maps-to-arrays)
- [Decoding by content type](#decoding-by-content-type)
- [Reading nested data](#reading-nested-data)
- [Method reference](#method-reference)

## Introduction

PHP's own functions for JSON, XML and form strings each report errors differently and have different defaults. `Sapling\Data` gives every format the same pair of methods, one to encode an array and one to decode it back, and broken input always throws. The methods are static and need no app, so scripts can use them too.

## JSON

JSON is the format most APIs speak:

```php
$json = Data::toJson(['name' => 'Ann', 'city' => 'Cape Town']);
$user = Data::fromJson($json);
```

`toJson()` leaves slashes and Unicode as they are, and `fromJson()` decodes objects as arrays. Both throw a `JsonException` when they can't do their job.

> [!NOTE]
> Because objects become arrays, `{}` decodes to `[]`, which encodes as a list. Use `new stdClass()` where another service needs `{}`.

## Forms and query strings

A URL-encoded form body and a query string are the same format, so one pair of methods covers both:

```php
$query = Data::toForm(['q' => 'notes', 'page' => 2]); // q=notes&page=2
$fields = Data::fromForm('a=1&b[]=2&b[]=3');          // ['a' => 1, 'b' => [2, 3]]
```

## XML

XML is here for the services that only speak XML, such as older SOAP APIs:

```php
$xml = Data::toXml(['id' => 7, 'items' => ['item' => ['Pen', 'Paper']]], root: 'order');
$order = Data::fromXml($xml);
```

Invalid XML throws a `RuntimeException`, and nothing external is ever fetched while parsing.

### How XML maps to arrays

XML doesn't map onto arrays one to one, so `fromXml()` follows a few rules:

- Elements become keys, and repeated elements become lists. One `<item>` is a string and two are a list, so check with `is_array()` when the count varies.
- `@name` keys are attributes, and `#text` is text beside attributes.
- Values are strings, because XML has no types.
- Namespace prefixes stay on the keys, as in `soap:Body`.
- A root holding only text decodes to a string.

## Decoding by content type

When a body could be in any of these formats, `decode()` picks the right one from its `Content-Type`:

```php
$data = Data::decode($body, 'application/json; charset=utf-8');
```

Suffixes count, so `application/problem+json` is JSON. An empty body gives `null`, and any other type gives the body back as it is. `$request->data()` and `$response->data()` both use it.

## Reading nested data

Decoded data can hold anything, and a key you expect may be missing. Wrap it in a [`Value`](values.md) to read nested keys safely, with a default at the end:

```php
$envelope = new Value(Data::fromXml($xml));
$price = $envelope->get('soap:Body', 'Price')->string();
```

## Method reference

| Method | What it does |
| --- | --- |
| `toJson($data)` | Returns JSON, with slashes and Unicode as they are. Throws `JsonException` when it can't |
| `fromJson($json)` | Decodes JSON, with objects as arrays. Throws `JsonException` when it's invalid |
| `toXml($data, $root = 'root')` | Returns an XML document with the data inside a root element |
| `fromXml($xml)` | Decodes XML into arrays, or a string when the root only holds text. Throws `RuntimeException` when it's invalid |
| `toForm($data)` | Returns a URL-encoded form body, the same format as a query string |
| `fromForm($form)` | Decodes a URL-encoded form body |
| `decode($body, $contentType)` | Decodes JSON, XML or a form by its `Content-Type`. Any other type gives the body as it is |
