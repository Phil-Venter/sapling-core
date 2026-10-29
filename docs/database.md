# Database

- [Introduction](#introduction)
- [Connecting](#connecting)
    - [SQLite](#sqlite)
    - [Other databases](#other-databases)
- [Running queries](#running-queries)
    - [Fetching rows](#fetching-rows)
    - [Fetching a single value](#fetching-a-single-value)
    - [Inserting, updating and deleting](#inserting-updating-and-deleting)
- [Transactions](#transactions)
- [Schema migrations](#schema-migrations)
    - [Creating a migration](#creating-a-migration)
    - [Running migrations](#running-migrations)
    - [Checking what has run](#checking-what-has-run)
    - [Running migrations from code](#running-migrations-from-code)
- [Method reference](#method-reference)
    - [Db](#db)
    - [Migrations](#migrations)

## Introduction

`Sapling\Db` wraps PDO for SQL you write yourself. Each query is one call with `?` placeholders, and rows come back as arrays keyed by column name.

## Connecting

The database is registered once in `bootstrap.php`, and any class can then ask for `Db` in its constructor. Registered as a closure, it never connects for a request that doesn't use it:

```php
$app->container->set(Db::class, fn(): Db => Db::sqlite($config->get('db_file')));
```

### SQLite

`Db::sqlite()` opens a SQLite database file, creating it if needed, and applies these pragmas:

| Pragma | What it does |
| --- | --- |
| `journal_mode = WAL` | Readers don't block the writer |
| `synchronous = NORMAL` | Commits don't wait for the disk. A power cut can lose the last few, but can't corrupt the file |
| `foreign_keys = ON` | Foreign keys are enforced |
| `busy_timeout = 5000` | A locked database is retried for 5 seconds before "database is locked" |
| `journal_size_limit = 67108864` | The WAL file shrinks back to 64 MB after a big write |

A file that can't be opened throws a `RuntimeException` that names it. To set more pragmas, use `$db->pdo` when you register the database:

```php
$app->container->set(Db::class, function () use ($config): Db {
    $db = Db::sqlite($config->get('db_file'));
    $db->pdo->exec('PRAGMA cache_size = -20000');

    return $db;
});
```

### Other databases

For MySQL, PostgreSQL or anything else PDO supports, create the connection yourself and wrap it in `new Db()`:

```php
$app->container->set(Db::class, function () use ($config): Db {
    $pdo = new PDO($config->get('db_dsn'), $config->get('db_user'), $config->get('db_password'));

    return new Db($pdo);
});
```

`Db` then sets the connection to throw on errors, to fetch rows by name, and to use real prepared statements, which make `LIMIT ?` work on MySQL.

## Running queries

Every method takes the SQL and a list of values for its `?` placeholders:

```php
$sql = <<<SQL
    SELECT id, body, created_at
    FROM notes
    WHERE user_id = ?
    ORDER BY created_at DESC
    SQL;

$notes = $this->db->all($sql, [$userId]);
```

A query that fails throws a `PDOException`, which you can catch for cases such as a duplicate email.

### Fetching rows

`all()` returns every row as a list, and `one()` returns the first row, or `null` when there isn't one:

```php
$sql = <<<SQL
    SELECT id, body
    FROM notes
    WHERE id = ?
    SQL;

$note = $this->db->one($sql, [$request->param('id')->int()]);
if ($note === null) abort(404, 'That note does not exist.');

return Response::text($note['body']);
```

### Fetching a single value

For a count or a single field, `value()` returns the first column of the first row as a [`Value`](values.md):

```php
$sql = <<<SQL
    SELECT count(*)
    FROM notes
    WHERE user_id = ?
    SQL;

$count = $this->db->value($sql, [$userId])->int();
```

Drivers differ in the types they return. MySQL gives a `DECIMAL` as a string, in a row as well as from `value()`.

### Inserting, updating and deleting

Statements that change data go through `run()`, which returns how many rows changed. After an insert, `lastId()` returns the new row's id as a string:

```php
$sql = <<<SQL
    INSERT INTO notes (user_id, body)
    VALUES (?, ?)
    SQL;

$this->db->run($sql, [$userId, $body]);
$id = $this->db->lastId();
```

## Transactions

`transaction()` runs a closure and commits its writes, or rolls them all back if it throws. It returns the closure's result:

```php
$id = $this->db->transaction(function (Db $db) use ($userId, $body): string {
    $sql = <<<SQL
        INSERT INTO notes (user_id, body)
        VALUES (?, ?)
        SQL;

    $db->run($sql, [$userId, $body]);
    $id = $db->lastId();

    $sql = <<<SQL
        UPDATE users
        SET note_count = note_count + 1
        WHERE id = ?
        SQL;

    $db->run($sql, [$userId]);

    return $id;
});
```

On SQLite, a transaction starts with `BEGIN IMMEDIATE`, which waits for the write lock at the start, as a single write does, rather than failing at the transaction's first write.

> [!WARNING]
> Transactions don't nest. A `transaction()` inside another throws a `PDOException`.

## Schema migrations

Each schema change is a migration, a plain SQL file in `database/migrations/`, and three commands manage them.

### Creating a migration

```sh
php bin/console migrations:new add notes
```

This creates an empty `database/migrations/20261012093000-add_notes.sql`. Starting the name with the UTC time sorts the files in the order they were made, on any machine. Zero-padded names such as `001_notes.sql` sort in order too.

### Running migrations

```sh
php bin/console migrations:run
```

This runs the files that haven't run yet, in name order and each in its own transaction, and records them in a `migrations` table. An empty file is skipped until it has SQL in it. There are no down migrations, and you undo one by writing another that reverses it.

Migrations are recorded by file name, and editing one that has already run changes nothing. A migration that fails is rolled back and stops the run, which exits with 1, while the ones before it stay recorded.

On SQLite, foreign keys are off while a migration runs, which lets you [rebuild a table](https://www.sqlite.org/lang_altertable.html#otheralter) without its `DROP TABLE` deleting the rows that point at the table, though it also means a delete doesn't cascade. Before the migration commits, every foreign key is checked, and a row that points nowhere fails the migration.

> [!NOTE]
> MySQL commits `CREATE`, `ALTER` and `DROP` immediately, outside the transaction. A migration that fails partway can leave some of its changes behind.

### Checking what has run

After a deploy, `migrations:list` shows when each file ran, or that it's still pending:

```sh
php bin/console migrations:list
```

```text
20261012093000-add_notes.sql  ran 2026-10-12 09:31:04 UTC
20261019112938-add_tags.sql   pending
```

### Running migrations from code

`Sapling\Migrations` runs a folder of migrations on any `Db`, as `migrations:run` does on the app's database. Use it for another database, such as one file per customer, or to set up a database in a test:

```php
use Sapling\Migrations;

$db = Db::sqlite("/data/customers/{$customerId}.db");
$ran = new Migrations($db, $app->path('database/customer-migrations'))->run();
```

`run()` prints nothing and returns the names of the files it ran. A failed file makes it throw a `RuntimeException` that names the file. `all()` returns each file with the UTC time it ran, or `null` while it's pending.

## Method reference

### Db

| Method | What it does |
| --- | --- |
| `Db::sqlite($file)` | Opens a SQLite file with the pragmas above |
| `all($sql, $params = [])` | Returns every row, as a list of arrays |
| `one($sql, $params = [])` | Returns the first row, or `null` |
| `value($sql, $params = [])` | Returns the first column of the first row as a [`Value`](values.md) |
| `run($sql, $params = [])` | Runs a statement and returns how many rows it changed |
| `lastId()` | Returns the id of the last inserted row, as a string |
| `transaction($fn)` | Runs `$fn` with the `Db` in a transaction and returns its result. Rolls back if it throws |
| `$db->pdo` | The PDO connection, for anything the methods don't cover |
| `new Db($pdo)` | Wraps any PDO connection, set to throw on errors, fetch rows by name and use real prepared statements |

### Migrations

| Method | What it does |
| --- | --- |
| `$migrations->run()` | Runs the migrations that haven't run yet and returns their file names |
| `$migrations->all()` | Returns each migration's file name with the UTC time it ran, or `null` while it's pending |
| `new Migrations($db, $dir)` | The `.sql` files in `$dir`, to run on `$db` |
