<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `after (append to|replace|delete) <range> call <routine> [as <alias>]` — attaches a
	 * database change event to an already-defined `trigger`-returning routine. The routine's
	 * own entity-row parameters receive the event's rows by declaration order (see
	 * AttachmentEvent::rowRoles()); the attachment carries no argument list of its own.
	 * Contains no procedural body of its own; see objectquel-equel-triggers-design.md.
	 */
	class AstEventAttachment extends Ast implements AstStatement {

		private AttachmentEvent $event;
		private AstRangeDatabase $range;
		private string $routineName;
		private ?string $alias;

		/**
		 * @param AttachmentEvent $event Physical write event the attachment fires on
		 * @param AstRangeDatabase $range Target range; its physical table is what the attachment fires on
		 * @param string $routineName Name of the called `trigger`-returning routine
		 * @param string|null $alias Name given with `as <alias>`, or null to generate one at compile time
		 */
		public function __construct(AttachmentEvent $event, AstRangeDatabase $range, string $routineName, ?string $alias = null) {
			$this->event = $event;
			$this->range = $range;
			$this->routineName = $routineName;
			$this->alias = $alias;
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
		 * @return string Name of the called `trigger`-returning routine
		 */
		public function getRoutineName(): string {
			return $this->routineName;
		}

		/**
		 * @return string|null Name given with `as <alias>`, or null when one wasn't given
		 */
		public function getAlias(): ?string {
			return $this->alias;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->event, $this->range->deepClone(), $this->routineName, $this->alias);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
