# Commands

- [Introduction](#introduction)
- [Writing a command](#writing-a-command)
- [Running commands](#running-commands)
- [Input](#input)
    - [Arguments](#arguments)
    - [Options and flags](#options-and-flags)
    - [Asking a question](#asking-a-question)
- [Output and exit codes](#output-and-exit-codes)
- [Adding commands in code](#adding-commands-in-code)
- [Built-in commands](#built-in-commands)
- [Method reference](#method-reference)

## Introduction

Jobs such as clearing out old rows or creating accounts don't belong behind a URL. A command is a class you run from the terminal instead, such as `php bin/console notes:prune`. It gets its dependencies from the same container as your actions.

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

`#[Command]` takes the command's name and a description, which the list of commands shows.

> [!TIP]
> Name commands `domain:action` with a plural domain, such as `notes:prune` or `users:add`, so the list groups them by domain.

## Running commands

```sh
php bin/console notes:prune --days=7
```

Run without a name, `bin/console` lists every command, and an unknown name prints the same list and exits with 1.

There's no scheduler built in. Commands that should run regularly go in the server's crontab:

```sh
0 3 * * * php /path/to/app/bin/console notes:prune
```

## Input

`Console` reads the words after the command name in three ways. `--name=value` is an option, `--name` on its own is a flag, and any other word is an argument. Every word after `--` is an argument, even one that starts with dashes.

### Arguments

Arguments are read by their position after the command name, counting from 0. Like request input, `arg()` returns a [`Value`](values.md):

```php
// php bin/console users:add ann@example.com
$email = $console->arg(0)->string();        // ann@example.com
$role = $console->arg(1)->string('member'); // member
```

### Options and flags

`option()` also returns a `Value`, while `flag()` says whether the flag was given:

```php
// php bin/console notes:prune --days=7 --dry-run
$days = $console->option('days')->int(30); // 7
$dryRun = $console->flag('dry-run');       // true
```

An option that's missing or isn't of the type you read gives the default, so `--days=soon` gives 30.

> [!WARNING]
> Options need `=`. Written as `--days 7`, the value would be lost, so `option('days')` throws a `UsageException` instead. `flag('force')` throws the same way when given `--force=yes`.

### Asking a question

`ask()` prints a question and returns the answer as a `Value`. A blank answer gives the default you read it with, so pressing Enter here keeps the notes:

```php
if (!$console->ask("Delete {$count} notes? (yes/no)")->bool()) {
    return Console::SUCCESS;
}
```

Without a terminal, such as under cron, standard input is usually empty, so every answer is blank. Read answers with a safe default, or skip the question when `stream_isatty(STDIN)` is false.

## Output and exit codes

`line()` prints to standard output and `error()` to standard error:

```php
$console->line("Deleted {$deleted} notes.");
$console->error('No such user.');
```

Return `Console::SUCCESS` (0) or `Console::FAILURE` (1). A command that throws prints the error and exits with 1. It's also logged when `error_log` is set, such as to a file or `syslog`. Without it, PHP's log is the terminal.

A `Sapling\Cli\UsageException`, such as the one `--days 7` causes, prints its message and exits with 1, with no trace and no log line. Throw one for your own usage mistakes:

```php
if (blank($email)) {
    throw new UsageException('Usage: php bin/console users:add <email>');
}
```

Any other exception, such as an `InvalidArgumentException` from a bug, is a crash and shows its trace.

## Adding commands in code

Like routes, commands can also be added in code, which suits one-file apps. `$app->commands->add()` takes a class name, an object or a closure:

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
| `migrations:run` | Runs new migrations, see [database](database.md#running-migrations) |
| `migrations:new <name>` | Creates an empty migration named by the UTC time, see [database](database.md#creating-a-migration) |

## Method reference

| Method | What it does |
| --- | --- |
| `arg($index)` | Returns the argument at `$index` after the command name, counting from 0, as a `Value` |
| `option($name)` | Returns the value of `--name=value`, as a `Value` |
| `flag($name)` | Returns whether `--name` was given |
| `ask($question)` | Prints `$question` and returns the answer as a `Value`. A blank answer, or the end of input, is an empty `Value` |
| `line($text = '')` | Prints a line to standard output |
| `error($text)` | Prints a line to standard error |
| `Console::SUCCESS`, `Console::FAILURE` | The exit codes 0 and 1 |
| `throw new UsageException($message)` | Stops the command for a usage mistake, printing only the message and exiting with 1 |
| `$console->command` | The command name |
| `$app->commands->add($name, $action, $description = '')` | Adds a command in code |
