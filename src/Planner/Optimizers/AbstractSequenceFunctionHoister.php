<?php

	namespace Quellabs\ObjectQuel\Planner\Optimizers;

	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\IdentifierType;
	use Quellabs\ObjectQuel\Planner\Helpers\AstUtilities;

	/**
	 * Shared mechanics for optimizer passes that extract a window-shaped
	 * sequence function out of some part of the query it can't legally live in
	 * (SQL forbids a window function inside another aggregate's argument, and
	 * forbids referencing one in the same query block's WHERE) and into its own
	 * derived-table range, joined 1:1 back to the query's single range on
	 * primary key.
	 *
	 * Concrete subclasses each contribute their own detection (what triggers
	 * the rewrite) and their own extract-and-replace loop (what tree shape gets
	 * rewritten); this class owns the generic "build a helper range over a
	 * cloned single range, carry conditions, infer partition columns, join back
	 * on primary key" steps that are otherwise identical between them.
	 */
	abstract class AbstractSequenceFunctionHoister {

		protected EntityStore $entityStore;
		protected PlatformCapabilitiesInterface $platform;
		protected int $helperCounter = 0;

		/**
		 * @param EntityStore $entityStore Provides entity metadata for primary key lookups
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			$this->entityStore = $entityStore;
			$this->platform = $platform;
		}

		/**
		 * Guards the single-range shape this extraction requires, then clones the
		 * range and starts the helper query's own AstRetrieve, pre-populated with
		 * the row-identity column the outer query will join back on.
		 * @param AstRetrieve $root
		 * @param string $opLabel Describes the operation being attempted, for error messages
		 * @return array{0: AstRetrieve, 1: AstRange, 2: AstRange, 3: string} [$innerRetrieve, $clonedRange, $originalRange, $primaryKey]
		 * @throws QuelException
		 */
		protected function beginHelperRange(AstRetrieve $root, string $opLabel): array {
			$queryRanges = $root->getRanges();

			if (count($queryRanges) !== 1 || !$queryRanges[0] instanceof AstRangeDatabase) {
				throw new QuelException("{$opLabel} is only supported for single-range queries.");
			}

			/** @var AstRangeDatabase $originalRange */
			$originalRange = $queryRanges[0];
			$entityName = $originalRange->getEntityName();
			$primaryKey = $entityName !== null ? $this->entityStore->getMetadata($entityName)->getPrimaryKey() : null;

			if ($primaryKey === null) {
				throw new QuelException("Cannot extract {$opLabel}: range '{$originalRange->getName()}' has no declared primary key.");
			}

			// Clone the range the inner query runs over - it must be independent
			// of the outer query's copy.
			$clonedRange = $originalRange->deepClone();
			$innerRetrieve = new AstRetrieve([], [$clonedRange], false);

			// Row-identity column, selected so the outer query can join back to it.
			$innerRetrieve->addValue(new AstAlias(
				'_pk',
				$this->buildPropertyIdentifier($clonedRange, $primaryKey)
			));

			return [$innerRetrieve, $clonedRange, $originalRange, $primaryKey];
		}

		/**
		 * Deep-clones + relinks a condition subtree onto $clonedRange and sets it
		 * as $innerRetrieve's WHERE, so the helper's window computation runs over
		 * the same row set the outer query itself considers. No-op when $conditions
		 * is null.
		 * @param AstRetrieve $innerRetrieve
		 * @param AstInterface|null $conditions
		 * @param AstRange $originalRange
		 * @param AstRange $clonedRange
		 * @return void
		 */
		protected function carryConditions(AstRetrieve $innerRetrieve, ?AstInterface $conditions, AstRange $originalRange, AstRange $clonedRange): void {
			if ($conditions === null) {
				return;
			}

			$clonedConditions = $conditions->deepClone();
			$this->relinkIdentifiers($clonedConditions, $originalRange, $clonedRange);
			$innerRetrieve->setConditions($clonedConditions);
		}

		/**
		 * Infers PARTITION BY columns from $root's own non-aggregate SELECT items
		 * (minus its primary key), cloned and relinked to run against $clonedRange -
		 * the same partition-inference rule AggregateOptimizer already applies for
		 * a sequence function appearing directly in the SELECT list.
		 * @param AstRetrieve $root
		 * @param AstRange $originalRange
		 * @param AstRange $clonedRange
		 * @return AstAlias[]
		 */
		protected function inferOuterPartitionItems(AstRetrieve $root, AstRange $originalRange, AstRange $clonedRange): array {
			$outerPartitionItems = AstUtilities::excludePrimaryKeyItems(
				$this->entityStore,
				AstUtilities::collectNonAggregateSelectItems($root)
			);

			$innerPartitionItems = [];

			foreach ($outerPartitionItems as $partitionItem) {
				$clonedExpression = $partitionItem->getExpression()->deepClone();
				$this->relinkIdentifiers($clonedExpression, $originalRange, $clonedRange);
				$innerPartitionItems[] = new AstAlias($partitionItem->getName(), $clonedExpression);
			}

			return $innerPartitionItems;
		}

		/**
		 * Joins the helper range back to the original range on primary key, binds
		 * every pending identifier that referenced the not-yet-constructed helper
		 * range to it, appends it to $root's ranges, and marks it as a hoister
		 * helper so later passes (e.g. AggregateOptimizer::excludeHelperRanges())
		 * skip it when counting "real" ranges.
		 * @param AstRetrieve $root
		 * @param AstRetrieve $innerRetrieve
		 * @param AstRange $originalRange
		 * @param AstRange $clonedRange Unused by this base implementation; kept for subclasses that need it
		 * @param string $primaryKey
		 * @param string $helperRangeName
		 * @param AstIdentifier[] $pendingHelperIdentifiers Identifiers referencing the helper range by name only
		 * @return void
		 */
		protected function finishHelperRange(
			AstRetrieve $root,
			AstRetrieve $innerRetrieve,
			AstRange $originalRange,
			AstRange $clonedRange,
			string $primaryKey,
			string $helperRangeName,
			array $pendingHelperIdentifiers
		): void {
			$helperPkReference = $this->buildPropertyIdentifier(null, '_pk', $helperRangeName);
			$pendingHelperIdentifiers[] = $helperPkReference;

			$joinCondition = new AstExpression(
				$helperPkReference,
				$this->buildPropertyIdentifier($originalRange, $primaryKey),
				'='
			);

			$helperRange = new AstRangeDatabaseSubquery($helperRangeName, $innerRetrieve, $joinCondition, required: true);

			foreach ($pendingHelperIdentifiers as $pendingIdentifier) {
				$pendingIdentifier->setRange($helperRange);
			}

			$root->setRanges([...$root->getRanges(), $helperRange]);
			$root->addWindowChainHelperRange($helperRangeName);
		}

		/**
		 * Re-points every identifier in $expression that references $oldRange (by
		 * stored object identity) to $newRange instead - needed after deep-cloning
		 * an expression whose identifiers still point at the pre-clone range.
		 * @param AstInterface $expression
		 * @param AstRange $oldRange
		 * @param AstRange $newRange
		 * @return void
		 */
		protected function relinkIdentifiers(AstInterface $expression, AstRange $oldRange, AstRange $newRange): void {
			foreach (AstUtilities::collectIdentifiersFromAst($expression) as $identifier) {
				if ($identifier->getRange() === $oldRange) {
					$identifier->setRange($newRange);
				}
			}
		}

		/**
		 * Builds a `range.property` identifier chain. Pass $range for an
		 * entity-backed reference (range resolved directly); pass $range as null
		 * with $rangeName set for a reference into a helper range that may not exist
		 * yet at construction time - the caller is responsible for calling
		 * setRange() on the returned identifier once the range object exists.
		 * @param AstRange|null $range
		 * @param string $propertyName
		 * @param string|null $rangeName Required when $range is null
		 */
		protected function buildPropertyIdentifier(?AstRange $range, string $propertyName, ?string $rangeName = null): AstIdentifier {
			$baseName = $range?->getName() ?? $rangeName;

			if ($baseName === null) {
				throw new \LogicException('buildPropertyIdentifier() requires either $range or $rangeName.');
			}

			$baseType = $range instanceof AstRangeDatabase ? IdentifierType::EntityRoot : IdentifierType::SubqueryRoot;
			$leafType = $range instanceof AstRangeDatabase ? IdentifierType::EntityProperty : IdentifierType::SubqueryProperty;

			$base = new AstIdentifier($baseName, $baseType);
			$leaf = new AstIdentifier($propertyName, $leafType);
			$base->setNext($leaf);

			if ($range !== null) {
				$base->setRange($range);
			}

			return $base;
		}
	}
