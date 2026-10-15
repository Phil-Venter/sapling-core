# Sessions

- [Introduction](#introduction)
- [Using the session](#using-the-session)
- [Flash values](#flash-values)
- [Logging in and out](#logging-in-and-out)
- [The session cookie](#the-session-cookie)
    - [How long a session lasts](#how-long-a-session-lasts)
- [Method reference](#method-reference)

## Introduction

A session keeps values for one visitor between requests, such as who's logged in. `Sapling\Http\Session` is PHP's own session with two things added. It sets safer cookie settings before the session starts, which PHP's defaults don't, and it has flash values for messages that need to survive a redirect.

The session only starts the first time something uses it, so a request that never touches it sends no cookie.

## Using the session

Any action, middleware or command can ask for `Session` in its constructor, and every class in the same request gets the same one:

```php
#[Route('GET', '/settings')]
final class ShowSettings
{
    public function __construct(private Session $session) {}

    // ...
}
```

`set()` stores a value, `forget()` removes it, and `get()` reads it back as a [`Value`](values.md), so a key that was never set simply gives the default:

```php
$this->session->set('theme', 'dark');

$userId = $this->session->get('user_id')->int();
$theme = $this->session->get('theme')->string('light');

$this->session->forget('theme');
```

## Flash values

A flash value is kept for the next request only. That suits a message such as "Saved.", which the action sets before redirecting and the next page shows once:

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

The request after the redirect reads it with `getFlash()`, and the one after that no longer sees it:

```php
$notice = $this->session->getFlash('notice')->string();
```

> [!NOTE]
> "The next request" is whichever one uses the session first. A background request, such as an htmx poll, can take the flash value before the page does.

## Logging in and out

Logging in is the moment a session becomes valuable, so give it a new id right then. An id someone learned before the login is then useless after it:

```php
$this->session->regenerate();
$this->session->set('user_id', $user['id']);
```

On logout, `destroy()` clears the data and the cookie at once, rather than forgetting keys one at a time:

```php
$this->session->destroy();
```

## The session cookie

The session cookie is HttpOnly, so scripts can't read it, and SameSite=Lax, so other sites can't send it along with their forms. It's also Secure over HTTPS, or behind a proxy that sends `X-Forwarded-Proto: https`. Strict mode is on, so PHP only accepts session ids it created itself.

### How long a session lasts

`session_lifetime` in `config.php` sets, in seconds, how long the cookie lasts and how long an idle session is kept. Without it, php.ini decides both:

```php
'session_lifetime' => 60 * 60 * 24 * 14,   // two weeks
```

> [!WARNING]
> On Debian and Ubuntu, a cron job deletes old sessions by php.ini's `session.gc_maxlifetime` instead. Set it to the same value, or sessions end sooner than you expect.

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
