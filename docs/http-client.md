# Talking to other services

- [Introduction](#introduction)
- [Making requests](#making-requests)
    - [Sending JSON](#sending-json)
    - [Sending forms and files](#sending-forms-and-files)
    - [Headers](#headers)
- [Reading the reply](#reading-the-reply)
    - [Errors](#errors)
- [Timeouts](#timeouts)
- [How it behaves](#how-it-behaves)
- [Method reference](#method-reference)

## Introduction

`Sapling\Http\HttpClient` sends HTTP requests with curl and returns the reply as a `Response`. It's enough for calling a JSON API without a client library.

## Making requests

Take `HttpClient` in a constructor and call `request()`:

```php
$reply = $this->http->request('GET', 'https://api.example.com/status');
```

### Sending JSON

A string body is sent as is, so set its `Content-Type`:

```php
$body = Data::toJson(['name' => 'Ann']);
$headers = ['Content-Type' => 'application/json'];

$reply = $this->http->request('POST', 'https://api.example.com/users', $body, $headers);
```

Without one, curl sends it as a form.

### Sending forms and files

An array body is sent as multipart form data, and can include a `CURLFile`:

```php
$body = [
    'title' => 'Report',
    'file' => new CURLFile($path, 'application/pdf'),
];

$reply = $this->http->request('POST', 'https://api.example.com/uploads', $body);
```

For a URL-encoded form, send [`Data::toForm()`](data.md) with an `application/x-www-form-urlencoded` content type.

### Headers

Headers are the fourth argument:

```php
$reply = $this->http->request('GET', 'https://api.example.com/me', headers: [
    'Authorization' => "Bearer {$token}",
]);
```

## Reading the reply

The reply is a [`Response`](requests.md#reading-a-response). `data()` decodes its body as a [`Value`](values.md):

```php
$me = $reply->data();
$name = $me->get('profile', 'name')->string();
```

Reply header names are lowercase, and a header sent more than once is a list. `header()` is case-insensitive:

```php
$retryAfter = $reply->header('Retry-After')->int(60);
```

### Errors

Error statuses are normal responses. Only a failed connection or a timeout throws a `RuntimeException`, so check the status:

```php
if ($reply->status !== 200) {
    error_log("Weather API answered {$reply->status}");

    return Response::text('The forecast is unavailable.', 503);
}
```

## Timeouts

Requests time out after 30 seconds. For calls made while a visitor waits, register a shorter timeout:

```php
$app->container->set(HttpClient::class, fn() => new HttpClient(timeout: 5));
```

## How it behaves

- GET and HEAD follow up to 5 redirects. Other methods return the redirect, so a POST is never resent.
- TLS certificates are always verified, and compressed replies are decompressed.

> [!TIP]
> Keep API keys in `config.php`, or encrypted with [Crypto](encryption.md) when each tenant has its own.

## Method reference

| Method | What it does |
| --- | --- |
| `request($method, $url, $body = '', $headers = [])` | Sends a request and returns the reply as a `Response` |
| `new HttpClient($timeout = 30)` | A client that gives up after `$timeout` seconds |
| `$reply->data()` | Returns the reply's body decoded as JSON, XML or a form, as a [`Value`](values.md). See [Reading a response](requests.md#reading-a-response) |
| `$reply->status`, `$reply->headers`, `$reply->body` | The reply. Header names are lowercase |
