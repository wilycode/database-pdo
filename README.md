# WilyCode Database PDO

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://github.com/wilycode/database-pdo/actions/workflows/tests.yml/badge.svg)](https://github.com/wilycode/database-pdo/actions/workflows/tests.yml)

**A lightweight PDO implementation of the WilyCode database driver contract, with a companion query parameter binder.**

Wrap a native `PDO` connection behind `DatabaseInterface`, execute parameterized SQL, fetch associative results, and use strict transactions. `PdoBinder` translates compiled query parameter types into PDO binding types.

> **WilyCode PHP Ecosystem**
>
> Part of **[WilyCode](https://github.com/wilycode/wilycode)** — independent, reusable PHP components, each with its own repository, releases, and Composer installation.
>
> **[Explore the WilyCode ecosystem →](https://github.com/wilycode/wilycode)**

## Platform detection

PdoDatabase implements PlatformProviderInterface and returns the actual PDO::ATTR_DRIVER_NAME. This enables executor dialect detection without exposing the connection. Requires database >=0.1.2; compatible with query 0.1 and 0.2. This does not add SQL Server/Oracle binding or generated-ID guarantees.

## Features

- Implement `wilycode/database`'s `DatabaseInterface` using PDO.
- Accept an existing `PDO` instance or create one with `PdoDatabase::connect()`.
- Support named and zero-based positional SQL parameters with automatic basic type detection.
- Return associative arrays for `fetch()` and `fetchAll()` even when PDO's default fetch mode is configured differently.
- Wrap query errors in `QueryException`, including PDO preparation, binding, and execution failures reported as `false`.
- Commit successful transactions, roll back on thrown exceptions, and reject nested transactions.
- Convert `wilycode/database-query` parameter types using `PdoBinder`.

## Requirements

- PHP 8.4 or newer.
- PHP `pdo` extension, plus a driver for your database (such as `pdo_mysql`, `pdo_pgsql`, or `pdo_sqlite`).
- `wilycode/database` `^0.1`.
- `wilycode/database-query` `^0.1` (used by `PdoBinder`).

Both WilyCode dependencies must be available to Composer before installation succeeds. The SQLite test suite additionally requires `pdo_sqlite`.

## Installation

```bash
composer require wilycode/database-pdo
```

## Quick start

For an SQLite example (requires `pdo_sqlite`):

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use WilyCode\Database\DriverPdo\PdoDatabase;

$db = PdoDatabase::connect('sqlite::memory:');
$db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
$db->execute('INSERT INTO users (email) VALUES (:email)', ['email' => 'user@example.test']);

$user = $db->fetch('SELECT id, email FROM users WHERE email = :email', [
    'email' => 'user@example.test',
]);

$users = $db->fetchAll('SELECT id, email FROM users ORDER BY id');
$count = $db->fetchColumn('SELECT COUNT(*) FROM users');
$lastId = $db->getLastInsertId(); // String, as returned by PDO.
```

`fetch()` returns `[]` when there is no row, `fetchAll()` returns `[]` for no matches, and `fetchColumn()` returns `null` when there is no row. A SQL `NULL` column also produces `null`.

## Connect with MySQL or an existing PDO instance

```php
use WilyCode\Database\DriverPdo\PdoDatabase;

$db = PdoDatabase::connect(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    getenv('DB_USER') ?: null,
    getenv('DB_PASSWORD') ?: null,
);

// Or reuse a PDO connection that your application already manages:
$db = new PdoDatabase($pdo);
```

The default connection options include `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` and `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`. Explicit connection options take precedence. Query results from `fetch()` and `fetchAll()` remain associative regardless of that fetch mode override.

Never commit real database passwords to source control. Parameter binding protects *values*, not table or column identifiers: never concatenate untrusted identifiers into SQL.

## SQL parameters and types

Named placeholders work with keys such as `email` or `:email`. Positional `?` placeholders use **zero-based PHP arrays**, for example `['name', 18]`.

```php
use PDO;

$db->execute('INSERT INTO users (email) VALUES (?)', ['second@example.test']);

// Optional explicit PDO parameter types; keys may include a leading colon.
$db->fetch('SELECT email FROM users WHERE id = :id', ['id' => '1'], [
    ':id' => PDO::PARAM_INT,
]);
```

Without explicit types, `null` is bound with `PDO::PARAM_NULL`, booleans with `PDO::PARAM_BOOL`, integers with `PDO::PARAM_INT`, and other values with `PDO::PARAM_STR`. Supply a valid PDO parameter type where this default is insufficient.

## Integrate with WilyCode Database Query

`PdoBinder` implements `BinderInterface` from `wilycode/database-query` and maps the query's `ParameterType` values to PDO constants. It does not execute queries itself.

```php
use WilyCode\Database\DriverPdo\PdoBinder;
use WilyCode\Database\Query\Query;

$compiled = Query::select('users', 'u')
    ->columns(['id' => 'id', 'email' => 'email'])
    ->where('u.id', 1)
    ->compile();

$bound = (new PdoBinder())->bind($compiled);
$row = $db->fetch($bound->sql, $bound->params, $bound->types);
```

`Query::select()` only *compiles* SQL; `PdoBinder` maps parameter types, and `PdoDatabase` executes it. The query builder currently uses some MySQL-style identifier quoting, so verify compiled SQL against your database dialect. The `PdoDatabase` class itself does not require using a query builder or query executor to execute ordinary SQL.

## Transactions

```php
$result = $db->transaction(function (PdoDatabase $transaction): string {
    $transaction->execute(
        'INSERT INTO users (email) VALUES (:email)',
        ['email' => 'another@example.test'],
    );

    return $transaction->getLastInsertId();
});
```

The callback receives the active `PdoDatabase` instance. A successful callback commits and returns its result; a thrown exception triggers rollback and is rethrown. **Nested calls to `transaction()` are not supported** and raise `NestedTransactionException`. This adapter does not implement savepoints.

## Error handling

```php
use WilyCode\Database\Driver\Exception\ConnectionException;
use WilyCode\Database\Driver\Exception\QueryException;
use WilyCode\Database\Driver\Exception\TransactionException;

try {
    $db->execute('INSERT INTO users (email) VALUES (:email)', ['email' => 'user@example.test']);
} catch (QueryException $exception) {
    // Decide whether to retry, log, or return an application-level error.
}
```

`PdoDatabase::connect()` wraps PDO connection failures in `ConnectionException`; query failures in `QueryException`; and transaction setup or commit failures in `TransactionException`. Error context records parameter **keys**, not the parameter values. However, exception SQL text or chained PDO exceptions may still reveal sensitive information: never expose raw exception details to clients or untrusted logs.

## Related packages

| Package | Purpose |
| --- | --- |
| [`wilycode/database`](https://github.com/wilycode/database) | Shared database driver contract and exceptions |
| [`wilycode/database-query`](https://github.com/wilycode/database-query) | Compiled query, parameter types, and binder contract |
| [`wilycode/database-mysqli`](https://github.com/wilycode/database-mysqli) | Alternative driver based on MySQLi |

Each package is versioned and installed independently.

## Development

```bash
composer install
composer validate --strict
composer test
```

The integration tests use SQLite in memory and require `pdo_sqlite`. GitHub Actions installs that extension and runs PHPUnit on PHP 8.4. Tests are included in Git, but excluded from Composer distribution archives.

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) and the [issue tracker](https://github.com/wilycode/database-pdo/issues).

## License

MIT © 2026 Simon Piter. See [LICENSE](LICENSE).
