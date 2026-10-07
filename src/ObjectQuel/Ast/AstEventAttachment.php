<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `after (append to|replace|delete) <range> call <routine>(args) [as <alias>]` — attaches a
	 * database change event to a call of an already-defined `trigger`-returning routine.
	 * Contains no procedural body of its own; see objectquel-equel-triggers-design.md.
	 */
	class AstEventAttachment extends Ast implements AstStatement {

		private AttachmentEvent $event;
		private AstRangeDatabase $range;
		private AstRoutineCall $call;
		private ?string $alias;

		/**
		 * @param AttachmentEvent $event Physical write event the attachment fires on
		 * @param AstRangeDatabase $range Target range; its physical table is what the attachment fires on
		 * @param AstRoutineCall $call Called routine name and argument expressions (`old`/`new` bindings or scalar expressions)
		 * @param string|null $alias Name given with `as <alias>`, or null to generate one at compile time
		 */
		public function __construct(AttachmentEvent $event, AstRangeDatabase $range, AstRoutineCall $call, ?string $alias = null) {
			$this->event = $event;
			$this->range = $range;
			$this->call = $call;
			$this->alias = $alias;

			// The range is a shared pre-declared node (see Rules\Range), not reparented here,
			// same as AstReplace/AstDelete's own target range.
			$this->call->setParent($this);
		}

		/**
		 * Visits this node, then its range, then its call.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->range->accept($visitor);
			$this->call->accept($visitor);
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
		 * @return AstRoutineCall
		 */
		public function getCall(): AstRoutineCall {
			return $this->call;
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
			$clone = new static($this->event, $this->range->deepClone(), $this->call->deepClone(), $this->alias);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
