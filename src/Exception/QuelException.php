<?php
	
	namespace Quellabs\ObjectQuel\Exception;
	
	/**
	 * Thrown when an entity was not found
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