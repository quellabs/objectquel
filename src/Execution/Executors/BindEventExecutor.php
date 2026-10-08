<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventBinding;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventBindingCompiler;

	/**
	 * Executes `after ... call ... [as <alias>]`: validates, compiles and creates the
	 * binding on the connected engine. A conflicting alias is rejected inside the compiler,
	 * which resolves it (given or generated) before rendering — see
	 * EventBindingCompiler::resolveAlias().
	 */
	class BindEventExecutor implements DdlStatementExecutorInterface {

		private EntityManager $entityManager;
		private DatabaseAdapter $connection;
		private PlatformCapabilitiesInterface $platform;
		private DdlRunner $ddlRunner;

		/**
		 * @param EntityManager $entityManager Entity metadata and connection
		 * @param PlatformCapabilitiesInterface $platform Connected engine
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform) {
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->platform = $platform;
			$this->ddlRunner = new DdlRunner($this->connection);
		}

		/**
		 * Compiles and creates the binding.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException When the binding's alias conflicts, it doesn't validate, or the DDL fails
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstEventBinding);

			$routineName = $statement->getRoutineName();
			$table = $this->entityManager->getEntityStore()->getMetadata($statement->getRange()->getEntityName())->tableName;

			$compiler = new EventBindingCompiler($this->entityManager, $this->platform, $this->connection->getRoutineSchema(), $this->connection);
			$ddl = $compiler->compileBinding($statement);

			$this->ddlRunner->runTransactionally($ddl, $this->platform, "Failed to bind '{$routineName}' to '{$table}'", 'routine_definition_error');
		}
	}
