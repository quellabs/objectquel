<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\PrimaryKeyInfo;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;
	use Quellabs\ObjectQuel\Execution\Visitors\DetectPrimaryKeyInClause;
	use Quellabs\ObjectQuel\Execution\Visitors\DetectPrimaryKeyInClauseException;
	
	class PaginationTransformer {
		
		private EntityStore $entityStore;
		private DatabaseAdapter $connection;
		private PlatformCapabilitiesInterface $platform;
		private ?PrimaryKeyInfo $primaryKeyInfo = null;
		
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
		 * Parameters are needed for pagination to work with bound values in conditions
		 * @param AstRetrieve $ast
		 * @param array<int|string, mixed> $parameters
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		public function transform(AstRetrieve $ast, array $parameters): void {
			$window = $ast->getWindow();
			$windowSize = $ast->getWindowSize();
			
			if (
				$window !== null &&
				$windowSize !== null &&
				!$ast->getSortInApplicationLogic()
			) {
				try {
					$this->primaryKeyInfo = $this->entityStore->fetchPrimaryKeyOfMainRange($ast);
				$this->processPagination($ast, $parameters, $window, $windowSize);
				} finally {
					$this->primaryKeyInfo = null;
				}
			}
		}
		
		// ========== PAGINATION METHODS ==========
		
		/**
		 * Processes pagination for the query.
		 * @param AstRetrieve $ast
		 * @param array<int|string, mixed> $parameters
		 * @param int $window
		 * @param int $windowSize
		 * @return void
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		private function processPagination(AstRetrieve $ast, array $parameters, int $window, int $windowSize): void {
			// Check for query directives that might affect pagination behavior
			$directives = $ast->getDirectives();
			
			// Look for the 'InValuesAreFinal' directive which indicates that any IN conditions
			// in the query are already finalized and don't need additional validation/processing
			// This is an optimization flag that can skip the validation phase of pagination
			$skipValidation = isset($directives['InValuesAreFinal']) && $directives['InValuesAreFinal'] === true;
			
			// Choose the appropriate pagination strategy based on the directive
			if ($skipValidation) {
				// Fast path: Skip the validation step and process pagination directly
				// Used when we know the IN conditions are already properly constructed
				$this->processPaginationSkippingValidation($ast, $parameters, $window, $windowSize);
			} else {
				// Standard path: Use the full validation approach which fetches all primary keys first
				// This is the safer, more comprehensive method for most queries
				$this->processPaginationWithValidation($ast, $parameters, $window, $windowSize);
			}
		}
		
		/**
		 * Processes pagination by directly manipulating existing IN() values.
		 * @param AstRetrieve $ast
		 * @param array<int|string, mixed> $parameters
		 * @param int $window
		 * @param int $windowSize
		 * @return void
		 */
		private function processPaginationSkippingValidation(
			AstRetrieve $ast,
			array $parameters,
			int $window,
			int $windowSize
		): void {
			try {
				// Fetch IN() statement
				$astIdentifier = $this->createPrimaryKeyIdentifier();
				$visitor = new DetectPrimaryKeyInClause($astIdentifier);
				$ast->getConditions()?->accept($visitor);
				
				// If no exception, fall back to validation method
				$this->processPaginationWithValidation($ast, $parameters, $window, $windowSize);
				
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
		 * @param array<int|string, mixed> $parameters
		 * @param int $window
		 * @param int $windowSize
		 * @return void
		 */
		private function processPaginationWithValidation(
			AstRetrieve $ast,
			array $parameters,
			int $window,
			int $windowSize
		): void {
			// First pass: Execute a lightweight query to fetch only the primary keys
			// This avoids loading full records when we only need to determine pagination boundaries
			$primaryKeys = $this->fetchAllPrimaryKeysForPagination($ast, $parameters);
			
			// Early exit if no records match the query conditions
			if (empty($primaryKeys)) {
				return;
			}
			
			// Apply pagination logic to get only the subset of primary keys for the requested page
			// Uses the window (offset) and window size (limit) from the AST
			$filteredKeys = $this->getPageSubset($primaryKeys, $window, $windowSize);
			
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
			$this->addInConditionForPagination($ast, $filteredKeys);
		}
		
		/**
		 * Fetches all primary keys for pagination by temporarily modifying the query.
		 * @param AstRetrieve $ast
		 * @param array<int|string, mixed> $parameters
		 * @return list<int|string>
		 */
		private function fetchAllPrimaryKeysForPagination(AstRetrieve $ast, array $parameters): array {
			// Store original state
			$originalValues = $ast->getValues();
			$originalUnique = $ast->getUnique();
			
			try {
				// Modify query to get only primary keys
				$ast->setUnique(true);
				$astIdentifier = $this->createPrimaryKeyIdentifier();
				$ast->setValues([new AstAlias("pk", $astIdentifier)]);
				
				// Execute modified query
				$sql = $this->convertToSQL($ast, $parameters);
				
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
		 * @param array<int|string, mixed> $parameters Query parameters (passed by reference)
		 * @return string The generated SQL query
		 */
		private function convertToSQL(AstRetrieve $retrieve, array $parameters): string {
			// Convert all keys to strings
			$stringKeyedParameters = [];
			foreach ($parameters as $key => $value) {
				$stringKeyedParameters[(string)$key] = $value;
			}
			
			// Transform the Quel query to SQL
			$quelToSQL = new QuelToSQL($this->entityStore, $stringKeyedParameters, $this->platform);
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
		 * Adds IN condition for pagination filtering.
		 * @param AstRetrieve $ast
		 * @param list<int|string> $filteredKeys
		 * @return void
		 */
		private function addInConditionForPagination(AstRetrieve $ast, array $filteredKeys): void {
			// Create the primary key identifier
			$astIdentifier = $this->createPrimaryKeyIdentifier();
			
			// Transform filtered keys to AstNumbers
			$astValues = array_map(
				fn($item) => new AstNumber((string)$item),
				$filteredKeys
			);
			
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