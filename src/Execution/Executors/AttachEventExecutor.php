<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentCompiler;

	/**
	 * Executes `after ... call ... [as <alias>]`: validates, compiles and creates the
	 * attachment on the connected engine. A conflicting alias is rejected inside the compiler,
	 * which resolves it (given or generated) before rendering — see
	 * EventAttachmentCompiler::resolveAlias().
	 */
	class AttachEventExecutor implements DdlStatementExecutorInterface {

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
		 * Compiles and creates the attachment.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException When the attachment's alias conflicts, it doesn't validate, or the DDL fails
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstEventAttachment);

			$routineName = $statement->getRoutineName();
			$table = $this->entityManager->getEntityStore()->getMetadata($statement->getRange()->getEntityName())->tableName;

			$compiler = new EventAttachmentCompiler($this->entityManager, $this->platform, $this->connection->getRoutineSchema(), $this->connection);
			$ddl = $compiler->compileAttachment($statement);

			$this->ddlRunner->runTransactionally($ddl, $this->platform, "Failed to attach '{$routineName}' to '{$table}'", 'routine_definition_error');
		}
	}
