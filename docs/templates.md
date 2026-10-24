# Templates

- [Introduction](#introduction)
- [Rendering a template](#rendering-a-template)
- [Printing values](#printing-values)
- [Partials](#partials)
- [Components](#components)
    - [Named slots](#named-slots)
- [Layouts](#layouts)
- [Plain text](#plain-text)
- [Sharing data with every template](#sharing-data-with-every-template)
- [Templates next to their classes](#templates-next-to-their-classes)
- [When something is wrong](#when-something-is-wrong)
- [Method reference](#method-reference)

## Introduction

Sapling's templates are plain PHP files in `views/`, rendered by `Sapling\Template`. It adds partials, components and layouts. Inside a template, `$this` is the `Template`.

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

To review the prints that use neither, list them:

```sh
grep -rnoE '<\?= *[^ ]+' views | grep -vF -e '<?= e(' -e '<?= attrs(' -e '<?= $this->'
```

[Plain-text templates](#plain-text) show up too, since they leave out `e()` on purpose.

## Partials

Pieces that several pages share, such as a navigation bar, go in their own file, named with a leading `_` by convention. Inside a template, print one with `render()`:

```php
<?= $this->render('_nav', ['active' => 'notes']) ?>
```

A partial sees the variables you pass it and the [shared](#sharing-data-with-every-template) ones, but not the page's.

## Components

A component is a partial that wraps content, such as a card or a dialog. Everything printed between `component()` and `end()` becomes the component's default slot, and `end()` then prints the component in its place:

```php
<?php $this->component('_card', ['title' => 'Notes']) ?>
    <p>Anything here is the slot.</p>
<?php $this->end() ?>
```

The component template prints the default slot with `yield()`:

```php
<article class="card">
    <h2><?= e($title) ?></h2>
    <?= $this->yield() ?>
</article>
```

A slot is HTML the page has already printed. Don't escape it again.

### Named slots

A component can take more than one piece of content. `slot()` with only a name captures everything printed until its `end()`, and the component prints it with `yield()` and the name:

```php
<?php $this->component('_card', ['title' => 'Notes']) ?>
    <p>The body.</p>

    <?php $this->slot('footer') ?>
        <a href="/notes">All notes</a>
    <?php $this->end() ?>
<?php $this->end() ?>
```

```php
<footer><?= $this->yield('footer') ?></footer>
```

`slot()` with a value sets the slot to it, such as a title or a list of scripts. `yield()` takes a default for a slot that wasn't set:

```php
<?php $this->slot('scripts', ['/notes.js']) ?>
```

```php
<?php foreach ($this->yield('scripts', []) as $src): ?>
    <script src="<?= e($src) ?>"></script>
<?php endforeach ?>
```

A slot belongs to the component it's set in. Another component never sees it.

## Layouts

Most pages share the same outer HTML. A layout is a component that wraps the whole page, opened on its first line and closed on its last:

```php
<?php $this->component('layout') ?>
<?php $this->slot('title', 'Notes') ?>

<?php foreach ($notes as $note): ?>
    <p><?= e($note['body']) ?></p>
<?php endforeach ?>

<?php $this->end() ?>
```

```php
<!doctype html>
<title><?= e($this->yield('title', 'My app')) ?></title>
<main><?= $this->yield() ?></main>
```

A layout can wrap itself in another the same way, such as an admin layout inside the main one. It passes on the slots the outer layout needs, as in `slot('title', $this->yield('title'))`.

## Plain text

A template can render plain text as well as HTML, such as a command's output or the text part of an email. PHP drops the newline straight after a closing `?>`. A browser never shows the difference, but in plain text two lines run together. A line that prints a value needs its newline inside the tag, while lines that hold only `foreach` or `if` need nothing:

```php
<?php foreach ($notes as $note): ?>
<?= $note['title'], "\n" ?>
<?php endforeach ?>
Total: <?= count($notes), "\n" ?>
```

Leave out `e()` in plain text, or `O'Brien` prints as `O&#039;Brien`.

## Sharing data with every template

`share()` makes a value available to every template rendered after it, such as the app's name. Share it in `bootstrap.php` to reach the whole app:

```php
$view = $app->container->get(Template::class);
$view->share('appName', 'Notes');
```

Share helpers as closures, which only run when a template calls them. A page that never shows a flash message never starts the session:

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

`notes::list` then finds `app/Notes/list.tmpl.php`, in `render()` and `component()`:

```php
return Response::html($this->view->render('notes::list', ['notes' => $notes]));
```

An unknown prefix throws a `LogicException`. To use another suffix, such as plain `.php`, register `Template` yourself with `new Template($app->path('views'), suffix: 'php')`.

## When something is wrong

- A missing template throws a `RuntimeException`.
- An unclosed component or slot, `end()` with nothing open, or `slot()` outside a component throws a `LogicException`.

## Method reference

| Method | What it does |
| --- | --- |
| `render($template, $data = [])` | Renders `views/{$template}.tmpl.php` with `$data` as its variables. Inside a template, it renders a partial |
| `component($template, $data = [])` and `end()` | Print a partial, such as a layout, with everything printed between them as its default slot |
| `slot($name)` and `end()` | Capture everything printed between them as a named slot |
| `slot($name, $content)` | Sets a named slot of the component it's in |
| `yield()` | Inside a component, returns its default slot |
| `yield($name, $default = null)` | Inside a component, returns a named slot, or `$default` when it wasn't set |
| `share($key, $value)` | Makes a value available to every template rendered after this |
| `prefix($prefix, $dir)` | Makes `prefix::name` find templates in `$dir` |
| `new Template($dir, $suffix = 'tmpl.php')` | Finds templates in `$dir` ending in `.{$suffix}`. `App` registers one for `views/` |
