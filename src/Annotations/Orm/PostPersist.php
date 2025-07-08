<?php
    
    namespace Quellabs\ObjectQuel\Annotations\Orm;
    
    use Quellabs\AnnotationReader\AnnotationInterface;
    
    class PostPersist implements AnnotationInterface {
        
        protected $parameters;
        
        /**
         * PostPersist constructor.
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
    }