<?php
	
	namespace Quellabs\ObjectQuel\Execution\RangeReferences;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	
	class ReferenceAggregate extends Reference{
		
		private string $parentContext;
		
		public function __construct(AstIdentifier $identifier, string $parentContext) {
			parent::__construct($identifier);
			$this->parentContext = $parentContext;
		}
		
		public function getParentContext(): string {
			return $this->parentContext;
		}
	}
