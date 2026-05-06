<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	/**
	 * Class AstFinderException
	 * @package Quellabs\ObjectQuel\ObjectQuel
	 */
	class AstFinderException extends \Exception {

		private AstInterface $data;
		
		/**
		 * Redefine the exception so message isn't optional
		 * @param string $message
		 * @param int $code
		 * @param \Throwable|null $previous
		 * @param AstInterface $data
		 */
		public function __construct(string $message, int $code, ?\Throwable $previous, AstInterface $data) {
			$this->data = $data;
			parent::__construct($message, $code, $previous);
		}
		
		/**
		 * Get the data associated with the exception
		 * @return AstInterface
		 */
		public function getData(): AstInterface {
			return $this->data;
		}
	}