<?php
	
	namespace Quellabs\ObjectQuel\Execution\Transformers;
	
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\PrimaryKeyInfo;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLRetrieve;
	use Quellabs\ObjectQuel\Planner\Helpers\RangeUtilities;
	
	/**
	 * Selects a page of root entities before loading their complete joined rows.
	 */
	class PaginationTransformer {
		
		private EntityStore $entityStore;
		private DatabaseAdapter $connection;
		private PlatformCapabilitiesInterface $platform;
		private ?PrimaryKeyInfo $primaryKeyInfo = null;

		/** Monotonic counter for this transform() call's generated bound-parameter names. */
		private int $parameterSequence = 0;

		/**
		 * QueryBuilder constructor
		 * @param EntityManager $entityManager
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->connection = $entityManager->getConnection();
			$this->platform = $platform;
		}
		
		/**
		 * Apply pagination logic using the provided parameters
		 * This may involve primary key fetching, result counting, and query modification
		 * Parameters are needed for pagination to work with bound values in conditions; new
		 * bound values generated for the page filter are added back into $parameters.
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		public function transform(AstRetrieve $ast, array &$parameters): void {
			$window = $ast->getWindow();
			$windowSize = $ast->getWindowSize();

			// Only database-sorted WINDOW queries need a primary-key page pass.
			if ($window === null || $windowSize === null || $ast->getSortInApplicationLogic()) {
				return;
			}

			try {
				// Resolve the root key used by both the key query and the final page filter.
				$this->primaryKeyInfo = PrimaryKeyInfo::fromRetrieve($ast, $this->entityStore);
				$this->parameterSequence = 0;

				// Select parent keys before loading their complete joined rows.
				$this->processPagination($ast, $parameters, (int)$window, (int)$windowSize);

				if ($ast->isUnique() || $ast->getDirective('InValuesAreFinal') === true) {
					return;
				}

				// Break sort ties by primary key in the final non-unique query.
				$sort = $ast->getSort();
				$sort[] = ['ast' => $this->createPrimaryKeyIdentifier(), 'order' => 'asc'];
				$ast->setSort($sort);
			} finally {
				// Release the key metadata even if the key query fails.
				$this->primaryKeyInfo = null;
			}
		}
		
		/**
		 * Pages primary keys before fetching the complete matching joined rows.
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @param int $window
		 * @param int $windowSize
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		private function processPagination(AstRetrieve $ast, array &$parameters, int $window, int $windowSize): void {
			// Validate window size
			if ($window > intdiv(PHP_INT_MAX, $windowSize)) {
				throw new QuelException('The window page and size produce an offset that is too large.');
			}

			// SQL can page keys only when the key query has one row per parent.
			// Otherwise, fetch all matching keys for PHP deduplication and slicing.
			$useSqlPage = $this->canPageKeysInSql($ast);
			$primaryKeys = $this->fetchPrimaryKeysForPagination(
				$ast,
				$parameters,
				$useSqlPage ? $window * $windowSize : null,
				$windowSize
			);

			// The fallback key query can repeat a parent for every joined child.
			if (!$useSqlPage) {
				$primaryKeys = array_values(array_unique($primaryKeys));
			}

			// Early exit if no records match the query conditions
			if (empty($primaryKeys)) {
				$this->addImpossibleCondition($ast);
				return;
			}
			
			// The SQL key query is already paged; slice only the fallback results.
			$filteredKeys = $useSqlPage ? $primaryKeys : $this->getPageSubset($primaryKeys, $window, $windowSize);
			
			// Handle edge case where pagination parameters result in no valid results
			// (e.g., requesting page 100 when there are only 50 total pages)
			if (empty($filteredKeys)) {
				// Add a condition that will never match (like "1=0") to make the query return empty results
				// This is more efficient than letting the database process the full query
				$this->addImpossibleCondition($ast);
				return;
			}
			
			// Modify the original query to include an IN condition that limits results
			// to only the primary keys we determined should be on this page
			// This ensures the final query returns exactly the records we want, in the right order
			$this->addInConditionForPagination($ast, $parameters, $filteredKeys);
		}

		/**
		 * Checks whether the key query can have exactly one row per root entity.
		 * @param AstRetrieve $ast Query after normalization and optimization
		 * @return bool
		 * @throws EntityResolutionException
		 */
		private function canPageKeysInSql(AstRetrieve $ast): bool {
			// Offset paging cannot preserve custom IN-list order or grouped results.
			if (!$this->platform->supportsOffsetPagination() ||
				$ast->getDirective('InValuesAreFinal') === true ||
				$ast->getHaving() !== null ||
				$ast->getGroupBy() !== []) {
				return false;
			}

			// The key pass needs primary-key metadata for the root entity.
			$primaryKeyInfo = $this->primaryKeyInfo;
			
			if ($primaryKeyInfo === null) {
				return false;
			}

			// Replacing the range list is safe only for a plain database root.
			$root = $primaryKeyInfo->range;
			
			if ($root::class !== AstRangeDatabase::class) {
				return false;
			}

			// Required or custom joins can change which parents appear in the page.
			foreach ($ast->getRanges() as $range) {
				if ($range === $root) {
					continue;
				}

				if (get_class($range) !== AstRangeDatabase::class ||
					$range->getJoinProperty() === null ||
					$range->isRequired()) {
					return false;
				}
			}

			// Computed values may depend on joins removed from the key pass.
			foreach ($ast->getValues() as $value) {
				if (!$value->getExpression() instanceof AstIdentifier) {
					return false;
				}
			}

			// A predicate on a joined range changes which parent keys match.
			if ($ast->getConditions() !== null) {
				foreach (RangeUtilities::collectRangesFromNode($ast->getConditions()) as $range) {
					if ($range->getName() !== $root->getName()) {
						return false;
					}
				}
			}

			// Only mapped root columns retain their order without the joins.
			$metadata = $this->entityStore->getMetadata($primaryKeyInfo->entityName);

			foreach ($ast->getSort() as $sortItem) {
				$identifier = $sortItem['ast'];

				if (!$identifier instanceof AstIdentifier ||
					$identifier->getRange()?->getName() !== $root->getName() ||
					$identifier->getNext() === null ||
					$identifier->getNext()->getNext() !== null ||
					$metadata->getColumnName($identifier->getNext()->getName()) === null) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Fetches a bounded root-key page when safe, otherwise all sorted joined keys.
		 * The fallback is deduplicated afterward so joined-column sorting works.
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @param int|null $offset SQL row offset when the key query has one row per parent
		 * @param int $windowSize Requested parent count
		 * @return list<int|string>
		 */
		private function fetchPrimaryKeysForPagination(AstRetrieve $ast, array $parameters, ?int $offset, int $windowSize): array {
			// Store original state
			$originalValues = $ast->getValues();
			$originalUnique = $ast->isUnique();
			$originalSort = $ast->getSort();
			$originalRanges = $ast->getRanges();

			try {
				// Modify query to get only primary keys
				$astIdentifier = $this->createPrimaryKeyIdentifier();
				$ast->setValues([new AstAlias("pk", $astIdentifier)]);
				$ast->setUnique(false);

				if ($offset !== null) {
					if ($this->primaryKeyInfo === null) {
						throw new \LogicException('Pagination primary key information is missing.');
					}

					$ast->setRanges([$this->primaryKeyInfo->range]);
				}

				if ($ast->getDirective('InValuesAreFinal') !== true) {
					$sort = $originalSort;
					$sort[] = ['ast' => $this->createPrimaryKeyIdentifier(), 'order' => 'asc'];
					$ast->setSort($sort);
				}

				// Execute modified query
				$sql = $this->convertToSQL($ast, $parameters);

				if ($offset !== null) {
					if ($this->platform->getDatabaseType() === 'sqlsrv') {
						$sql .= " OFFSET {$offset} ROWS FETCH NEXT {$windowSize} ROWS ONLY";
					} else {
						$sql .= " LIMIT {$windowSize} OFFSET {$offset}";
					}
				}

				// Execute the query and return the first column
				$result = $this->connection->execute($sql, $parameters);

				if ($result === null) {
					return [];
				}

				return array_column($result->fetchAll(), 0);
			} finally {
				// Always restore original state
				$ast->setValues($originalValues);
				$ast->setUnique($originalUnique);
				$ast->setSort($originalSort);
				$ast->setRanges($originalRanges);
			}
		}
		
		/**
		 * Gets the subset of primary keys for the current page.
		 * @param list<int|string> $primaryKeys
		 * @param int $window
		 * @param int $windowSize
		 * @return list<int|string>
		 */
		private function getPageSubset(array $primaryKeys, int $window, int $windowSize): array {
			return array_slice($primaryKeys, $window * $windowSize, $windowSize);
		}
		
		/**
		 * Convert AstRetrieve node to SQL
		 * @param AstRetrieve $retrieve The AST to convert
		 * @param array<string, mixed> $parameters Query parameters (passed by reference)
		 * @return string The generated SQL query
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function convertToSQL(AstRetrieve $retrieve, array $parameters): string {
			// Convert all keys to strings
			$stringKeyedParameters = [];
			foreach ($parameters as $key => $value) {
				$stringKeyedParameters[(string)$key] = $value;
			}
			
			// Transform the Quel query to SQL
			$quelToSQL = new QuelToSQLRetrieve(
				$this->entityStore,
				$stringKeyedParameters,
				$this->platform,
				$this->connection->getRoutineSchema()
			);
			
			return $quelToSQL->convertToSQL($retrieve);
		}
		
		/**
		 * Factory method to create primary key identifiers.
		 * @return AstIdentifier
		 */
		private function createPrimaryKeyIdentifier(): AstIdentifier {
			assert($this->primaryKeyInfo !== null);
			$astIdentifier = new AstIdentifier($this->primaryKeyInfo->entityName);
			$astIdentifier->setRange(clone $this->primaryKeyInfo->range);
			$astIdentifier->setNext(new AstIdentifier($this->primaryKeyInfo->primaryKey));
			return $astIdentifier;
		}
		
		/**
		 * Returns a bound-parameter name unused by the caller.
		 * @param array<string, mixed> $parameters Existing bound parameters
		 * @return string
		 */
		private function nextParameterName(array $parameters): string {
			do {
				$name = '__pagination_pk_' . $this->parameterSequence++;
			} while (array_key_exists($name, $parameters));
			
			return $name;
		}

		/**
		 * Adds IN condition for pagination filtering. Binds each page value as a named
		 * parameter instead of embedding it as a literal AstNumber node — a non-numeric
		 * primary key (e.g. a string key) is no longer silently miscompiled as a number.
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @param list<int|string> $filteredKeys
		 * @return void
		 */
		private function addInConditionForPagination(AstRetrieve $ast, array &$parameters, array $filteredKeys): void {
			// Create the primary key identifier
			$astIdentifier = $this->createPrimaryKeyIdentifier();

			// Bind each page value as a named parameter rather than guessing its AST literal type
			$astValues = [];

			foreach ($filteredKeys as $item) {
				$paramName = $this->nextParameterName($parameters);
				$parameters[$paramName] = $item;
				$astValues[] = new AstParameter($paramName);
			}

			// Keep the original predicate intact, including any OR branch containing an IN.
			$astIn = new AstIn($astIdentifier, $astValues);
			
			if ($ast->getConditions() === null) {
				$ast->setConditions($astIn);
			} else {
				$ast->setConditions(new AstBinaryOperator($ast->getConditions(), $astIn, "AND"));
			}
		}
		
		/**
		 * Adds an impossible condition (1 = 0) for empty result sets.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function addImpossibleCondition(AstRetrieve $ast): void {
			$condition = new AstBinaryOperator(new AstNumber("1"), new AstNumber("0"), "=");
			
			if ($ast->getConditions() === null) {
				$ast->setConditions($condition);
			} else {
				$ast->setConditions(new AstBinaryOperator($ast->getConditions(), $condition, "AND"));
			}
		}
	}