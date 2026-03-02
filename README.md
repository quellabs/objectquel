# ObjectQuel

[![Latest Version](https://img.shields.io/packagist/v/quellabs/objectquel.svg)](https://packagist.org/packages/quellabs/objectquel)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/quellabs/objectquel.svg)](https://packagist.org/packages/quellabs/objectquel)

A PHP ORM with its own query language. ObjectQuel uses the Data Mapper pattern and a declarative syntax inspired by [QUEL](https://en.wikipedia.org/wiki/QUEL_query_language) to express entity queries at the domain level — not the table level.

```php
$results = $entityManager->executeQuery("
    range of p is App\\Entity\\Product
    range of c is App\\Entity\\Category via p.categories
    retrieve (p, c.name as categoryName)
    where p.price < :maxPrice and c.active = true
    sort by p.name asc
", [
    'maxPrice' => 50.00
]);
```

The engine resolves entity relationships, decomposes the query into optimized SQL, and hydrates the results. You write intent; ObjectQuel handles the mechanics.

## What the query language can do that others can't

Most ORM query languages are SQL with different syntax. ObjectQuel's abstraction layer sits above SQL, which lets it do things that aren't possible in DQL, Eloquent, or raw query builders:

**Pattern matching and regex in where clauses:**

```php
// Wildcard matching — no LIKE syntax needed
retrieve (p) where p.sku = "ABC*XYZ"

// Regex with flags
retrieve (p) where p.name = /^tech/i
```

The equivalent in Doctrine requires `$qb->expr()->like()` or a raw `REGEXP` call. In Eloquent you'd write `whereRaw('name REGEXP ?', [...])`. ObjectQuel treats patterns as first-class query expressions.

**Full-text search with boolean operators and weighting:**

```php
retrieve (p) where search(p.description, "banana +pear -apple")
```

No raw SQL, no engine-specific syntax. The query engine translates this to the appropriate full-text implementation for your database.

**Hybrid data sources — database + JSON in one query:**

```php
range of order is App\\Entity\\OrderEntity
range of product is json_source('external/product_catalog.json')
retrieve (order, product.name, product.manufacturer)
where order.productSku = product.sku and order.status = :status
sort by order.orderDate desc
```

ObjectQuel can join database entities with JSON files in a single query — the engine handles the cross-source matching. Neither Doctrine nor Eloquent can do this. You'd query the database, load the JSON separately, and merge results in PHP. ObjectQuel also supports JSONPath prefiltering to extract nested structures before the query runs, keeping memory usage low on large files.

**Existence checks as expressions:**

```php
// In the retrieve clause
retrieve (p.name, ANY(o.orderId) as hasOrders)

// In the where clause
retrieve (p) where ANY(o.orderId)
```

**Automatic query decomposition:**

Complex queries are split into optimized sub-tasks by the engine rather than sent as a single monolithic SQL statement. This means ObjectQuel can optimize execution paths that a single SQL query cannot express efficiently.

## Comparison

A multi-entity query with filtering and relationship traversal:

**ObjectQuel:**
```php
$results = $entityManager->executeQuery("
    range of o is App\\Entity\\Order
    range of c is App\\Entity\\Customer via o.customerId
    retrieve (o, c.name) where o.createdAt > :since
    sort by o.createdAt desc
    window 0 using window_size 20
");
```

**Doctrine DQL:**
```php
$results = $entityManager->createQuery(
    'SELECT o, c.name FROM App\\Entity\\Order o
     JOIN o.customer c
     WHERE o.createdAt > :since
     ORDER BY o.createdAt DESC'
)->setParameter('since', $since)
 ->setMaxResults(20)
 ->getResult();
```

**Eloquent:**
```php
$results = Order::with('customer:id,name')
    ->where('created_at', '>', $since)
    ->orderByDesc('created_at')
    ->take(20)
    ->get();
```

The difference becomes more pronounced with regex filtering, existence checks, hybrid sources, and multi-relationship traversals — operations that require raw SQL or post-processing in other ORMs.

## Installation

```bash
composer require quellabs/objectquel
```

Supports MySQL, PostgreSQL, SQLite, and SQL Server through CakePHP's database abstraction layer (used for connection handling and SQL execution only — ObjectQuel implements its own query engine, Data Mapper, and entity management).

## Quick start

```php
use Quellabs\ObjectQuel\Configuration;
use Quellabs\ObjectQuel\EntityManager;

$config = new Configuration();
$config->setEntityNamespace('App\\Entity');
$config->setEntityPath(__DIR__ . '/src/Entity');

$entityManager = new EntityManager($config, $connection);

// Standard lookups
$product = $entityManager->find(Product::class, 101);
$active  = $entityManager->findBy(Product::class, ['active' => true]);

// ObjectQuel for anything more complex
$results = $entityManager->executeQuery("
    range of p is App\\Entity\\Product
    retrieve (p) where p.name = /^Tech/i
    sort by p.createdAt desc
    window 0 using window_size 10
");
```

## ORM capabilities

ObjectQuel is a full Data Mapper ORM, not just a query language:

- **Entity mapping** — annotation-based with `@Orm\Table`, `@Orm\Column`, and relationship annotations
- **Relationships** — OneToOne, ManyToOne, OneToMany, ManyToMany (via bridge entities)
- **Unit of Work** — change tracking with persist and flush
- **Lazy loading** — configurable proxy generation with caching
- **Immutable entities** — for database views and read-only tables
- **Optimistic locking** — version-based concurrency control
- **Cascading** — configurable cascade operations across relationships
- **Lifecycle events** — pre/post persist, update, and delete via SignalHub
- **Custom repositories** — optional repository pattern with type-safe access
- **Indexing** — annotation-driven index management
- **Migrations** — database schema migrations powered by Phinx

## CLI tooling

ObjectQuel ships with Sculpt, a CLI tool for entity and schema management:

```bash
# Generate a new entity interactively
php bin/sculpt make:entity

# Reverse-engineer entities from an existing database table
php bin/sculpt make:entity-from-table

# Generate migrations from entity changes
php bin/sculpt make:migrations

# Run pending migrations
php bin/sculpt quel:migrate
```

`make:entity-from-table` is particularly useful when adopting ObjectQuel in an existing project — point it at your tables and get annotated entities without writing them by hand.

## Framework integration

ObjectQuel works standalone or with the [Canvas framework](https://canvasphp.com). The `quellabs/canvas-objectquel` package provides automatic service discovery, dependency injection, and Sculpt CLI integration within Canvas.

For other frameworks, configure the `EntityManager` directly — it has no framework dependencies.

## Documentation

Full query language reference, entity mapping guide, and architecture docs: **[objectquel.com/docs](https://objectquel.com/docs)**

## License

MIT