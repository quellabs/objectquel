<?php
    
    namespace Quellabs\ObjectQuel\Serialization\Normalizer;
    
    interface NormalizerInterface {

	    /**
	     * Sets the value to normalize/denormalize
	     * @param $value
	     * @return void
	     */
		public function setValue($value): void;
    
        /**
         * The normalize function converts a value residing in an entity into a value
         * that can be inserted into an entity
         */
        public function normalize();

        /**
         * The denormalize function converts a value residing in the database into a value
         * that can be implanted in the DB
         */
        public function denormalize();
    }