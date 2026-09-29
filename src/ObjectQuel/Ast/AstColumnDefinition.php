<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A single column definition inside `create [temporary] Name (...)`: a
	 * name, an abstract type (the @Orm\Column vocabulary — see
	 * DatabaseAdapter\Mapper\TypeMapper), optional limit/precision/scale, and the
	 * minimal constraint set supported (`nullable`, `identity`). Columns are
	 * NOT NULL by default, matching @Orm\Column's `nullable` parameter — the
	 * `nullable` keyword opts a column out of that default, rather than
	 * `create`/`alter` reading like raw SQL DDL (nullable-by-default unless
	 * `not null` is written). No nested AstInterface children.
	 *
	 * Primary key is not a per-column constraint here — it's declared via a
	 * table-level `primary key (...)` clause instead (see
	 * AstCreateTable::getPrimaryKeyColumns() and
	 * objectquel-primary-key-design.md).
	 */
	class AstColumnDefinition extends Ast {

		private string $name;
		private string $type;
		private ?int $limit;
		private ?int $precision;
		private ?int $scale;
		private bool $unsigned;
		private bool $nullable;
		private bool $identity;

		/** @var string[]|null */
		private ?array $enumValues;

		/**
		 * AstColumnDefinition constructor.
		 * @param string $name Column name
		 * @param string $type Abstract column type (TypeMapper vocabulary)
		 * @param int|null $limit Optional length limit (string/char/binary)
		 * @param int|null $precision Optional precision (decimal)
		 * @param int|null $scale Optional scale (decimal)
		 * @param bool $unsigned Whether the column is unsigned
		 * @param bool $nullable Whether the column accepts NULL values (default: false, i.e. NOT NULL)
		 * @param bool $identity Whether the column auto-increments
		 * @param string[]|null $enumValues Declared values, only non-null when $type === 'enum'
		 */
		public function __construct(
			string $name,
			string $type,
			?int $limit = null,
			?int $precision = null,
			?int $scale = null,
			bool $unsigned = false,
			bool $nullable = false,
			bool $identity = false,
			?array $enumValues = null
		) {
			$this->name = $name;
			$this->type = $type;
			$this->limit = $limit;
			$this->precision = $precision;
			$this->scale = $scale;
			$this->unsigned = $unsigned;
			$this->nullable = $nullable;
			$this->identity = $identity;
			$this->enumValues = $enumValues;
		}

		/**
		 * Passes this node to the visitor.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
		}

		/**
		 * Returns the name.
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * Returns the type.
		 * @return string
		 */
		public function getType(): string {
			return $this->type;
		}

		/**
		 * Returns the limit.
		 * @return ?int
		 */
		public function getLimit(): ?int {
			return $this->limit;
		}

		/**
		 * Returns the precision.
		 * @return ?int
		 */
		public function getPrecision(): ?int {
			return $this->precision;
		}

		/**
		 * Returns the scale.
		 * @return ?int
		 */
		public function getScale(): ?int {
			return $this->scale;
		}

		/**
		 * Reports whether the column is unsigned.
		 * @return bool
		 */
		public function isUnsigned(): bool {
			return $this->unsigned;
		}

		/**
		 * Reports whether the column accepts NULL values.
		 * @return bool
		 */
		public function isNullable(): bool {
			return $this->nullable;
		}

		/**
		 * Reports whether the column is an identity column.
		 * @return bool
		 */
		public function isIdentity(): bool {
			return $this->identity;
		}

		/**
		 * @return string[]|null Declared enum values, only non-null when getType() === 'enum'
		 */
		public function getEnumValues(): ?array {
			return $this->enumValues;
		}

		/**
		 * Returns this column's type metadata shaped the way
		 * DDLTypeMapper::getTempTableColumnType() (and the new constraint
		 * renderer) expect it. 'values' is always a list (never null) here,
		 * even for non-enum columns — DDLTypeMapper only ever reads it when
		 * 'type' === 'enum', where the parser (ColumnDefinitionClause::
		 * parseEnumValues()) guarantees at least one declared value.
		 * @return array{type: string, limit: int|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]}
		 */
		public function toColumnDefinitionArray(): array {
			return [
				'type'      => $this->type,
				'limit'     => $this->limit,
				'unsigned'  => $this->unsigned,
				'precision' => $this->precision,
				'scale'     => $this->scale,
				'values'    => $this->enumValues ?? [],
			];
		}

		/**
		 * Returns a deep clone of this node.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static(
				$this->name,
				$this->type,
				$this->limit,
				$this->precision,
				$this->scale,
				$this->unsigned,
				$this->nullable,
				$this->identity,
				$this->enumValues
			);

			$clone->setParent($this->getParent());
			return $clone;
		}
	}
