<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\RoutineSignature;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NativeColumnTypeMapper;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Reads routine kinds and return types from the connected database catalog.
	 */
	class RoutineDefinitionInspector {

		/**
		 * Creates an inspector for the connected database.
		 * @param DatabaseAdapter $connection Connection whose routine catalog is read
		 * @return void
		 */
		public function __construct(private readonly DatabaseAdapter $connection) {
		}

		/**
		 * Looks up a routine in the catalog.
		 * @param string $name Routine name as written
		 * @return RoutineSignature
		 * @throws QuelException When no routine or more than one kind of routine has the name, or the lookup fails
		 */
		public function getRoutineSignature(string $name): RoutineSignature {
			[$sql, $parameters] = $this->signatureQuery($name);
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to look up routine '{$name}': {$this->connection->getLastErrorMessage()}", 'routine_call_error');
			}

			$kinds = [];
			$returnTypes = [];

			foreach ($result->fetchAll('assoc') as $row) {
				$isProcedure = (int)$row['is_procedure'] === 1;
				$kinds[(int)$isProcedure] = true;

				if (!$isProcedure) {
					$returnTypes[] = self::returnType(
						$this->connection->getDatabaseType(),
						(string)$row['data_type'],
						$row['type_detail'] === null ? null : (string)$row['type_detail'],
						$row['max_length'] === null ? null : (int)$row['max_length']
					);
				}
			}

			if ($kinds === []) {
				throw new QuelException("Can't call '{$name}': no routine by that name exists.", 'routine_call_error');
			}

			if (count($kinds) > 1) {
				throw new QuelException("Can't call '{$name}': both a function and a procedure have that name.", 'routine_call_error');
			}

			// PostgreSQL overloads that return different types leave the type unknown
			$distinctTypes = array_unique($returnTypes);
			return new RoutineSignature(isset($kinds[1]), count($distinctTypes) === 1 ? $distinctTypes[0] : null);
		}

		/**
		 * Checks whether any function or procedure has this name.
		 * @param string $name Routine name as written
		 * @return bool True when at least one routine exists
		 * @throws QuelException When the lookup fails or the engine has no stored routines
		 */
		public function routineExists(string $name): bool {
			[$sql, $parameters] = $this->existenceQuery($name);
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to look up routine '{$name}': {$this->connection->getLastErrorMessage()}", 'routine_destruction_error');
			}

			$row = $result->fetch('assoc');
			return is_array($row) && is_numeric($row['routine_count'] ?? null) && (int)$row['routine_count'] > 0;
		}

		/**
		 * Builds the catalog query for a routine. Each row has `is_procedure` (1 or 0) and, for a function,
		 * its return type as `data_type`, `type_detail` and `max_length`; no rows means no routine by that name.
		 * @param string $name Routine name as written
		 * @return array{string, array<string, string>} SQL and its parameters
		 * @throws QuelException When the engine has no stored routines
		 */
		private function signatureQuery(string $name): array {
			return match ($this->connection->getDatabaseType()) {
				// Routine return types carry no type modifier, so format_type() gets none
				'pgsql' => [
					"SELECT DISTINCT CASE WHEN prokind = 'p' THEN 1 ELSE 0 END AS is_procedure, format_type(prorettype, NULL) AS data_type, NULL AS type_detail, NULL AS max_length FROM pg_proc WHERE proname = :name AND pg_function_is_visible(oid)",
					['name' => $name],
				],

				// Procedures and scalar functions, native or CLR; a scalar function's return value is parameter 0
				'sqlsrv' => [
					"SELECT CASE WHEN o.type IN ('P', 'PC') THEN 1 ELSE 0 END AS is_procedure, TYPE_NAME(p.system_type_id) AS data_type, NULL AS type_detail, p.max_length AS max_length FROM sys.objects o LEFT JOIN sys.parameters p ON p.object_id = o.object_id AND p.parameter_id = 0 WHERE o.object_id = OBJECT_ID(:name) AND o.type IN ('P', 'PC', 'FN', 'FS')",
					['name' => $this->sqlServerRoutineName($name)],
				],

				// Functions and procedures have separate namespaces, so both can match
				'mysql', 'mariadb' => [
					"SELECT CASE WHEN ROUTINE_TYPE = 'PROCEDURE' THEN 1 ELSE 0 END AS is_procedure, DATA_TYPE AS data_type, DTD_IDENTIFIER AS type_detail, CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = :name",
					['name' => $name],
				],

				default => throw new QuelException("Routines can't be called on '{$this->connection->getDatabaseType()}'.", 'routine_call_error'),
			};
		}

		/**
		 * Builds a catalog query that counts functions and procedures without applying call-signature ambiguity rules.
		 * @param string $name Routine name as written
		 * @return array{string, array<string, string>} SQL and its parameters
		 * @throws QuelException When the engine has no stored routines
		 */
		private function existenceQuery(string $name): array {
			return match ($this->connection->getDatabaseType()) {
				'pgsql' => [
					'SELECT COUNT(*) AS routine_count FROM pg_proc WHERE proname = :name AND pg_function_is_visible(oid)',
					['name' => $name],
				],

				'sqlsrv' => [
					"SELECT COUNT(*) AS routine_count FROM sys.objects WHERE object_id = OBJECT_ID(:name) AND type IN ('P', 'PC', 'FN', 'FS', 'IF', 'TF', 'FT')",
					['name' => $this->sqlServerRoutineName($name)],
				],

				'mysql', 'mariadb' => [
					'SELECT COUNT(*) AS routine_count FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = :name',
					['name' => $name],
				],

				default => throw new QuelException("Routines can't be looked up on '{$this->connection->getDatabaseType()}'.", 'routine_destruction_error'),
			};
		}

		/**
		 * Qualifies the SQL Server lookup exactly as routine calls are qualified.
		 * @param string $name Unquoted routine name
		 * @return string Quoted name qualified by the connection's default schema
		 */
		private function sqlServerRoutineName(string $name): string {
			$quote = static fn(string $identifier): string => '[' . str_replace(']', ']]', $identifier) . ']';
			$schema = $this->connection->getRoutineSchema();
			return $schema === null ? $quote($name) : $quote($schema) . '.' . $quote($name);
		}

		/**
		 * Maps a function's catalog return type to an abstract column type.
		 * @param string $databaseType Connected engine
		 * @param string $dataType Engine type name, e.g. 'int' or 'timestamp without time zone'
		 * @param string|null $typeDetail MySQL/MariaDB DTD_IDENTIFIER, e.g. 'tinyint(1)'; null elsewhere
		 * @param int|null $maxLength Character length (MySQL/MariaDB) or byte length, -1 for MAX (SQL Server)
		 * @return string|null Abstract column type, or null for a type ObjectQuel doesn't create
		 */
		public static function returnType(string $databaseType, string $dataType, ?string $typeDetail, ?int $maxLength): ?string {
			try {
				$type = match ($databaseType) {
					'mysql', 'mariadb' => NativeColumnTypeMapper::mysqlType($dataType, $typeDetail ?? $dataType, $maxLength),
					'pgsql' => NativeColumnTypeMapper::postgresType($dataType),
					'sqlsrv' => NativeColumnTypeMapper::sqlServerType($dataType, $maxLength),
					default => null,
				};
			} catch (\RuntimeException) {
				// A routine defined outside ObjectQuel may return any engine type
				return null;
			}

			// An enum's PHP class isn't in the catalog, so its value stays a string
			return $type === 'enum' ? 'string' : $type;
		}
	}
