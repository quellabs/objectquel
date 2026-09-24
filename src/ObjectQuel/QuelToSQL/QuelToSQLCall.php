<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;

	/**
	 * Compiles the `name(args)` statement: the catalog query for a routine's kind and return type,
	 * and the statement that runs either kind.
	 */
	class QuelToSQLCall {

		/** Literal nodes SQL Server's EXEC accepts as arguments; the rule holds on every engine so calls stay portable */
		public const array LITERAL_ARGUMENTS = [AstNumber::class, AstString::class, AstBool::class, AstNull::class];

		private EntityStore $entityStore;
		private PlatformCapabilitiesInterface $platform;

		/** @var string|null Schema that qualifies routine names, or null for none */
		private ?string $routineSchema;
		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * @param EntityStore $entityStore Needed by the SQL builder for literals
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 * @param string|null $routineSchema Schema that qualifies routine names, or null for none
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform, ?string $routineSchema) {
			$this->entityStore = $entityStore;
			$this->platform = $platform;
			$this->routineSchema = $routineSchema;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
		}

		/**
		 * Builds the catalog query for a routine. Each row has `is_procedure` (1 or 0) and, for a function,
		 * its return type as `data_type`, `type_detail` and `max_length`; no rows means no routine by that name.
		 * @param string $name Routine name as written
		 * @return array{string, array<string, string>} SQL and its parameters
		 * @throws QuelException When the engine has no stored routines
		 */
		public function signatureQuery(string $name): array {
			return match ($this->platform->getDatabaseType()) {
				// Routine return types carry no type modifier, so format_type() gets none
				'pgsql' => [
					"SELECT DISTINCT CASE WHEN prokind = 'p' THEN 1 ELSE 0 END AS is_procedure, format_type(prorettype, NULL) AS data_type, NULL AS type_detail, NULL AS max_length FROM pg_proc WHERE proname = :name AND pg_function_is_visible(oid)",
					['name' => $name],
				],

				// Procedures and scalar functions, native or CLR; a scalar function's return value is parameter 0
				'sqlsrv' => [
					"SELECT CASE WHEN o.type IN ('P', 'PC') THEN 1 ELSE 0 END AS is_procedure, TYPE_NAME(p.system_type_id) AS data_type, NULL AS type_detail, p.max_length AS max_length FROM sys.objects o LEFT JOIN sys.parameters p ON p.object_id = o.object_id AND p.parameter_id = 0 WHERE o.object_id = OBJECT_ID(:name) AND o.type IN ('P', 'PC', 'FN', 'FS')",
					['name' => $this->identifierQuoter->quoteRoutineName($name, $this->routineSchema)],
				],

				// Functions and procedures have separate namespaces, so both can match
				'mysql', 'mariadb' => [
					"SELECT CASE WHEN ROUTINE_TYPE = 'PROCEDURE' THEN 1 ELSE 0 END AS is_procedure, DATA_TYPE AS data_type, DTD_IDENTIFIER AS type_detail, CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = :name",
					['name' => $name],
				],

				default => throw new QuelException("Routines can't be called on '{$this->platform->getDatabaseType()}'.", 'routine_call_error'),
			};
		}

		/**
		 * Compiles the call as a procedure call or as a one-row SELECT of a function.
		 * @param AstCall $statement The call
		 * @param bool $isProcedure True to call a procedure, false to select a function's value
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string
		 * @throws SemanticException When an argument isn't a literal or a parameter
		 */
		public function convertToSQL(AstCall $statement, bool $isProcedure, array &$parameters): string {
			$call = $statement->getCall();
			$name = $this->identifierQuoter->quoteRoutineName($call->getName(), $this->routineSchema);
			$arguments = implode(', ', $this->compileArguments($statement, $parameters));

			if (!$isProcedure) {
				return "SELECT {$name}({$arguments}) AS " . $this->identifierQuoter->quoteIdentifier($call->getName());
			}

			return $this->procedureCall($call->getName(), $arguments);
		}

		/**
		 * @param string $name Procedure name as written in the source
		 * @param string $arguments Compiled, comma-separated arguments
		 * @return string `CALL name(args)`, or `EXEC name args` on SQL Server
		 */
		public function procedureCall(string $name, string $arguments): string {
			$name = $this->identifierQuoter->quoteRoutineName($name, $this->routineSchema);

			if ($this->platform->getDatabaseType() === 'sqlsrv') {
				return "EXEC {$name}" . ($arguments === '' ? '' : " {$arguments}");
			}

			return "CALL {$name}({$arguments})";
		}

		/**
		 * @param AstCall $statement The call
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return list<string> SQL of each argument
		 * @throws SemanticException When an argument isn't a literal or a parameter
		 */
		private function compileArguments(AstCall $statement, array &$parameters): array {
			$call = $statement->getCall();
			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform, $this->routineSchema);
			$result = [];

			foreach ($call->getArguments() as $argument) {
				if (!$argument instanceof AstParameter && !in_array(get_class($argument), self::LITERAL_ARGUMENTS, true)) {
					throw new SemanticException("The arguments of '{$call->getName()}()' must be literals or parameters; compute other values before the call.");
				}

				$result[] = $builder->visitNodeAndReturnSQL($argument);
			}

			return $result;
		}
	}
