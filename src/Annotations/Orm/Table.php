<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_CLASS)]
	class Table implements AnnotationInterface {
		use ReflectsAnnotationParameters;
		
		/**
		 * @var string Table name
		 */
		private string $name;
		
		/**
		 * Constructs the Table annotation.
		 * @param string $name
		 * @return void
		 */
		public function __construct(string $name) {
			$this->name = $name;
		}
		
		/**
		 * Returns the table name
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}
	}