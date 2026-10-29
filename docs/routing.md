# Routing and actions

- [Introduction](#introduction)
- [Writing an action](#writing-an-action)
- [Route parameters](#route-parameters)
- [More than one route per action](#more-than-one-route-per-action)
- [Route middleware](#route-middleware)
- [Adding routes in code](#adding-routes-in-code)
- [How routes match](#how-routes-match)
    - [Which route wins](#which-route-wins)
    - [HEAD requests](#head-requests)
    - [Not found and wrong method](#not-found-and-wrong-method)
- [Method reference](#method-reference)

## Introduction

An action is the class that answers a request, what other frameworks call a controller. Its `#[Route]` says which method and path it answers, and its `__invoke()` takes the `Request` and returns a `Response`.

## Writing an action

An action lives in `app/`, where the [scan](app.md#scanning-a-folder) finds it. This one shows a note from the [database](database.md), with [`abort()`](errors.md#errors-the-visitor-causes) for a missing one:

```php
namespace App\Notes;

use Sapling\Attributes\Route;
use Sapling\Db;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/notes/:id')]
final class ShowNote
{
    public function __construct(private Db $db) {}

    public function __invoke(Request $request): Response
    {
        $sql = <<<SQL
            SELECT body
            FROM notes
            WHERE id = ?
            SQL;

        $note = $this->db->one($sql, [$request->param('id')->int()]);
        if ($note === null) abort(404, 'That note does not exist.');

        return Response::text($note['body']);
    }
}
```

The [container](container.md) builds the constructor's arguments, and an action that doesn't read the request can leave the `Request` parameter out.

## Route parameters

A part of the path that starts with a colon is a parameter. It matches any one part of the URL, and the action reads it with `param()`, which returns a [`Value`](values.md):

```php
#[Route('GET', '/users/:user/notes/:note')]
```

```php
$userId = $request->param('user')->int();
$noteId = $request->param('note')->int();
```

Parameters arrive URL-decoded, as in `café` from `/tags/caf%C3%A9`, and never match an empty part or a slash.

Parameter names start with a letter or underscore, followed by letters, digits and underscores. `:note_id` works, while `:note-id` and `:2fa` throw a `LogicException`, as does a path that uses one name twice.

> [!WARNING]
> A parameter can still be `..`, or hold a backslash, which Windows reads as a folder separator. Match it against the names you allow before using it in a file path.

## More than one route per action

Routes have no optional parts. When one action should answer two paths, such as a form that both creates and edits, repeat `#[Route]`:

```php
#[Route('GET', '/notes/form')]
#[Route('GET', '/notes/form/:id')]
final class NoteForm
```

The action tells them apart by `raw`, which is `null` when the parameter isn't there:

```php
$editing = $request->param('id')->raw !== null;
```

## Route middleware

Most [middleware](middleware.md) is added for every request, but `#[Middleware]` runs it around one class's routes only:

```php
#[Route('GET', '/admin/users')]
#[Middleware(RequireLogin::class)]
#[Middleware(RequireAdmin::class)]
final class ListUsers
```

Like `#[Route]`, it can be repeated, with the first one outermost, so the login check runs before the admin check. Middleware added with `$app->middleware()` runs before both.

## Adding routes in code

For [one-file apps](app.md#one-file-apps) and tests, `$app->router->add()` adds a route in code, with a class name, an object or a closure as its action:

```php
$app->router->add('GET', '/hello/:name', function (Request $request): Response {
    return Response::text("Hello {$request->param('name')->string()}");
});
```

An optional fourth argument lists middleware for the route, as `#[Middleware]` does.

## How routes match

A route matches a path one part at a time, where a part is the text between two slashes. Slashes at either end of a route's path are ignored, which makes `notes/:id/` and `/notes/:id` the same route. Fixed parts are compared as browsers send them, so write a route for `/café` as `/caf%C3%A9`, with uppercase hex.

### Which route wins

When more than one route fits a path, the most specific one wins, whatever order the routes were added in. `/notes/new` beats `/notes/:id`, which beats `/:section/edit`. Only routes for the request's method count, so with `GET /notes/new` and `POST /notes/:id`, a POST to `/notes/new` reaches the `:id` route.

Two classes on the same method and path stop the app with a `LogicException` that names both.

### HEAD requests

A HEAD request is answered by the GET route for the same path, unless you add a `HEAD` route of your own. The GET action still runs in full, and only its headers are sent back.

### Not found and wrong method

A path that no route fits is a 404, and a known path with the wrong method is a 405 with an `Allow` header listing the methods it does take. Both reach your [error handler](errors.md#showing-your-own-error-page) like any other error.

## Method reference

| Method | What it does |
| --- | --- |
| `#[Route($method, $path)]` | Makes the class answer that method and path. Slashes at either end don't matter |
| `#[Middleware($class)]` | Runs middleware around the class's routes. Repeat it for more, first outermost |
| `$app->router->add($method, $path, $action, $middleware = [])` | Adds a route in code |
| `$app->router->match($method, $path)` | Returns the route and its parameters. Throws a 404 or 405 `HttpException` |
| `$app->router->all()` | Returns every route with its action's class name |
