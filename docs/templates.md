# Templates

- [Introduction](#introduction)
- [Rendering a template](#rendering-a-template)
- [Printing values](#printing-values)
- [Layouts](#layouts)
- [Sections](#sections)
    - [Longer sections](#longer-sections)
- [Including other templates](#including-other-templates)
- [Sharing data with every template](#sharing-data-with-every-template)
- [When something is wrong](#when-something-is-wrong)
- [Method reference](#method-reference)

## Introduction

`Sapling\Template` renders plain PHP files from `views/`, with layouts and sections. Inside a template, `$this` is the `Template`. There's no compiler, cache or new syntax.

## Rendering a template

Take `Template` in the action's constructor and pass the variables the file needs:

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

`render('notes')` runs `views/notes.php` with each array key as a variable. `render('notes/show')` runs `views/notes/show.php`.

> [!TIP]
> Load data in the action, not the template.

## Printing values

Escape everything you print with `e()`:

```php
<?php foreach ($notes as $note): ?>
    <p><?= e($note['body']) ?></p>
<?php endforeach ?>
```

`attrs()` builds escaped attributes and leaves out empty or false ones:

```php
<li <?= attrs(['class' => 'note', 'data-id' => $note['id'], 'hidden' => !$note['visible']]) ?>>
```

> [!WARNING]
> Nothing is escaped automatically. An unescaped value can inject HTML and scripts into the page.

## Layouts

A template picks its layout with `layout()`:

```php
<?php $this->layout('layout') ?>
<?php $this->section('title', 'Notes') ?>

<?php foreach ($notes as $note): ?>
    <p><?= e($note['body']) ?></p>
<?php endforeach ?>
```

The template's output is the layout's `content` section:

```php
<!doctype html>
<title><?= $this->yield('title', 'My app') ?></title>
<main><?= $this->yield('content') ?></main>
```

## Sections

A template sets a section and its layout prints it with `yield()`. `section()` with two arguments takes a string, number or backed enum:

```php
<?php $this->section('title', e($note['title'])) ?>
```

> [!WARNING]
> Sections aren't escaped. Escape what you put in them, as above.

### Longer sections

`section()` with only a name captures output until `end()`:

```php
<?php $this->section('aside') ?>
    <h2>Tags</h2>
    <p><?= e(implode(', ', $tags)) ?></p>
<?php $this->end() ?>
```

## Including other templates

Call `render()` inside a template for shared pieces:

```php
<?= $this->render('_nav', ['active' => 'notes']) ?>
```

> [!TIP]
> Prefix partials with `_`, such as `_nav.php`.

## Sharing data with every template

`share()` makes a value available to every later render. Share in `bootstrap.php` to reach every template:

```php
$view = $app->container->get(Template::class);
$view->share('appName', 'Notes');
```

Share helpers as closures, so they only run when a template calls them:

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

A variable passed to `render()` wins over a shared one.

## When something is wrong

- A missing template throws a `RuntimeException`.
- An unclosed section, or `end()` with nothing open, throws a `LogicException`.
- A section set to anything but a string, number or backed enum throws a `TypeError`.

## Method reference

| Method | What it does |
| --- | --- |
| `render($template, $data = [])` | Renders `views/{$template}.php` with `$data` as its variables, inside its layout |
| `share($key, $value)` | Makes a value available to every template rendered after this |
| `layout($template)` | Wraps the current template in another, which prints it with `yield('content')` |
| `section($name, $content)` | Sets a section to a string, number or backed enum |
| `section($name)` and `end()` | Capture everything printed between them as a section |
| `yield($name, $default = '')` | Returns a section, or `$default` when it wasn't set |
