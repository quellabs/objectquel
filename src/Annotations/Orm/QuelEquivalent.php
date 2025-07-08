<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * The QuelEquivalent class is intended to handle Quel-equivalent values.
	 */
	class QuelEquivalent implements AnnotationInterface {
		
		// Protected variable that will contain the parameters.
		protected array $parameters;
		
		/**
		 * Constructor for QuelEquivalent.
		 * This method initializes the class with the provided parameters.
		 * @param array $parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns the parameters for this annotation
		 * @return array
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * This method returns the Quel-equivalent value.
		 * @return string The Quel-equivalent value.
		 */
		public function getSqlEquivalent(): string {
			return $this->parameters["value"];
		}
	}