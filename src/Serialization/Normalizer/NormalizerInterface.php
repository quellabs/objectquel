<?php
    
    namespace Quellabs\ObjectQuel\Serialization\Normalizer;
    
    interface NormalizerInterface {
	    
	    /**
	     * Passed annotation parameters
	     * @param array $parameters
	     */
		public function __construct(array $parameters);
	    
        /**
         * The normalize function converts a value residing in an entity into a value
         * that can be inserted into an entity
         */
        public function normalize(mixed $value): mixed;

        /**
         * The denormalize function converts a value residing in the database into a value
         * that can be implanted in the DB
         */
        public function denormalize(mixed $value): mixed;
    }