<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Rejects any(...) nested inside another any(...), for contexts without nested range scoping.
	 */
	class ValidateNoNestedAny implements AstVisitorInterface {
		
		/** @var string Where the visited expression sits, used in the error message */
		private string $context;
		
		/**
		 * ValidateNoNestedAny constructor
		 * @param string $context Where the visited expression sits, e.g. "a delete statement's where clause"
		 */
		public function __construct(string $context) {
			$this->context = $context;
		}
		
		/**
		 * Throws when the node is an any(...) with an enclosing any(...).
		 * @param AstInterface $node
		 * @return void
		 * @throws SemanticException
		 */
		public function visitNode(AstInterface $node): void {
			if ($node instanceof AstAny && $node->parentIsOneOf([AstAny::class])) {
				throw new SemanticException(
					"Nested any(...) is not supported in {$this->context}. " .
					"Nested any(...) is only supported in a retrieve statement's where clause."
				);
			}
		}
	}
