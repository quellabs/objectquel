<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\Execution\Helpers\RoutineCallTyper;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\ProcedureCompiler;

	/**
	 * Executes `define function ...`: compiles the routine for the connected engine and
	 * creates it on the server.
	 */
	class DefineRoutineExecutor implements DdlStatementExecutorInterface {

		private EntityManager $entityManager;
		private PlatformCapabilitiesInterface $platform;
		private ?ProcedureCompiler $compiler = null;
		private DdlRunner $ddlRunner;

		/**
		 * @param EntityManager $entityManager Entity metadata and connection
		 * @param PlatformCapabilitiesInterface $platform Connected engine
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform) {
			$this->entityManager = $entityManager;
			$this->platform = $platform;
			$this->ddlRunner = new DdlRunner($entityManager->getConnection());
		}

		/**
		 * Returns the routine compiler. Built on first use, so SQL Server reads the routine schema only when a statement needs compiling.
		 * @return ProcedureCompiler
		 */
		private function compiler(): ProcedureCompiler {
			$connection = $this->entityManager->getConnection();
			return $this->compiler ??= new ProcedureCompiler($this->entityManager, $this->platform, $connection->getRoutineSchema(), new RoutineCallTyper($connection));
		}

		/**
		 * Rejects an existing routine before running CREATE, preserving its definition.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException When compilation or a statement fails
		 * @throws SemanticException|EntityResolutionException|TransformationException When the routine doesn't compile
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstRoutineDefinition);
			$connection = $this->entityManager->getConnection();
			$statements = $this->compiler()->compileRoutine($statement);

			try {
				$exists = $connection->routineExists($statement->getName());
			} catch (QuelException $exception) {
				throw new QuelException("Failed to define routine '{$statement->getName()}': {$exception->getMessage()}", 'routine_definition_error', 0, $exception);
			}

			if ($exists) {
				throw new QuelException("Failed to define routine '{$statement->getName()}': a routine with that name already exists", 'routine_definition_error');
			}

			$this->ddlRunner->run(
				$statements,
				"Failed to define routine '{$statement->getName()}'",
				'routine_definition_error'
			);
		}
	}
