<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\IdentifierType;
	
	/**
	 * Injects a soft-delete filter condition into an AstRetrieve for every
	 * database range whose entity carries an @SoftDelete annotation.
	 *
	 * The injected condition depends on the annotated column's type:
	 *   - 'datetime'  →  range.property IS NULL              (null means the record is active)
	 *   - 'boolean'   →  COALESCE(range.property, false) = false  (false or absent means active)
	 *
	 * For a joined range the condition is ANDed onto that range's own JOIN (ON
	 * clause) instead of the WHERE clause, so a soft-deleted related row is
	 * treated like an absent one rather than dropping the parent row. Only the
	 * primary (FROM) range has no ON clause, so its condition goes into WHERE.
	 *
	 * Injection is skipped entirely when the query carries the
	 * @ignoreSoftDelete true compiler directive, which is set automatically by
	 * QueryBuilder::prepareQuery() for primary-key lookups (find()) so that
	 * soft-deleted entities can still be loaded by identity.
	 *
	 * This transformer is called directly from QueryNormalizer::transform()
	 * after all identifier types have been resolved, ensuring that the injected
	 * identifiers are fully typed when they enter the SQL generation phase.
	 */
	class InjectSoftDeleteCondition {
		
		/** @var EntityStore */
		private EntityStore $entityStore;
		
		/**
		 * InjectSoftDeleteCondition constructor.
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Walks every database range and ANDs a soft-delete filter onto its JOIN
		 * condition (or WHERE, for the primary range) — see class docblock.
		 * @param AstRetrieve $ast
		 * @return void
		 * @throws EntityResolutionException
		 */
		public function inject(AstRetrieve $ast): void {
			// The @ignoreSoftDelete directive opts the entire query out of filtering.
			// QueryBuilder sets this for find() so PK lookups always return the entity
			// regardless of its soft-delete state.
			if ($ast->getDirective('ignoreSoftDelete')) {
				return;
			}

			foreach ($ast->getRanges() as $range) {
				// Only concrete entity ranges have EntityStore metadata to inspect
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}

				$metadata = $this->entityStore->getMetadata($range->getEntityName());

				if (!$metadata->hasSoftDelete()) {
					continue;
				}

				$condition = $this->buildCondition($range, $metadata);

				if ($condition === null) {
					continue;
				}

				// Joined range: AND onto its own ON-clause condition (see class docblock).
				$joinProperty = $range->getJoinProperty();

				if ($joinProperty !== null) {
					$range->setJoinProperty(new AstBinaryOperator($joinProperty, $condition, 'AND'));
					continue;
				}

				// Primary range: AND onto existing WHERE, existing conditions as left
				// operand so the filter can't be short-circuited by an inner OR.
				$existing = $ast->getConditions();

				if ($existing !== null) {
					$combined = new AstBinaryOperator($existing, $condition, 'AND');
					$ast->setConditions($combined);
				} else {
					$ast->setConditions($condition);
				}
			}
		}
		
		/**
		 * Builds the filter expression for a single soft-delete column.
		 *
		 * Returns null when the column type is unrecognised so that injection
		 * is silently skipped rather than emitting a broken condition. A warning
		 * could be added here if a stricter failure mode is ever preferred.
		 *
		 * @param AstRangeDatabase $range The range that owns the soft-delete column
		 * @param EntityMetadataRecord $metadata Metadata for the range's entity
		 * @return AstInterface|null
		 */
		private function buildCondition(AstRangeDatabase $range, EntityMetadataRecord $metadata): ?AstInterface {
			// softDeleteProperty is non-null here: buildCondition is only called
			// from inject() after hasSoftDelete() confirmed the property exists.
			// The assertion satisfies PHPStan without a runtime cost.
			$softDeleteProperty = $metadata->softDeleteProperty ?? throw new \LogicException('buildCondition called on entity without @SoftDelete');

			// Build the two-node identifier chain: range.softDeleteProperty
			// The root node carries EntityRoot type (the range alias)
			$root = new AstIdentifier($range->getName(), IdentifierType::EntityRoot);
			$root->setRange($range);

			// The leaf carries EntityProperty type (the column on that entity)
			$leaf = new AstIdentifier($softDeleteProperty, IdentifierType::EntityProperty);
			$root->setNext($leaf);
			$leaf->setParent($root);

			return match ($metadata->softDeleteColumnType) {
				// NULL means active (not yet soft-deleted); any timestamp means deleted
				'datetime' => new AstCheckNull($root),

				// false means active; true means deleted. COALESCE treats a LEFT JOIN's
				// NULL as active too, avoiding SQL's three-valued NULL logic on `= false`.
				'boolean'  => new AstExpression(new AstIfNull($root, new AstBool(false)), new AstBool(false), '='),

				// Unknown column type — skip rather than silently emitting a broken query
				default    => null,
			};
		}
	}