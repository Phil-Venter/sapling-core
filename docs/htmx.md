# htmx

- [Introduction](#introduction)
- [The htmx middleware](#the-htmx-middleware)
- [Fragments and full pages](#fragments-and-full-pages)
- [Redirects](#redirects)
- [Forms and methods](#forms-and-methods)
- [Errors and validation](#errors-and-validation)
- [Scripts](#scripts)
- [Sessions](#sessions)
- [Header reference](#header-reference)

## Introduction

[htmx](https://htmx.org) swaps HTML from your actions into the page. It works with Sapling as it is, apart from a few defaults that trip it up.

## The htmx middleware

Two of the fixes belong on every response. They fit in one small middleware in your app:

```php
use Closure;
use Sapling\Http\Request;
use Sapling\Http\Response;

final class Htmx
{
    public function __invoke(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $vary = $response->header('Vary')->string();
        $response = $response->withHeader('Vary', ltrim("{$vary}, HX-Request", ', '));

        $location = $response->header('Location')->string();
        $redirect = $response->status >= 300 && $response->status < 400 && $location !== '';

        if (!$redirect || !$request->header('HX-Request')->bool()) {
            return $response;
        }

        return new Response($response->body, 200, [...$response->headers, 'HX-Redirect' => $location]);
    }
}
```

Add it in `bootstrap.php` with `$app->middleware(Htmx::class)`, after the built-in middleware and before any login check. That way a login redirect reaches htmx as `HX-Redirect`.

## Fragments and full pages

htmx sends `HX-Request: true` with every request it makes. An action can answer it with only the part of the page that changes, and with the whole page otherwise:

```php
$template = 'notes';

if ($request->header('HX-Request')->bool()) {
    $template = 'notes/_list';
}

return Response::html($this->view->render($template, ['notes' => $notes]));
```

That gives one URL two different bodies. The middleware adds `Vary: HX-Request`. Without it, the back button can show a cached fragment in place of the page.

## Redirects

The browser follows a redirect before htmx sees it. htmx would then swap the whole page it lands on into the target. For htmx requests, the middleware turns a redirect into a normal response with `HX-Redirect`, which loads the new page in full. Actions keep returning `Response::redirect()`. To have htmx fetch and swap the page instead, as a boosted link does, send `HX-Location` from the action.

## Forms and methods

`Csrf` lets htmx's requests through without a token. They come from your own pages, and browsers mark them as same-origin.

`hx-put` and `hx-patch` send their values in the body. PHP only reads POST bodies, but `$request->data()` decodes these too. `hx-delete` is different. htmx 2 sends a DELETE's values in the query string, as for a GET. Read them with `query()`:

```php
$id = $request->query('id')->int();
```

## Errors and validation

By default htmx doesn't swap a 4xx or 5xx response. A page from your [error handler](errors.md#showing-your-own-error-page) never appears, and neither does a form sent back with a 422 to show its errors. htmx's `responseHandling` setting decides what gets swapped. This one adds 422 to the defaults:

```html
<meta name="htmx-config" content='{"responseHandling": [{"code": "204", "swap": false}, {"code": "[23]..", "swap": true}, {"code": "422", "swap": true}, {"code": "[45]..", "swap": false, "error": true}]}'>
```

## Scripts

htmx is a script file, and `hx-*` attributes aren't scripts. Most pages work with [`SecurityHeaders`](middleware.md#security-headers) as they are.

htmx features that evaluate strings need `'unsafe-eval'`, which the policy doesn't allow. That covers `hx-on` attributes, event filters such as `hx-trigger="keyup[key=='Enter']"`, and `js:` values in `hx-vals`. Write those as listeners in a script file, or loosen `script-src` in `content_policy`.

## Sessions

PHP locks the session from the moment something uses it until the request ends. Requests that use the session run one at a time. A slow one holds up the polls and swaps behind it. Keep frequent requests, such as polling, away from the session.

A flash value goes to the next request that uses the session. With htmx, that can be a background poll rather than the page after a redirect, as [sessions](sessions.md#flash-values) explains.

## Header reference

| Header | What it does |
| --- | --- |
| `HX-Request` | Sent by htmx with its requests. Read it with `$request->header('HX-Request')->bool()` |
| `Vary: HX-Request` | Added by the middleware. Keeps pages and fragments from one URL cached apart |
| `HX-Redirect` | Sent by the middleware instead of a redirect. The browser loads the new page in full |
| `HX-Location` | Sent by an action instead of a redirect, to have htmx fetch another page and swap it in |
