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

`Sapling\Data` converts arrays to and from JSON, XML and form strings, with one pair of methods per format. Broken JSON or XML throws. The methods are static and need no app.

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

A URL-encoded form body and a query string are the same format. One pair of methods covers both:

```php
$query = Data::toForm(['q' => 'notes', 'page' => 2]); // q=notes&page=2
$fields = Data::fromForm('a=1&b[]=2&b[]=3');          // ['a' => '1', 'b' => ['2', '3']]
```

## XML

`toXml()` and `fromXml()` turn arrays into XML and back:

```php
$xml = Data::toXml(['id' => 7, 'items' => ['item' => ['Pen', 'Paper']]], root: 'order');
$order = Data::fromXml($xml);
```

`toXml()` needs keys at the top, since a document has one root element. An empty list becomes an empty element. Invalid XML throws a `RuntimeException`, and nothing external is ever fetched while parsing.

### How XML maps to arrays

XML doesn't map onto arrays one to one. `fromXml()` follows these rules:

- Elements become keys, and repeated elements become lists. One `<item>` is a string and two are a list. Check with `is_array()` when the count varies.
- `@name` keys are attributes, and `#text` is text beside attributes.
- Values are strings, because XML has no types.
- Namespace prefixes stay on the keys, as in `soap:Body`.
- A root holding only text decodes to a string.

## Decoding by content type

When a body could be in any of these formats, `decode()` picks the right one from its `Content-Type`:

```php
$data = Data::decode($body, 'application/json; charset=utf-8');
```

A `+json` or `+xml` suffix counts, as in `application/problem+json`. An empty body gives `null`, and any other type gives the body back as it is. `data()` on requests, responses and replies from other services uses it.

## Reading nested data

Decoded data can hold anything, and a key you expect may be missing. Wrap it in a [`Value`](values.md) to read nested keys safely:

```php
$envelope = value(Data::fromXml($xml));
$price = $envelope->get('soap:Body', 'Price')->string();
```

## Method reference

| Method | What it does |
| --- | --- |
| `toJson($data)` | Returns JSON, with slashes and Unicode as they are. Throws `JsonException` when it can't |
| `fromJson($json)` | Decodes JSON, with objects as arrays. Throws `JsonException` when it's invalid |
| `toForm($data)` | Returns a URL-encoded form body, the same format as a query string |
| `fromForm($form)` | Decodes a URL-encoded form body |
| `toXml($data, $root = 'root')` | Returns an XML document with the data inside a root element. Throws `InvalidArgumentException` when `$data` is a list |
| `fromXml($xml)` | Decodes XML into arrays, or a string when the root only holds text. Throws `RuntimeException` when it's invalid |
| `decode($body, $contentType)` | Decodes JSON, XML or a form by its `Content-Type`. Any other type gives the body as it is |
