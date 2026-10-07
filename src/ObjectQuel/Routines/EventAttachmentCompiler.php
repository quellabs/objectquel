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

		/** Attempts before giving up on finding a free generated alias; collision is astronomically unlikely with one */
		private const int ALIAS_ATTEMPTS = 5;

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
		 * Parses, validates and lowers one attachment.
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
		 * Validates and lowers an already parsed attachment. Resolves the physical alias
		 * (given with `as <alias>`, rejecting a duplicate on this table; generated and
		 * collision-checked otherwise — see EventAttachmentNaming::randomAlias()) before
		 * rendering, so the DDL is built with the final name in one pass.
		 * @param AstEventAttachment $attachment Attachment parsed from an `after ... call ...` source
		 * @return list<string> Statements to run in order
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		public function compileAttachment(AstEventAttachment $attachment): array {
			$entityStore = $this->entityManager->getEntityStore();
			(new EventAttachmentValidator($this->connection, $entityStore))->validate($attachment);

			$table = $entityStore->getMetadata($attachment->getRange()->getEntityName())->tableName;
			$alias = $this->resolveAlias($table, $attachment->getAlias());

			return $this->lowering()->render($attachment, $alias);
		}

		/**
		 * Lowers the removal of one attachment. No analysis or validation is needed: `destroy
		 * trigger` names the attachment directly by (table, alias), with no call arguments to
		 * check against the routine's metadata.
		 * @param AstDestroyEventAttachment $statement Parsed `destroy trigger ...` statement
		 * @return list<string> Statements to run in order
		 * @throws EntityResolutionException|QuelException
		 */
		public function compileDestroy(AstDestroyEventAttachment $statement): array {
			$table = $this->entityManager->getEntityStore()->getMetadata($statement->getRange()->getEntityName())->tableName;
			return $this->lowering()->renderDestroy($table, $statement->getAlias());
		}

		/**
		 * Resolves the alias an attachment is created under. Given explicitly, it must not
		 * already name a live attachment on this table. Omitted, a random one is generated and
		 * retried on the astronomically unlikely chance it collides with an existing name.
		 * @param string $table Physical table the attachment is on
		 * @param string|null $explicitAlias Alias given with `as <alias>`, or null
		 * @return string The alias to build the physical name from
		 * @throws QuelException When the given alias is already in use, or no free generated alias was found
		 */
		private function resolveAlias(string $table, ?string $explicitAlias): string {
			if ($explicitAlias !== null) {
				if ($this->connection->triggerExists($table, EventAttachmentNaming::triggerName($table, $explicitAlias))) {
					throw new QuelException("Can't attach: '{$explicitAlias}' already exists on '{$table}'.", 'routine_definition_error');
				}

				return $explicitAlias;
			}

			for ($attempt = 0; $attempt < self::ALIAS_ATTEMPTS; $attempt++) {
				$candidate = EventAttachmentNaming::randomAlias();

				if (!$this->connection->triggerExists($table, EventAttachmentNaming::triggerName($table, $candidate))) {
					return $candidate;
				}
			}

			throw new QuelException("Can't attach to '{$table}': failed to generate a free alias after " . self::ALIAS_ATTEMPTS . ' attempts.', 'routine_definition_error');
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
