<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Class GetMainEntityInAstException
	 */
	class GetMainEntityInAstException extends \Exception {

		/**
		 * The AST object that is stored and passed with the exception.
		 * This allows the exception handler to access the found node.
		 * @var AstInterface
		 */
		private AstInterface $astObject;
		
		/**
		 * Constructor for GetMainEntityInAstException.
		 * @param AstInterface $astObject The AST object that represents the main entity.
		 * @param string $message The error message for the exception (empty by default).
		 * @param int $code The error code for the exception (0 by default).
		 * @param \Throwable|null $previous Any previous exception that caused this exception.
		 */
		public function __construct(AstInterface $astObject, $message = "", $code = 0, \Throwable $previous = null) {
			// Call the parent Exception constructor to properly initialize the exception
			parent::__construct($message, $code, $previous);
			
			// Store the AST object in a private property for later retrieval
			$this->astObject = $astObject;
		}
		
		/**
		 * Retrieves the stored AST object.
		 * @return AstInterface The stored AST object representing the found main entity.
		 */
		public function getAstObject(): AstInterface {
			return $this->astObject;
		}
	}