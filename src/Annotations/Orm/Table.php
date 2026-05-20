<?php
    
    namespace Quellabs\ObjectQuel\Annotations\Orm;
    
    use Quellabs\AnnotationReader\AnnotationInterface;
    
    class Table implements AnnotationInterface {
        
        /** @var array<string, mixed> */
        protected array $parameters;
    
        /**
         * Table constructor.
         * @param array<string, mixed> $parameters
         */
        public function __construct(array $parameters) {
            $this->parameters = $parameters;
        }
	    
	    /**
	     * Returns the parameters for this annotation
	     * @return array<string, mixed>
	     */
	    public function getParameters(): array {
		    return $this->parameters;
	    }

        /**
         * Returns the table name
         * @return string
         */
        public function getName(): string {
			if (
				!isset($this->parameters['name']) ||
	            !is_string($this->parameters['name'])
			) {
				throw new \InvalidArgumentException("Table annotation requires a valid 'name' parameter");
			}
	        
            return $this->parameters["name"];
        }
    }