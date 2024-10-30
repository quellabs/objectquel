<?php
	
	
	namespace Services\ObjectQuel\Visitors;
	
	use Services\EntityManager\EntityManager;
	use Services\EntityManager\EntityStore;
	use Services\ObjectQuel\Ast\AstEntity;
	use Services\ObjectQuel\Ast\AstIdentifier;
	use Services\ObjectQuel\Ast\AstRange;
	use Services\ObjectQuel\Ast\AstRetrieve;
	use Services\ObjectQuel\AstInterface;
	use Services\ObjectQuel\AstVisitorInterface;
	use Services\ObjectQuel\QuelException;
	
	/**
	 * Class AddNamespacesToEntities
	 * Validates the existence of entities within an AST.
	 */
	class AddNamespacesToEntities implements AstVisitorInterface {
		
		/**
		 * The EntityStore for storing and fetching entity metadata.
		 * @var $entityStore EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * The macros used
		 */
		private array $macros;

		/**
		 * The ranges used
		 */
		private array $ranges;
		
		/**
		 * EntityExistenceValidator constructor.
		 * @param EntityManager $entityManager The EntityManager to use for entity validation.
		 * @param array $ranges
		 * @param array $macros
		 */
		public function __construct(EntityManager $entityManager, array $ranges, array $macros) {
			$this->entityStore = $entityManager->getUnitOfWork()->getEntityStore();
			$this->ranges = $ranges;
			$this->macros = $macros;
		}
		
		/**
		 * @param string $name
		 * @return bool
		 */
		private function rangeExists(string $name): bool {
			foreach($this->ranges as $range) {
				if ($name == $range->getName()) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Returns true if the macro exists, false if not
		 * @param string $name
		 * @return bool
		 */
		private function macroExists(string $name): bool {
			return array_key_exists($name, $this->macros);
		}
		
		/**
		 * Functie om een node in de AST (Abstract Syntax Tree) te bezoeken.
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node) {
			// Controleert of de node een instantie van AstEntity is. Zo niet, dan stopt de functie.
			if (!$node instanceof AstEntity) {
				return;
			}
			
			// Controleert of er een macro bestaat met dezelfde naam als de node.
			// Als dat het geval is, stopt de functie zonder verdere actie.
			if ($this->macroExists($node->getName())) {
				return;
			}
			
			// Controleert of er een bereik (range) bestaat met dezelfde naam als de node.
			// Ook hier, als dat het geval is, stopt de functie zonder verdere actie.
			if ($this->rangeExists($node->getName())) {
				return;
			}
			
			// Als geen van de bovenstaande controles waar is, voegt de functie een namespace toe
			// aan de naam van de node. Dit wordt gedaan door een methode van het entityStore-object.
			$node->setName($this->entityStore->addNamespaceToEntityName($node->getName()));
		}
	}