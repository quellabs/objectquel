<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\Exception\QuelException;
	
	/**
	 * Class ViaClauseValidator
	 * Validates the existence of entities within an AST.
	 */
	class ViaClauseValidator implements AstVisitorInterface {
		
		private EntityStore $entityStore;
		private string $entityName;
		
		/**
		 * ViaClauseValidator constructor.
		 * @param EntityStore $entityStore
		 * @param string $entityName
		 */
		public function __construct(EntityStore $entityStore, string $entityName) {
			$this->entityStore = $entityStore;
			$this->entityName = $entityName;
		}
		
		/**
		 * Visit a node in the AST and validate any via-clause relationship it represents.
		 * Only root AstIdentifier nodes with a chained property that originate from a
		 * database range are subject to validation. For those nodes, every declared
		 * relationship (OneToOne, ManyToOne, OneToMany) is checked to ensure the target
		 * entity matches the entity this validator was constructed for.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 * @throws SemanticException When the relationship path does not lead to the expected entity.
		 * @throws EntityResolutionException When entity metadata cannot be resolved.
		 */
		public function visitNode(AstInterface $node): void {
			// Only AstIdentifier nodes carry relationship information
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Skip chained segments — only the root of a chain represents a full path
			if (!$node->isRoot()) {
				return;
			}
			
			// A bare identifier with no property access (e.g. "u" vs "u.name") has nothing to validate
			$next = $node->getNext();
			
			if ($next === null) {
				return;
			}
			
			// Only database ranges map to entities; subquery and other range types are skipped
			$sourceRange = $node->getSourceRange();
			
			if (!$sourceRange instanceof AstRangeDatabase) {
				return;
			}
			
			// Extract the three name components needed for validation and error reporting
			$entityName   = $sourceRange->getResolvedEntityName(); // e.g. "App\Entity\Order"
			$rangeName    = $sourceRange->getName();               // e.g. "o" (the range alias)
			$propertyName = $next->getName();                      // e.g. "customer" (the accessed property)
			
			// Collect all relationship types that could legitimately reference another entity
			$dependencies = [
				'oneToOne'  => $this->entityStore->getOneToOneDependencies($entityName),
				'manyToOne' => $this->entityStore->getManyToOneDependencies($entityName),
				'oneToMany' => $this->entityStore->getOneToManyDependencies($entityName),
			];
			
			// For each relationship type, check whether the accessed property is declared
			// and, if so, whether its target entity matches the expected entity
			foreach ($dependencies as $dependency) {
				if (!isset($dependency[$propertyName])) {
					continue;
				}
				
				$relation     = $dependency[$propertyName];
				$targetEntity = $relation->getTargetEntity();
				
				// The property exists as a relationship but points to the wrong entity
				if ($targetEntity !== $this->entityName) {
					throw new SemanticException("Failed to join {$targetEntity} via {$rangeName}.{$propertyName} from {$this->entityName}. This is not a valid relationship path.");
				}
			}
		}
	}