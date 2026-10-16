# Templates

- [Introduction](#introduction)
- [Rendering a template](#rendering-a-template)
- [Printing values](#printing-values)
- [Partials](#partials)
- [Layouts](#layouts)
- [Sections](#sections)
    - [Longer sections](#longer-sections)
- [Components](#components)
- [Sharing data with every template](#sharing-data-with-every-template)
- [Templates next to their classes](#templates-next-to-their-classes)
- [When something is wrong](#when-something-is-wrong)
- [Method reference](#method-reference)

## Introduction

PHP is already a template language, so Sapling's templates are plain PHP files in `views/`, with no compiler, cache or new syntax to learn. `Sapling\Template` adds layouts, sections, partials and components. Inside a template, `$this` is the `Template`.

## Rendering a template

An action asks for `Template` in its constructor, renders a page with the variables it needs, and returns the HTML:

```php
#[Route('GET', '/notes')]
final class ListNotes
{
    public function __construct(private NoteRepository $notes, private Template $view) {}

    public function __invoke(): Response
    {
        $html = $this->view->render('notes', ['notes' => $this->notes->all()]);

        return Response::html($html);
    }
}
```

`render('notes')` runs `views/notes.tmpl.php` with each array key as a variable, and a name with a slash, such as `notes/show`, looks in a subfolder. The `.tmpl.php` suffix keeps templates apart from classes.

## Printing values

Inside a template, values are printed with `<?= ?>`. Escape every one of them with `e()`:

```php
<?php foreach ($notes as $note): ?>
    <p><?= e($note['body']) ?></p>
<?php endforeach ?>
```

For attributes, `attrs()` builds the escaped list and leaves out the ones that are empty or false:

```php
<li <?= attrs(['class' => 'note', 'data-id' => $note['id'], 'hidden' => !$note['visible']]) ?>>
```

> [!WARNING]
> Nothing is escaped automatically. A value printed without `e()` or `attrs()` can put a visitor's HTML, and their scripts, on your page.

## Partials

Pieces that several pages share, such as a navigation bar, go in their own file. `include()` prints one inside the current template:

```php
<?= $this->include('_nav', ['active' => 'notes']) ?>
```

A partial sees the variables you pass it and the [shared](#sharing-data-with-every-template) ones, but not the page's. It can't have a layout of its own, so `layout()` inside a partial is ignored. Use `include()` rather than `render()` here, since `render()` starts a whole new page and throws inside a template.

> [!TIP]
> Prefix partials with `_`, such as `_nav.tmpl.php`.

## Layouts

Most pages share the same outer HTML, which belongs in a layout. A template picks its layout with `layout()`, usually on its first line:

```php
<?php $this->layout('layout') ?>
<?php $this->section('title', 'Notes') ?>

<?php foreach ($notes as $note): ?>
    <p><?= e($note['body']) ?></p>
<?php endforeach ?>
```

Everything the template prints becomes the layout's `content` section, which the layout prints where the page should go:

```php
<!doctype html>
<title><?= $this->yield('title', 'My app') ?></title>
<main><?= $this->yield('content') ?></main>
```

## Sections

The `title` above is a section, a named piece of the page that the template sets and the layout prints with `yield()`. `yield()` also takes a default for when the template doesn't set one. `section()` with two arguments sets a section to a string, number or backed enum, printed as given, so escape it first:

```php
<?php $this->section('title', e($note['title'])) ?>
```

### Longer sections

For more than a line, `section()` with only a name captures everything printed until `end()`:

```php
<?php $this->section('aside') ?>
    <h2>Tags</h2>
    <p><?= e(implode(', ', $tags)) ?></p>
<?php $this->end() ?>
```

## Components

A component is a partial that wraps content, such as a card or a dialog. It opens like a section, and everything printed before `end()` becomes the component's `$slot`. `end()` then prints the component in its place:

```php
<?php $this->component('_card', ['title' => 'Notes']) ?>
    <p>Anything here is the slot.</p>
<?php $this->end() ?>
```

```php
<article class="card">
    <h2><?= e($title) ?></h2>
    <?= $slot ?>
</article>
```

The component template prints `$slot` where the content goes. It's HTML the page has already printed, so don't escape it again.

## Sharing data with every template

`share()` makes a value available to every template rendered after it, such as the app's name. Share it in `bootstrap.php` to reach the whole app:

```php
$view = $app->container->get(Template::class);
$view->share('appName', 'Notes');
```

Share helpers as closures, so they only run when a template calls them. A page that never shows a flash message then never starts the session:

```php
$view->share('flash', function (string $key) use ($app): string {
    $session = $app->container->get(Session::class);

    return $session->getFlash($key)->string();
});
```

```php
<?php $notice = $flash('notice') ?>

<?php if ($notice !== ''): ?>
    <p class="notice"><?= e($notice) ?></p>
<?php endif ?>
```

If a variable passed to `render()` has the same name as a shared one, the variable wins.

## Templates next to their classes

Templates don't have to live in `views/`. A feature's templates can sit next to its actions, since [`scan()`](app.md#scanning-a-folder) skips `.tmpl.php` files. Register the folder under a prefix in `bootstrap.php`:

```php
$view = $app->container->get(Template::class);
$view->prefix('notes', __DIR__ . '/app/Notes');
```

`notes::list` then finds `app/Notes/list.tmpl.php`, in `render()`, `include()`, `layout()` and `component()`:

```php
return Response::html($this->view->render('notes::list', ['notes' => $notes]));
```

An unknown prefix throws a `LogicException`. To use another suffix, such as plain `.php`, register `Template` yourself with `new Template($app->path('views'), suffix: 'php')`.

## When something is wrong

- A missing template throws a `RuntimeException`.
- An unclosed section or component, or `end()` with nothing open, throws a `LogicException`.

## Method reference

| Method | What it does |
| --- | --- |
| `prefix($prefix, $dir)` | Makes `prefix::name` find templates in `$dir` |
| `share($key, $value)` | Makes a value available to every template rendered after this |
| `render($template, $data = [])` | Renders the page `views/{$template}.tmpl.php` with `$data` as its variables, inside its layout if defined |
| `include($template, $data = [])` | Renders a partial inside a template |
| `layout($template)` | Wraps the current template in another, which prints it with `yield('content')` |
| `section($name, $content)` | Sets a section to a string, number or backed enum |
| `section($name)` and `end()` | Capture everything printed between them as a section |
| `component($template, $data = [])` and `end()` | Print a partial with everything printed between them as `$slot` |
| `yield($name, $default = '')` | Returns a section, or `$default` when it wasn't set |
| `new Template($dir, $suffix = 'tmpl.php')` | Finds templates in `$dir` ending in `.{$suffix}`. `App` registers one for `views/` |
