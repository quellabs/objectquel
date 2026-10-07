<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AttachmentEvent;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

	/**
	 * Database-independent checks on an `after ... call ...(...)` attachment: `old`/`new`
	 * availability for the event, and the shape of each call argument. Matching the called
	 * routine's deployed signature and safety graph needs its live metadata and is checked
	 * separately, at attachment time (see objectquel-equel-triggers-design.md).
	 */
	class EventAttachmentAnalyzer {

		/**
		 * @param EntityStore $entityStore Entity metadata, to validate `old.field`/`new.field`
		 */
		public function __construct(private readonly EntityStore $entityStore) {
		}

		/**
		 * Validates every call argument of an attachment.
		 * @param AstEventAttachment $attachment Parsed attachment
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		public function analyze(AstEventAttachment $attachment): void {
			$event = $attachment->getEvent();
			$entityName = $attachment->getRange()->getEntityName();

			foreach ($attachment->getCall()->getArguments() as $argument) {
				$this->analyzeArgument($argument, $event, $entityName);
			}
		}

		/**
		 * Checks every root identifier an argument expression reads.
		 * @param AstInterface $argument One call argument
		 * @param AttachmentEvent $event The attachment's event
		 * @param string $entityName Target range's entity, for `old.field`/`new.field`
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeArgument(AstInterface $argument, AttachmentEvent $event, string $entityName): void {
			$collector = new CollectNodes(AstIdentifier::class);
			$argument->accept($collector);

			foreach ($collector->getCollectedNodes() as $identifier) {
				// Only root identifiers are checked directly; a field segment is checked
				// through its root (see checkRowReference()).
				if (!$identifier->getParent() instanceof AstIdentifier) {
					$this->checkRowReference($identifier, $event, $entityName);
				}
			}
		}

		/**
		 * Checks one root identifier: it must be `old` or `new`, available for this event, with
		 * at most one field segment naming a mapped column — there is no other scope here, so
		 * any other bare name is undefined and any deeper chain has nothing to resolve against.
		 * @param AstIdentifier $identifier Root identifier
		 * @param AttachmentEvent $event The attachment's event
		 * @param string $entityName Target range's entity
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkRowReference(AstIdentifier $identifier, AttachmentEvent $event, string $entityName): void {
			$written = $identifier->getName();
			$name = strtolower($written);

			if ($name !== 'old' && $name !== 'new') {
				throw new SemanticException("Undefined name '{$written}' in attachment call arguments; only 'old', 'new' and constant expressions are available here.");
			}

			if ($name === 'old' && !$event->hasOld()) {
				throw new SemanticException("'old' is not available for '{$event->keyword()}': it has no previous row.");
			}

			if ($name === 'new' && !$event->hasNew()) {
				throw new SemanticException("'new' is not available for '{$event->keyword()}': it has no resulting row.");
			}

			$field = $identifier->getNext();

			if ($field === null) {
				// Whole-row argument; matched against the called routine's entity-row
				// parameter at attachment time, once its metadata is read (see class docblock).
				return;
			}

			if ($field->getNext() !== null) {
				throw new SemanticException("'{$identifier->getCompleteName()}' is invalid: a row field has no further fields.");
			}

			if ($this->entityStore->getMetadata($entityName)->getColumnName($field->getName()) === null) {
				throw new SemanticException("'{$entityName}' has no mapped column '{$field->getName()}' to read through '{$written}.{$field->getName()}'.");
			}
		}
	}
