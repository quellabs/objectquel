<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Helpers\EventAttachmentValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\EventAttachmentLowering;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\MysqlEventAttachmentLowering;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\PostgresEventAttachmentLowering;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\SqlServerEventAttachmentLowering;

	/**
	 * Compiles EQUEL attachment source (`after ... call ...`) to the target engine's trigger
	 * DDL. Unlike ProcedureCompiler, this always needs a live connection: an attachment's whole
	 * purpose is to validate against an already-deployed routine's metadata (see
	 * EventAttachmentValidator), so there is no offline compilation mode.
	 */
	class EventAttachmentCompiler {

		/**
		 * @param EntityManager $entityManager Entity metadata
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 * @param string|null $routineSchema Schema that qualifies the table/trigger/routine, or null for none
		 * @param DatabaseAdapter $connection Connection the called routine's metadata is read from
		 */
		public function __construct(
			private readonly EntityManager $entityManager,
			private readonly PlatformCapabilitiesInterface $platform,
			private readonly ?string $routineSchema,
			private readonly DatabaseAdapter $connection,
		) {
		}

		/**
		 * Parses, analyzes, validates and lowers one attachment.
		 * @param string $source Attachment source containing one `after ... call ...` statement
		 * @return list<string> Statements to run in order
		 * @throws LexerException|ParserException|\ReflectionException
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		public function compile(string $source): array {
			$entityStore = $this->entityManager->getEntityStore();
			$ast = (new Parser(new Lexer($source), $entityStore))->parse();

			if (!$ast instanceof AstEventAttachment) {
				throw new ParserException("An attachment source must contain exactly one 'after ... call ...' statement.");
			}

			return $this->compileAttachment($ast);
		}

		/**
		 * Analyzes, validates and lowers an already parsed attachment.
		 * @param AstEventAttachment $attachment Attachment parsed from an `after ... call ...` source
		 * @return list<string> Statements to run in order
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		public function compileAttachment(AstEventAttachment $attachment): array {
			$entityStore = $this->entityManager->getEntityStore();
			(new EventAttachmentAnalyzer($entityStore))->analyze($attachment);
			(new EventAttachmentValidator($this->connection, $entityStore))->validate($attachment);

			return $this->lowering()->render($attachment);
		}

		/**
		 * Lowers the removal of one attachment. No analysis or validation is needed: a
		 * `destroy event` names only the (table, event, routine) triple, with no call
		 * arguments to check against the routine's metadata.
		 * @param AstDestroyEventAttachment $statement Parsed `destroy event ...` statement
		 * @return list<string> Statements to run in order
		 * @throws EntityResolutionException|QuelException
		 */
		public function compileDestroy(AstDestroyEventAttachment $statement): array {
			$table = $this->entityManager->getEntityStore()->getMetadata($statement->getRange()->getEntityName())->tableName;
			return $this->lowering()->renderDestroy($table, $statement->getEvent(), $statement->getRoutineName());
		}

		/**
		 * Picks the dialect lowering for the connected engine.
		 * @return EventAttachmentLowering
		 * @throws QuelException
		 */
		private function lowering(): EventAttachmentLowering {
			$entityStore = $this->entityManager->getEntityStore();

			return match ($this->platform->getDatabaseType()) {
				'pgsql' => new PostgresEventAttachmentLowering($entityStore, $this->platform, $this->routineSchema),
				'sqlsrv' => new SqlServerEventAttachmentLowering($entityStore, $this->platform, $this->routineSchema),
				'mysql', 'mariadb' => new MysqlEventAttachmentLowering($entityStore, $this->platform, $this->routineSchema),
				default => throw new QuelException("Attachments can't be compiled for '{$this->platform->getDatabaseType()}'."),
			};
		}
	}
