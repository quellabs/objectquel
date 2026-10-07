<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `destroy event after (append to|replace|delete) <range> call <routine> [if exists]` —
	 * removes one attachment, identified by its (table, event, routine) triple, without
	 * touching the routine itself. See objectquel-equel-triggers-design.md.
	 */
	class AstDestroyEventAttachment extends Ast implements AstStatement {

		private AttachmentEvent $event;
		private AstRangeDatabase $range;
		private string $routineName;
		private bool $ifExists;

		/**
		 * @param AttachmentEvent $event Physical write event the attachment fires on
		 * @param AstRangeDatabase $range Target range the attachment is on
		 * @param string $routineName Called routine's name, as written
		 * @param bool $ifExists True when a missing attachment is ignored instead of an error
		 */
		public function __construct(AttachmentEvent $event, AstRangeDatabase $range, string $routineName, bool $ifExists = false) {
			$this->event = $event;
			$this->range = $range;
			$this->routineName = $routineName;
			$this->ifExists = $ifExists;
		}

		/**
		 * Visits this node, then its range.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->range->accept($visitor);
		}

		/**
		 * @return AttachmentEvent
		 */
		public function getEvent(): AttachmentEvent {
			return $this->event;
		}

		/**
		 * @return AstRangeDatabase
		 */
		public function getRange(): AstRangeDatabase {
			return $this->range;
		}

		/**
		 * @return string Called routine's name, as written
		 */
		public function getRoutineName(): string {
			return $this->routineName;
		}

		/**
		 * @return bool True when a missing attachment is ignored instead of an error
		 */
		public function isIfExists(): bool {
			return $this->ifExists;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->event, $this->range->deepClone(), $this->routineName, $this->ifExists);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
