<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\RoutineSignature;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NativeColumnTypeMapper;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Reads routine kinds and return types from the connected database catalog.
	 * @phpstan-import-type RoutineParameter from DatabaseAdapter
	 * @phpstan-import-type RoutineListEntry from DatabaseAdapter
	 */
	readonly class RoutineDefinitionInspector {

		/** @var DatabaseAdapter Database connection */
		private DatabaseAdapter $connection;

		/**
		 * Creates an inspector for the connected database.
		 * @param DatabaseAdapter $connection Connection whose routine catalog is read
		 * @return void
		 */
		public function __construct(DatabaseAdapter $connection) {
			$this->connection = $connection;
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
			$needsTransaction = false;
			$isTrigger = false;
			$databaseType = $this->connection->getDatabaseType();

			foreach ($result->fetchAll('assoc') as $row) {
				$isProcedure = (int)$row['is_procedure'] === 1;
				$kinds[(int)$isProcedure] = true;
				$comment = $row['routine_comment'] ?? null;

				// The savepoint-based caller transaction is a MySQL/MariaDB-only mechanism (see
				// MysqlRoutineLowering); Postgres/SQL Server handle 'atomic' entirely inside the
				// routine body, so an 'atomic' metadata flag there implies no caller obligation.
				if (in_array($databaseType, ['mysql', 'mariadb'], true)) {
					$needsTransaction = $needsTransaction || self::isAtomic($comment);
				}

				$isTrigger = $isTrigger || self::isTriggerMetadata($comment);

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
				throw new QuelException("Can't call '{$name}': both a void and a value-returning function have that name.", 'routine_call_error');
			}

			// PostgreSQL overloads that return different types leave the type unknown
			$distinctTypes = array_unique($returnTypes);
			return new RoutineSignature(isset($kinds[1]), count($distinctTypes) === 1 ? $distinctTypes[0] : null, $needsTransaction, $isTrigger);
		}

		/**
		 * Reads a routine's full versioned JSON metadata (RoutineMetadata), for binding
		 * validation: matching the called routine's row-parameter types and walking its safety
		 * graph needs the whole document, not just the isTrigger/needsTransaction flags
		 * getRoutineSignature() exposes.
		 * @param string $name Routine name as written
		 * @return array<string, mixed> Decoded metadata
		 * @throws QuelException When the routine is missing/ambiguous, its metadata is missing,
		 *         unreadable, malformed, or from an unsupported version, or the lookup fails
		 */
		public function getRoutineMetadata(string $name): array {
			[$sql, $parameters] = $this->signatureQuery($name);
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to look up routine '{$name}': {$this->connection->getLastErrorMessage()}", 'routine_call_error');
			}

			$rows = $result->fetchAll('assoc');

			// Binding validation needs one unambiguous routine, not merely a matching name.
			if ($rows === []) {
				throw new QuelException("Can't bind to '{$name}': no routine by that name exists.", 'routine_call_error');
			}

			if (count($rows) > 1) {
				throw new QuelException("Can't bind to '{$name}': both a void and a value-returning function have that name.", 'routine_call_error');
			}

			// Row-parameter types and the safety graph exist only in ObjectQuel's metadata.
			$comment = $rows[0]['routine_comment'] ?? null;

			if ($comment === null || $comment === '') {
				throw new QuelException("Can't bind to '{$name}': it has no ObjectQuel metadata. Redefine it with the current ObjectQuel version.", 'routine_definition_error');
			}

			// Decode the JSON
			$decoded = json_decode($comment, true);

			if (!is_array($decoded) || !isset($decoded['objectQuel'])) {
				throw new QuelException("Can't bind to '{$name}': its metadata is missing or unreadable.", 'routine_definition_error');
			}

			// The validator can only interpret the metadata format it was built for.
			if ($decoded['objectQuel'] !== 1) {
				throw new QuelException("Can't bind to '{$name}': its metadata is from an unsupported ObjectQuel version.", 'routine_definition_error');
			}

			// Fetch the metadata
			$metadata = [];

			foreach ($decoded as $field => $value) {
				$metadata[(string)$field] = $value;
			}

			return $metadata;
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
		 * Lists every ObjectQuel-managed function and procedure in the connected schema: one
		 * carrying recognizable, current-version metadata (RoutineMetadata). A routine without
		 * it — created outside ObjectQuel, or deployed before metadata existed — is not listed,
		 * since its EQUEL-level return type and row-parameter shape can't be recovered. Return
		 * type comes from the metadata itself (`void`, `trigger` for an `isTrigger` routine, or a
		 * scalar type), not the native catalog; parameter types are still normalized to ObjectQuel's abstract column
		 * types from the native catalog. A name can appear twice (once as a function, once as a
		 * procedure) since MySQL/MariaDB give the two kinds separate namespaces.
		 * @return list<RoutineListEntry>
		 * @throws QuelException When the lookup fails or the engine has no stored routines
		 */
		public function listRoutines(): array {
			[$sql, $parameters] = $this->listQuery();
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to list routines: {$this->connection->getLastErrorMessage()}", 'routine_call_error');
			}

			$grouped = [];

			foreach ($result->fetchAll('assoc') as $row) {
				$metadata = self::decodeManagedMetadata($row['routine_comment'] ?? null);

				if ($metadata === null) {
					continue;
				}

				$isProcedure = (int)$row['is_procedure'] === 1;
				$key = $row['name'] . '|' . (int)$isProcedure;
				$returnType = $metadata['returnType'] ?? null;
				$displayReturnType = (bool)($metadata['isTrigger'] ?? false) ? 'trigger' : $returnType;

				$grouped[$key] = [
					'name'        => (string)$row['name'],
					'isProcedure' => $isProcedure,
					'returnType'  => is_string($displayReturnType) ? $displayReturnType : 'unknown',
				];
			}

			$parametersByRoutine = $this->listParameters();
			$routines = [];

			foreach ($grouped as $key => $group) {
				$routines[] = $group + ['parameters' => $parametersByRoutine[$key] ?? []];
			}

			usort($routines, static fn(array $a, array $b): int => $a['name'] <=> $b['name'] ?: $a['isProcedure'] <=> $b['isProcedure']);

			return $routines;
		}

		/**
		 * Reads every routine's parameter list, keyed the same way listRoutines() groups
		 * routines ("name|0" for a function, "name|1" for a procedure), each ordered by
		 * declaration position. Parameter types go through the same native-to-abstract
		 * mapping as return types.
		 * @return array<string, list<RoutineParameter>>
		 * @throws QuelException When the lookup fails
		 */
		private function listParameters(): array {
			[$sql, $parameters] = $this->parameterQuery();
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to list routine parameters: {$this->connection->getLastErrorMessage()}", 'routine_call_error');
			}

			$byRoutine = [];

			$databaseType = $this->connection->getDatabaseType();

			foreach ($result->fetchAll('assoc') as $row) {
				$isProcedure = (int)$row['is_procedure'] === 1;
				$key = $row['name'] . '|' . (int)$isProcedure;
				$byRoutine[$key][] = [
					'name' => self::stripParameterDecoration($databaseType, (string)$row['param_name']),
					'type' => self::returnType(
						$databaseType,
						(string)$row['data_type'],
						$row['type_detail'] === null ? null : (string)$row['type_detail'],
						$row['max_length'] === null ? null : (int)$row['max_length']
					),
				];
			}

			return $byRoutine;
		}

		/**
		 * Strips the dialect-specific decoration EQUEL's routine lowering adds to a parameter
		 * name, so the listing shows the name exactly as the `define function` source wrote
		 * it. MySQL/MariaDB prefix every local and parameter with `_v_` to keep it from
		 * shadowing a same-named column (see RoutineReferenceSql::MYSQL_VARIABLE_PREFIX);
		 * SQL Server requires the `@` sigil on every parameter, which the catalog then stores
		 * verbatim. PostgreSQL keeps the name exactly as declared, aside from its own
		 * unrelated case-folding of unquoted identifiers, which is left alone here.
		 * @param string $databaseType Connected engine
		 * @param string $name Catalog parameter name
		 * @return string
		 */
		private static function stripParameterDecoration(string $databaseType, string $name): string {
			return match ($databaseType) {
				'mysql', 'mariadb' => str_starts_with($name, '_v_') ? substr($name, 3) : $name,
				'sqlsrv' => ltrim($name, '@'),
				default => $name,
			};
		}

		/**
		 * Builds the catalog query listing every routine's name, kind and return-type columns
		 * for the connected schema. Only `name`, `is_procedure` and `routine_comment` are read:
		 * listRoutines() takes return type from the metadata, not the native catalog.
		 * @return array{string, array<string, string>} SQL and its parameters
		 * @throws QuelException When the engine has no stored routines
		 */
		private function listQuery(): array {
			return match ($this->connection->getDatabaseType()) {
				// obj_description() reads the ObjectQuel metadata written by COMMENT ON FUNCTION/PROCEDURE (RoutineMetadata)
				'pgsql' => [
					"SELECT p.proname AS name, CASE WHEN p.prokind = 'p' THEN 1 ELSE 0 END AS is_procedure, obj_description(p.oid, 'pg_proc') AS routine_comment FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() ORDER BY p.proname",
					[],
				],

				// The extended property reads the ObjectQuel metadata written by sp_addextendedproperty (RoutineMetadata)
				'sqlsrv' => [
					"SELECT o.name AS name, CASE WHEN o.type IN ('P', 'PC') THEN 1 ELSE 0 END AS is_procedure, CAST(ep.value AS NVARCHAR(MAX)) AS routine_comment FROM sys.objects o JOIN sys.schemas s ON s.schema_id = o.schema_id LEFT JOIN sys.extended_properties ep ON ep.major_id = o.object_id AND ep.minor_id = 0 AND ep.name = N'ObjectQuel_Metadata' WHERE s.name = :schema AND o.type IN ('P', 'PC', 'FN', 'FS') ORDER BY o.name",
					['schema' => (string)$this->connection->getRoutineSchema()],
				],

				'mysql', 'mariadb' => [
					"SELECT ROUTINE_NAME AS name, CASE WHEN ROUTINE_TYPE = 'PROCEDURE' THEN 1 ELSE 0 END AS is_procedure, ROUTINE_COMMENT AS routine_comment FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_NAME",
					[],
				],

				default => throw new QuelException("Routines can't be listed on '{$this->connection->getDatabaseType()}'.", 'routine_call_error'),
			};
		}

		/**
		 * Builds the catalog query listing every routine's parameters, ordered by declaration
		 * position, for the connected schema. Only IN parameters exist here — EQUEL's own
		 * `define function` syntax has no OUT/INOUT mode, so a routine it deployed never has
		 * one; a routine created outside EQUEL with one is out of scope, same as an
		 * unrecognized return type. MySQL/MariaDB's ordinal 0 (a function's own return slot)
		 * and SQL Server's parameter_id 0 (likewise) are excluded, leaving only real parameters.
		 * @return array{string, array<string, string>} SQL and its parameters
		 * @throws QuelException When the engine has no stored routines
		 */
		private function parameterQuery(): array {
			return match ($this->connection->getDatabaseType()) {
				// proargtypes lists only IN parameter types, in order; proargnames is
				// parallel to it as long as the routine has no OUT/INOUT parameter
				'pgsql' => [
					"SELECT p.proname AS name, CASE WHEN p.prokind = 'p' THEN 1 ELSE 0 END AS is_procedure, COALESCE(p.proargnames[t.ord], '') AS param_name, format_type(t.type_oid, NULL) AS data_type, NULL AS type_detail, NULL AS max_length FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace CROSS JOIN LATERAL unnest(p.proargtypes::oid[]) WITH ORDINALITY AS t(type_oid, ord) WHERE n.nspname = current_schema() ORDER BY p.proname, t.ord",
					[],
				],

				'sqlsrv' => [
					"SELECT o.name AS name, CASE WHEN o.type IN ('P', 'PC') THEN 1 ELSE 0 END AS is_procedure, p.name AS param_name, TYPE_NAME(p.system_type_id) AS data_type, NULL AS type_detail, p.max_length AS max_length FROM sys.objects o JOIN sys.schemas s ON s.schema_id = o.schema_id JOIN sys.parameters p ON p.object_id = o.object_id AND p.parameter_id > 0 WHERE s.name = :schema AND o.type IN ('P', 'PC', 'FN', 'FS') ORDER BY o.name, p.parameter_id",
					['schema' => (string)$this->connection->getRoutineSchema()],
				],

				'mysql', 'mariadb' => [
					"SELECT SPECIFIC_NAME AS name, CASE WHEN ROUTINE_TYPE = 'PROCEDURE' THEN 1 ELSE 0 END AS is_procedure, PARAMETER_NAME AS param_name, DATA_TYPE AS data_type, DTD_IDENTIFIER AS type_detail, CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA = DATABASE() AND ORDINAL_POSITION > 0 ORDER BY SPECIFIC_NAME, ORDINAL_POSITION",
					[],
				],

				default => throw new QuelException("Routines can't be listed on '{$this->connection->getDatabaseType()}'.", 'routine_call_error'),
			};
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
				// Routine return types carry no type modifier, so format_type() gets none.
				// obj_description() reads the ObjectQuel metadata written by COMMENT ON PROCEDURE (RoutineMetadata).
				'pgsql' => [
					"SELECT DISTINCT CASE WHEN p.prokind = 'p' THEN 1 ELSE 0 END AS is_procedure, format_type(p.prorettype, NULL) AS data_type, NULL AS type_detail, NULL AS max_length, obj_description(p.oid, 'pg_proc') AS routine_comment FROM pg_proc p JOIN pg_namespace s ON s.oid = p.pronamespace WHERE p.proname = :name AND s.nspname = current_schema()",
					['name' => $name],
				],

				// Procedures and scalar functions, native or CLR; a scalar function's return value is parameter 0.
				// The extended property reads the ObjectQuel metadata written by sp_addextendedproperty (RoutineMetadata).
				'sqlsrv' => [
					"SELECT CASE WHEN o.type IN ('P', 'PC') THEN 1 ELSE 0 END AS is_procedure, TYPE_NAME(p.system_type_id) AS data_type, NULL AS type_detail, p.max_length AS max_length, CAST(ep.value AS NVARCHAR(MAX)) AS routine_comment FROM sys.objects o LEFT JOIN sys.parameters p ON p.object_id = o.object_id AND p.parameter_id = 0 LEFT JOIN sys.extended_properties ep ON ep.major_id = o.object_id AND ep.minor_id = 0 AND ep.name = N'ObjectQuel_Metadata' WHERE o.object_id = OBJECT_ID(:name) AND o.type IN ('P', 'PC', 'FN', 'FS')",
					['name' => $this->sqlServerRoutineName($name)],
				],

				// Functions and procedures have separate namespaces, so both can match
				'mysql', 'mariadb' => [
					"SELECT CASE WHEN ROUTINE_TYPE = 'PROCEDURE' THEN 1 ELSE 0 END AS is_procedure, DATA_TYPE AS data_type, DTD_IDENTIFIER AS type_detail, CHARACTER_MAXIMUM_LENGTH AS max_length, ROUTINE_COMMENT AS routine_comment FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = :name",
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
					'SELECT COUNT(*) AS routine_count FROM pg_proc p JOIN pg_namespace s ON s.oid = p.pronamespace WHERE p.proname = :name AND s.nspname = current_schema()',
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

		/**
		 * Reads whether a routine's comment marks it as containing an `atomic` block, requiring
		 * the caller to wrap its call in a transaction (MySQL/MariaDB only — see
		 * MysqlRoutineLowering). Recognizes the current versioned JSON metadata (RoutineMetadata)
		 * and falls back to the legacy `ObjectQuel:atomic-block` sentinel for a routine deployed
		 * before that metadata existed.
		 * @param string|null $comment MySQL/MariaDB ROUTINE_COMMENT
		 * @return bool
		 */
		private static function isAtomic(?string $comment): bool {
			if ($comment === null || $comment === '') {
				return false;
			}

			$decoded = json_decode($comment, true);

			if (is_array($decoded) && isset($decoded['objectQuel'])) {
				return (bool)($decoded['atomic'] ?? false);
			}

			return $comment === 'ObjectQuel:atomic-block';
		}

		/**
		 * Reads whether a routine's comment/extended property marks it as declared with
		 * `tfunction` (RoutineMetadata), on any of the three engines. There is no legacy
		 * sentinel for this: `tfunction` did not exist before this metadata did, so a routine
		 * without recognizable ObjectQuel metadata is never one.
		 * @param string|null $comment Routine comment (MySQL/MariaDB) or extended property value (Postgres/SQL Server)
		 * @return bool
		 */
		private static function isTriggerMetadata(?string $comment): bool {
			if ($comment === null || $comment === '') {
				return false;
			}

			$decoded = json_decode($comment, true);

			return is_array($decoded) && isset($decoded['objectQuel']) && (bool)($decoded['isTrigger'] ?? false);
		}

		/**
		 * Decodes a routine's comment/extended property as current-version ObjectQuel metadata
		 * (RoutineMetadata), for listRoutines() to tell an ObjectQuel-managed routine from one
		 * created outside ObjectQuel. Unlike getRoutineMetadata(), a bad comment here is not an
		 * error: the caller just excludes the routine from the listing.
		 * @param string|null $comment Routine comment (MySQL/MariaDB) or extended property value (Postgres/SQL Server)
		 * @return array<string, mixed>|null Decoded metadata, or null when it isn't valid current-version ObjectQuel metadata
		 */
		private static function decodeManagedMetadata(?string $comment): ?array {
			if ($comment === null || $comment === '') {
				return null;
			}

			$decoded = json_decode($comment, true);

			if (!is_array($decoded) || ($decoded['objectQuel'] ?? null) !== 1) {
				return null;
			}

			return $decoded;
		}
	}
