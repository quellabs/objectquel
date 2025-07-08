<?php
	
	namespace Quellabs\ObjectQuel\Annotations;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Class for handling serialization groups in object serialization
	 * Implements the AnnotationInterface for the annotation reader system
	 */
	class SerializationGroups implements AnnotationInterface {
		
		/**
		 * Array to store annotation parameters
		 */
		protected array $parameters;
		
		/**
		 * SerializationGroups constructor.
		 * Initializes the class with the provided annotation parameters
		 * @param array $parameters Parameters passed to this annotation
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns the parameters for this annotation
		 * Used by the annotation reader to access all parameters
		 * @return array All parameters stored in this annotation
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns the specific serialization groups defined in the parameters
		 * Provides direct access to the "groups" parameter array
		 * @return array List of serialization groups this annotation defines
		 */
		public function getGroups(): array {
			return $this->parameters["groups"];
		}
	}