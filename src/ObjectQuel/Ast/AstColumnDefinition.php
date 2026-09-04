<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A single column definition inside `create [temporary] Name (...)`: a
	 * name, an abstract type (the @Orm\Column vocabulary — see
	 * DatabaseAdapter\TypeMapper), optional limit/precision/scale, and the
	 * minimal constraint set supported (`not null`, `primary key`,
	 * `identity`). No nested AstInterface children.
	 */
	class AstColumnDefinition extends Ast {

		private string $name;
		private string $type;
		private ?int $limit;
		private ?int $precision;
		private ?int $scale;
		private bool $unsigned;
		private bool $notNull;
		private bool $primaryKey;
		private bool $identity;

		/**
		 * AstColumnDefinition constructor.
		 * @param string $name Column name
		 * @param string $type Abstract column type (TypeMapper vocabulary)
		 * @param int|null $limit Optional length limit (string/char/binary)
		 * @param int|null $precision Optional precision (decimal)
		 * @param int|null $scale Optional scale (decimal)
		 * @param bool $unsigned Whether the column is unsigned
		 * @param bool $notNull Whether the column rejects NULL values
		 * @param bool $primaryKey Whether the column is the table's primary key
		 * @param bool $identity Whether the column auto-increments
		 */
		public function __construct(
			string $name,
			string $type,
			?int $limit = null,
			?int $precision = null,
			?int $scale = null,
			bool $unsigned = false,
			bool $notNull = false,
			bool $primaryKey = false,
			bool $identity = false
		) {
			$this->name = $name;
			$this->type = $type;
			$this->limit = $limit;
			$this->precision = $precision;
			$this->scale = $scale;
			$this->unsigned = $unsigned;
			$this->notNull = $notNull;
			$this->primaryKey = $primaryKey;
			$this->identity = $identity;
		}

		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
		}

		public function getName(): string {
			return $this->name;
		}

		public function getType(): string {
			return $this->type;
		}

		public function getLimit(): ?int {
			return $this->limit;
		}

		public function getPrecision(): ?int {
			return $this->precision;
		}

		public function getScale(): ?int {
			return $this->scale;
		}

		public function isUnsigned(): bool {
			return $this->unsigned;
		}

		public function isNotNull(): bool {
			return $this->notNull;
		}

		public function isPrimaryKey(): bool {
			return $this->primaryKey;
		}

		public function isIdentity(): bool {
			return $this->identity;
		}

		/**
		 * Returns this column's type metadata shaped the way
		 * DDLTypeMapper::getTempTableColumnType() (and the new constraint
		 * renderer) expect it.
		 * @return array{type: string, limit: int|null, unsigned: bool, precision: int|null, scale: int|null}
		 */
		public function toColumnDefinitionArray(): array {
			return [
				'type'      => $this->type,
				'limit'     => $this->limit,
				'unsigned'  => $this->unsigned,
				'precision' => $this->precision,
				'scale'     => $this->scale,
			];
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static(
				$this->name,
				$this->type,
				$this->limit,
				$this->precision,
				$this->scale,
				$this->unsigned,
				$this->notNull,
				$this->primaryKey,
				$this->identity
			);

			$clone->setParent($this->getParent());
			return $clone;
		}
	}
