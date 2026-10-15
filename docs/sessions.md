# Sessions

- [Introduction](#introduction)
- [Using the session](#using-the-session)
    - [Reading values](#reading-values)
    - [Storing and removing values](#storing-and-removing-values)
- [Flash values](#flash-values)
- [Logging in and out](#logging-in-and-out)
- [The session cookie](#the-session-cookie)
    - [How long a session lasts](#how-long-a-session-lasts)
- [Method reference](#method-reference)

## Introduction

`Sapling\Http\Session` is PHP's session with safer cookie settings and flash values. It starts the first time it's used, so a request that doesn't use it sends no cookie.

## Using the session

Take `Session` in a constructor:

```php
#[Route('GET', '/settings')]
final class ShowSettings
{
    public function __construct(private Session $session) {}

    // ...
}
```

### Reading values

`get()` returns a [`Value`](values.md):

```php
$userId = $this->session->get('user_id')->int();
$theme = $this->session->get('theme')->string('light');
```

### Storing and removing values

```php
$this->session->set('theme', 'dark');
$this->session->forget('theme');
```

> [!TIP]
> Store ids, not objects. A stored object goes stale.

## Flash values

A flash value lasts for the next request only, which suits a message after a redirect:

```php
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

        $this->db->run($sql, [$request->input('body')->string()]);
        $this->session->setFlash('notice', 'Saved.');

        return Response::redirect('/notes');
    }
}
```

The next request reads it with `getFlash()`:

```php
$notice = $this->session->getFlash('notice')->string();
```

> [!NOTE]
> The next request is whichever one uses the session first. A background request, such as an htmx poll, can take the flash value before the page does.

## Logging in and out

Regenerate the session id on login, so an id known before the login is useless after it:

```php
$this->session->regenerate();
$this->session->set('user_id', $user['id']);
```

`destroy()` clears the data and the cookie:

```php
$this->session->destroy();
```

## The session cookie

The cookie is HttpOnly and SameSite=Lax, and Secure over HTTPS or behind a proxy that sends `X-Forwarded-Proto: https`. Strict mode is on, so PHP only accepts ids it created.

### How long a session lasts

`session_lifetime` sets the cookie's lifetime and how long an idle session is kept, in seconds. Without it, php.ini decides:

```php
'session_lifetime' => 60 * 60 * 24 * 14,   // two weeks
```

> [!WARNING]
> On Debian and Ubuntu, a cron job deletes sessions by php.ini's `session.gc_maxlifetime`. Set it to the same value.

## Method reference

| Method | What it does |
| --- | --- |
| `get($key)` | Returns a value as a [`Value`](values.md), as in `get('user_id')->int()` |
| `set($key, $value)` | Stores a value |
| `forget($key)` | Removes a value |
| `setFlash($key, $value)` | Stores a value that only the next request can read |
| `getFlash($key)` | Returns a value the previous request stored with `setFlash()`, as a `Value` |
| `regenerate()` | Gives the session a new id and keeps its data |
| `destroy()` | Clears the data and the cookie. The next call starts a new session |
