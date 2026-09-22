<?php

	namespace Quellabs\ObjectQuel\Persistence;

	/**
	 * What actually happened to a row when an entity was deleted — passed
	 * as the second argument to UnitOfWork's signalPreDelete/signalPostDelete
	 * so a listener can tell a real DELETE apart from a soft-delete UPDATE.
	 */
	enum DeleteType {
		/** A real `DELETE FROM` was issued — the row is gone. */
		case Hard;

		/** The row still exists; only its @SoftDelete column was updated. */
		case Soft;
	}
