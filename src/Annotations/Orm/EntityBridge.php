<?php
    
    namespace Quellabs\ObjectQuel\Annotations\Orm;
    
    use Quellabs\AnnotationReader\AnnotationInterface;
    
    class EntityBridge implements AnnotationInterface {
        
        protected array $parameters;
    
        /**
         * EntityBridge constructor.
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