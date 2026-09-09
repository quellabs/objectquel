<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CoerceDateTimeParameters;

	/**
	 * Recursively applies CoerceDateTimeParameters to a query and every nested
	 * subquery range, mirroring how QueryNormalizer::transform() recurses into
	 * nested queries before processing the outer one.
	 */
	class DateTimeParameterCoercer {

		/**
		 * @param AstRetrieve $ast
		 * @param array<string, mixed> $parameters Reference to the query's bound parameters
		 * @return void
		 * @throws QuelException
		 */
		public function coerce(AstRetrieve $ast, array &$parameters): void {
			foreach ($ast->getRanges() as $range) {
				if ($range instanceof AstRangeDatabaseSubquery) {
					$this->coerce($range->getQuery(), $parameters);
				}
			}

			$ast->accept(new CoerceDateTimeParameters($parameters));
		}
	}
