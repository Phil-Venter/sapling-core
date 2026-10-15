# Commands

- [Introduction](#introduction)
- [Writing a command](#writing-a-command)
- [Running commands](#running-commands)
- [Input](#input)
    - [Arguments](#arguments)
    - [Options and flags](#options-and-flags)
- [Output and exit codes](#output-and-exit-codes)
- [Adding commands in code](#adding-commands-in-code)
- [Scheduling commands](#scheduling-commands)
- [Built-in commands](#built-in-commands)
- [Method reference](#method-reference)

## Introduction

A command is a class you run from the terminal, such as `php bin/console notes:prune`. It gets its dependencies from the same container as your actions.

## Writing a command

Give a class in `app/` a `#[Command]` and an `__invoke()` that takes a `Console` and returns an exit code:

```php
namespace App\Notes;

use Sapling\Attributes\Command;
use Sapling\Cli\Console;
use Sapling\Db;

#[Command('notes:prune', 'Delete notes older than --days=30')]
final class PruneNotes
{
    public function __construct(private Db $db) {}

    public function __invoke(Console $console): int
    {
        $days = $console->option('days')->int(30);

        if ($days < 1) {
            $console->error('--days must be a whole number of at least 1.');

            return Console::FAILURE;
        }

        $sql = <<<SQL
            DELETE FROM notes
            WHERE created_at < datetime('now', ?)
            SQL;

        $deleted = $this->db->run($sql, ["-{$days} days"]);
        $console->line("Deleted {$deleted} notes.");

        return Console::SUCCESS;
    }
}
```

`#[Command]` takes the name and a description for the command list.

> [!TIP]
> Name commands `domain:action` with a plural domain, such as `notes:prune`. The list groups them by domain.

## Running commands

```sh
php bin/console notes:prune --days=7
```

With no name, `bin/console` lists the commands. An unknown name lists them and exits with 1.

## Input

`--name=value` is an option, `--name` is a flag, and other words are arguments.

### Arguments

`arg()` reads an argument by position, from 0, as a [`Value`](values.md):

```php
// php bin/console users:add ann@example.com admin
$email = $console->arg(0)->string();
$role = $console->arg(1)->string('member');
```

### Options and flags

`option()` returns a `Value`, and `flag()` a boolean:

```php
// php bin/console notes:prune --days=7 --dry-run
$days = $console->option('days')->int(30);
$dryRun = $console->flag('dry-run');
```

A missing or invalid option gives the default, so `--days=soon` gives 30.

> [!WARNING]
> Options need `=`. With `--days 7`, `option('days')` throws an `InvalidArgumentException`, and so does `flag('force')` with `--force=yes`.

## Output and exit codes

`line()` writes to stdout and `error()` to stderr:

```php
$console->line("Deleted {$deleted} notes.");
$console->error('No such user.');
```

Return `Console::SUCCESS` (0) or `Console::FAILURE` (1). A command that throws is logged, prints the error and exits with 1.

## Adding commands in code

`$app->commands->add()` takes a class name, an object or a closure:

```php
$app->commands->add('greet', function (Console $console): int {
    $console->line("Hello {$console->arg(0)->string('world')}");

    return Console::SUCCESS;
}, 'Say hello');
```

Two commands with the same name throw a `LogicException`.

## Scheduling commands

There's no scheduler. Use cron:

```sh
0 3 * * * php /path/to/app/bin/console notes:prune
```

## Built-in commands

| Command | What it does |
| --- | --- |
| `commands:list` | Lists every command, grouped by the part of the name before the colon |
| `routes:list` | Lists every route and the class that answers it |
| `migrations:run` | Runs new migrations, see [database](database.md#running-migrations) |
| `migrations:new <name>` | Creates an empty migration named by the UTC time, see [database](database.md#creating-a-migration) |

## Method reference

| Method | What it does |
| --- | --- |
| `arg($index)` | Returns the argument at `$index` after the command name, counting from 0, as a `Value` |
| `option($name)` | Returns the value of `--name=value`, as a `Value` |
| `flag($name)` | Returns whether `--name` was given |
| `line($text = '')` | Prints a line to standard output |
| `error($text)` | Prints a line to standard error |
| `$console->command` | The command name |
| `Console::SUCCESS`, `Console::FAILURE` | The exit codes 0 and 1 |
| `$app->commands->add($name, $action, $description = '')` | Adds a command in code |
