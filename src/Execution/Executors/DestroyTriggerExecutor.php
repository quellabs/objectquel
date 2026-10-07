<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentCompiler;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentNaming;

	/**
	 * Executes `destroy trigger <range> <alias> [if exists]`: removes only this one
	 * attachment, never the routine itself (see "Attachment identity and removal" in
	 * objectquel-equel-triggers-design.md).
	 */
	class DestroyTriggerExecutor implements DdlStatementExecutorInterface {

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
		 * Drops the attachment; a missing one is an error unless `if exists` is given.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException When the attachment is missing (without `if exists`) or the DROP fails
		 * @throws \Throwable
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstDestroyEventAttachment);

			$alias = $statement->getAlias();
			$table = $this->entityManager->getEntityStore()->getMetadata($statement->getRange()->getEntityName())->tableName;
			$triggerName = EventAttachmentNaming::triggerName($table, $alias);

			if (!$this->connection->triggerExists($table, $triggerName)) {
				if ($statement->isIfExists()) {
					return;
				}

				throw new QuelException("Failed to destroy the attachment '{$alias}' on '{$table}': it doesn't exist", 'routine_destruction_error');
			}

			$compiler = new EventAttachmentCompiler($this->entityManager, $this->platform, $this->connection->getRoutineSchema(), $this->connection);
			$ddl = $compiler->compileDestroy($statement);

			$this->ddlRunner->runTransactionally($ddl, $this->platform, "Failed to destroy the attachment '{$alias}' on '{$table}'", 'routine_destruction_error');
		}
	}
