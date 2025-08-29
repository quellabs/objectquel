<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	/**
	 * This enum-like class replaces multiple boolean checks with clear strategy types,
	 * providing a clean way to determine how queries should be optimized and executed.
	 * Each strategy represents a different approach to handling query conditions and joins.
	 */
	class OptimizationStrategy {
		
		/** @var string Strategy for conditions that always evaluate to true */
		public const string CONSTANT_TRUE = 'constant_true';
		
		/** @var string Strategy for simple existence checks without complex joins */
		public const string SIMPLE_EXISTS = 'simple_exists';
		
		/** @var string Strategy for null value checks */
		public const string NULL_CHECK = 'null_check';
		
		/** @var string Strategy that can utilize database joins for optimization */
		public const string JOIN_BASED = 'join_based';
		
		/** @var string Strategy that requires a separate subquery */
		public const string SUBQUERY = 'subquery';
		
		/** @var string The current strategy type */
		private string $type;
		
		/** @var array Additional metadata and context for the strategy */
		private array $metadata;
		
		/**
		 * Creates a new optimization strategy instance.
		 * @param string $type The strategy type (should be one of the class constants)
		 * @param array $metadata Optional metadata to store additional context
		 */
		public function __construct(string $type, array $metadata = []) {
			$this->type = $type;
			$this->metadata = $metadata;
		}
		
		/**
		 * Gets the strategy type.
		 * @return string The current strategy type
		 */
		public function getType(): string {
			return $this->type;
		}
		
		/**
		 * Gets the strategy metadata.
		 * @return array The metadata array containing additional context
		 */
		public function getMetadata(): array {
			return $this->metadata;
		}
		
		/**
		 * Checks if this strategy represents a constant true condition.
		 * @return bool True if the strategy is CONSTANT_TRUE
		 */
		public function isConstantTrue(): bool {
			return $this->type === self::CONSTANT_TRUE;
		}
		
		/**
		 * Determines if this strategy can be executed within the main query.
		 * @return bool True if the strategy can be handled in the main query
		 */
		public function canUseMainQuery(): bool {
			return in_array($this->type, [self::CONSTANT_TRUE, self::JOIN_BASED, self::NULL_CHECK]);
		}
		
		/**
		 * Determines if this strategy requires a separate subquery.
		 * @return bool True if the strategy must be executed as a subquery
		 */
		public function requiresSubquery(): bool {
			return $this->type === self::SUBQUERY;
		}

		// ==============================================================
		// Factory methods for creating specific strategies
		// These provide a clean, fluent interface for strategy creation
		// ==============================================================
		
		/**
		 * Creates a constant true strategy. Used when a condition always evaluates
		 * to true, often due to optimization or simplification of the original query logic.
		 * @param string $reason Optional reason why this became a constant true
		 * @return self A new OptimizationStrategy instance
		 */
		public static function constantTrue(string $reason = ''): self {
			return new self(self::CONSTANT_TRUE, ['reason' => $reason]);
		}
		
		/**
		 * Creates a simple exists strategy. Used for straightforward existence
		 * checks that don't require complex joins or subqueries.
		 * @return self A new OptimizationStrategy instance
		 */
		public static function simpleExists(): self {
			return new self(self::SIMPLE_EXISTS);
		}
		
		/**
		 * Creates a null check strategy. Used when the optimization involves
		 * checking for null values, which can often be done efficiently in the main query.
		 * @return self A new OptimizationStrategy instance
		 */
		public static function nullCheck(): self {
			return new self(self::NULL_CHECK);
		}
		
		/**
		 * Creates a join-based strategy. Used when the optimization can leverage
		 * database joins to efficiently execute the query condition.
		 * @return self A new OptimizationStrategy instance
		 */
		public static function joinBased(): self {
			return new self(self::JOIN_BASED);
		}
		
		/**
		 * Creates a subquery strategy. Used when the condition is complex
		 * enough that it requires a separate subquery for proper execution.
		 * @return self A new OptimizationStrategy instance
		 */
		public static function subquery(): self {
			return new self(self::SUBQUERY);
		}
	}