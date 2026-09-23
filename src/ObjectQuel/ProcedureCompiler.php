<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;

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
		 * @return string The CREATE statement
		 * @throws LexerException|ParserException|\ReflectionException
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		public function compile(string $source): string {
			$entityStore = $this->entityManager->getEntityStore();
			$routine = (new ProcedureParser(new Lexer($source), $entityStore))->parse();
			(new RoutineAnalyzer($entityStore))->analyze($routine);

			return $this->lower($routine);
		}

		/**
		 * @param AstRoutineDefinition $routine Routine that passed RoutineAnalyzer
		 * @return string The CREATE statement
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		public function lower(AstRoutineDefinition $routine): string {
			$statements = new RoutineStatementCompiler($this->entityManager, $this->platform);

			return match ($this->platform->getDatabaseType()) {
				'pgsql' => (new PostgresRoutineLowering($this->entityManager->getEntityStore(), $statements, $this->platform))->lower($routine),
				default => throw new QuelException("Routines can't be compiled for '{$this->platform->getDatabaseType()}' yet."),
			};
		}
	}
