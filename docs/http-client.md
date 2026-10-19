# Talking to other services

- [Introduction](#introduction)
- [Making requests](#making-requests)
    - [Sending data](#sending-data)
    - [Headers](#headers)
    - [Uploading files](#uploading-files)
- [Sending requests at once](#sending-requests-at-once)
- [Reading the reply](#reading-the-reply)
    - [Errors](#errors)
- [Caching replies](#caching-replies)
- [Timeouts, redirects and TLS](#timeouts-redirects-and-tls)
- [Method reference](#method-reference)

## Introduction

Most apps call an API or two, such as a payment provider or a weather service. `Sapling\Client\HttpClient` covers that without a client library. It sends requests with curl, and each reply is a `Sapling\Client\ClientResponse`.

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

`sendAll(['weather' => $a, 'news' => $b])` returns its replies as `['weather' => …, 'news' => …]`. A failed connection throws after the other requests finish, and names the request.

## Reading the reply

A reply has its `status`, `headers` and `body`. `data()` decodes the body by its `Content-Type` and returns a [`Value`](values.md), and more keys read deeper:

```php
$name = $reply->data('profile', 'name')->string(); // 'bob' from {"profile": {"name": "bob"}}
```

A reply's header names are lowercase, and a header the service sent more than once is a list. `header()` ignores case:

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

## Caching replies

Sapling doesn't cache replies, since only your app knows which ones can be kept and for how long. A small class around `HttpClient` can keep them in the database. This one keeps successful GET replies for a day:

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

Only 200 replies are kept, so a 404 or an outage isn't remembered for a day. Keeping the headers lets `data()` decode a cached reply by its `Content-Type`.

## Timeouts, redirects and TLS

A request gives up after 30 seconds. When a visitor waits on it, register a client with a shorter timeout in `bootstrap.php`:

```php
$app->container->set(HttpClient::class, fn() => new HttpClient(timeout: 5));
```

GET and HEAD requests follow up to 5 redirects. Other methods get the redirect back as the reply, so a POST is never sent twice. Only `http` and `https` URLs are allowed, redirects included, so a URL from a visitor can't read local files. TLS certificates are always checked, and compressed replies are unpacked.

> [!WARNING]
> A URL from a visitor, such as a webhook or a link to preview, can still reach services on your own network, such as `localhost` or a cloud server's metadata at `169.254.169.254`. Check its host before sending it.

## Method reference

| Method | What it does |
| --- | --- |
| `send($request)` | Sends a `ClientRequest` and returns the reply as a `ClientResponse` |
| `sendAll($requests)` | Sends `ClientRequest`s at once and returns their replies under the same keys |
| `new HttpClient($timeout = 30)` | A client whose requests give up after `$timeout` seconds |
| `new ClientRequest($method, $url, $body = '', $headers = [])` | A request. A string body goes as it is, and an array as multipart form data. Header names are lowercased |
| `ClientRequest::json($method, $url, $data = null, $headers = [])` | A request with `$data` as JSON, or no body without data |
| `ClientRequest::form($method, $url, $data, $headers = [])` | A request with `$data` as a URL-encoded form |
| `ClientRequest::xml($method, $url, $data, $root = 'root', $headers = [])` | A request with `$data` as XML inside `$root` |
| `ClientRequest::text($method, $url, $text, $headers = [])` | A request with a plain text body |
| `$reply->data(...$keys)` | Returns a field from the reply's body, decoded as JSON, XML or a form, as a [`Value`](values.md). No key gives the whole body |
| `$reply->header($name)` | Returns a header, ignoring case, as a `Value`. A header sent more than once is a list |
| `$reply->status`, `$reply->headers`, `$reply->body` | The reply. Header names are lowercase |
| `new ClientResponse($body, $status, $headers)` | A reply built by hand, for tests or from a cache |
