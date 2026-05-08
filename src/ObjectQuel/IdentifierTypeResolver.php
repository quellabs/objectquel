<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Resolvers\ResolveRootIdentifierType;
	
	class IdentifierTypeResolver {
		
		private ResolveRootIdentifierType $entityRootTypeResolver;
		private AstRetrieve $retrieve;
		
		public function __construct(AstRetrieve $retrieve) {
			$this->retrieve = $retrieve;
			$this->entityRootTypeResolver = new ResolveRootIdentifierType($retrieve);
		}
		
		public function resolve(): void {
			$this->retrieve->accept($this->entityRootTypeResolver);
		}
	}