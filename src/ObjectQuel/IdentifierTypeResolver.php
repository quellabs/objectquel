<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Resolvers\ResolveIdentifierRange;
	use Quellabs\ObjectQuel\ObjectQuel\Resolvers\ResolvePropertyType;
	use Quellabs\ObjectQuel\ObjectQuel\Resolvers\ResolveRootIdentifierType;
	
	class IdentifierTypeResolver {
		
		private AstRetrieve $retrieve;
		private ResolveRootIdentifierType $entityRootTypeResolver;
		private ResolvePropertyType $propertyTypeResolver;
		private ResolveIdentifierRange $identifierRangeResolver;
		
		/**
		 * @param AstRetrieve $retrieve
		 */
		public function __construct(AstRetrieve $retrieve) {
			$this->retrieve = $retrieve;
			$this->entityRootTypeResolver = new ResolveRootIdentifierType($retrieve);
			$this->propertyTypeResolver = new ResolvePropertyType();
			$this->identifierRangeResolver = new ResolveIdentifierRange($retrieve);
		}
		
		/**
		 * Resolver
		 * @return void
		 */
		public function resolve(): void {
			$this->retrieve->accept($this->entityRootTypeResolver);
			$this->retrieve->accept($this->propertyTypeResolver);
			$this->retrieve->accept($this->identifierRangeResolver);
		}
	}