<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\DateTimeWriteSql;
	use Quellabs\ObjectQuel\ObjectQuel\Passes\IdentifierTypeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Passes\QueryNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLAppend;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLCall;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLDelete;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLReplace;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLUpsert;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\Persistence\VersionValueHandler;
	use Quellabs\ObjectQuel\Planner\ExecutionPlanBuilder;
	use Quellabs\ObjectQuel\Planner\ExecutionStage;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NormalizeDateTime;

	/**
	 * Compiles the statements and expressions embedded in a routine body to SQL
	 * for one target engine, reusing the ad hoc query compilers. Dialect-neutral:
	 * the routine lowerings wrap its output in engine-specific control flow.
	 *
	 * Nothing compiled here may need a bound parameter; a routine has no PHP
	 * side to supply one, so such statements are rejected.
	 */
	class RoutineStatementCompiler {

		private EntityManager $entityManager;
		private EntityStore $entityStore;
		private PlatformCapabilitiesInterface $platform;

		/** @var string|null Schema that qualifies routine names, or null for none */
		private ?string $routineSchema;
		private RoutineRangeReferences $rangeReferences;
		private QuelToSQLDelete $deleteCompiler;
		private QuelToSQLReplace $replaceCompiler;
		private QuelToSQLAppend $appendCompiler;
		private QuelToSQLCall $callCompiler;
		private RoutineFieldTypes $fieldTypes;

		/**
		 * @param EntityManager $entityManager Entity metadata and the optimizer's dependencies
		 * @param PlatformCapabilitiesInterface $platform Target engine, which need not be the connected one
		 * @param string|null $routineSchema Schema that qualifies routine names, or null for none
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform, ?string $routineSchema) {
			$this->entityManager = $entityManager;
			$this->entityStore = $entityManager->getEntityStore();
			$this->platform = $platform;
			$this->routineSchema = $routineSchema;
			$this->rangeReferences = new RoutineRangeReferences($this->entityStore);

			// Built for the target platform; the unit of work's own handler renders for the connected engine
			$unitOfWork = $entityManager->getUnitOfWork();
			$versionValueHandler = new VersionValueHandler($entityManager->getConnection(), $this->entityStore, $unitOfWork, $unitOfWork->getPropertyHandler(), $platform);

			// Written values may read routine variables and cursor fields, so the write compilers type them with these
			$this->fieldTypes = new RoutineFieldTypes($this->entityStore, new DDLTypeMapper($platform));

			$this->deleteCompiler = new QuelToSQLDelete($this->entityStore, $platform, $routineSchema);
			$this->replaceCompiler = new QuelToSQLReplace($this->entityStore, $platform, $routineSchema, $versionValueHandler, $this->fieldTypes);
			$this->callCompiler = new QuelToSQLCall($this->entityStore, $platform, $routineSchema);
			$this->appendCompiler = new QuelToSQLAppend($entityManager, $platform, $routineSchema, new QuelToSQLUpsert($this->entityStore, $platform, $routineSchema, $this->replaceCompiler), $versionValueHandler, $this->fieldTypes);
		}

		/**
		 * @return RoutineFieldTypes Types of the routine's variables and cursor fields, filled in by the lowering
		 */
		public function getFieldTypes(): RoutineFieldTypes {
			return $this->fieldTypes;
		}

		/**
		 * Runs a copy of an embedded retrieve through the ad hoc query pipeline, narrowed to the ranges it reads.
		 * The copy selects only its target list, without the optimizer's PHP-side projections.
		 * @param AstRetrieve $retrieve Analyzed standalone or cursor retrieve
		 * @param AstAlias[] $extraValues Values appended to the copy's target list before the pipeline runs
		 * @return AstRetrieve The optimized copy, ready for retrieveSql()
		 * @throws SemanticException When the query needs PHP-side processing
		 * @throws EntityResolutionException|TransformationException|QuelException
		 */
		public function prepareRetrieve(AstRetrieve $retrieve, array $extraValues = []): AstRetrieve {
			// Declared ranges are shared between statements and the pipeline mutates them
			$query = $retrieve->deepClone();

			foreach ($extraValues as $value) {
				$query->addValue($value);
			}

			$this->narrowRanges($query);

			$parameters = [];
			(new IdentifierTypeResolver($this->entityStore))->resolve($query);
			(new QueryNormalizer($this->entityStore))->transform($query);
			$this->normalizeDateTimes($query);
			(new SemanticAnalyzer($this->entityStore, $this->platform))->validate($query);
			(new QueryOptimizer($this->entityManager, $this->platform))->transform($query, $parameters);

			$stages = (new ExecutionPlanBuilder())->build($query, $parameters)->getStagesInOrder();

			// The main database stage is the only one without a range of its own
			if (count($stages) !== 1 || !$stages[0] instanceof ExecutionStage || $stages[0]->getRange() !== null) {
				throw new SemanticException('This retrieve needs PHP-side processing (a JSON source, a temp table, or no range at all), which a routine can\'t run. Use an assignment for a value without a range.');
			}

			$query->setValues(array_values(array_filter($query->getValues(), fn(AstAlias $value) => $value->showInResult())));

			return $query;
		}

		/**
		 * @param AstRetrieve $prepared Result of prepareRetrieve()
		 * @return string The SELECT statement
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		public function retrieveSql(AstRetrieve $prepared): string {
			return $this->withoutBoundParameters('retrieve', function (array &$parameters) use ($prepared): string {
				return (new QuelToSQLRetrieve($this->entityStore, $parameters, $this->platform, $this->routineSchema))->convertToSQL($prepared);
			});
		}

		/**
		 * @param AstDelete $delete Analyzed `delete range where ...`
		 * @return string
		 * @throws SemanticException
		 */
		public function compileDelete(AstDelete $delete): string {
			$statement = $delete->deepClone();
			$this->normalizeDateTimes($statement->getConditionsOrFail());

			return $this->withoutBoundParameters('delete', function (array &$parameters) use ($statement): string {
				return $this->deleteCompiler->convertToSQL($statement, $parameters);
			});
		}

		/**
		 * @param AstReplace $replace Analyzed `replace range (...) where ...`
		 * @return string
		 * @throws SemanticException
		 */
		public function compileReplace(AstReplace $replace): string {
			$statement = $replace->deepClone();
			$this->normalizeDateTimes($statement->getConditionsOrFail());

			return $this->withoutBoundParameters('replace', function (array &$parameters) use ($statement): string {
				return $this->replaceCompiler->convertToSQL($statement, $parameters);
			});
		}

		/**
		 * @param AstAppend $append Analyzed `append to range ...`, literal values or insert-from-select
		 * @return string
		 * @throws SemanticException When the insert needs PHP-generated keys, a PHP-side fallback or the planner
		 * @throws EntityResolutionException|TransformationException|QuelException
		 */
		public function compileAppend(AstAppend $append): string {
			$statement = $append->deepClone();
			$entityName = $statement->getEntityName();

			if ($statement->getOnConflict() !== null) {
				$this->normalizeDateTimes($statement->getOnConflict()->getConditionsOrFail());
			}

			if ($entityName === null) {
				throw new SemanticException("'append to {$statement->getRange()->getName()}' targets a JSON source, which a routine can't write.");
			}

			return $this->withoutBoundParameters('append', function (array &$parameters) use ($statement, $entityName): string {
				if ($statement->isInsertFromSelect()) {
					$source = $statement->getSourceOrFail();
					$this->narrowRanges($source);
					$this->appendCompiler->prepareSource($source, $parameters);
					$this->normalizeDateTimes($source);

					if ($this->appendCompiler->needsPlanner($source)) {
						throw new SemanticException("The retrieve feeding 'append to {$statement->getRange()->getName()}' needs PHP-side processing (a JSON source or a temp table), which a routine can't run.");
					}
				} else {
					$this->assertKeyNeedsNoPhp($statement, $entityName);
				}

				$compiled = $this->appendCompiler->convertToSQL($statement, $parameters);

				if ($compiled->hasFallbackUpdate()) {
					throw new SemanticException("'append to {$statement->getRange()->getName()} ... or replace' needs a separate fallback UPDATE run from PHP on this engine, which a routine can't do.");
				}

				return $compiled->primarySql;
			});
		}

		/**
		 * @param AstRangeDatabase $source The cursor's source range
		 * @param string $rowCondition SQL condition selecting the current row
		 * @return string
		 * @throws SemanticException
		 */
		public function compileCurrentRowDelete(AstRangeDatabase $source, string $rowCondition): string {
			return $this->withoutBoundParameters('delete', function () use ($source, $rowCondition): string {
				return $this->deleteCompiler->convertCurrentRowToSQL($source, $rowCondition);
			});
		}

		/**
		 * @param AstRangeDatabase $source The cursor's source range
		 * @param AstAssignment[] $assignments Analyzed `column = value` assignments
		 * @param string $rowCondition SQL condition selecting the current row
		 * @return string
		 * @throws SemanticException
		 */
		public function compileCurrentRowReplace(AstRangeDatabase $source, array $assignments, string $rowCondition): string {
			return $this->withoutBoundParameters('replace', function (array &$parameters) use ($source, $assignments, $rowCondition): string {
				return $this->replaceCompiler->convertCurrentRowToSQL($source, $assignments, $rowCondition, $parameters);
			});
		}

		/**
		 * Compiles a procedural condition (`if`, `while`).
		 * @param AstInterface $condition Analyzed condition
		 * @return string
		 * @throws SemanticException
		 */
		public function compileCondition(AstInterface $condition): string {
			$condition = $condition->deepClone();
			$this->normalizeDateTimes($condition);

			return $this->withoutBoundParameters('expression', function (array &$parameters) use ($condition): string {
				return (new BuildSqlFromAst($this->entityStore, $parameters, 'WHERE', $this->platform, $this->routineSchema))->visitConditionAndReturnSQL($condition);
			});
		}

		/**
		 * Compiles a procedural value (assignment, initializer, `return`). Without boolean literals (SQL Server)
		 * a predicate isn't a value, so it becomes a CASE that yields 1, 0 or NULL like a boolean would.
		 * @param AstInterface $value Analyzed expression
		 * @return string
		 * @throws SemanticException
		 */
		public function compileValue(AstInterface $value): string {
			if (!$this->platform->supportsBooleanLiterals() && !$value instanceof AstBool && $value->getReturnType() === 'boolean') {
				$predicate = $this->compileCondition($value);
				return "CASE WHEN {$predicate} THEN 1 WHEN NOT ({$predicate}) THEN 0 END";
			}

			$value = $value->deepClone();
			$this->normalizeDateTimes($value);

			return $this->withoutBoundParameters('expression', function (array &$parameters) use ($value): string {
				return (new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform, $this->routineSchema))->visitNodeAndReturnSQL($value);
			});
		}

		/**
		 * Compiles a value stored in a variable or returned; a Unix timestamp stored as a datetime is converted to one.
		 * @param AstInterface $value Analyzed expression
		 * @param string $receiver Variable name, or the routine name for a return value, for error messages
		 * @param string $routineType Declared type of the variable or return value
		 * @return string
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		public function compileStoredValue(AstInterface $value, string $receiver, string $routineType): string {
			$normalized = $value->deepClone();
			$this->normalizeDateTimes($normalized);

			$targetType = TypeMapper::phinxTypeToPhpType(RoutineAnalyzer::normalizeType($routineType));
			return DateTimeWriteSql::convert($this->compileValue($value), $this->fieldTypes->inferReturnType($normalized), $targetType, $receiver, $this->platform);
		}

		/**
		 * Compiles a `call` statement as a procedure call; functions are called from expressions instead.
		 * @param AstCall $statement Analyzed call, whose arguments are literals or routine variables
		 * @return string `CALL name(args)`, or `EXEC name args` on SQL Server
		 * @throws SemanticException
		 */
		public function compileCall(AstCall $statement): string {
			$call = $statement->getCall();
			$arguments = array_map(fn(AstInterface $argument) => $this->compileValue($argument), $call->getArguments());

			return $this->callCompiler->procedureCall($call->getName(), implode(', ', $arguments));
		}

		/**
		 * @return PlatformCapabilitiesInterface The target engine
		 */
		public function getPlatform(): PlatformCapabilitiesInterface {
			return $this->platform;
		}

		/**
		 * @return string|null Schema that qualifies routine names, or null for none
		 */
		public function getRoutineSchema(): ?string {
			return $this->routineSchema;
		}

		/**
		 * Drops the declared ranges $query doesn't read; the pipeline would cross-join them.
		 * @param AstRetrieve $query Cloned query, modified in place
		 * @return void
		 * @throws EntityResolutionException
		 */
		private function narrowRanges(AstRetrieve $query): void {
			$query->setRanges(array_values($this->rangeReferences->withJoinDependencies($query, $query->getRanges())));
		}

		/**
		 * Expresses datetime routine variables and cursor fields in comparisons and arithmetic as Unix timestamps,
		 * like datetime columns, so both sides of a comparison agree.
		 * @param AstInterface $node Cloned statement part, modified in place
		 * @return void
		 * @throws EntityResolutionException|QuelException
		 */
		private function normalizeDateTimes(AstInterface $node): void {
			$node->accept(new NormalizeDateTime($this->entityStore, $this->fieldTypes));
		}

		/**
		 * Rejects a literal-values append whose primary key `append` would generate in PHP.
		 * @param AstAppend $statement Literal-values append
		 * @param string $entityName Target entity
		 * @return void
		 * @throws SemanticException
		 */
		private function assertKeyNeedsNoPhp(AstAppend $statement, string $entityName): void {
			$metadata = $this->entityStore->getMetadata($entityName);
			$primaryKey = $metadata->getPrimaryKey();

			if ($primaryKey === null || $metadata->getPrimaryKeyStrategy($primaryKey) === 'identity') {
				return;
			}

			// Rows share one column shape, so the first row decides
			foreach ($statement->getRowsOrFail()[0] as $assignment) {
				if ($assignment->getProperty() === $primaryKey) {
					return;
				}
			}

			throw new SemanticException("'append to {$statement->getRange()->getName()}' leaves primary key '{$primaryKey}' to its '{$metadata->getPrimaryKeyStrategy($primaryKey)}' strategy, which runs in PHP. Assign '{$primaryKey}' explicitly inside a routine.");
		}

		/**
		 * Runs a compile step and rejects its output when it bound a parameter.
		 * @param string $statement Statement keyword, for the error message
		 * @param callable(array<string, mixed>&): string $compile Compile step receiving the parameter array by reference
		 * @return string
		 * @throws SemanticException
		 */
		private function withoutBoundParameters(string $statement, callable $compile): string {
			$parameters = [];
			$sql = $compile($parameters);

			if (!empty($parameters)) {
				$names = implode(', ', array_keys($parameters));
				throw new SemanticException("This {$statement} needs values computed in PHP ({$names}), which a routine can't supply.");
			}

			return $sql;
		}
	}
