# Talking to other services

- [Introduction](#introduction)
- [Making requests](#making-requests)
    - [Headers](#headers)
    - [Other bodies](#other-bodies)
- [Reading the reply](#reading-the-reply)
    - [Errors](#errors)
- [Timeouts, redirects and TLS](#timeouts-redirects-and-tls)
- [Method reference](#method-reference)

## Introduction

Most apps call an API or two, such as a payment provider or a weather service. `Sapling\Http\HttpClient` covers that without a client library. It sends a request with curl, and the reply comes back as a `Response`, the same class your actions return.

## Making requests

Ask for `HttpClient` in a constructor. `request()` sends a request, and `json()` sends one with data as JSON:

```php
$status = $this->http->request('GET', 'https://api.example.com/status');

$user = $this->http->json('POST', 'https://api.example.com/users', ['name' => 'Ann']);
```

`json()` encodes the array with [`Data`](data.md) and sets `Content-Type: application/json`. With no data, it sends no body.

### Headers

Headers are the last argument of both:

```php
$me = $this->http->request('GET', 'https://api.example.com/me', headers: ['Authorization' => "Bearer {$token}"]);
$this->http->json('PUT', 'https://api.example.com/users/7', ['name' => 'Ann'], ['Authorization' => "Bearer {$token}"]);
```

### Other bodies

Other bodies go through `request()`. A string body is sent as it is, so it needs its `Content-Type`:

```php
$xml = Data::toXml($order, root: 'order');

$reply = $this->http->request('POST', 'https://api.example.com/orders', $xml, ['Content-Type' => 'application/xml']);
```

An array body is sent as multipart form data, with files as `CURLFile`:

```php
$body = [
    'title' => 'Report',
    'file' => new CURLFile($path, 'application/pdf'),
];

$reply = $this->http->request('POST', 'https://api.example.com/uploads', $body);
```

For a URL-encoded form, send [`Data::toForm()`](data.md) with an `application/x-www-form-urlencoded` content type.

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

A request gives up after 30 seconds. When a visitor waits on the call, register a client with a shorter timeout in `bootstrap.php`:

```php
$app->container->set(HttpClient::class, fn() => new HttpClient(timeout: 5));
```

GET and HEAD requests follow up to 5 redirects. Other methods get the redirect back as the reply, so a POST is never sent twice. TLS certificates are always checked, and compressed replies are unpacked.

## Method reference

| Method | What it does |
| --- | --- |
| `request($method, $url, $body = '', $headers = [])` | Sends a request and returns the reply as a `Response`. A string body goes as it is, and an array as multipart form data |
| `json($method, $url, $data = [], $headers = [])` | Sends `$data` as JSON and returns the reply |
| `new HttpClient($timeout = 30)` | A client that gives up after `$timeout` seconds |
| `$reply->data()` | Returns the reply's body decoded as JSON, XML or a form, as a [`Value`](values.md). See [Reading a response](requests.md#reading-a-response) |
| `$reply->status`, `$reply->headers`, `$reply->body` | The reply. Header names are lowercase |
