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
- [Migrations](#migrations)
    - [Creating a migration](#creating-a-migration)
    - [Running migrations](#running-migrations)
- [Using PDO directly](#using-pdo-directly)
- [Method reference](#method-reference)

## Introduction

`Sapling\Db` is a thin layer over PDO. PDO does the real work, but every query takes a prepare, an execute and a fetch, and rows come back twice, by name and by position. `Db` turns each query into one call with `?` placeholders, and returns rows as arrays keyed by column name.

There's no query builder or ORM. You write the SQL, and it stays where you can read it.

## Connecting

The database is registered once in `bootstrap.php`, and any class can then ask for `Db` in its constructor. Because it's registered as a closure, a request that never uses the database never connects:

```php
$app->container->set(Db::class, fn() => Db::sqlite($config->get('db_file')));
```

### SQLite

SQLite suits most small apps, since the whole database is one file. `Db::sqlite()` opens that file, creating it if needed, and applies settings that suit a web app:

| Setting | What it does |
| --- | --- |
| `journal_mode = WAL` | Readers don't block the writer |
| `synchronous = NORMAL` | Commits don't wait for the disk. A power cut can lose the last few, but can't corrupt the file |
| `foreign_keys = ON` | Foreign keys are enforced |
| `busy_timeout = 5000` | A locked database is retried for 5 seconds before "database is locked" |
| `journal_size_limit = 67108864` | The WAL file shrinks back to 64 MB after a big write |

A file that can't be opened throws a `RuntimeException` that names it.

### Other databases

For MySQL, PostgreSQL or anything else PDO supports, create the connection yourself and wrap it in `new Db()`:

```php
$app->container->set(Db::class, function () use ($config): Db {
    $pdo = new PDO($config->get('db_dsn'), $config->get('db_user'), $config->get('db_password'));

    return new Db($pdo);
});
```

`Db` then sets the connection to throw on errors, to fetch rows by name, and to use real prepared statements, so `LIMIT ?` works on MySQL.

## Running queries

Every method takes the SQL and a list of values for its `?` placeholders. Writing the SQL as a heredoc, one clause per line, keeps longer queries readable:

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
abort_if($note === null, 404, 'That note does not exist.');

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

Rows come back as plain arrays, since their shape is your own schema. A lone value is wrapped because drivers differ in its type, such as MySQL returning a `DECIMAL` as a string.

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

Writes that belong together should succeed or fail together. `transaction()` runs a closure and commits everything it does, or rolls it all back if it throws, and returns whatever the closure returns:

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

On SQLite, a transaction starts with `BEGIN IMMEDIATE`, so it waits for the write lock like any other write, instead of failing at its first write.

> [!WARNING]
> Transactions don't nest. A `transaction()` inside another throws a `PDOException`, so start the transaction in the outermost code, such as the action, and let the classes it calls use plain `run()`.

## Migrations

The schema changes over time, one step at a time. Each step is a migration, a plain SQL file in `database/migrations/`, and two commands manage them.

### Creating a migration

```sh
php bin/console migrations:new "add notes"
```

This creates an empty `database/migrations/20261012093000-add_notes.sql`. Starting the name with the UTC time sorts the files in the order they were made, on any machine. Zero-padded names such as `001_notes.sql` sort correctly too.

### Running migrations

```sh
php bin/console migrations:run
```

This runs the files that haven't run yet, in name order and each in its own transaction, and records them in a `migrations` table. An empty file is skipped without being recorded, so it still runs once it has SQL in it. There are no down migrations. To undo one, write another that reverses it.

> [!NOTE]
> MySQL commits `CREATE`, `ALTER` and `DROP` immediately, outside the transaction, so a migration that fails partway can leave some changes behind. Keep one schema change per file.

## Using PDO directly

For anything the methods don't cover, `$db->pdo` is the PDO connection itself. A common use is setting more SQLite pragmas when the database is registered:

```php
$app->container->set(Db::class, function () use ($config): Db {
    $db = Db::sqlite($config->get('db_file'));
    $db->pdo->exec('PRAGMA cache_size = -20000');

    return $db;
});
```

## Method reference

| Method | What it does |
| --- | --- |
| `all($sql, $params = [])` | Returns every row, as a list of arrays |
| `one($sql, $params = [])` | Returns the first row, or `null` |
| `value($sql, $params = [])` | Returns the first column of the first row as a [`Value`](values.md) |
| `run($sql, $params = [])` | Runs a statement and returns how many rows it changed |
| `lastId()` | Returns the id of the last inserted row, as a string |
| `transaction($fn)` | Runs `$fn` with the `Db` in a transaction and returns its result. Rolls back if it throws |
| `Db::sqlite($file)` | Opens a SQLite file with the settings above |
| `new Db($pdo)` | Wraps any PDO connection, set to throw on errors, fetch rows by name and use real prepared statements |
| `$db->pdo` | The PDO connection itself, for anything the methods don't cover |
