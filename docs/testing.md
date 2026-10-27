# Testing

- [Introduction](#introduction)
- [An app for each test](#an-app-for-each-test)
- [Sending a request](#sending-a-request)
    - [Signing in](#signing-in)
    - [Checking a flash value](#checking-a-flash-value)
- [Faking other services](#faking-other-services)
- [Running a command](#running-a-command)
- [Method reference](#method-reference)

## Introduction

Your tests load `bootstrap.php`, as the web server and `bin/console` do, and send requests and commands straight through the app.

The examples use [Pest](https://pestphp.com). The skeleton comes with it and a `composer test` script. In an app made without the skeleton, add it with `composer require --dev pestphp/pest`.

## An app for each test

The skeleton's `tests/Pest.php` looks like this. Its `testApp()` helper empties the session, loads the app, and gives it an in-memory SQLite database with the migrations run:

```php
use Sapling\App;
use Sapling\Db;
use Sapling\Migrations;

pest()->beforeEach(function () {
    error_reporting(E_ALL);
});

function testApp(): App
{
    $_SESSION = [];

    $app = require __DIR__ . '/../bootstrap.php';
    $db = Db::sqlite(':memory:');
    new Migrations($db, $app->path('database/migrations'))->run();
    $app->container->set(Db::class, $db);

    return $app;
}
```

> [!NOTE]
> PHPUnit lowers `error_reporting()` while a test runs, which hides the [warnings](errors.md#warnings-and-notices) Sapling turns into crashes.

## Sending a request

`handle()` answers a `Request` and returns the response instead of sending it:

```php
use Sapling\Http\Request;

test('a new note goes back to the list', function () {
    $response = testApp()->handle(new Request('POST', '/notes', input: ['body' => 'Buy milk']));

    expect($response->status)->toBe(302);
});
```

`handle()` never throws. A 404, a crash or any other error comes back as the response a visitor would get, and you [read it](requests.md#reading-a-response) as middleware does.

The session cookie never shows up on the response from `handle()`, since PHP sends it itself.

> [!NOTE]
> Send one request per app, as the server does. A second request through the same app shares the first one's objects, so a [`set()` in middleware](middleware.md#passing-data-to-the-action) can throw.

### Signing in

To act as a signed-in visitor, set the session before the request. The action sees the same values, since the app shares one `Session`:

```php
$app = testApp();
$app->container->get(Session::class)->set('user_id', 7);

$response = $app->handle(new Request('GET', '/settings'));
```

PHP keeps that session for the rest of the test run. `testApp()` empties it for each test.

### Checking a flash value

To check what an action flashed, read it from a new app, as the next request would:

```php
$app = testApp();
$app->handle(new Request('POST', '/notes', input: ['body' => 'Buy milk']));

$next = require __DIR__ . '/../bootstrap.php';

expect($next->container->get(Session::class)->getFlash('notice')->string())->toBe('Saved.');
```

Load `bootstrap.php` directly here. `testApp()` would empty the session, and the flash value with it.

## Faking other services

`fake()` makes the app's [HTTP client](http-client.md) answer every request with a closure instead of curl. The closure gets each `ClientRequest`, and can answer by its URL or record what was sent:

```php
use Sapling\Client\ClientRequest;
use Sapling\Client\ClientResponse;
use Sapling\Client\HttpClient;
use Sapling\Http\Request;

$app = testApp();

$app->container->get(HttpClient::class)->fake(function (ClientRequest $request): ClientResponse {
    return new ClientResponse('{"status":true}', 200, ['Content-Type' => 'application/json']);
});

$response = $app->handle(new Request('POST', '/billing/charge'));
```

The client keeps the settings `bootstrap.php` gave it, and `sendAll()` calls the closure once per request.

## Running a command

`runCommand()` runs a [command](commands.md) and returns its exit code. `ob_start()` catches what `line()` prints, and a command that asks reads its answers from the stream given to `Console`:

```php
$answers = fopen('php://memory', 'r+');
fwrite($answers, "yes\n");
rewind($answers);

ob_start();
$code = testApp()->runCommand(new Console(['bin/console', 'notes:prune'], $answers));
$output = ob_get_clean();

expect($code)->toBe(Console::SUCCESS);
expect($output)->toContain('Deleted 3 notes.');
```

`error()` writes to standard error, which the buffer doesn't catch.

## Method reference

| Method | What it does |
| --- | --- |
| `$app->handle($request)` | Returns the `Response` for a `Request`, even for an error or a crash. It never throws |
| `$app->runCommand($console)` | Runs a command and returns its exit code |
| `$client->fake($answer)` | Answers every request with the closure `$answer` instead of curl. Returns the client |
| `new Request($method, $path, ...)` | A request built by hand, with named arguments for `query`, `input`, `headers`, `cookies`, `files`, `body` and `ip` |
| `new Console($argv, $input = null)` | A command's input, read from `$argv`. `ask()` reads answers from `$input` when you pass one |
| `new ClientResponse($body, $status, $headers)` | A reply built by hand, for a fake to return |
