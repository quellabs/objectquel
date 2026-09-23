<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineAnalyzer;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineStatementCompiler;

	/**
	 * Compiles EQUEL routine source (`define function ...`) to the target
	 * engine's CREATE FUNCTION/PROCEDURE statement. The target need not be the
	 * connected engine; routines compile ahead of time.
	 */
	class ProcedureCompiler {

		private EntityManager $entityManager;
		private PlatformCapabilitiesInterface $platform;

		/**
		 * @param EntityManager $entityManager Entity metadata and query pipeline dependencies
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilitiesInterface $platform) {
			$this->entityManager = $entityManager;
			$this->platform = $platform;
		}

		/**
		 * Parses, analyzes and lowers one routine.
		 * @param string $source Routine source containing one `define function`
		 * @return list<string> Statements to run in order, the last one creating the routine
		 * @throws LexerException|ParserException|\ReflectionException
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		public function compile(string $source): array {
			$entityStore = $this->entityManager->getEntityStore();
			$routine = (new ProcedureParser(new Lexer($source), $entityStore))->parse();

			return $this->compileRoutine($routine);
		}

		/**
		 * Analyzes and lowers an already parsed routine.
		 * @param AstRoutineDefinition $routine Routine from ProcedureParser
		 * @return list<string> Statements to run in order, the last one creating the routine
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		public function compileRoutine(AstRoutineDefinition $routine): array {
			(new RoutineAnalyzer($this->entityManager->getEntityStore()))->analyze($routine);

			return $this->lower($routine);
		}

		/**
		 * @param AstRoutineDefinition $routine Routine that passed RoutineAnalyzer
		 * @return list<string> Statements to run in order, the last one creating the routine
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		public function lower(AstRoutineDefinition $routine): array {
			$entityStore = $this->entityManager->getEntityStore();
			$statements = new RoutineStatementCompiler($this->entityManager, $this->platform);

			$lowering = match ($this->platform->getDatabaseType()) {
				'pgsql' => new PostgresRoutineLowering($entityStore, $statements),
				'sqlsrv' => new SqlServerRoutineLowering($entityStore, $statements),
				'mysql', 'mariadb' => new MysqlRoutineLowering($entityStore, $statements),
				default => throw new QuelException("Routines can't be compiled for '{$this->platform->getDatabaseType()}'."),
			};

			return $lowering->lower($routine);
		}
	}
