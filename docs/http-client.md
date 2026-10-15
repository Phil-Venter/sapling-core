# Talking to other services

- [Introduction](#introduction)
- [Making requests](#making-requests)
    - [Sending JSON](#sending-json)
    - [Sending forms and files](#sending-forms-and-files)
    - [Headers](#headers)
- [Reading the reply](#reading-the-reply)
    - [Errors](#errors)
- [Timeouts, redirects and TLS](#timeouts-redirects-and-tls)
- [Method reference](#method-reference)

## Introduction

Most apps call an API or two, such as a payment provider or a weather service. `Sapling\Http\HttpClient` covers that without a client library. It sends a request with curl, and the reply comes back as a `Response`, the same class your actions return.

## Making requests

Ask for `HttpClient` in a constructor. Every request goes through one method, `request()`, which takes the method and the URL:

```php
$reply = $this->http->request('GET', 'https://api.example.com/status');
```

### Sending JSON

A string body is sent exactly as it is, so it needs its `Content-Type` alongside it. For JSON, encode the data with [`Data`](data.md) and say so in the headers:

```php
$body = Data::toJson(['name' => 'Ann']);
$headers = ['Content-Type' => 'application/json'];

$reply = $this->http->request('POST', 'https://api.example.com/users', $body, $headers);
```

Without a `Content-Type`, curl labels a string body as a form.

### Sending forms and files

An array body is sent as multipart form data instead, which means it can include a file upload as a `CURLFile`:

```php
$body = [
    'title' => 'Report',
    'file' => new CURLFile($path, 'application/pdf'),
];

$reply = $this->http->request('POST', 'https://api.example.com/uploads', $body);
```

For a URL-encoded form, send [`Data::toForm()`](data.md) with an `application/x-www-form-urlencoded` content type.

### Headers

Headers are the fourth argument, so name it when there's no body:

```php
$reply = $this->http->request('GET', 'https://api.example.com/me', headers: [
    'Authorization' => "Bearer {$token}",
]);
```

## Reading the reply

Since the reply is a [`Response`](requests.md#reading-a-response), it's read the same way as any other. `data()` decodes the body by its `Content-Type` and returns a [`Value`](values.md):

```php
$me = $reply->data();                          // ['profile' => ['name' => 'bob', 'age' => 30]]
$name = $me->get('profile', 'name')->string(); // 'bob'
```

A reply's header names are lowercase, and a header the service sent more than once is a list. `header()` finds one whatever case you write it in:

```php
$retryAfter = $reply->header('Retry-After')->int(60);
```

### Errors

A 404 or a 500 from the other service is still a normal reply. Only a failed connection or a timeout throws a `RuntimeException`, so check the status before using the data:

```php
if ($reply->status !== 200) {
    error_log("Weather API answered {$reply->status}");

    return Response::text('The forecast is unavailable.', 503);
}
```

## Timeouts, redirects and TLS

A request gives up after 30 seconds. When calls happen while a visitor waits for a page, register a client with a shorter timeout in `bootstrap.php`, so a slow service can't hold them up:

```php
$app->container->set(HttpClient::class, fn() => new HttpClient(timeout: 5));
```

GET and HEAD requests follow up to 5 redirects. Other methods get the redirect back as the reply, so a POST is never sent twice. TLS certificates are always checked, and compressed replies are unpacked for you.

## Method reference

| Method | What it does |
| --- | --- |
| `request($method, $url, $body = '', $headers = [])` | Sends a request and returns the reply as a `Response` |
| `new HttpClient($timeout = 30)` | A client that gives up after `$timeout` seconds |
| `$reply->data()` | Returns the reply's body decoded as JSON, XML or a form, as a [`Value`](values.md). See [Reading a response](requests.md#reading-a-response) |
| `$reply->status`, `$reply->headers`, `$reply->body` | The reply. Header names are lowercase |
