<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	final readonly class PrimaryKeyInfo {

		public AstRange $range;
		public string $entityName;

		/** @var non-empty-list<string> Every identifier property name, in metadata order */
		public array $primaryKeys;

		/**
		 * PrimaryKeyInfo constructor
		 * @param AstRange $range
		 * @param string $entityName
		 * @param non-empty-list<string> $primaryKeys Every identifier property name, in metadata order
		 */
		public function __construct(AstRange $range, string $entityName, array $primaryKeys) {
			$this->range = $range;
			$this->entityName = $entityName;
			$this->primaryKeys = $primaryKeys;
		}

		/**
		 * True when the entity has more than one identifier property.
		 * @return bool
		 */
		public function isComposite(): bool {
			return count($this->primaryKeys) > 1;
		}

		/**
		 * Builds a fresh `range.property` identifier for every identifier property,
		 * in metadata order — the AST shape callers need to project, sort, or filter
		 * on the complete key tuple. Each call returns new nodes (with their own
		 * cloned range) so callers can attach them to an AST without aliasing.
		 * @return list<AstIdentifier>
		 */
		public function createIdentifiers(): array {
			return array_map(
				function (string $property): AstIdentifier {
					$identifier = new AstIdentifier($this->entityName);
					$identifier->setRange(clone $this->range);
					$identifier->setNext(new AstIdentifier($property));
					return $identifier;
				},
				$this->primaryKeys
			);
		}

		/**
		 * Retrieves every identifier property of the main range from an AstRetrieve object.
		 * @param AstRetrieve $astRetrieve A reference to the AstRetrieve object representing the query
		 * @param EntityStore $entityStore
		 * @return PrimaryKeyInfo
		 * @throws EntityResolutionException|\LogicException
		 */
		public static function fromRetrieve(AstRetrieve $astRetrieve, EntityStore $entityStore): self {
			foreach ($astRetrieve->getRanges() as $range) {
				// Only accept database ranges
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}

				// Continue if the range contains a join property
				if ($range->getJoinProperty() !== null) {
					continue;
				}

				// Get the associated primary key(s) if the range doesn't have a join property
				$entityName = $range->getEntityName();
				$primaryKeys = $entityStore->getMetadata($entityName)->identifierKeys;

				// Continue if there is no primary key
				if ($primaryKeys === []) {
					continue;
				}

				// Return the range name, entity name, and every identifier property of the entity
				return new PrimaryKeyInfo($range, $entityName, $primaryKeys);
			}

			// Return null if no range without a join property is found
			// This should never happen in practice, as such a query cannot be created
			throw new \LogicException("Malformed query: no primary range found in query");
		}
	}
