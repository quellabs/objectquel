<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\Execution\Helpers\RoutineCallTyper;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\MysqlRoutineLowering;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\PostgresRoutineLowering;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering\SqlServerRoutineLowering;

	/**
	 * Compiles EQUEL routine source (`define function ...`) to the target
	 * engine's CREATE FUNCTION/PROCEDURE statement. The target need not be the
	 * connected engine; routines compile ahead of time.
	 */
	class RoutineCompiler {

		private EntityManager $entityManager;
		private PlatformCapabilitiesInterface $platform;

		/** @var string|null Schema that qualifies routine names, or null for none */
		private ?string $routineSchema;
		private ?RoutineCallTyper $routineCallTyper;

		/**
		 * Initializes the routine compiler with the entity manager and target platform.
		 * @param EntityManager $entityManager Entity metadata and query pipeline dependencies
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 * @param string|null $routineSchema Schema that qualifies routine names, or null for none
		 * @param RoutineCallTyper|null $routineCallTyper Catalog lookup for live compilation; null for offline compilation
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform, ?string $routineSchema, ?RoutineCallTyper $routineCallTyper = null) {
			$this->entityManager = $entityManager;
			$this->platform = $platform;
			$this->routineSchema = $routineSchema;
			$this->routineCallTyper = $routineCallTyper;
		}

		/**
		 * Parses, analyzes and lowers one routine.
		 * @param string $source Routine source containing one `define function`
		 * @return list<string> Statements to run in order; the first creates the routine, any later ones attach its metadata
		 * @throws LexerException|ParserException|\ReflectionException
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		public function compile(string $source): array {
			$entityStore = $this->entityManager->getEntityStore();
			$ast = (new Parser(new Lexer($source), $entityStore))->parse();

			if (!$ast instanceof AstRoutineDefinition) {
				throw new ParserException("A routine source must contain exactly one 'define function'.");
			}

			return $this->compileRoutine($ast);
		}

		/**
		 * Analyzes and lowers an already parsed routine.
		 * @param AstRoutineDefinition $routine Routine parsed from a `define function` source
		 * @return list<string> Statements to run in order; the first creates the routine, any later ones attach its metadata
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		public function compileRoutine(AstRoutineDefinition $routine): array {
			(new RoutineAnalyzer($this->entityManager->getEntityStore()))->analyze($routine);

			return $this->lower($routine);
		}

		/**
		 * Compiles an analyzed routine into ordered database statements.
		 * @param AstRoutineDefinition $routine Routine that passed RoutineAnalyzer
		 * @return list<string> Statements to run in order; the first creates the routine, any later ones attach its metadata
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		public function lower(AstRoutineDefinition $routine): array {
			$this->routineCallTyper?->typeCalls($routine, true);
			$entityStore = $this->entityManager->getEntityStore();
			$statements = new RoutineStatementCompiler($this->entityManager, $this->platform, $this->routineSchema, (bool)$routine->getDirective('ignoreSoftDelete'));

			$lowering = match ($this->platform->getDatabaseType()) {
				'pgsql' => new PostgresRoutineLowering($entityStore, $statements),
				'sqlsrv' => new SqlServerRoutineLowering($entityStore, $statements),
				'mysql', 'mariadb' => new MysqlRoutineLowering($entityStore, $statements, $this->entityManager->getConfiguration()->getCollation()),
				default => throw new QuelException("Routines can't be compiled for '{$this->platform->getDatabaseType()}'."),
			};

			return $lowering->lower($routine);
		}
	}
