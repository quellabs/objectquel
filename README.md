# ObjectQuel

[![Latest Version](https://img.shields.io/packagist/v/quellabs/objectquel.svg)](https://packagist.org/packages/quellabs/objectquel)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](https://phpstan.org)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

ObjectQuel is a query language and ORM for PHP. Query mapped entities, traverse relationships, combine database and JSON sources, and let ObjectQuel compile the database work for MySQL/MariaDB, PostgreSQL, SQLite, or SQL Server.

```php
$results = $entityManager->executeQuery('
    range of p is App\Entity\Product
    range of c is App\Entity\Category via p.categories
    retrieve (p, categoryName = c.name)
    where p.price < :maxPrice and c.active = true
    sort by p.name asc
', ['maxPrice' => 50.00]);
```

## Installation

```bash
composer require quellabs/objectquel
```

## Upgrading to 2.0

Version 2.0 changes relationship annotations: `@OneToMany` and the non-owning form of `@OneToOne` are replaced by `@InverseOf(targetEntity=..., relation="...")`. `@OneToOne` now marks only the side that owns the foreign key. For example:

```php
/** @Orm\InverseOf(targetEntity=PostEntity::class, relation="user") */
public CollectionInterface $posts;
```

## Quick start

```php
use Quellabs\ObjectQuel\Configuration;
use Quellabs\ObjectQuel\EntityManager;

$config = new Configuration();
$config->setEntityNamespace('App\\Entity');
$config->setEntityPath(__DIR__ . '/src/Entity');

$entityManager = new EntityManager($config, $connection);
$product = $entityManager->find(Product::class, 101);

$results = $entityManager->executeQuery('
    range of p is App\Entity\Product
    retrieve (p) where p.name = /^Tech/i
    sort by p.createdAt desc
    window 0, 10
');
```

ObjectQuel supports regex and wildcard predicates, full-text search, existence checks, and queries that join mapped entities with JSON sources. It can split a query across database and PHP stages when needed. See the [query language guide](https://objectquel.com/docs) for examples and syntax.

## Writes, DDL, and EQUEL

Use `append`, `replace`, and `delete` for set-based writes through `executeQuery()`. These bypass the Unit of Work; use `persist()` and `flush()` when you need entity lifecycle behavior. See the [write statements](https://objectquel.com/docs?section=language-append).

Schema statements include `create`, `alter`, `destroy`, and index operations. Use [DDL queries](https://objectquel.com/docs?section=language-create-destroy) for ad hoc schema work and migrations for maintained schemas.

EQUEL defines stored functions in ObjectQuel syntax. A value-returning definition produces a database function; `void` produces a database procedure. For example:

```php
$entityManager->executeQuery('define function double_value (int value) int { return value * 2 }');
$result = $entityManager->executeQuery('double_value(:value)', ['value' => 21]);
```

EQUEL supports MySQL/MariaDB, PostgreSQL, and SQL Server. A definition fails if its name already exists; use `destroy function` before redefining it. See the [EQUEL guide](https://objectquel.com/docs?section=language-equel) for function bodies, calls, and engine notes.

A `tfunction`, taking a mapped entity as a row parameter, attaches to a table's writes instead of being called directly:

```php
$entityManager->executeQuery('
    range of u is App\Entity\User
    define tfunction audit_user (App\Entity\User old, App\Entity\User new) {
        append to a (userId = new.id, previousName = old.username, currentName = new.username)
    }
');
$entityManager->executeQuery('
    range of u is App\Entity\User
    after replace u call audit_user(old, new) as audit_trigger
');
```

Attach with `after append to|replace|delete <range> call <function>(...) [as <alias>]`; detach with `destroy trigger <range> <alias> [if exists]`. Omit the alias and one is generated — `quel:list-triggers` shows it, along with every other live attachment's table, event and routine. `destroy function` refuses while an attachment still calls it, and `alter` refuses a column-shape or primary-key change on a table with a live attachment — detach first, then redefine and reattach as needed. See the [EQUEL guide](https://objectquel.com/docs?section=language-equel) for row-parameter typing, event mapping, and per-engine safety notes.

## ORM and tooling

ObjectQuel includes entity and relationship mapping, a Unit of Work, lazy loading, optimistic locking, lifecycle events, repositories, and migrations. Its Sculpt CLI can generate entities from scratch or existing tables and create or run migrations:

```bash
php bin/sculpt make:entity
php bin/sculpt make:entity-from-table
php bin/sculpt make:migrations
php bin/sculpt quel:migrate
```

It works standalone or with [Canvas](https://canvasphp.com) through `quellabs/canvas-objectquel`.

## Documentation

The full query language and ORM reference is at [objectquel.com/docs](https://objectquel.com/docs).

## Support

If ObjectQuel saves you time, consider [sponsoring development](https://github.com/sponsors/quellabs).

## License

MIT
