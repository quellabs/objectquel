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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\PrimaryKeyInfo;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLRetrieve;
	use Quellabs\ObjectQuel\Execution\Visitors\DetectPrimaryKeyInClause;
	use Quellabs\ObjectQuel\Execution\Visitors\DetectPrimaryKeyInClauseException;

	class PaginationTransformer {

		private EntityStore $entityStore;
		private DatabaseAdapter $connection;
		private PlatformCapabilitiesInterface $platform;

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

			if ($window !== null && $windowSize !== null && !$ast->getSortInApplicationLogic()) {
				$primaryKeyInfo = PrimaryKeyInfo::fromRetrieve($ast, $this->entityStore);
				$this->parameterSequence = 0;
				$this->processPagination($ast, $parameters, $primaryKeyInfo, (int)$window, (int)$windowSize);
			}
		}

		// ========== PAGINATION METHODS ==========

		/**
		 * Processes pagination for the query.
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @param int $window
		 * @param int $windowSize
		 * @return void
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		private function processPagination(AstRetrieve $ast, array &$parameters, PrimaryKeyInfo $primaryKeyInfo, int $window, int $windowSize): void {
			// Look for the 'InValuesAreFinal' directive which indicates that any IN conditions
			// in the query are already finalized and don't need additional validation/processing.
			// A composite key can't be expressed as a single-column IN() literal list at all,
			// so it always takes the full validated tuple path regardless of this directive.
			$skipValidation = !$primaryKeyInfo->isComposite()
				&& $ast->getDirective('InValuesAreFinal') === true;

			// Choose the appropriate pagination strategy based on the directive
			if ($skipValidation) {
				// Fast path: Skip the validation step and process pagination directly
				// Used when we know the IN conditions are already properly constructed
				$this->processPaginationSkippingValidation($ast, $parameters, $primaryKeyInfo, $window, $windowSize);
			} else {
				// Standard path: Use the full validation approach which fetches all primary keys first
				// This is the safer, more comprehensive method for most queries
				$this->processPaginationWithValidation($ast, $parameters, $primaryKeyInfo, $window, $windowSize);
			}
		}

		/**
		 * Processes pagination by directly manipulating existing IN() values. Only ever
		 * reached for a single-column primary key — see processPagination().
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @param int $window
		 * @param int $windowSize
		 * @return void
		 */
		private function processPaginationSkippingValidation(
			AstRetrieve $ast,
			array &$parameters,
			PrimaryKeyInfo $primaryKeyInfo,
			int $window,
			int $windowSize
		): void {
			try {
				// Fetch IN() statement
				$astIdentifier = $this->createPrimaryKeyIdentifiers($primaryKeyInfo)[0];
				$visitor = new DetectPrimaryKeyInClause($astIdentifier);
				$ast->getConditions()?->accept($visitor);

				// If no exception, fall back to validation method
				$this->processPaginationWithValidation($ast, $parameters, $primaryKeyInfo, $window, $windowSize);

			} catch (DetectPrimaryKeyInClauseException $exception) {
				$astObject = $exception->getAstObject();

				$filteredParams = array_slice(
					$astObject->getParameters(),
					$window * $windowSize,
					$windowSize
				);

				$astObject->setParameters($filteredParams);
			}
		}

		/**
		 * Processes pagination by fetching all primary keys first.
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @param int $window
		 * @param int $windowSize
		 * @return void
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		private function processPaginationWithValidation(
			AstRetrieve $ast,
			array &$parameters,
			PrimaryKeyInfo $primaryKeyInfo,
			int $window,
			int $windowSize
		): void {
			// First pass: Execute a lightweight query to fetch only the primary keys
			// This avoids loading full records when we only need to determine pagination boundaries
			$primaryKeyTuples = $this->fetchAllPrimaryKeysForPagination($ast, $parameters, $primaryKeyInfo);
			$primaryKeyTuples = $this->deduplicateTuples($primaryKeyTuples, $primaryKeyInfo);

			// Early exit if no records match the query conditions
			if (empty($primaryKeyTuples)) {
				return;
			}

			// Apply pagination logic to get only the subset of primary keys for the requested page
			// Uses the window (offset) and window size (limit) from the AST
			$pageTuples = $this->getPageSubset($primaryKeyTuples, $window, $windowSize);

			// Handle edge case where pagination parameters result in no valid results
			// (e.g., requesting page 100 when there are only 50 total pages)
			if (empty($pageTuples)) {
				// Add a condition that will never match (like "1=0") to make the query return empty results
				// This is more efficient than letting the database process the full query
				$this->addImpossibleCondition($ast);
				return;
			}

			// Modify the original query to include a filter that limits results to only the
			// primary key tuples determined to belong on this page. The query's own ORDER BY,
			// if any (left untouched above), reproduces the exact order already established
			// by the first pass, so the final result set comes back in that same order.
			$this->addFilterForPagination($ast, $parameters, $primaryKeyInfo, $pageTuples);
		}

		/**
		 * Fetches all primary key tuples for pagination by temporarily modifying the query.
		 * Never applies SELECT DISTINCT: combined with an ORDER BY on anything other than
		 * these exact columns, DISTINCT is invalid or silently reorders rows on some engines.
		 * Deduplication happens afterward, on the typed tuple, in deduplicateTuples().
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @return list<array<string, int|string>> Property name => value, in result-set order
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		private function fetchAllPrimaryKeysForPagination(AstRetrieve $ast, array $parameters, PrimaryKeyInfo $primaryKeyInfo): array {
			// Store original state
			$originalValues = $ast->getValues();
			$originalUnique = $ast->getUnique();

			try {
				$ast->setUnique(false);

				// Project every key component, each under its own alias
				$aliasToProperty = [];
				$values = [];

				foreach ($this->createPrimaryKeyIdentifiers($primaryKeyInfo) as $index => $identifier) {
					$alias = "pk{$index}";
					$aliasToProperty[$alias] = $primaryKeyInfo->primaryKeys[$index];
					$values[] = new AstAlias($alias, $identifier);
				}

				$ast->setValues($values);

				// Execute modified query
				$sql = $this->convertToSQL($ast, $parameters);
				$result = $this->connection->execute($sql, $parameters);

				if ($result === null) {
					return [];
				}

				// Canonicalize every row to the entity's own property types (e.g. a numeric
				// id read back as a driver string becomes a real int) so duplicate rows from
				// a join compare equal in deduplicateTuples() and bind correctly afterward.
				$metadata = $this->entityStore->getMetadata($primaryKeyInfo->entityName);
				$tuples = [];

				foreach ($result->fetchAll('assoc') as $row) {
					$tuple = [];

					foreach ($aliasToProperty as $alias => $property) {
						$tuple[$property] = $row[$alias];
					}

					$tuples[] = $metadata->canonicalizeIdentifier($tuple);
				}

				return $tuples;
			} finally {
				// Always restore original state
				$ast->setValues($originalValues);
				$ast->setUnique($originalUnique);
			}
		}

		/**
		 * Removes duplicate primary key tuples (e.g. from a one-to-many eager join fanning
		 * out a row), keeping each tuple's first occurrence and therefore the result set's
		 * original order.
		 * @param list<array<string, int|string>> $tuples
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @return list<array<string, int|string>>
		 */
		private function deduplicateTuples(array $tuples, PrimaryKeyInfo $primaryKeyInfo): array {
			$seen = [];
			$result = [];

			foreach ($tuples as $tuple) {
				$key = $this->encodeTuple($tuple, $primaryKeyInfo);

				if (isset($seen[$key])) {
					continue;
				}

				$seen[$key] = true;
				$result[] = $tuple;
			}

			return $result;
		}

		/**
		 * Encodes a canonicalized key tuple into a collision-safe string: each value is
		 * type-tagged (keeping int 1 and string "1" distinct) and length-prefixed before
		 * being concatenated, so a value containing the tag/length separator can never be
		 * misread as a boundary between components.
		 * @param array<string, int|string> $tuple
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @return string
		 */
		private function encodeTuple(array $tuple, PrimaryKeyInfo $primaryKeyInfo): string {
			$segments = [];

			foreach ($primaryKeyInfo->primaryKeys as $property) {
				$value = $tuple[$property];
				$tagged = is_int($value) ? 'i:' . $value : 's:' . $value;
				$segments[] = strlen($tagged) . ':' . $tagged;
			}

			return implode('', $segments);
		}

		/**
		 * Gets the subset of primary key tuples for the current page.
		 * @param list<array<string, int|string>> $tuples
		 * @param int $window
		 * @param int $windowSize
		 * @return list<array<string, int|string>>
		 */
		private function getPageSubset(array $tuples, int $window, int $windowSize): array {
			return array_slice($tuples, $window * $windowSize, $windowSize);
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
			$quelToSQL = new QuelToSQLRetrieve($this->entityStore, $stringKeyedParameters, $this->platform, $this->connection->getRoutineSchema());
			return $quelToSQL->convertToSQL($retrieve);
		}

		/**
		 * Factory method to create one primary key identifier per identifier property, in
		 * metadata order. Each call returns fresh nodes (and a fresh range clone), since an
		 * AST node can only ever belong to one parent.
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @return list<AstIdentifier>
		 */
		private function createPrimaryKeyIdentifiers(PrimaryKeyInfo $primaryKeyInfo): array {
			return array_map(
				static function (string $property) use ($primaryKeyInfo): AstIdentifier {
					$astIdentifier = new AstIdentifier($primaryKeyInfo->entityName);
					$astIdentifier->setRange(clone $primaryKeyInfo->range);
					$astIdentifier->setNext(new AstIdentifier($property));
					return $astIdentifier;
				},
				$primaryKeyInfo->primaryKeys
			);
		}

		/**
		 * Returns the next unique bound-parameter name for this transform() call.
		 * @return string
		 */
		private function nextParameterName(): string {
			return '__pagination_pk_' . $this->parameterSequence++;
		}

		/**
		 * Adds the page filter for pagination: a single-column IN() of bound parameters for
		 * a single-key entity (the existing fast path, now using bound values instead of
		 * literal AST nodes — a string key no longer risks becoming an AstNumber), or an OR
		 * of parenthesized AND-equality groups, one group per tuple, for a composite key.
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @param non-empty-list<array<string, int|string>> $pageTuples
		 * @return void
		 */
		private function addFilterForPagination(AstRetrieve $ast, array &$parameters, PrimaryKeyInfo $primaryKeyInfo, array $pageTuples): void {
			if (!$primaryKeyInfo->isComposite()) {
				$this->addInConditionForPagination($ast, $parameters, $primaryKeyInfo, $pageTuples);
				return;
			}

			$properties = $primaryKeyInfo->primaryKeys;
			$groups = [];

			foreach ($pageTuples as $tuple) {
				$groups[] = $this->buildEqualityGroup($tuple, $properties, $primaryKeyInfo, $parameters);
			}

			$filter = $groups[0];

			for ($i = 1, $count = count($groups); $i < $count; $i++) {
				$filter = new AstBinaryOperator($filter, $groups[$i], 'OR');
			}

			if ($ast->getConditions() === null) {
				$ast->setConditions($filter);
			} else {
				$ast->setConditions(new AstBinaryOperator($ast->getConditions(), $filter, 'AND'));
			}
		}

		/**
		 * Builds a single parenthesized AND-equality group — one tuple's worth of "pk1 = :p0
		 * AND pk2 = :p1 ..." — binding a fresh named parameter per component.
		 * @param array<string, int|string> $tuple
		 * @param non-empty-list<string> $properties
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @param array<string, mixed> $parameters
		 * @return AstInterface
		 */
		private function buildEqualityGroup(array $tuple, array $properties, PrimaryKeyInfo $primaryKeyInfo, array &$parameters): AstInterface {
			$identifiers = $this->createPrimaryKeyIdentifiers($primaryKeyInfo);

			$paramName = $this->nextParameterName();
			$parameters[$paramName] = $tuple[$properties[0]];
			$group = new AstExpression($identifiers[0], new AstParameter($paramName), '=');

			for ($i = 1, $count = count($properties); $i < $count; $i++) {
				$paramName = $this->nextParameterName();
				$parameters[$paramName] = $tuple[$properties[$i]];

				$equality = new AstExpression($identifiers[$i], new AstParameter($paramName), '=');
				$group = new AstBinaryOperator($group, $equality, 'AND');
			}

			return $group;
		}

		/**
		 * Adds a single-column IN() condition of bound parameters for pagination filtering.
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters
		 * @param PrimaryKeyInfo $primaryKeyInfo
		 * @param list<array<string, int|string>> $pageTuples
		 * @return void
		 */
		private function addInConditionForPagination(AstRetrieve $ast, array &$parameters, PrimaryKeyInfo $primaryKeyInfo, array $pageTuples): void {
			// Create the primary key identifier
			$astIdentifier = $this->createPrimaryKeyIdentifiers($primaryKeyInfo)[0];
			$property = $primaryKeyInfo->primaryKeys[0];

			// Bind each page value as a named parameter instead of embedding it as a literal
			// AST node — sidesteps ever having to guess AstNumber vs AstString for it.
			$astValues = [];

			foreach ($pageTuples as $tuple) {
				$paramName = $this->nextParameterName();
				$parameters[$paramName] = $tuple[$property];
				$astValues[] = new AstParameter($paramName);
			}

			// Check if AstIn already exists and replace its parameters
			try {
				$visitor = new DetectPrimaryKeyInClause($astIdentifier);
				$ast->getConditions()?->accept($visitor);
			} catch (DetectPrimaryKeyInClauseException $exception) {
				$exception->getAstObject()->setParameters($astValues);
				return;
			}

			// Create new AstIn condition
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
