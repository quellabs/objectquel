<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * `destroy trigger <range> <alias> [if exists]` — removes one attachment, identified by
	 * its (table, alias) pair, without touching the routine itself. See
	 * objectquel-equel-triggers-design.md.
	 */
	class AstDestroyEventAttachment extends Ast implements AstStatement {

		private AstRangeDatabase $range;
		private string $alias;
		private bool $ifExists;

		/**
		 * @param AstRangeDatabase $range Target range the attachment is on
		 * @param string $alias The attachment's alias, as written
		 * @param bool $ifExists True when a missing attachment is ignored instead of an error
		 */
		public function __construct(AstRangeDatabase $range, string $alias, bool $ifExists = false) {
			$this->range = $range;
			$this->alias = $alias;
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
		 * @return AstRangeDatabase
		 */
		public function getRange(): AstRangeDatabase {
			return $this->range;
		}

		/**
		 * @return string The attachment's alias, as written
		 */
		public function getAlias(): string {
			return $this->alias;
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
			$clone = new static($this->range->deepClone(), $this->alias, $this->ifExists);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
