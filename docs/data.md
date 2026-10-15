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

`Sapling\Data` converts PHP arrays to and from JSON, XML and form strings. Its static methods need no app, and broken input always throws.

## JSON

```php
$json = Data::toJson(['name' => 'Ann', 'city' => 'Cape Town']);
$user = Data::fromJson($json);
```

`toJson()` doesn't escape slashes or Unicode. `fromJson()` decodes objects as arrays. Both throw a `JsonException` on failure.

> [!NOTE]
> `{}` decodes to `[]`, which encodes as a list. Use `new stdClass()` when you need `{}`.

## Forms and query strings

```php
$query = Data::toForm(['q' => 'notes', 'page' => 2]);   // q=notes&page=2
$fields = Data::fromForm('a=1&b[]=2&b[]=3');
```

Form bodies and query strings use the same format.

## XML

```php
$xml = Data::toXml(['id' => 7, 'items' => ['item' => ['Pen', 'Paper']]], root: 'order');
$order = Data::fromXml($xml);
```

Invalid XML throws a `RuntimeException`. External entities are never fetched.

### How XML maps to arrays

- Elements become keys, and repeated elements become lists. One `<item>` is a string and two are a list, so check with `is_array()` when the count varies.
- `@name` keys are attributes, and `#text` is text beside attributes.
- Values are strings.
- Namespace prefixes stay on the keys, as in `soap:Body`.
- A root holding only text decodes to a string.

## Decoding by content type

`decode()` picks the format from a `Content-Type`:

```php
$data = Data::decode($body, 'application/json; charset=utf-8');
```

Suffixes count, so `application/problem+json` is JSON. An empty body gives `null`, and other types give the body as is. `$request->data()` and `$response->data()` use it.

## Reading nested data

Wrap decoded data in a [`Value`](values.md) to read nested keys safely:

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
