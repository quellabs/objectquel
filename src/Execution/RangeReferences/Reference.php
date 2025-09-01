<?php
	
	namespace Quellabs\ObjectQuel\Execution\RangeReferences;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	
	class Reference {
		
		private AstIdentifier $identifier;
		
		public function __construct(AstIdentifier $identifier) {
			$this->identifier = $identifier;
		}
		
		public function getIdentifier(): AstIdentifier {
			return $this->identifier;
		}
	}
