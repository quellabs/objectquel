<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * The physical write event an `after ... call ...` attachment fires on
	 * (objectquel-equel-triggers-design.md). `Append`/`Replace`/`Delete` map to
	 * physical INSERT/UPDATE/DELETE, independent of the EQUEL statement that
	 * caused the write — a soft delete fires `Replace`, for instance.
	 */
	enum AttachmentEvent: string {

		case Append = 'append';
		case Replace = 'replace';
		case Delete = 'delete';

		/**
		 * @return bool True when `old` is bound for this event (UPDATE/DELETE)
		 */
		public function hasOld(): bool {
			return $this !== self::Append;
		}

		/**
		 * @return bool True when `new` is bound for this event (INSERT/UPDATE)
		 */
		public function hasNew(): bool {
			return $this !== self::Delete;
		}

		/**
		 * The keyword as written in `after <event> ...`, for error messages.
		 * @return string
		 */
		public function keyword(): string {
			return match ($this) {
				self::Append => 'append to',
				self::Replace => 'replace',
				self::Delete => 'delete',
			};
		}
	}
