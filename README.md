# ObjectQuel

[![Latest Version](https://img.shields.io/packagist/v/quellabs/objectquel.svg)](https://packagist.org/packages/quellabs/objectquel)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/quellabs/objectquel.svg)](https://packagist.org/packages/quellabs/objectquel)

A domain-level query engine with integrated ORM capabilities, built on the Data Mapper pattern. ObjectQuel provides a purpose-built query language for expressing complex data relationships naturally, powered by CakePHP's database foundation under the hood.

## Why ObjectQuel?

ORMs force you to choose: readable code or powerful queries. Query builders are verbose. DQL is SQL with extra steps. ObjectQuel takes a different approach — a declarative query language inspired by [QUEL](https://en.wikipedia.org/wiki/QUEL_query_language) that works at the entity level, not the table level.

```php
// Relationships, filtering, regex, aliasing — in readable syntax
$results = $entityManager->executeQuery("
    range of p is App\\Entity\\Product
    range of c is App\\Entity\\Category via p.categories
    retrieve (p, c.name as categoryName)
    where p.price < :maxPrice and c.active = true
", [
    'maxPrice' => 50.00
]);
```

The query engine decomposes this into optimized SQL, handles joins from your entity relationships, and hydrates the results — while the syntax stays close to what you actually mean.

## Installation

```bash
composer require quellabs/objectquel
```

## Quick Start

```php
use Quellabs\ObjectQuel\Configuration;
use Quellabs\ObjectQuel\EntityManager;

$config = new Configuration();
$config->setEntityNamespace('App\\Entity');
$config->setEntityPath(__DIR__ . '/src/Entity');

$entityManager = new EntityManager($config, $connection);

// Standard ORM operations
$product = $entityManager->find(Product::class, 101);
$active = $entityManager->findBy(Product::class, ['active' => true]);

// ObjectQuel for complex queries
$results = $entityManager->executeQuery("
    range of p is App\\Entity\\Product
    retrieve (p) where p.name = /^Tech/i
    sort by p.createdAt desc
");
```

## What Makes the Query Language Different

ObjectQuel isn't SQL with renamed keywords. The abstraction layer enables things SQL can't do natively:

- **Pattern matching** — `p.name = "abc*xyz"` or `p.name = /^a/i` instead of verbose `LIKE` / `REGEXP` syntax
- **Full-text search** — `search(p.name, "banana +pear -apple")` with weight support
- **Existence checks** — `ANY(o.orderId)` in both `retrieve` and `where` clauses
- **Relationship traversal** — `via` keyword resolves entity relationships without manual joins
- **Hybrid data sources** — combine database queries with external JSON sources
- **Query decomposition** — the engine splits complex queries into optimized sub-tasks automatically

## ORM Features

Beyond the query language, ObjectQuel provides a full Data Mapper ORM:

- Annotation-based entity mapping with `@Orm\Table`, `@Orm\Column`
- OneToOne, ManyToOne, OneToMany, and ManyToMany relationships via bridge entities
- Unit of Work with change tracking, persist, and flush
- Lazy loading with configurable proxy generation
- Immutable entities for views and read-only tables
- Custom repositories
- Lifecycle events via SignalHub (pre/post persist, update, delete)
- Database migrations powered by Phinx
- CLI tooling: `make:entity`, `make:entity-from-table`, `make:migrations`, `quel:migrate`

## Documentation

Full docs, query language reference, and entity mapping guide: **[objectquel.com/docs](https://objectquel.com/docs)**

## License

MIT