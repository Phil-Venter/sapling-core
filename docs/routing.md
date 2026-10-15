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
- [Listing routes](#listing-routes)
- [Method reference](#method-reference)

## Introduction

An action is a class that answers a request, what other frameworks call a controller. `#[Route]` gives its method and path, and `__invoke()` takes a `Request` and returns a `Response`.

Each action is one class in one file, with its route on the class. Searching for a path finds the code behind it.

## Writing an action

Put the class in `app/` and give it a `#[Route]`. The [scan](app.md#scanning-a-folder) registers it:

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

The [container](container.md) builds the constructor's arguments. The `Request` parameter is optional.

Slashes at either end of a path are ignored, so `notes/:id/` and `/notes/:id` are the same route.

> [!TIP]
> Name actions after what they do, such as `ShowNote`, and group them in folders by feature. Move a query two actions share into a class they both take in their constructor.

## Route parameters

A path part that starts with a colon is a parameter. Read it with `param()`, which returns a [`Value`](values.md):

```php
#[Route('GET', '/users/:user/notes/:note')]
```

```php
$userId = $request->param('user')->int();
$noteId = $request->param('note')->int();
```

Parameters are URL-decoded, so `/tags/caf%C3%A9` gives `café`. A parameter never matches an empty part or a slash.

## More than one route per action

`#[Route]` can be repeated. Routes have no optional parts, so a form for creating and editing takes two paths:

```php
#[Route('GET', '/notes/form')]
#[Route('GET', '/notes/form/:id')]
final class NoteForm
```

`raw` is `null` when the parameter isn't there:

```php
$editing = $request->param('id')->raw !== null;
```

## Route middleware

`#[Middleware]` runs [middleware](middleware.md) around one class's routes:

```php
#[Route('GET', '/admin/users')]
#[Middleware(RequireLogin::class)]
#[Middleware(RequireAdmin::class)]
final class ListUsers
```

The first one is outermost. Middleware added with `$app->middleware()` runs before both.

## Adding routes in code

`$app->router->add()` takes a class name, an object or a closure, which suits one-file apps and tests:

```php
$app->router->add('GET', '/hello/:name', function (Request $request): Response {
    return Response::text("Hello {$request->param('name')->string()}");
});
```

The optional fourth argument is a list of middleware for the route.

## How routes match

### Which route wins

The most specific route wins, so `/notes/new` beats `/notes/:id`, which beats `/:section/edit`. The order routes are added in doesn't matter.

Two classes with the same method and path throw a `LogicException` that names both.

### HEAD requests

A HEAD request runs the GET route for the same path and sends only the headers. A `HEAD` route of your own takes precedence.

The GET action runs in full, so keep GET actions free of side effects.

### Not found and wrong method

An unknown path is a 404. A known path with the wrong method is a 405, with an `Allow` header. Both pass through your global middleware and your [error handler](errors.md#showing-your-own-error-page).

## Listing routes

```sh
php bin/console routes:list
```

> [!NOTE]
> There are no route groups or prefixes. Every route spells out its full path, so you can grep for it.

## Method reference

| Method | What it does |
| --- | --- |
| `#[Route($method, $path)]` | Makes the class answer that method and path. Slashes at either end don't matter |
| `#[Middleware($class)]` | Runs middleware around the class's routes. Repeat it for more, first outermost |
| `$app->router->add($method, $path, $action, $middleware = [])` | Adds a route in code |
| `$app->router->match($method, $path)` | Returns the route and its parameters. Throws a 404 or 405 `HttpException` |
| `$app->router->all()` | Returns every route, as `routes:list` shows them |
