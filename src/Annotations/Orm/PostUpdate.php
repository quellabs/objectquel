<?php
    
    namespace Quellabs\ObjectQuel\Annotations\Orm;
    
    use Quellabs\AnnotationReader\AnnotationInterface;
    
    class PostUpdate implements AnnotationInterface {
        
        /** @var array<string, mixed> */
        protected array $parameters;
        
        /**
         * PostUpdate constructor.
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
    }