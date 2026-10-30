# Templates

- [Introduction](#introduction)
- [Rendering a template](#rendering-a-template)
- [Printing values](#printing-values)
    - [Plain text](#plain-text)
- [Partials](#partials)
- [Components](#components)
    - [Named slots](#named-slots)
- [Layouts](#layouts)
- [Sharing data with every template](#sharing-data-with-every-template)
- [Templates next to their classes](#templates-next-to-their-classes)
- [When something is wrong](#when-something-is-wrong)
- [Method reference](#method-reference)

## Introduction

Sapling's templates are plain PHP files in `views/`, rendered by `Sapling\Template`, which adds partials, components and layouts. Inside a template, `$this` is the `Template`.

## Rendering a template

An action asks for `Template` in its constructor, renders a template with the variables it needs, and returns the HTML:

```php
use Sapling\Attributes\Route;
use Sapling\Db;
use Sapling\Http\Response;
use Sapling\Template;

#[Route('GET', '/notes')]
final class ListNotes
{
    public function __construct(private Db $db, private Template $view) {}

    public function __invoke(): Response
    {
        $sql = <<<SQL
            SELECT id, body
            FROM notes
            ORDER BY created_at DESC
            SQL;

        $notes = $this->db->all($sql);
        $html = $this->view->render('notes', ['notes' => $notes]);

        return Response::html($html);
    }
}
```

`render('notes')` runs `views/notes.tmpl.php` with each array key as a variable, and a name with a slash, such as `notes/show`, looks in a subfolder. The `.tmpl.php` suffix keeps templates apart from classes. To use another suffix, such as plain `.php`, [register `Template` yourself](container.md#replacing-saplings-services) with `new Template(__DIR__ . '/views', suffix: 'php')`.

## Printing values

Inside a template, print values with `<?= ?>` and escape every one with `e()`, which covers quotes and prints a backed enum as its value:

```php
<?php foreach ($notes as $note): ?>
    <p><?= e($note['body']) ?></p>
<?php endforeach ?>
```

For attributes, `attrs()` builds the escaped list from an array. `true` gives a bare attribute such as `hidden`, while `null`, `false` and empty values leave the attribute out, and an array is joined with spaces:

```php
<li <?= attrs(['class' => ['note', 'pinned'], 'data-id' => $note['id'], 'hidden' => true, 'title' => null]) ?>>

// <li class="note pinned" data-id="7" hidden>
```

> [!WARNING]
> Nothing is escaped automatically. A value printed without `e()` or `attrs()` can put a visitor's HTML, and their scripts, on your page.

To review the prints that use neither, list them:

```sh
grep -rnoE '<\?= *[^ ]+' views | grep -vF -e '<?= e(' -e '<?= attrs(' -e '<?= $this->'
```

[Plain-text templates](#plain-text) show up too, since they leave out `e()`.

### Plain text

A template can render plain text as well as HTML, such as a command's output or the text part of an email. Leave out `e()` there, or `O'Brien` prints as `O&#039;Brien`.

PHP drops the newline straight after a closing `?>`. HTML hides the difference, but plain text runs the two lines together. A line that prints a value needs its newline inside the tag, while lines that hold only `foreach` or `if` need nothing:

```php
<?php foreach ($notes as $note): ?>
<?= $note['body'], "\n" ?>
<?php endforeach ?>
Total: <?= count($notes), "\n" ?>
```

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

Don't escape a slot again, since it's HTML the page has already printed.

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

A slot belongs to the component it's set in, and no other component sees it.

## Layouts

A layout is a component that wraps the whole page in the outer HTML your pages share, and a page opens it on its first line and closes it on its last:

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

A layout can wrap itself in another the same way, such as an admin layout inside the main one, and passes on the slots the outer layout needs, as in `slot('title', $this->yield('title'))`.

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

If a variable passed to `render()` has the same name as a shared one, the one passed to `render()` wins.

## Templates next to their classes

A feature's templates can sit next to its actions instead of in `views/`, since [`scan()`](app.md#scanning-a-folder) skips `.tmpl.php` files. Register the folder under a prefix in `bootstrap.php`:

```php
$view = $app->container->get(Template::class);
$view->prefix('notes', __DIR__ . '/app/Notes');
```

`notes::list` then finds `app/Notes/list.tmpl.php`, in `render()` and `component()`:

```php
return Response::html($this->view->render('notes::list', ['notes' => $notes]));
```

## When something is wrong

- A missing template throws a `RuntimeException`.
- An unknown prefix, an unclosed component or slot, `end()` with nothing open, or `slot()` outside a component throws a `LogicException`.

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
