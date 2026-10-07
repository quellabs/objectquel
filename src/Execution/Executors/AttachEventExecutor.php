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
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentNaming;

	/**
	 * Executes `after ... call ...(...)`: validates, compiles and creates the attachment on
	 * the connected engine.
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
		 * Rejects a conflicting existing attachment before compiling and creating the new one.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException When the attachment conflicts, doesn't validate, or the DDL fails
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstEventAttachment);

			$routineName = $statement->getCall()->getName();
			$table = $this->entityManager->getEntityStore()->getMetadata($statement->getRange()->getEntityName())->tableName;
			$triggerName = EventAttachmentNaming::triggerName($table, $statement->getEvent(), $routineName);

			if ($this->connection->triggerExists($table, $triggerName)) {
				throw new QuelException("Failed to attach '{$routineName}' to '{$table}': an attachment for this table, event and routine already exists.", 'routine_definition_error');
			}

			$compiler = new EventAttachmentCompiler($this->entityManager, $this->platform, $this->connection->getRoutineSchema(), $this->connection);
			$ddl = $compiler->compileAttachment($statement);

			$this->ddlRunner->runTransactionally($ddl, $this->platform, "Failed to attach '{$routineName}' to '{$table}'", 'routine_definition_error');
		}
	}
