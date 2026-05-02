<?php
    
    namespace Quellabs\ObjectQuel\Annotations\Orm;
    
    use Quellabs\AnnotationReader\AnnotationInterface;
    
    class PrePersist implements AnnotationInterface {
        
        /** @var array<string, mixed> */
        protected array $parameters;
        
        /**
         * PrePersist constructor.
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