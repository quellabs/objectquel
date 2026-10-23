<?php

	namespace Quellabs\ObjectQuel\Annotations;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 * Class for handling serialization groups in object serialization
	 * Implements the AnnotationInterface for the annotation reader system
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class SerializationGroups implements AnnotationInterface {
		use ReflectsAnnotationParameters;
		/**
		 * @var array<mixed>
		 */
		private array $groups;


		/**
		 * Constructs the SerializationGroups annotation.
		 * @param array<mixed>|null $groups
		 * @return void
		 */
		public function __construct(?array $groups = null) {
			$this->groups = $groups ?? [];
		}



		/**
		 * Returns the specific serialization groups defined in the parameters
		 * Provides direct access to the "groups" parameter array
		 * @return array<string> List of serialization groups this annotation defines
		 */
		public function getGroups(): array {
			if (
				$this->groups === []
			) {
				return [];
			}

			return array_values(array_filter($this->groups, 'is_string'));
		}
	}
