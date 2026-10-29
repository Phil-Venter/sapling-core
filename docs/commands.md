# Commands

- [Introduction](#introduction)
- [Writing a command](#writing-a-command)
- [Running commands](#running-commands)
- [Input](#input)
    - [Arguments](#arguments)
    - [Options and flags](#options-and-flags)
    - [Asking a question](#asking-a-question)
- [Output](#output)
- [Exit codes and errors](#exit-codes-and-errors)
- [Adding commands in code](#adding-commands-in-code)
- [Built-in commands](#built-in-commands)
- [Method reference](#method-reference)

## Introduction

A command is a class you run from the terminal, such as `php bin/console notes:prune`, for jobs that don't belong behind a URL. It gets its dependencies from the same container as your actions.

## Writing a command

A command is written much like an action. It's a class in `app/` with a `#[Command]` attribute, and its `__invoke()` takes a `Console` and returns an exit code:

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

`#[Command]` takes the command's name and a description, which `commands:list` shows grouped by the part of the name before the colon, such as `notes` in `notes:prune`.

## Running commands

```sh
php bin/console notes:prune --days=7
```

Run without a name, `bin/console` lists every command, and an unknown name prints the same list and exits with 1.

There's no scheduler built in, and commands that should run regularly go in the server's crontab:

```sh
0 3 * * * php /path/to/app/bin/console notes:prune
```

> [!WARNING]
> php-fpm's `env[NAME]` variables only reach web requests. A command started from cron or a shell gets the defaults in `config.php` and an empty `app_key`, unless you set the same variables for it, such as at the top of the crontab.

## Input

`Console` reads the words after the command name in three ways. `--name=value` is an option, `--name` on its own is a flag, and any other word is an argument. Every word after `--` is an argument, even one that starts with dashes.

### Arguments

Arguments are read by their position after the command name, counting from 0. Like request input, `arg()` returns a [`Value`](values.md):

```php
// php bin/console users:add ann@example.com
$email = $console->arg(0)->string();        // ann@example.com
$role = $console->arg(1)->string('member'); // member
```

`$console->args` holds every argument as a list of strings, and joining it puts a name typed without quotes back together:

```php
// php bin/console users:find Ann Smith
$name = implode(' ', $console->args); // Ann Smith
```

### Options and flags

`option()` also returns a `Value`, while `flag()` says whether the flag was given:

```php
// php bin/console notes:prune --days=7 --dry-run
$days = $console->option('days')->int(30); // 7
$dryRun = $console->flag('dry-run');       // true
```

An option that's missing or isn't of the type you read gives the default, as `--days=soon` gives 30.

> [!WARNING]
> Options need `=`. For `--days 7`, `option('days')` throws a `UsageException`, as `flag('force')` does for `--force=yes`.

### Asking a question

`ask()` prints a question and returns the answer as a `Value`, and a blank answer gives the default you read it with. Pressing Enter here keeps the notes:

```php
if (!$console->ask("Delete {$count} notes? (yes/no)")->bool()) {
    return Console::SUCCESS;
}
```

Without a terminal, such as under cron, every answer is blank and reads as the default.

## Output

`line()` prints to standard output and `error()` to standard error:

```php
$console->line("Deleted {$deleted} notes.");
$console->error('No such user.');
```

`table()` prints rows as columns, lined up two spaces apart. An empty row prints a blank line:

```php
$console->table([
    ['ann@example.com', 'admin'],
    ['bob@example.com', 'member'],
]);
```

## Exit codes and errors

A command returns `Console::SUCCESS` (0) or `Console::FAILURE` (1), which becomes its exit code.

For a usage mistake, throw a `Sapling\Cli\UsageException`, as `option()` does for `--days 7`. It prints only its message and exits with 1, with no trace and no log line:

```php
if (blank($email)) {
    throw new UsageException('Usage: php bin/console users:add <email>');
}
```

Any other exception, such as an `InvalidArgumentException` from a bug, is a crash. It prints the error with its trace and exits with 1, and is also logged when `error_log` is set, such as to a file or `syslog`.

## Adding commands in code

Like routes, commands can be added in code for [one-file apps](app.md#one-file-apps), with `$app->commands->add()` taking a class name, an object or a closure:

```php
$app->commands->add('greet', function (Console $console): int {
    $console->line("Hello {$console->arg(0)->string('world')}");

    return Console::SUCCESS;
}, 'Say hello');
```

Two commands with the same name stop the app with a `LogicException` that names both.

## Built-in commands

| Command | What it does |
| --- | --- |
| `commands:list` | Lists every command, grouped by the part of the name before the colon |
| `routes:list` | Lists every route and the class that answers it |
| `migrations:run` | [Runs new migrations](database.md#running-migrations) |
| `migrations:list` | [Lists every migration](database.md#checking-what-has-run) and when it ran |
| `migrations:new <name>` | [Creates an empty migration](database.md#creating-a-migration) named by the UTC time |

## Method reference

| Method | What it does |
| --- | --- |
| `#[Command($name, $description = '')]` | Makes the class a command. `commands:list` shows the description |
| `arg($index)` | Returns the argument at `$index` after the command name, counting from 0, as a `Value` |
| `option($name)` | Returns the value of `--name=value`, as a `Value` |
| `flag($name)` | Returns whether `--name` was given |
| `ask($question)` | Prints `$question` and returns the answer as a `Value`. A blank answer, or the end of input, is an empty `Value` |
| `line($text = '')` | Prints a line to standard output |
| `error($text)` | Prints a line to standard error |
| `table($rows)` | Prints a list of rows as lined-up columns. An empty row prints a blank line |
| `Console::SUCCESS`, `Console::FAILURE` | The exit codes 0 and 1 |
| `$console->command` | The command name |
| `$console->args` | Every argument after the command name, as a list of strings |
| `$app->commands->add($name, $action, $description = '')` | Adds a command in code |
| `throw new UsageException($message)` | Stops the command for a usage mistake, printing only the message and exiting with 1 |
| `new Console($argv, $input = null)` | A command's input, read from `$argv`. `ask()` reads answers from `$input` when you pass one, as [tests](testing.md#running-a-command) do |
