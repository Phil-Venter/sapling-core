# Talking to other services

- [Introduction](#introduction)
- [Making requests](#making-requests)
    - [Sending data](#sending-data)
    - [Headers](#headers)
    - [Uploading files](#uploading-files)
- [Reading the reply](#reading-the-reply)
    - [Errors](#errors)
- [Sending requests at once](#sending-requests-at-once)
- [Retrying and caching](#retrying-and-caching)
- [Timeouts, redirects and TLS](#timeouts-redirects-and-tls)
- [Method reference](#method-reference)
    - [HttpClient](#httpclient)
    - [ClientRequest](#clientrequest)
    - [ClientResponse](#clientresponse)

## Introduction

`Sapling\Client\HttpClient` calls other services, such as a payment provider or a weather API, through curl. Each reply is a `Sapling\Client\ClientResponse`.

## Making requests

Ask for `HttpClient` in a constructor. A `Sapling\Client\ClientRequest` describes one request, and `send()` sends it and returns the reply:

```php
$status = $this->http->send(new ClientRequest('GET', 'https://api.example.com/status'));
```

### Sending data

`ClientRequest` has a constructor for each body format, which encodes the data and sets its `Content-Type`:

```php
ClientRequest::json('POST', 'https://api.example.com/users', ['name' => 'Ann']);
ClientRequest::form('POST', 'https://api.example.com/login', ['user' => 'ann', 'password' => $password]);
ClientRequest::text('PUT', 'https://api.example.com/notes/7/body', 'Buy milk');
```

`json()` without data sends no body and no `Content-Type`, while `[]` sends `[]` and `new stdClass()` sends `{}`. For another format, such as XML, pass the body and its `Content-Type` to the constructor.

### Headers

Headers are the last argument of every constructor, and one you pass replaces the default of the same name, whatever its case:

```php
$me = $this->http->send(new ClientRequest('GET', 'https://api.example.com/me', headers: ['Authorization' => "Bearer {$token}"]));

ClientRequest::json('PATCH', 'https://api.example.com/users/7', $changes, ['Content-Type' => 'application/merge-patch+json']);
```

### Uploading files

An array body is sent as multipart form data, with files as `CURLFile`:

```php
$body = [
    'title' => 'Report',
    'file' => new CURLFile($path, 'application/pdf'),
];

$reply = $this->http->send(new ClientRequest('POST', 'https://api.example.com/uploads', $body));
```

## Reading the reply

A reply has its `status`, `headers` and `body`. `data()` decodes the body by its `Content-Type` and returns a [`Value`](values.md), and more keys read deeper:

```php
$name = $reply->data('profile', 'name')->string(); // 'bob' from {"profile": {"name": "bob"}}
```

A JSON body that doesn't decode, such as a cut-off reply, makes `data()` throw a `JsonException`, which is a crash. An XML body stays a string, which you can [read with SimpleXML](data.md#xml).

A reply's header names are lowercase, and a header the service sent more than once is a list, read with `rawArray()`. The name you pass to `header()` is case-insensitive:

```php
$retryAfter = $reply->header('Retry-After')->int(60);
```

### Errors

A 404 or a 500 from the other service is still a normal reply. So is a connection that fails or times out, with status 0 and the error as its body. Check the status before using the data:

```php
if ($reply->status !== 200) {
    error_log("Weather API answered {$reply->status}");

    return Response::text('The forecast is unavailable.', 503);
}
```

## Sending requests at once

`sendAll()` sends several requests at once and returns the replies under the same keys. An action that calls several services waits only for the slowest:

```php
[$weather, $news] = $this->http->sendAll([
    new ClientRequest('GET', 'https://weather.example.com/today'),
    new ClientRequest('GET', 'https://news.example.com/top'),
]);
```

Named keys work the same way, and `sendAll(['weather' => $a, 'news' => $b])` returns `['weather' => …, 'news' => …]`. It opens at most 6 connections to one host and queues the rest, and a failed connection comes back with status 0, as from `send()`, without losing the other replies.

## Retrying and caching

`HttpClient` sends each request once and keeps no replies. Retry only requests that change nothing, such as a GET. This function tries one up to 3 times, waiting 1 second and then 2, while the connection fails or the service answers 429, 502, 503 or 504:

```php
use Sapling\Client\ClientRequest;
use Sapling\Client\ClientResponse;
use Sapling\Client\HttpClient;

function fetch(HttpClient $http, string $url, int $tries = 3): ClientResponse
{
    $delay = 1;

    for ($try = 1; $try < $tries; $try++) {
        $reply = $http->send(new ClientRequest('GET', $url));

        if (!in_array($reply->status, [0, 429, 502, 503, 504], true)) {
            return $reply;
        }

        sleep($delay);
        $delay *= 2;
    }

    return $http->send(new ClientRequest('GET', $url));
}
```

The last try returns its reply, whatever its status. The waits hold up a visitor, which makes it a better fit for commands than for actions.

To cache a reply, store its body with its `Content-Type`. A reply rebuilt from both with `new ClientResponse()` decodes with `data()` as the original did.

## Timeouts, redirects and TLS

A request gives up after 30 seconds. When a visitor waits on it, register a client with a shorter timeout in `bootstrap.php`:

```php
$app->container->set(HttpClient::class, fn(): HttpClient => new HttpClient(timeout: 5));
```

A redirect comes back as the reply, as an error status does. To follow one, send a new request to its `Location` header, which can also be a path on the same host. Only `http` and `https` URLs are allowed, TLS certificates are always checked, and compressed replies are unpacked.

> [!WARNING]
> A URL from a visitor, such as a webhook or a link to preview, can still reach services on your own network, such as `localhost` or a cloud server's metadata at `169.254.169.254`. Check its host before sending it, and again for each redirect you follow.

## Method reference

### HttpClient

| Method | What it does |
| --- | --- |
| `send($request)` | Sends a `ClientRequest` and returns the reply as a `ClientResponse`. A failed connection comes back as status 0, with the error as its body |
| `sendAll($requests)` | Sends `ClientRequest`s at once and returns their replies under the same keys |
| `fake($answer)` | Answers every request with the closure `$answer` instead of curl, for [tests](testing.md#faking-other-services). Returns the client |
| `new HttpClient($timeout = 30)` | A client whose requests give up after `$timeout` seconds |

### ClientRequest

| Method | What it does |
| --- | --- |
| `ClientRequest::json($method, $url, $data = null, $headers = [])` | A request with `$data` as JSON, or no body without data |
| `ClientRequest::form($method, $url, $data, $headers = [])` | A request with `$data` as a URL-encoded form |
| `ClientRequest::text($method, $url, $text, $headers = [])` | A request with a plain text body |
| `new ClientRequest($method, $url, $body = '', $headers = [])` | A request. A string body goes as it is, and an array as multipart form data. Header names are lowercased |

### ClientResponse

| Method | What it does |
| --- | --- |
| `$reply->header($name)` | Returns a header as a `Value`. The name is case-insensitive, and a header sent more than once is a list, read with `rawArray()` |
| `$reply->data(...$keys)` | Returns a field from the reply's body, decoded as JSON or a form, as a [`Value`](values.md). No key gives the whole body. Throws `JsonException` when a JSON body is invalid |
| `$reply->status`, `$reply->headers`, `$reply->body` | The reply. Header names are lowercase |
| `new ClientResponse($body, $status, $headers)` | A reply built by hand, for tests or from a cache |
