<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Helpers\EventBindingValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyEventBinding;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventBinding;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\EventBindingLowering;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\MysqlEventBindingLowering;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\PostgresEventBindingLowering;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\SqlServerEventBindingLowering;

	/**
	 * Compiles EQUEL binding source (`after ... call ...`) to the target engine's trigger
	 * DDL. Unlike RoutineCompiler, this always needs a live connection: a binding's whole
	 * purpose is to validate against an already-deployed routine's metadata (see
	 * EventBindingValidator), so there is no offline compilation mode.
	 */
	class EventBindingCompiler {

		/** Attempts before giving up on finding a free generated alias; collision is astronomically unlikely with one */
		private const int ALIAS_ATTEMPTS = 5;

		private readonly EntityManager $entityManager;
		private readonly PlatformCapabilitiesInterface $platform;
		private readonly ?string $routineSchema;
		private readonly DatabaseAdapter $connection;

		/**
		 * @param EntityManager $entityManager Entity metadata
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 * @param string|null $routineSchema Schema that qualifies the table/trigger/routine, or null for none
		 * @param DatabaseAdapter $connection Connection the called routine's metadata is read from
		 */
		public function __construct(
			EntityManager $entityManager,
			PlatformCapabilitiesInterface $platform,
			?string $routineSchema,
			DatabaseAdapter $connection,
		) {
			$this->entityManager = $entityManager;
			$this->platform = $platform;
			$this->routineSchema = $routineSchema;
			$this->connection = $connection;
		}

		/**
		 * Parses, validates and lowers one binding.
		 * @param string $source Binding source containing one `after ... call ...` statement
		 * @return list<string> Statements to run in order
		 * @throws LexerException|ParserException|\ReflectionException
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		public function compile(string $source): array {
			$entityStore = $this->entityManager->getEntityStore();
			$ast = (new Parser(new Lexer($source), $entityStore))->parse();

			if (!$ast instanceof AstEventBinding) {
				throw new ParserException("A binding source must contain exactly one 'after ... call ...' statement.");
			}

			return $this->compileBinding($ast);
		}

		/**
		 * Validates and lowers an already parsed binding. Resolves the physical alias
		 * (given with `as <alias>`, rejecting a duplicate on this table; generated and
		 * collision-checked otherwise — see EventBindingNaming::randomAlias()) before
		 * rendering, so the DDL is built with the final name in one pass.
		 * @param AstEventBinding $binding Binding parsed from an `after ... call ...` source
		 * @return list<string> Statements to run in order
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		public function compileBinding(AstEventBinding $binding): array {
			$entityStore = $this->entityManager->getEntityStore();
			$parameterCount = (new EventBindingValidator($this->connection, $entityStore))->validate($binding);

			$table = $entityStore->getMetadata($binding->getRange()->getEntityName())->tableName;
			$alias = $this->resolveAlias($table, $binding->getAlias());

			return $this->lowering()->render($binding, $alias, $parameterCount);
		}

		/**
		 * Lowers the removal of one binding. No analysis or validation is needed: `destroy
		 * trigger` names the binding directly by (table, alias), with no call arguments to
		 * check against the routine's metadata.
		 * @param AstDestroyEventBinding $statement Parsed `destroy trigger ...` statement
		 * @return list<string> Statements to run in order
		 * @throws EntityResolutionException|QuelException
		 */
		public function compileDestroy(AstDestroyEventBinding $statement): array {
			$table = $this->entityManager->getEntityStore()->getMetadata($statement->getRange()->getEntityName())->tableName;
			return $this->lowering()->renderDestroy($table, $statement->getAlias());
		}

		/**
		 * Resolves the alias a binding is created under. Given explicitly, it must not
		 * already name a live binding on this table. Omitted, a random one is generated and
		 * retried on the astronomically unlikely chance it collides with an existing name.
		 * @param string $table Physical table the binding is on
		 * @param string|null $explicitAlias Alias given with `as <alias>`, or null
		 * @return string The alias to build the physical name from
		 * @throws QuelException When the given alias is already in use, or no free generated alias was found
		 */
		private function resolveAlias(string $table, ?string $explicitAlias): string {
			if ($explicitAlias !== null) {
				if ($this->connection->triggerExists($table, EventBindingNaming::triggerName($table, $explicitAlias))) {
					throw new QuelException("Can't bind: '{$explicitAlias}' already exists on '{$table}'.", 'routine_definition_error');
				}

				return $explicitAlias;
			}

			for ($attempt = 0; $attempt < self::ALIAS_ATTEMPTS; $attempt++) {
				$candidate = EventBindingNaming::randomAlias();

				if (!$this->connection->triggerExists($table, EventBindingNaming::triggerName($table, $candidate))) {
					return $candidate;
				}
			}

			throw new QuelException("Can't bind to '{$table}': failed to generate a free alias after " . self::ALIAS_ATTEMPTS . ' attempts.', 'routine_definition_error');
		}

		/**
		 * Picks the dialect lowering for the connected engine.
		 * @return EventBindingLowering
		 * @throws QuelException
		 */
		private function lowering(): EventBindingLowering {
			$entityStore = $this->entityManager->getEntityStore();

			return match ($this->platform->getDatabaseType()) {
				'pgsql' => new PostgresEventBindingLowering($entityStore, $this->platform, $this->routineSchema),
				'sqlsrv' => new SqlServerEventBindingLowering($entityStore, $this->platform, $this->routineSchema),
				'mysql', 'mariadb' => new MysqlEventBindingLowering($entityStore, $this->platform, $this->routineSchema),
				default => throw new QuelException("Bindings can't be compiled for '{$this->platform->getDatabaseType()}'."),
			};
		}
	}
