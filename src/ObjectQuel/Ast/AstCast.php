<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Represents a C-style type cast expression in the AST.
	 *
	 * In ObjectQuel, a cast has the form:
	 *
	 *   (int)x.id        → CAST(x.id AS SIGNED)   on MySQL/MariaDB
	 *   (float)x.price   → CAST(x.price AS DOUBLE) on MySQL/MariaDB
	 *   (string)x.code   → CAST(x.code AS CHAR)    on MySQL/MariaDB
	 *   (datetime)x.ts   → no SQL CAST; hydrator converts raw value to \DateTime
	 *
	 * Most cast types map directly to a SQL CAST() expression. PHP-only cast types
	 * (currently just 'datetime') are handled entirely in the hydration layer —
	 * the SQL generator emits the inner expression unchanged, and the hydrator
	 * performs the conversion. Use isPhpOnlyCast() to distinguish between the two.
	 *
	 * The exact SQL type token emitted depends on the connected engine and is
	 * resolved by PlatformCapabilitiesInterface::getSupportedCastTypes() at
	 * SQL-generation time. The cast type stored here is always the canonical
	 * QUEL keyword (e.g. 'int', 'float', 'string', 'decimal'), never the
	 * engine-specific SQL token.
	 *
	 * Only properties may be cast; bare entity references (e.g. (int)x where x
	 * is a range) are rejected by the semantic analyser.
	 */
	class AstCast extends Ast {
		
		/**
		 * Cast types that are handled entirely in PHP rather than emitting a SQL
		 * CAST() expression. The SQL generator passes the inner expression through
		 * unchanged; the hydrator performs the conversion.
		 * @var string[]
		 */
		private const array PHP_ONLY_CAST_TYPES = ['datetime'];

		/**
		 * The canonical QUEL cast type keyword ('int', 'float', 'string', 'decimal', …).
		 * @var string
		 */
		private string $castType;
		
		/**
		 * The property expression being cast. Must be an AstIdentifier that
		 * resolves to a column (not a bare entity reference).
		 * @var AstInterface
		 */
		private AstInterface $expression;
		
		/**
		 * @param string $castType  Canonical QUEL type keyword (e.g. 'int', 'float')
		 * @param AstInterface $expression The property expression being cast
		 */
		public function __construct(string $castType, AstInterface $expression) {
			$this->castType = $castType;
			$this->expression = $expression;
			
			$expression->setParent($this);
		}
		
		/**
		 * Accepts a visitor. Visits this node first, then recurses into the operand
		 * so visitors that walk the full tree (e.g. range collectors, validators)
		 * can reach the inner property expression.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->expression->accept($visitor);
		}
		
		/**
		 * Deep-clones this node and its inner expression.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->castType, $this->expression->deepClone());
		}
		
		/**
		 * Returns the canonical QUEL cast type keyword (e.g. 'int', 'float').
		 * @return string
		 */
		public function getCastType(): string {
			return $this->castType;
		}
		
		/**
		 * Returns true when this cast is handled entirely in PHP and produces no
		 * SQL CAST() expression. The SQL generator emits the inner expression as-is;
		 * the hydrator performs the actual type conversion.
		 * @return bool
		 */
		public function isPhpOnlyCast(): bool {
			return in_array($this->castType, self::PHP_ONLY_CAST_TYPES, true);
		}
		
		/**
		 * Returns the PHP type this cast produces, so that ResolveType can
		 * propagate the correct type through expressions containing a cast node.
		 * Maps canonical QUEL cast keywords to the type strings used by ResolveType.
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return match ($this->castType) {
				'int', 'decimal' => 'int',
				'float'          => 'float',
				'string'         => 'string',
				'bool'           => 'bool',
				'datetime'       => 'datetime',
				default          => null,
			};
		}
		
		/**
		 * Returns the property expression being cast.
		 * @return AstInterface
		 */
		public function getExpression(): AstInterface {
			return $this->expression;
		}
	}