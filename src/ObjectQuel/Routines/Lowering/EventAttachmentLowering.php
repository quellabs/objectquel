<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AttachmentEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect\DDLTypeMapper;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentNaming;

	/**
	 * Lowers an attachment to one engine's CREATE TRIGGER (+ helper objects, where the engine
	 * needs one) — see "Event lowering" in objectquel-equel-triggers-design.md. Subclasses
	 * supply the engine's trigger syntax and how it receives OLD/NEW rows.
	 */
	abstract class EventAttachmentLowering {

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
		 * Lowers the attachment to target-platform DDL.
		 * @param AstEventAttachment $attachment Attachment that passed EventAttachmentValidator
		 * @param string $alias The attachment's resolved alias (given with `as <alias>`, or generated)
		 * @param int $parameterCount Number of entity-row parameters the called routine declares
		 * @return list<string> Statements to run in order
		 * @throws SemanticException|EntityResolutionException
		 */
		abstract public function render(AstEventAttachment $attachment, string $alias, int $parameterCount): array;

		/**
		 * Lowers the removal of one attachment, identified by its (table, alias) pair —
		 * symmetric with render(), but takes primitives since `destroy trigger` has no call
		 * arguments to carry an AstEventAttachment's shape.
		 * @param string $table Physical table the attachment is on
		 * @param string $alias The attachment's alias
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
		 * parameters receive, in the fixed order AttachmentEvent::rowRoles() declares — matching
		 * the routine's own flattened parameter order (RoutineLowering::flattenedParameters()).
		 * A routine declaring fewer entity-row parameters than the event supplies rows binds to
		 * the leading rows only (e.g. one parameter on `replace` binds `old`, never `new`); the
		 * trailing, undeclared rows are left out of the call entirely. Each bound row expands to
		 * every mapped column, in the entity's column-declaration order; there is no per-field
		 * selection, since the attachment has no argument list.
		 * @param AstEventAttachment $attachment The attachment
		 * @param int $parameterCount Number of entity-row parameters the called routine declares
		 * @return list<array{row: 'old'|'new', property: string, column: string}>
		 * @throws EntityResolutionException
		 */
		protected function expandArguments(AstEventAttachment $attachment, int $parameterCount): array {
			$metadata = $this->targetMetadata($attachment);
			$rows = array_slice($attachment->getEvent()->rowRoles(), 0, $parameterCount);
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
		 * @param AstEventAttachment $attachment The attachment, for its target entity
		 * @param string $property Mapped property name
		 * @return string SQL type on the target engine
		 */
		protected function columnSqlType(AstEventAttachment $attachment, string $property): string {
			$metadata = $this->targetMetadata($attachment);
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
		 * @param AstEventAttachment $attachment The attachment
		 * @return EntityMetadataRecord Target entity's metadata
		 * @throws EntityResolutionException
		 */
		protected function targetMetadata(AstEventAttachment $attachment): EntityMetadataRecord {
			return $this->entityStore->getMetadata($attachment->getRange()->getEntityName());
		}

		/**
		 * @param AstEventAttachment $attachment The attachment
		 * @return string Physical table the attachment is on
		 * @throws EntityResolutionException
		 */
		protected function physicalTable(AstEventAttachment $attachment): string {
			return $this->targetMetadata($attachment)->tableName;
		}

		/**
		 * @param AstEventAttachment $attachment The attachment
		 * @return string Quoted, schema-qualified table name
		 * @throws EntityResolutionException
		 */
		protected function quotedTable(AstEventAttachment $attachment): string {
			return $this->quoter->quoteRoutineName($this->physicalTable($attachment), $this->routineSchema);
		}

		/**
		 * @param AstEventAttachment $attachment The attachment
		 * @param string $alias The attachment's resolved alias
		 * @return string Physical trigger name (see EventAttachmentNaming)
		 * @throws EntityResolutionException
		 */
		protected function triggerName(AstEventAttachment $attachment, string $alias): string {
			return EventAttachmentNaming::triggerName($this->physicalTable($attachment), $alias);
		}

		/**
		 * Maps the attachment's event to the engine's trigger-event keyword.
		 * @param AttachmentEvent $event The event
		 * @return string 'INSERT', 'UPDATE' or 'DELETE'
		 */
		protected function sqlEvent(AttachmentEvent $event): string {
			return match ($event) {
				AttachmentEvent::Append => 'INSERT',
				AttachmentEvent::Replace => 'UPDATE',
				AttachmentEvent::Delete => 'DELETE',
			};
		}
	}
