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

A `tfunction`, taking a mapped entity as a row parameter, binds to a table's writes instead of being called directly:

```php
$entityManager->executeQuery('
    range of u is App\Entity\User
    define tfunction audit_user (App\Entity\User old, App\Entity\User new) {
        append to a (userId = new.id, previousName = old.username, currentName = new.username)
    }
');
$entityManager->executeQuery('
    range of u is App\Entity\User
    after replace u call audit_user as audit_trigger
');
```

Bind with `after append to|replace|delete <range> call <function> [as <alias>]`; unbind with `destroy trigger <range> <alias> [if exists]`. The routine is named only — its own entity-row parameters receive the event's rows by declaration order, so `replace` binds `old` and `new` in that order and `append to`/`delete` bind the single row they supply. Omit the alias and one is generated — `quel:list-triggers` shows it, along with every other live binding's table, event and routine. `destroy function` refuses while a binding still calls it, and `alter` refuses a column-shape or primary-key change on a table with a live binding — unbind first, then redefine and rebind as needed. See the [EQUEL guide](https://objectquel.com/docs?section=language-equel) for row-parameter typing, event mapping, and per-engine safety notes.

## Composite primary keys

An entity may declare more than one `@Orm\Column(primary_key=true)` property. Look it up with an associative array keyed by property name — not database column names or positional order:

```php
$order = $entityManager->find(Order::class, ['tenantId' => 7, 'number' => 'A-42']);
$order = $repository->find(['tenantId' => 7, 'number' => 'A-42']);
```

`find()`/`findOrFail()` still accept a plain scalar for a single-key entity. For a composite key, the array must name every identifier property exactly once; array order does not matter.

Relations to a composite-key target use ordered `localColumns`/`referencedColumns` instead of the singular `localColumn`/`referencedColumn`. Component *i* of `localColumns` pairs with component *i* of `referencedColumns`; both name `@Column`-backed PHP properties, not database columns:

```php
/** @Orm\ManyToOne(
 *     targetEntity=Order::class,
 *     localColumns={"orderTenantId", "orderNumber"},
 *     referencedColumns={"tenantId", "number"},
 *     fetch="LAZY"
 * ) */
public ?Order $order = null;
```

The singular `localColumn`/`referencedColumn` form remains fully supported for single-column relations and must not be combined with the plural form on the same declaration.

A real database-level foreign-key constraint is declared with `@Orm\ForeignKey`/`@Orm\ForeignKeyAction`. `@Orm\ForeignKey` takes a `columns` map (local property => target property) in place of the legacy `referencedColumn`:

- Property level: `columns` holds exactly one entry whose key equals the declaring property's own name.
- Class level, for a constraint spanning more than one column: `columns` holds one entry per participating column, in constraint order.

```php
/** @Orm\ForeignKey(target=UserEntity::class, columns={"userId"="id"}) */
public int $userId;

/** @Orm\ForeignKey(
 *     target=Order::class,
 *     columns={"orderTenantId"="tenantId", "orderNumber"="number"}
 * )
 * @Orm\ForeignKeyAction(
 *     localColumns={"orderTenantId", "orderNumber"},
 *     onDelete="CASCADE",
 *     onUpdate="NO ACTION"
 * ) */
class OrderLine { /* ... */ }
```

`@Orm\ForeignKeyAction` keeps its `onDelete`/`onUpdate` parameters unchanged. At property level it is matched to its `@ForeignKey` by colocation on the same property, with no `columns` of its own. At class level, where more than one constraint may be declared, it takes an additional `localColumns` parameter — an ordered list of local property names (not a map) — used only to pick which `@ForeignKey` it applies to.

The legacy property-level `referencedColumn` attribute (without `columns`) continues to work unchanged as single-column syntax, and must not be combined with `columns` on the same declaration.

`create`/`alter` DDL declares the same constraint directly, without an entity, using `foreign key (col {, col}) references Table [(col {, col})] [on delete action] [on update action]`. The local and referenced column lists pair positionally — component *i* of one with component *i* of the other — and must be the same length when both are given:

```
create order_lines (
    order_tenant_id = integer,
    order_number = string(20),
    foreign key (order_tenant_id, order_number) references orders (tenant_id, number) on delete cascade
)
```

Omitting the referenced column list — `references orders`, with no parentheses — resolves it to the target table's complete primary key, in that key's own column order.

A composite primary key is always assigned (or filled by an explicit application-side generator per component); an omitted `@Orm\PrimaryKeyStrategy` on a composite key means assigned, and an explicit database `identity` strategy on a composite key is rejected with a metadata error.

Query syntax for a composite foreign key join is unchanged — `via` traverses the relation using all of its column pairs:

```
range of ol is App\Entity\OrderLine
range of o is App\Entity\Order via ol.order
retrieve (ol, o.number)
```

JSON:API serialization keeps a single-key entity's existing plain resource id. A composite key is instead encoded as `c1.` followed by the unpadded base64url of a UTF-8 JSON array of its ordered `[propertyName, value]` pairs, so numeric and string components stay distinct. This replaces the previous underscore-joined format, which could not reconstruct component boundaries and collapsed every component to a string.

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
