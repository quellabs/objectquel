<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Validation;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Represents a validation annotation that targets a specific property.
	 *
	 * Validation annotations implementing this interface are applied to entity
	 * properties and carry configuration parameters such as which property to
	 * validate and the error message to display on failure.
	 *
	 * @phpstan-type AnnotationParameters array{
	 *     property?: string,
	 *     message?: string|null
	 * }
	 */
	interface PropertyValidationInterface extends AnnotationInterface {
		
		/**
		 * Returns whether this annotation has an explicit property target configured.
		 *
		 * When false, the validator should infer the target property from context
		 * (e.g. the property the annotation is declared on).
		 */
		public function hasProperty(): bool;
		
		/**
		 * Returns the name of the property this annotation targets.
		 *
		 * Only call this after confirming hasProperty() returns true,
		 * as the return value is undefined when no property is set.
		 */
		public function getProperty(): string;
		
		/**
		 * Returns the raw annotation parameters as an associative array.
		 * @return AnnotationParameters
		 */
		public function getParameters(): array;
		
		/**
		 * Returns the error message (if any)
		 * @return string|null
		 */
		public function getMessage(): ?string;
	}