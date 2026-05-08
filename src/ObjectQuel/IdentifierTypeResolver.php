<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Resolvers\ResolvePropertyType;
	use Quellabs\ObjectQuel\ObjectQuel\Resolvers\ResolveRootIdentifierType;
	
	class IdentifierTypeResolver {
		
		private AstRetrieve $retrieve;
		private ResolveRootIdentifierType $entityRootTypeResolver;
		private ResolvePropertyType $propertyTypeResolver;
		
		public function __construct(AstRetrieve $retrieve) {
			$this->retrieve = $retrieve;
			$this->entityRootTypeResolver = new ResolveRootIdentifierType($retrieve);
			$this->propertyTypeResolver = new ResolvePropertyType();
		}
		
		public function resolve(): void {
			$this->retrieve->accept($this->entityRootTypeResolver);
			$this->retrieve->accept($this->propertyTypeResolver);
		}
	}