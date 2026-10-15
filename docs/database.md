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

`Sapling\Db` is a thin layer over PDO. Each query is one call with `?` placeholders, and rows come back as arrays keyed by column name. There's no query builder or ORM.

## Connecting

Register the database in `bootstrap.php`. It connects on first use:

```php
$app->container->set(Db::class, fn() => Db::sqlite($config->get('db_file')));
```

### SQLite

`Db::sqlite()` opens or creates a SQLite file with these settings:

| Setting | What it does |
| --- | --- |
| `journal_mode = WAL` | Readers don't block the writer |
| `synchronous = NORMAL` | Commits don't wait for the disk. A power cut can lose the last few, but can't corrupt the file |
| `foreign_keys = ON` | Foreign keys are enforced |
| `busy_timeout = 5000` | A locked database is retried for 5 seconds before "database is locked" |
| `journal_size_limit = 67108864` | The WAL file shrinks back to 64 MB after a big write |

A file that can't be opened throws a `RuntimeException`.

> [!WARNING]
> Keep the database file outside `public/`.

### Other databases

Wrap any PDO connection in `new Db()`:

```php
$app->container->set(Db::class, function () use ($config): Db {
    $pdo = new PDO($config->get('db_dsn'), $config->get('db_user'), $config->get('db_password'));

    return new Db($pdo);
});
```

`Db` sets it to throw on errors, fetch rows by name, and use real prepared statements, so `LIMIT ?` works on MySQL.

## Running queries

Every method takes the SQL and the values for its `?` placeholders:

```php
$sql = <<<SQL
    SELECT id, body, created_at
    FROM notes
    WHERE user_id = ?
    ORDER BY created_at DESC
    SQL;

$notes = $this->db->all($sql, [$userId]);
```

> [!WARNING]
> Always pass values as `?` parameters, never in the SQL string.

A failed query throws a `PDOException`.

### Fetching rows

`all()` returns every row, and `one()` the first row or `null`:

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

> [!TIP]
> Name the columns you need instead of `SELECT *`.

### Fetching a single value

`value()` returns the first column of the first row as a [`Value`](values.md):

```php
$sql = <<<SQL
    SELECT count(*)
    FROM notes
    WHERE user_id = ?
    SQL;

$count = $this->db->value($sql, [$userId])->int();
```

### Inserting, updating and deleting

`run()` returns how many rows changed. `lastId()` returns the last inserted id as a string:

```php
$sql = <<<SQL
    INSERT INTO notes (user_id, body)
    VALUES (?, ?)
    SQL;

$this->db->run($sql, [$userId, $body]);
$id = $this->db->lastId();
```

## Transactions

`transaction()` commits the closure's writes together, or rolls them back if it throws. It returns the closure's result:

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

On SQLite, transactions start with `BEGIN IMMEDIATE`, so they wait for the write lock instead of failing at the first write.

> [!WARNING]
> Transactions don't nest. A `transaction()` inside another throws a `PDOException`, so start it in the outermost code, such as the action.

## Migrations

Migrations are SQL files in `database/migrations/`.

### Creating a migration

```sh
php bin/console migrations:new "add notes"
```

This creates an empty `database/migrations/20261012093000-add_notes.sql`, named by the UTC time so files sort in order. Zero-padded names such as `001_notes.sql` work too.

### Running migrations

```sh
php bin/console migrations:run
```

New files run in name order, each in a transaction, and are recorded in a `migrations` table. Empty files are skipped and run once they have SQL.

> [!WARNING]
> Don't edit a migration that has already run. Servers that ran it won't run it again.

> [!NOTE]
> MySQL commits `CREATE`, `ALTER` and `DROP` immediately, so a failed migration can leave changes behind. Keep one schema change per file.

## Using PDO directly

`$db->pdo` is the PDO connection. Use it for other SQLite pragmas, for example:

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
