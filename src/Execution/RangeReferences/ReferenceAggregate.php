<?php
	
	namespace Quellabs\ObjectQuel\Execution\RangeReferences;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	class ReferenceAggregate extends Reference{
		
		private string $parentContext;
		private AstInterface $parentAggregate;
		
		public function __construct(AstIdentifier $identifier, string $parentContext, AstInterface $parentAggregate) {
			parent::__construct($identifier);
			$this->parentContext = $parentContext;
			$this->parentAggregate = $parentAggregate;
		}
		
		public function getParentContext(): string {
			return $this->parentContext;
		}
		
		public function getParentAggregate(): AstInterface {
			return $this->parentAggregate;
		}
	}
