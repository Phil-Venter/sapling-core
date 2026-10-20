# Talking to other services

- [Introduction](#introduction)
- [Making requests](#making-requests)
    - [Sending data](#sending-data)
    - [Headers](#headers)
    - [Uploading files](#uploading-files)
- [Sending requests at once](#sending-requests-at-once)
- [Reading the reply](#reading-the-reply)
    - [Errors](#errors)
- [Retrying a request](#retrying-a-request)
- [Caching replies](#caching-replies)
- [Timeouts, redirects and TLS](#timeouts-redirects-and-tls)
- [Faking replies in tests](#faking-replies-in-tests)
- [Method reference](#method-reference)

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
ClientRequest::xml('POST', 'https://api.example.com/orders', $order, root: 'order');
ClientRequest::text('PUT', 'https://api.example.com/notes/7/body', 'Buy milk');
```

`json()` without data sends no body and no `Content-Type`, while `[]` sends `[]` and `new stdClass()` sends `{}`. For another format, pass the body and its `Content-Type` to the constructor.

### Headers

Headers are the last argument of every constructor. One you pass replaces the default of the same name, ignoring case:

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

## Sending requests at once

`sendAll()` sends several requests at once and returns the replies under the same keys. A page that needs several services then waits only for the slowest:

```php
[$weather, $news] = $this->http->sendAll([
    new ClientRequest('GET', 'https://weather.example.com/today'),
    new ClientRequest('GET', 'https://news.example.com/top'),
]);
```

`sendAll(['weather' => $a, 'news' => $b])` returns its replies as `['weather' => …, 'news' => …]`. It opens at most 6 connections to one host and queues the rest. A failed connection comes back with status 0, as from `send()`, without losing the other replies.

## Reading the reply

A reply has its `status`, `headers` and `body`. `data()` decodes the body by its `Content-Type` and returns a [`Value`](values.md), and more keys read deeper:

```php
$name = $reply->data('profile', 'name')->string(); // 'bob' from {"profile": {"name": "bob"}}
```

A JSON or XML body that doesn't decode, such as a cut-off reply, makes `data()` throw a `JsonException` or `RuntimeException`, which is a crash.

A reply's header names are lowercase, and a header the service sent more than once is a list. `header()` ignores case:

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

## Retrying a request

`HttpClient` sends each request once. Retry only requests that change nothing, such as a GET, and keep retries out of pages a visitor is waiting for.

This function tries a GET up to 3 times, waiting 1 second and then 2, when the connection fails or the service answers 429, 502, 503 or 504:

```php
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

The last try returns its reply, whatever its status.

## Caching replies

Sapling doesn't cache replies. A small class around `HttpClient` can keep them in the database. This one keeps successful GET replies for a day:

```php
final readonly class CachedHttp
{
    public function __construct(private Db $db, private HttpClient $http) {}

    public function get(string $url): ClientResponse
    {
        $sql = <<<SQL
            SELECT body, headers
            FROM http_cache
            WHERE url = ?
            AND cached_at > ?
            SQL;

        $row = $this->db->one($sql, [$url, time() - 86400]);

        if ($row !== null) {
            return new ClientResponse($row['body'], 200, Data::fromJson($row['headers']));
        }

        $reply = $this->http->send(new ClientRequest('GET', $url));

        if ($reply->status !== 200) {
            return $reply;
        }

        $sql = <<<SQL
            INSERT OR REPLACE INTO http_cache (url, body, headers, cached_at)
            VALUES (?, ?, ?, ?)
            SQL;

        $this->db->run($sql, [$url, $reply->body, Data::toJson($reply->headers), time()]);

        return $reply;
    }
}
```

It needs a table, created in a [migration](database.md#migrations):

```sql
CREATE TABLE http_cache (
    url TEXT PRIMARY KEY,
    body TEXT NOT NULL,
    headers TEXT NOT NULL,
    cached_at INTEGER NOT NULL
);
```

Only 200 replies are kept. A 404 or an outage isn't remembered for a day. Keeping the headers lets `data()` decode a cached reply by its `Content-Type`.

## Timeouts, redirects and TLS

A request gives up after 30 seconds. When a visitor waits on it, register a client with a shorter timeout in `bootstrap.php`:

```php
$app->container->set(HttpClient::class, fn() => new HttpClient(timeout: 5));
```

GET and HEAD requests follow up to 5 redirects. Other methods get the redirect back as the reply, which keeps a POST from being sent twice. Only `http` and `https` URLs are allowed, redirects included. A URL from a visitor can't read local files. TLS certificates are always checked, and compressed replies are unpacked.

> [!WARNING]
> A URL from a visitor, such as a webhook or a link to preview, can still reach services on your own network, such as `localhost` or a cloud server's metadata at `169.254.169.254`. Check its host before sending it.

## Faking replies in tests

In a test, `fake()` makes the app's client answer every request with a closure instead of curl. The closure gets each `ClientRequest`, and can answer by its URL or record what was sent:

```php
use Sapling\Client\ClientRequest;
use Sapling\Client\ClientResponse;
use Sapling\Client\HttpClient;
use Sapling\Http\Request;

$app = require __DIR__ . '/../bootstrap.php';

$app->container->get(HttpClient::class)->fake(function (ClientRequest $request): ClientResponse {
    return new ClientResponse('{"status":true}', 200, ['Content-Type' => 'application/json']);
});

$response = $app->handle(new Request('POST', '/billing/charge'));
```

The client keeps the settings `bootstrap.php` gave it, and `sendAll()` calls the closure once per request.

## Method reference

| Method | What it does |
| --- | --- |
| `send($request)` | Sends a `ClientRequest` and returns the reply as a `ClientResponse`. A failed connection comes back as status 0, with the error as its body |
| `sendAll($requests)` | Sends `ClientRequest`s at once and returns their replies under the same keys |
| `fake($answer)` | Answers every request with the closure `$answer` instead of curl, for tests. Returns the client |
| `new HttpClient($timeout = 30)` | A client whose requests give up after `$timeout` seconds |
| `new ClientRequest($method, $url, $body = '', $headers = [])` | A request. A string body goes as it is, and an array as multipart form data. Header names are lowercased |
| `ClientRequest::json($method, $url, $data = null, $headers = [])` | A request with `$data` as JSON, or no body without data |
| `ClientRequest::form($method, $url, $data, $headers = [])` | A request with `$data` as a URL-encoded form |
| `ClientRequest::xml($method, $url, $data, $root = 'root', $headers = [])` | A request with `$data` as XML inside `$root` |
| `ClientRequest::text($method, $url, $text, $headers = [])` | A request with a plain text body |
| `$reply->data(...$keys)` | Returns a field from the reply's body, decoded as JSON, XML or a form, as a [`Value`](values.md). No key gives the whole body. Throws `JsonException` or `RuntimeException` when a JSON or XML body is invalid |
| `$reply->header($name)` | Returns a header, ignoring case, as a `Value`. A header sent more than once is a list |
| `$reply->status`, `$reply->headers`, `$reply->body` | The reply. Header names are lowercase |
| `new ClientResponse($body, $status, $headers)` | A reply built by hand, for tests or from a cache |
