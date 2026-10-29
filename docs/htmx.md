# htmx

- [Introduction](#introduction)
- [Fragments and full pages](#fragments-and-full-pages)
- [Redirects](#redirects)
- [The htmx middleware](#the-htmx-middleware)
- [Reading PUT, PATCH and DELETE values](#reading-put-patch-and-delete-values)
- [Errors and validation](#errors-and-validation)
- [Scripts](#scripts)
- [Sessions](#sessions)
- [Header reference](#header-reference)

## Introduction

[htmx](https://htmx.org) swaps HTML from your actions into the page, and works with Sapling as it is, apart from a few defaults that trip it up. [`Csrf`](middleware.md#cross-site-request-protection) lets htmx's requests through without a token, since browsers mark requests from your own pages as same-origin.

## Fragments and full pages

htmx sends `HX-Request: true` with every request it makes. An action can answer it with only the part of the page that changes, and with the whole page otherwise:

```php
$template = 'notes';

if ($request->header('HX-Request')->bool()) {
    $template = 'notes/_list';
}

return Response::html($this->view->render($template, ['notes' => $notes]));
```

That gives one URL two different bodies, and the [htmx middleware](#the-htmx-middleware) below adds `Vary: HX-Request` to keep the back button from showing a cached fragment in place of the page.

## Redirects

The browser follows a redirect before htmx sees it, and htmx would then swap the whole page it lands on into the target. For htmx requests, the middleware below turns a redirect into a normal response with `HX-Redirect`, which loads the new page in full, while actions keep returning `Response::redirect()`. To have htmx fetch and swap the page instead, as a boosted link does, send `HX-Location` from the action.

## The htmx middleware

This small middleware fixes [caching](#fragments-and-full-pages) and [redirects](#redirects) for htmx on every response:

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

Add it in `bootstrap.php` with `$app->middleware(Htmx::class)`, after the built-in middleware and before any login check, so that a login redirect reaches htmx as `HX-Redirect`.

## Reading PUT, PATCH and DELETE values

`hx-put` and `hx-patch` send their values in the body, where [`$request->data()`](requests.md#body-fields) reads them. `hx-delete` is different. htmx 2 sends a DELETE's values in the query string, as for a GET, so read them with `query()`:

```php
$id = $request->query('id')->int();
```

## Errors and validation

By default htmx doesn't swap a 4xx or 5xx response, which means a page from your [error handler](errors.md#showing-your-own-error-page) never appears, and neither does a form sent back with a 422 to show its errors. htmx's `responseHandling` setting decides what gets swapped, and this one adds 422 to the defaults:

```html
<meta name="htmx-config" content='{"responseHandling": [{"code": "204", "swap": false}, {"code": "[23]..", "swap": true}, {"code": "422", "swap": true}, {"code": "[45]..", "swap": false, "error": true}]}'>
```

## Scripts

htmx is a script file and `hx-*` attributes aren't scripts, which lets most pages work with [`SecurityHeaders`](middleware.md#security-headers) as they are.

htmx features that evaluate strings need `'unsafe-eval'`, which the policy doesn't allow. Those features are `hx-on` attributes, event filters such as `hx-trigger="keyup[key=='Enter']"`, and `js:` values in `hx-vals`. Write them as listeners in a script file, or loosen `script-src` in `content_policy`.

## Sessions

A slow request that uses the session holds up the polls and swaps behind it, since PHP [locks the session](sessions.md#using-the-session) until the request ends. A background poll can also take a [flash value](sessions.md#flash-values) before the page after a redirect does.

## Header reference

| Header | What it does |
| --- | --- |
| `HX-Request` | Sent by htmx with its requests. Read it with `$request->header('HX-Request')->bool()` |
| `Vary: HX-Request` | Added by the middleware. Keeps pages and fragments from one URL cached apart |
| `HX-Redirect` | Sent by the middleware instead of a redirect. The browser loads the new page in full |
| `HX-Location` | Sent by an action instead of a redirect, to have htmx fetch another page and swap it in |
