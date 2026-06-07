<?php
	
	namespace Quellabs\ObjectQuel\Exception;
	
	/**
	 * Thrown when a QuelException occurred.
	 * This wraps SemanticException, ParserException, LexerException, etc.
	 * to provide one overall exception to catch.
	 */
	class QuelException extends \Exception {
		public function __construct(
			string                 $message,
			public readonly string $type = 'query_error',
			int                    $code = 0,
			?\Throwable            $previous = null
		) {
			parent::__construct($message, $code, $previous);
		}
	}