<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * Compiles the `name(args)` statement for either a function or a procedure.
	 */
	class QuelToSQLCall {

		/** Literal nodes SQL Server's EXEC takes as arguments; it also takes variables and parameters, but no other expression */
		public const array LITERAL_ARGUMENTS = [AstNumber::class, AstString::class, AstBool::class, AstNull::class];

		/** Prefix of the bound parameters that carry arguments evaluated before an EXEC */
		private const string EVALUATED_ARGUMENT_PREFIX = '_call_arg';

		private EntityStore $entityStore;
		private PlatformCapabilitiesInterface $platform;

		/** @var string|null Schema that qualifies routine names, or null for none */
		private ?string $routineSchema;
		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * Initializes the compiler for the target engine and routine schema.
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
		 * Compiles the call as a procedure call or as a one-row SELECT of a function.
		 * @param AstCall $statement The call
		 * @param bool $isProcedure True to call a procedure, false to select a function's value
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @param list<mixed>|null $evaluatedArguments Argument values from argumentsQuery(), bound in place of the arguments; null to compile them
		 * @return string
		 */
		public function convertToSQL(AstCall $statement, bool $isProcedure, array &$parameters, ?array $evaluatedArguments = null): string {
			$call = $statement->getCall();
			$name = $this->identifierQuoter->quoteRoutineName($call->getName(), $this->routineSchema);

			$arguments = $evaluatedArguments === null
				? $this->compileArguments($statement, $parameters)
				: $this->bindEvaluatedArguments($evaluatedArguments, $parameters);

			$arguments = implode(', ', $arguments);

			if (!$isProcedure) {
				return "SELECT {$name}({$arguments}) AS " . $this->identifierQuoter->quoteIdentifier($call->getName());
			}

			return $this->procedureCall($call->getName(), $arguments);
		}

		/**
		 * Checks whether a procedure call must evaluate its arguments first, which SQL Server's EXEC requires for any expression.
		 * @param AstCall $statement The call
		 * @param bool $isProcedure True when the routine is a procedure
		 * @return bool True when argumentsQuery() must run before the call
		 */
		public function requiresEvaluatedArguments(AstCall $statement, bool $isProcedure): bool {
			if (!$isProcedure || $this->platform->getDatabaseType() !== 'sqlsrv') {
				return false;
			}

			foreach ($statement->getCall()->getArguments() as $argument) {
				if (!self::isDirectArgument($argument)) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Compiles a one-row SELECT of every argument, whose values convertToSQL() then binds.
		 * @param AstCall $statement The call
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string
		 */
		public function argumentsQuery(AstCall $statement, array &$parameters): string {
			$columns = [];

			foreach ($this->compileArguments($statement, $parameters) as $index => $sql) {
				$columns[] = "{$sql} AS " . $this->identifierQuoter->quoteIdentifier(self::EVALUATED_ARGUMENT_PREFIX . $index);
			}

			return 'SELECT ' . implode(', ', $columns);
		}

		/**
		 * @param AstInterface $argument A call argument
		 * @return bool True when SQL Server's EXEC takes it as written: a literal or a bound parameter
		 */
		private static function isDirectArgument(AstInterface $argument): bool {
			return $argument instanceof AstParameter || in_array(get_class($argument), self::LITERAL_ARGUMENTS, true);
		}

		/**
		 * Renders the procedure call using the target dialect's syntax.
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
		 * Compiles the call's arguments to SQL; a predicate becomes a value.
		 * @param AstCall $statement The call
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return list<string> SQL of each argument
		 */
		private function compileArguments(AstCall $statement, array &$parameters): array {
			$builder = new BuildSqlFromAst($this->entityStore, $parameters, 'VALUES', $this->platform, $this->routineSchema);
			return array_map(fn(AstInterface $argument) => $builder->visitValueAndReturnSQL($argument), $statement->getCall()->getArguments());
		}

		/**
		 * Binds evaluated argument values as parameters.
		 * @param list<mixed> $values Argument values, in call order
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return list<string> Placeholder of each argument
		 */
		private function bindEvaluatedArguments(array $values, array &$parameters): array {
			$placeholders = [];

			foreach ($values as $index => $value) {
				$parameters[self::EVALUATED_ARGUMENT_PREFIX . $index] = $value;
				$placeholders[] = ':' . self::EVALUATED_ARGUMENT_PREFIX . $index;
			}

			return $placeholders;
		}
	}