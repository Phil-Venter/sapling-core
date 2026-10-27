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

An action lives in `app/`, where the [scan](app.md#scanning-a-folder) finds it. This one shows a note from the [database](database.md), with [`abort_if()`](helpers.md) for a missing one:

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
        abort_if($note === null, 404, 'That note does not exist.');

        return Response::text($note['body']);
    }
}
```

The [container](container.md) builds the constructor's arguments. An action that doesn't read the request can leave the `Request` parameter out. Slashes at either end of a path are ignored. `notes/:id/` and `/notes/:id` are the same route.

## Route parameters

A part of the path that starts with a colon is a parameter. It matches any one part of the URL, and the action reads it with `param()`, which returns a [`Value`](values.md):

```php
#[Route('GET', '/users/:user/notes/:note')]
```

```php
$userId = $request->param('user')->int();
$noteId = $request->param('note')->int();
```

Parameters arrive URL-decoded, as in `café` from `/tags/caf%C3%A9`. A parameter never matches an empty part or a slash. Fixed parts are matched as browsers send them. Write a route for `/café` as `/caf%C3%A9`, with uppercase hex.

Parameter names start with a letter or underscore, followed by letters, digits and underscores. `:note_id` works, while `:note-id` and `:2fa` throw a `LogicException`, as does a path that uses one name twice.

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

Like `#[Route]`, it can be repeated. The first one is outermost, so the login check runs before the admin check. Middleware added with `$app->middleware()` runs before both.

## Adding routes in code

Routes can also be added in code, for one-file apps and tests. `$app->router->add()` takes a class name, an object or a closure:

```php
$app->router->add('GET', '/hello/:name', function (Request $request): Response {
    return Response::text("Hello {$request->param('name')->string()}");
});
```

An optional fourth argument lists middleware for the route, as `#[Middleware]` does.

## How routes match

### Which route wins

When more than one route fits a path, the most specific one wins. `/notes/new` beats `/notes/:id`, which beats `/:section/edit`. The order routes are added in doesn't matter.

Two classes on the same method and path stop the app with a `LogicException` that names both. That usually means an action was copied without changing its route.

### HEAD requests

A HEAD request is answered by the GET route for the same path, with only the headers sent back. A `HEAD` route of your own wins. The GET action still runs in full. Keep it free of side effects.

### Not found and wrong method

A path that no route fits is a 404, and a known path with the wrong method is a 405 with an `Allow` header listing the methods it does take. Both pass through your global middleware and your [error handler](errors.md#showing-your-own-error-page), like any other error.

## Method reference

| Method | What it does |
| --- | --- |
| `#[Route($method, $path)]` | Makes the class answer that method and path. Slashes at either end don't matter |
| `#[Middleware($class)]` | Runs middleware around the class's routes. Repeat it for more, first outermost |
| `$app->router->add($method, $path, $action, $middleware = [])` | Adds a route in code |
| `$app->router->match($method, $path)` | Returns the route and its parameters. Throws a 404 or 405 `HttpException` |
| `$app->router->all()` | Returns every route with its action's class name |
