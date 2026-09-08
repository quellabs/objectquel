<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\Serialization\Serializers\Serializer;

	/**
	 * Normalizes bound-parameter (`:param`) values that end up written to, or
	 * compared against, an entity column — the single place every write verb
	 * (`append`, `replace`, `delete`) goes through Serializer::denormalizeValue()
	 * instead of each one growing its own copy of the same "resolve the
	 * property's Column annotation, denormalize its bound value" logic.
	 * Mirrors what InsertPersister/UpdatePersister already do when persisting
	 * a whole entity, applied to the QUEL write verbs' raw-SQL statements,
	 * which bypass UnitOfWork/persist() entirely and so never got that
	 * treatment on their own.
	 *
	 * One instance is scoped to a single write-verb statement (constructed
	 * with that statement's target entity metadata and bound-parameter
	 * array), and covers both halves of it:
	 *  - normalizeAssignments() — a flat `property = :param` list (append's
	 *    literal-values rows, replace's SET clause, upsert's explicit
	 *    `or replace (...)` on-conflict SET clause).
	 *  - As an AstVisitorInterface, passed to a WHERE clause's accept() —
	 *    walks every comparison in the tree and normalizes a bound parameter
	 *    compared against a real entity column (`delete`/`replace`'s WHERE;
	 *    `append`/upsert have no WHERE clause with a live runtime comparison
	 *    to normalize — see each caller's own docblock).
	 *
	 * Sharing one instance (and so one dedup set) across both halves of a
	 * single statement means a parameter reused between an assignment and a
	 * condition (or across multiple rows/conditions) is still normalized at
	 * most once — several normalizers (e.g. DatetimeNormalizer) aren't
	 * idempotent and would corrupt an already-denormalized value on a second
	 * pass.
	 *
	 * Only ever touches genuine AstParameter bindings — a QUEL literal
	 * written directly in the statement (true, 'foo', 123) is already
	 * SQL-ready as parsed and carries no PHP value to normalize. Plain-table
	 * ranges (no EntityMetadataRecord) have no Column annotations to
	 * normalize against, so callers simply never construct this for that
	 * case — same convention AssignmentValidator's checks follow.
	 */
	class WriteVerbParameterNormalizer implements AstVisitorInterface {

		/**
		 * Comparison operators eligible for WHERE-clause normalization.
		 * Arithmetic operators are excluded — `p.count = :x + 1` binds `:x`
		 * against no single column, so there's nothing to denormalize it
		 * against; the database receives it as written.
		 */
		private const array COMPARISON_OPERATORS = ['=', '<>', '>', '>=', '<', '<='];

		private EntityMetadataRecord $metadata;
		private Serializer $serializer;

		/** @var array<string, mixed> Reference to the statement's bound parameters */
		private array $parameters;

		/** @var array<string, true> Parameter names already normalized this statement */
		private array $normalizedParamNames = [];

		/**
		 * @param EntityMetadataRecord $metadata The write verb's single target entity
		 * @param Serializer $serializer
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 */
		public function __construct(EntityMetadataRecord $metadata, Serializer $serializer, array &$parameters) {
			$this->metadata = $metadata;
			$this->serializer = $serializer;
			$this->parameters = &$parameters;
		}

		/**
		 * Normalizes a flat assignment list's bound-parameter values in place.
		 * @param AstAssignment[] $assignments One row's (or replace's/upsert's
		 *        whole) assignment list
		 * @return void
		 */
		public function normalizeAssignments(array $assignments): void {
			foreach ($assignments as $assignment) {
				$this->normalizeIfParameter($assignment->getProperty(), $assignment->getValue());
			}
		}

		/**
		 * AstVisitorInterface entry point — call via `$conditions->accept($this)`
		 * to normalize every bound parameter compared against a real entity
		 * column in a WHERE clause.
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof NodeBinary || !in_array($node->getOperator(), self::COMPARISON_OPERATORS, true)) {
				return;
			}

			$this->normalizeIfComparison($node->getLeft(), $node->getRight());
			$this->normalizeIfComparison($node->getRight(), $node->getLeft());
		}

		/**
		 * When $identifierSide is a property reference on this normalizer's
		 * target entity and $paramSide is a bare parameter, normalize that
		 * parameter's bound value.
		 * @param AstInterface $identifierSide
		 * @param AstInterface $paramSide
		 * @return void
		 */
		private function normalizeIfComparison(AstInterface $identifierSide, AstInterface $paramSide): void {
			if (!$identifierSide instanceof AstIdentifier || !$identifierSide->hasNext()) {
				return;
			}

			$property = $identifierSide->getNext()?->getName();

			if ($property === null) {
				return;
			}

			$this->normalizeIfParameter($property, $paramSide);
		}

		/**
		 * Denormalizes $value's bound parameter in place when it is a bare
		 * AstParameter and $property maps to a real Column annotation — a
		 * no-op for anything else (a QUEL literal, an unmapped property, a
		 * parameter already normalized this statement, or one with no
		 * matching entry in $parameters).
		 * @param string $property
		 * @param AstInterface $value
		 * @return void
		 */
		private function normalizeIfParameter(string $property, AstInterface $value): void {
			if (!$value instanceof AstParameter) {
				return;
			}

			$paramName = $value->getName();

			if (isset($this->normalizedParamNames[$paramName]) || !array_key_exists($paramName, $this->parameters)) {
				return;
			}

			$annotation = $this->metadata->getColumnAnnotation($property);

			if ($annotation === null) {
				return;
			}

			$this->parameters[$paramName] = $this->serializer->denormalizeValue($annotation, $this->parameters[$paramName]);
			$this->normalizedParamNames[$paramName] = true;
		}
	}
