<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\ProcedureCompiler;

	/**
	 * Executes `define function ...`: compiles the routine for the connected engine and
	 * creates or replaces it on the server.
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
			return $this->compiler ??= new ProcedureCompiler($this->entityManager, $this->platform, $this->entityManager->getConnection()->getRoutineSchema());
		}

		/**
		 * On MySQL the existing routine is dropped before the CREATE runs, so a failing
		 * CREATE leaves no routine behind.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException When compilation or a statement fails
		 * @throws SemanticException|EntityResolutionException|TransformationException When the routine doesn't compile
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstRoutineDefinition);

			$this->ddlRunner->run(
				$this->compiler()->compileRoutine($statement),
				"Failed to define routine '{$statement->getName()}'",
				'routine_definition_error'
			);
		}
	}
