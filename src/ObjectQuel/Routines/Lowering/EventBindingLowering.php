<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventBinding;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\BindingEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect\DDLTypeMapper;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventBindingNaming;

	/**
	 * Lowers a binding to one engine's CREATE TRIGGER (+ helper objects, where the engine
	 * needs one) — see "Event lowering" in objectquel-equel-triggers-design.md. Subclasses
	 * supply the engine's trigger syntax and how it receives OLD/NEW rows.
	 * @phpstan-type ExpandedArgument array{row: 'old'|'new', property: string, column: string}
	 */
	abstract class EventBindingLowering {

		protected EntityStore $entityStore;
		protected PlatformCapabilitiesInterface $platform;
		protected SqlIdentifierQuoter $quoter;
		protected DDLTypeMapper $typeMapper;

		/** Schema that qualifies the table/trigger/routine, or null for none */
		protected ?string $routineSchema;

		/**
		 * @param EntityStore $entityStore Entity metadata
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 * @param string|null $routineSchema Schema that qualifies the table/trigger/routine, or null for none
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform, ?string $routineSchema) {
			$this->entityStore = $entityStore;
			$this->platform = $platform;
			$this->quoter = new SqlIdentifierQuoter($platform);
			$this->typeMapper = new DDLTypeMapper($platform);
			$this->routineSchema = $routineSchema;
		}

		/**
		 * Lowers the binding to target-platform DDL.
		 * @param AstEventBinding $binding Binding that passed EventBindingValidator
		 * @param string $alias The binding's resolved alias (given with `as <alias>`, or generated)
		 * @param int $parameterCount Number of entity-row parameters the called routine declares
		 * @return list<string> Statements to run in order
		 * @throws SemanticException|EntityResolutionException
		 */
		abstract public function render(AstEventBinding $binding, string $alias, int $parameterCount): array;

		/**
		 * Lowers the removal of one binding, identified by its (table, alias) pair —
		 * symmetric with render(), but takes primitives since `destroy trigger` has no call
		 * arguments to carry an AstEventBinding's shape.
		 * @param string $table Physical table the binding is on
		 * @param string $alias The binding's alias
		 * @return list<string> Statements to run in order
		 */
		abstract public function renderDestroy(string $table, string $alias): array;

		/**
		 * Engine name, for error messages.
		 * @return string
		 */
		abstract protected function engineName(): string;

		/**
		 * Expands the event's row bindings into the mapped columns the routine's entity-row
		 * parameters receive, in the fixed order BindingEvent::rowRoles() declares — matching
		 * the routine's own flattened parameter order (RoutineLowering::flattenedParameters()).
		 * A routine declaring fewer entity-row parameters than the event supplies rows binds to
		 * the leading rows only (e.g. one parameter on `replace` binds `old`, never `new`); the
		 * trailing, undeclared rows are left out of the call entirely. Each bound row expands to
		 * every mapped column, in the entity's column-declaration order; there is no per-field
		 * selection, since the binding has no argument list.
		 * @param AstEventBinding $binding The binding
		 * @param int $parameterCount Number of entity-row parameters the called routine declares
		 * @return list<ExpandedArgument>
		 * @throws EntityResolutionException
		 */
		protected function expandArguments(AstEventBinding $binding, int $parameterCount): array {
			$metadata = $this->targetMetadata($binding);
			$rows = array_slice($binding->getEvent()->rowRoles(), 0, $parameterCount);
			$expanded = [];

			foreach ($rows as $row) {
				foreach ($metadata->columnMap as $property => $column) {
					$expanded[] = ['row' => $row, 'property' => $property, 'column' => $column];
				}
			}

			return $expanded;
		}

		/**
		 * Maps a mapped property to its native SQL type.
		 * @param AstEventBinding $binding The binding, for its target entity
		 * @param string $property Mapped property name
		 * @return string SQL type on the target engine
		 */
		protected function columnSqlType(AstEventBinding $binding, string $property): string {
			$metadata = $this->targetMetadata($binding);
			$column = $metadata->columnDefinitions[$metadata->getColumnNameOrFail($property)];

			return $this->typeMapper->getTempTableColumnType([
				'type'      => $column['type'],
				'limit'     => $column['limit'],
				'unsigned'  => $column['unsigned'],
				'precision' => $column['precision'],
				'scale'     => $column['scale'],
				'values'    => $column['values'],
			]);
		}

		/**
		 * @param AstEventBinding $binding The binding
		 * @return EntityMetadataRecord Target entity's metadata
		 * @throws EntityResolutionException
		 */
		protected function targetMetadata(AstEventBinding $binding): EntityMetadataRecord {
			return $this->entityStore->getMetadata($binding->getRange()->getEntityName());
		}

		/**
		 * @param AstEventBinding $binding The binding
		 * @return string Physical table the binding is on
		 * @throws EntityResolutionException
		 */
		protected function physicalTable(AstEventBinding $binding): string {
			return $this->targetMetadata($binding)->tableName;
		}

		/**
		 * @param AstEventBinding $binding The binding
		 * @return string Quoted, schema-qualified table name
		 * @throws EntityResolutionException
		 */
		protected function quotedTable(AstEventBinding $binding): string {
			return $this->quoter->quoteRoutineName($this->physicalTable($binding), $this->routineSchema);
		}

		/**
		 * @param AstEventBinding $binding The binding
		 * @param string $alias The binding's resolved alias
		 * @return string Physical trigger name (see EventBindingNaming)
		 * @throws EntityResolutionException
		 */
		protected function triggerName(AstEventBinding $binding, string $alias): string {
			return EventBindingNaming::triggerName($this->physicalTable($binding), $alias);
		}

		/**
		 * Maps the binding's event to the engine's trigger-event keyword.
		 * @param BindingEvent $event The event
		 * @return string 'INSERT', 'UPDATE' or 'DELETE'
		 */
		protected function sqlEvent(BindingEvent $event): string {
			return match ($event) {
				BindingEvent::Append => 'INSERT',
				BindingEvent::Replace => 'UPDATE',
				BindingEvent::Delete => 'DELETE',
			};
		}
	}
