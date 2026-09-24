<?php

	namespace Quellabs\ObjectQuel\Execution\Helpers;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NativeColumnTypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLCall;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

	/**
	 * Reads a routine's kind and return type from the database catalog, so calls can be typed without a local registry.
	 */
	class RoutineCatalog {

		private DatabaseAdapter $connection;
		private EntityStore $entityStore;
		private PlatformCapabilitiesInterface $platform;
		private ?QuelToSQLCall $compiler = null;

		/**
		 * @param DatabaseAdapter $connection Connection whose catalog is read
		 * @param EntityStore $entityStore Needed by the call compiler
		 * @param PlatformCapabilitiesInterface $platform Connected engine
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->platform = $platform;
		}

		/**
		 * Looks up a routine in the catalog.
		 * @param string $name Routine name as written
		 * @return RoutineSignature
		 * @throws QuelException When no routine or more than one kind of routine has the name, or the lookup fails
		 */
		public function signature(string $name): RoutineSignature {
			$this->compiler ??= new QuelToSQLCall($this->entityStore, $this->platform, $this->connection->getRoutineSchema());
			[$sql, $parameters] = $this->compiler->signatureQuery($name);
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
						$this->platform->getDatabaseType(),
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
		 * Sets the return type of every routine call in a query, looking each routine up once.
		 * @param AstInterface $query Query whose calls are typed in place
		 * @return void
		 * @throws QuelException When a routine is missing or ambiguous, is a procedure, or the lookup fails
		 */
		public function typeCalls(AstInterface $query): void {
			$collector = new CollectNodes(AstRoutineCall::class);
			$query->accept($collector);

			/** @var array<string, RoutineSignature> $signatures */
			$signatures = [];

			foreach ($collector->getCollectedNodes() as $call) {
				$name = $call->getName();
				$signatures[$name] ??= $this->signature($name);

				if ($signatures[$name]->isProcedure) {
					throw new QuelException("'{$name}' is a procedure, which returns no value; only a function can be called inside a query.", 'routine_call_error');
				}

				$call->setRoutineReturnType($signatures[$name]->returnType);
			}
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
