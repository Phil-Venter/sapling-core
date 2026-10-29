# Sessions

- [Introduction](#introduction)
- [Using the session](#using-the-session)
- [Flash values](#flash-values)
- [Signing in and out](#signing-in-and-out)
- [How long a session lasts](#how-long-a-session-lasts)
- [Method reference](#method-reference)

## Introduction

A session keeps values for one visitor between requests, such as who's signed in. `Sapling\Http\Session` is PHP's session with two additions: flash values for messages that need to survive a redirect, and cookie settings safer than PHP's defaults, applied before the session starts.

The session cookie is HttpOnly and SameSite=Lax, and Secure over HTTPS or behind a proxy that sends `X-Forwarded-Proto: https`, a header Sapling reads from anyone, not only from [`trusted_proxies`](requests.md#accessing-the-request). Sapling also turns on strict mode, in which PHP only accepts session ids it created.

The session only starts the first time something uses it, and a request that never touches it sends no cookie.

## Using the session

Any action, middleware or command can ask for `Session` in its constructor, and every class in the same request gets the same one:

```php
use Sapling\Attributes\Route;
use Sapling\Http\Session;

#[Route('GET', '/settings')]
final class ShowSettings
{
    public function __construct(private Session $session) {}

    // ...
}
```

`set()` stores a value, `get()` reads it back as a [`Value`](values.md), and `forget()` removes it. A key that was never set gives the default:

```php
$this->session->set('theme', 'dark');

$userId = $this->session->get('user_id')->int();
$theme = $this->session->get('theme')->string('light');

$this->session->forget('theme');
```

> [!NOTE]
> PHP locks the session from the moment something uses it until the request ends, which makes one visitor's requests that use the session run one at a time. A slow request holds up the ones behind it, such as htmx polls, and a [download](requests.md#serving-large-files) behind a login check holds the lock until it ends.

## Flash values

A flash value is kept for the next request only, for a message such as "Saved." that the action sets before redirecting:

```php
use Sapling\Attributes\Route;
use Sapling\Db;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Http\Session;

#[Route('POST', '/notes')]
final class StoreNote
{
    public function __construct(private Db $db, private Session $session) {}

    public function __invoke(Request $request): Response
    {
        $sql = <<<SQL
            INSERT INTO notes (body)
            VALUES (?)
            SQL;

        $this->db->run($sql, [$request->data('body')->string()]);
        $this->session->setFlash('notice', 'Saved.');

        return Response::redirect('/notes');
    }
}
```

The request after the redirect reads it with `getFlash()`, and the one after that no longer sees it:

```php
$notice = $this->session->getFlash('notice')->string();
```

> [!NOTE]
> "The next request" is the first one that uses the session. A background request, such as an htmx poll, can take the flash value before the page does.

## Signing in and out

Give the session a new id when a visitor signs in, so that an id someone learned beforehand stops working:

```php
$this->session->regenerate();
$this->session->set('user_id', $user['id']);
```

To sign the visitor out, `destroy()` clears the data and the cookie:

```php
$this->session->destroy();
```

## How long a session lasts

`session_lifetime` in `config.php` sets how many seconds a visitor can go between requests and stay signed in, since every request that uses the session renews the cookie. Without the setting, php.ini decides:

```php
'session_lifetime' => 60 * 60 * 24 * 14,   // two weeks
```

> [!WARNING]
> On Debian and Ubuntu, a scheduled job deletes old sessions by php.ini's `session.gc_maxlifetime`, not by `session_lifetime`. Set it to the same value, or sessions end sooner than you expect.

## Method reference

| Method | What it does |
| --- | --- |
| `get(...$keys)` | Returns a value as a [`Value`](values.md), as in `get('user_id')->int()`. More keys read deeper |
| `set($key, $value)` | Stores a value |
| `forget($key)` | Removes a value |
| `getFlash(...$keys)` | Returns a value the previous request stored with `setFlash()`, as a `Value`. More keys read deeper |
| `setFlash($key, $value)` | Stores a value that only the next request can read |
| `regenerate()` | Gives the session a new id and keeps its data |
| `destroy()` | Clears the data and the cookie. The next call starts a new session |
| `new Session($lifetime = 0)` | Takes `session_lifetime`, the seconds the cookie and an idle session last, and 0 leaves both to php.ini. `App::boot()` registers it |
