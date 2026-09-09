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
	 * Converts a bound `:param`'s PHP value to its database storage
	 * representation, for a write verb (`append`, `replace`, `delete`) that
	 * compiles straight to SQL instead of going through persist().
	 *
	 * Example: `replace u (createdAt = :t) where u.id = :id`, bound with
	 * `['t' => new \DateTime(...), 'id' => 5]`. Without this class, the raw
	 * \DateTime object would be handed to the database driver as-is and the
	 * query would fail — the driver has no idea `createdAt` is a datetime
	 * column. This class looks up the target column's @Orm\Column
	 * annotation and runs the bound value through
	 * Serializer::denormalizeValue() — the exact conversion persist() already
	 * applies to every entity property via InsertPersister/UpdatePersister —
	 * mutating the parameter array in place so the SQL executes with a value
	 * the database can actually store.
	 *
	 * One instance is created per statement, and can be fed values two ways
	 * (a statement's parameters show up in two different shapes):
	 *  - normalizeAssignments($assignments) — a flat `property = :param`
	 *    list: append's row(s), replace's SET clause, upsert's explicit
	 *    `or replace (...)` SET clause.
	 *  - As an AstVisitorInterface: pass the instance to a WHERE clause's
	 *    accept() (`$conditions->accept($normalizer)`) to walk every
	 *    comparison in it and convert a parameter compared against a real
	 *    column (e.g. `p.deletedAt > :since`) — used by `delete` and
	 *    `replace`'s WHERE clause.
	 *
	 * Both entry points share the same internal state, so calling both on
	 * one instance (e.g. replace's SET clause and its WHERE clause) is safe
	 * even if the same parameter name appears in both — it's only converted
	 * once. That matters because some conversions aren't safely repeatable:
	 * running denormalizeValue() a second time on an already-converted value
	 * can produce garbage (e.g. the datetime converter expects a \DateTime
	 * object and returns null when handed the string it already produced).
	 *
	 * Skipped entirely: a QUEL literal written directly in the statement
	 * (true, 'foo', 123) — already valid SQL as parsed, no PHP value behind
	 * it to convert. Plain-table ranges (no entity, no @Orm\Column
	 * annotations) are also skipped — callers simply never construct this
	 * class for that case.
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
		 * When $identifierSide is a direct property reference (e.g.
		 * `p.deletedAt`) and $paramSide is a bare parameter, normalize that
		 * parameter's bound value. A chain continuing past the property (e.g.
		 * `p.metadata.status`, a JSON path) is skipped: the comparison targets
		 * a JSON_EXTRACT'd scalar, not the column's own value.
		 * @param AstInterface $identifierSide
		 * @param AstInterface $paramSide
		 * @return void
		 */
		private function normalizeIfComparison(AstInterface $identifierSide, AstInterface $paramSide): void {
			if (!$identifierSide instanceof AstIdentifier || !$identifierSide->hasNext()) {
				return;
			}

			$propertyNode = $identifierSide->getNext();

			if ($propertyNode === null || $propertyNode->hasNext()) {
				return;
			}

			$this->normalizeIfParameter($propertyNode->getName(), $paramSide);
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
