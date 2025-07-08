<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\RequiredRelation;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExists;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AddNamespacesToEntities;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AddRangeToEntityWhenItsMissing;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AliasPlugAliasPattern;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsCheckIsNullForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityExistenceValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityPlugMacros;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityProcessMacro;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityProcessRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityPropertyValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GatherReferenceJoinValues;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAst;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAstException;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NoExpressionsAllowedOnEntitiesValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\RangeOnlyReferencesOtherRanges;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\TransformRelationInViaToPropertyLookup;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateRelationInViaValid;
	
	class ObjectQuel {
		
		private EntityStore $entityStore;
		private EntityManager $entityManager;
		private DatabaseAdapter $connection;
		private int $fullQueryResultCount;
		
		/**
		 * Constructor om de EntityManager te injecteren.
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
			$this->entityStore = $entityManager->getEntityStore();
			$this->connection = $entityManager->getConnection();
			$this->fullQueryResultCount = 0;
		}
		
		/**
		 * Valideert dat er geen dubbele range-namen zijn in de AST van een Quel-query.
		 * Deze functie controleert of elke range in de AstRetrieve-query een unieke naam heeft.
		 * Als er dubbele range-namen worden gevonden, wordt een QuelException geworpen.
		 * @param AstRetrieve $ast De AST-representatie van de Quel-query.
		 * @return void
		 * @throws QuelException Als er dubbele range-namen worden gedetecteerd.
		 */
		private function validateNoDuplicateRanges(AstRetrieve $ast): void {
			// Verzamel alle range-namen uit de AST.
			$rangeNames = array_map(function ($e) { return $e->getName(); }, $ast->getRanges());
			
			// Vind en verzamel alle dubbele range-namen.
			$duplicateNames = [];
			
			foreach (array_count_values($rangeNames) as $name => $count) {
				if ($count > 1) {
					$duplicateNames[] = $name;
				}
			}
			
			// Als er dubbele namen zijn, gooi dan een uitzondering met een foutmelding.
			if (!empty($duplicateNames)) {
				throw new QuelException("Duplicate range name(s) detected. The range names " . implode(', ', $duplicateNames) . " have been defined more than once. Each range name must be unique within a query. Please revise the query to use distinct range names.");
			}
		}
		
		/**
		 * Takes all referenced ranges, looks up the accompanied entities and stores them in the AstIdentifier ASTs
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function processRanges(AstRetrieve $ast): void {
			// Creëert een nieuwe instantie van 'EntityProcessRange' met de bereiken die uit het AST-object worden opgehaald.
			$rangeProcessor = new EntityProcessRange($ast->getRanges());
			
			// Het AST-object accepteert de 'rangeProcessor' voor verdere verwerking.
			$ast->accept($rangeProcessor);
		}
		
		/**
		 * Looks into 'via' clauses for relations and transforms them into property lookups
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function transformViaRelationsIntoProperties(AstRetrieve $ast): void {
			foreach($ast->getRanges() as $range) {
				$joinProperty = $range->getJoinProperty();

				if ($joinProperty === null) {
					continue;
				}
				
				$converter = new TransformRelationInViaToPropertyLookup($this->entityStore, $range);
				$range->setJoinProperty($converter->processNodeSide($joinProperty));
				$range->accept($converter);
			}
		}
		
		/**
		 * Takes all referenced ranges, looks up the accompanied entities and stores them in the AstIdentifier ASTs
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function processMacros(AstRetrieve $ast): void {
			// Creëert een nieuwe instantie van 'EntityProcessRange' met de bereiken die uit het AST-object worden opgehaald.
			$rangeProcessor = new EntityProcessMacro($ast->getMacros());
			
			// Het AST-object accepteert de 'rangeProcessor' voor verdere verwerking.
			$ast->accept($rangeProcessor);
		}
		
		/**
		 * Validates that referenced properties exist in the entity
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function validateProperties(AstRetrieve $ast): void {
			// Creëert een nieuwe instantie van 'EntityPropertyValidator' met de bereiken die uit het AST-object worden opgehaald.
			$rangeProcessor = new EntityPropertyValidator($this->entityStore);
			
			// Het AST-object accepteert de 'rangeProcessor' voor verdere verwerking.
			$ast->accept($rangeProcessor);
		}
		
		/**
		 * Voegt bereiken toe aan de AstRetrieve-instantie.
		 * Deze functie haalt de bereiken op via de EntityPlugRange-klasse
		 * en voegt ze vervolgens toe aan de gegeven AstRetrieve-instantie.
		 * @param AstRetrieve $ast De instantie van AstRetrieve waar de bereiken aan toegevoegd worden.
		 * @return void
		 */
		private function plugRanges(AstRetrieve $ast): void {
			// Creëert een nieuwe instantie van 'EntityPlugRange' met de bereiken die uit het AST-object worden opgehaald.
			$rangeProcessor = new AddRangeToEntityWhenItsMissing();
			
			// Het AST-object accepteert de 'rangeProcessor' voor verdere verwerking.
			$ast->accept($rangeProcessor);
			
			// Voeg de toegevoegde ranges toe aan de AST
			foreach($rangeProcessor->getRanges() as $range) {
				$ast->addRange($range);
			}
		}
		
		/**
		 * Voegt een alias pattern toe waarmee het makkelijker wordt om
		 * entities terug te vinden in de result set
		 * @param AstRetrieve $ast De instantie van AstRetrieve waar de bereiken aan toegevoegd worden.
		 * @return void
		 */
		private function plugAliasPatterns(AstRetrieve $ast): void {
			// Creëert een nieuwe instantie van 'AliasPlugAliasPattern' met de bereiken die uit het AST-object worden opgehaald.
			$rangeProcessor = new AliasPlugAliasPattern();
			
			// Het AST-object accepteert de 'rangeProcessor' voor verdere verwerking.
			$ast->accept($rangeProcessor);
		}
		
		/**
		 * Validates that the referenced entities exist.
		 * The function assumes all ranges are processed and completed.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function validateEntitiesExist(AstRetrieve $ast): void {
			// Creëert een nieuwe instantie van 'EntityExistenceValidator' met de bereiken die uit het AST-object worden opgehaald.
			$rangeProcessor = new EntityExistenceValidator($this->entityStore);
			
			// Het AST-object accepteert de 'rangeProcessor' voor verdere verwerking.
			$ast->accept($rangeProcessor);
		}
		
		/**
		 * Complete namespaces
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function addNamespacesToEntities(AstRetrieve $ast): void {
			// Creëert een nieuwe instantie van 'AddNamespacesToEntities' met de bereiken die uit het AST-object worden opgehaald.
			$rangeProcessor = new AddNamespacesToEntities($this->entityStore, $ast->getRanges(), $ast->getMacros());
			
			// Het AST-object accepteert de 'rangeProcessor' voor verdere verwerking.
			$ast->accept($rangeProcessor);
		}
		
		/**
		 * Takes macros defined in the retrieve/column section and integrates them into the query
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function plugMacros(AstRetrieve $ast): void {
			// Creëert een nieuwe instantie van 'EntityPropertyValidator' met de bereiken die uit het AST-object worden opgehaald.
			$rangeProcessor = new EntityPlugMacros($ast->getMacros());
			
			// Het AST-object accepteert de 'rangeProcessor' voor verdere verwerking.
			$ast->accept($rangeProcessor);
		}
		
		/**
		 * Controleert of een array een object van een bepaalde klasse bevat.
		 * @param string $needle De naam van de klasse die gezocht wordt in de array.
		 * @param array $haystack De array waarin gezocht wordt naar een object van de gegeven klasse.
		 * @return bool Geeft 'true' terug als een object van de opgegeven klasse wordt gevonden in de array, anders 'false'.
		 */
		private function inArrayObject(string $needle, array $haystack): bool {
			foreach ($haystack as $item) {
				if ($item instanceof $needle) {
					return true;
				}
			}
			
			return false;
		}
		
		
		/**
		 * Controleert of de 'joinProperty' een geldige 'AstExpression' is met 'AstIdentifier' aan beide zijden.
		 * @param mixed $joinProperty
		 * @return bool
		 */
		private function isExpressionWithTwoIdentifiers($joinProperty): bool {
			return $joinProperty instanceof AstExpression &&
				$joinProperty->getLeft() instanceof AstIdentifier &&
				$joinProperty->getRight() instanceof AstIdentifier;
		}
	    
	    /**
	     * Verwerkt de annotaties van de gerelateerde entiteit voor een specifieke 'range'.
	     * De functie controleert of de 'range' als verplicht moet worden ingesteld op basis van
	     * de 'ManyToOne' annotaties van de gerelateerde entiteit.
	     * @param AstRangeDatabase $mainRange De hoofdrange voor deze query
	     * @param AstRangeDatabase $range Het 'range' object dat mogelijk als verplicht wordt gemarkeerd.
	     * @param AstIdentifier $left De linker identifier in de joinProperty van de 'range'.
	     * @param AstIdentifier $right De rechter identifier in de joinProperty van de 'range'.
	     * @return void
	     */
		private function setRangeRequiredIfNeeded(AstRangeDatabase $mainRange, AstRangeDatabase $range, AstIdentifier $left, AstIdentifier $right): void {
			// Properties
			$isMainRange = $right->getRange() === $mainRange;
			$ownPropertyName = $isMainRange ? $right->getName() : $left->getName();
			$ownEntityName = $isMainRange ? $right->getEntityName() : $left->getEntityName();
			$relatedPropertyName = $isMainRange ? $left->getName() : $right->getName();
			$relatedEntityName = $isMainRange ? $left->getEntityName() : $right->getEntityName();
			
			// Ophalen van de annotaties van de gerelateerde entiteit.
			$entityAnnotations = $this->entityStore->getAnnotations($ownEntityName);
			
			// Doorloop elke annotatiegroep van de gerelateerde entiteit.
			foreach ($entityAnnotations as $annotations) {
				// Ga door naar de volgende groep als RequiredRelation annotatie niet aanwezig is.
				if (!$this->inArrayObject(RequiredRelation::class, $annotations->toArray())) {
					continue;
				}
				
				// Controleer elke annotatie binnen de groep.
				foreach ($annotations as $annotation) {
					// Controleer of de annotatie een ManyToOne relatie is die overeenkomt met de opgegeven criteria.
					if (
						($annotation instanceof ManyToOne || $annotation instanceof OneToOne) &&
						$annotation->getTargetEntity() === $relatedEntityName &&
						$annotation->getRelationColumn() === $ownPropertyName &&
						$annotation->getInversedBy() === $relatedPropertyName
					) {
						// Markeer de range als verplicht en stop de verwerking.
						$range->setRequired();
						return;
					}
				}
			}
		}
		
		/**
		 * Make the range required when it's used inside the WHERE clause
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function setRangesRequiredThroughWhere(AstRetrieve $ast): void {
            if ($ast->getConditions() !== null) {
                foreach ($ast->getRanges() as $range) {
                    try {
                        if (!$range->isRequired()) {
                            $visitor = new ContainsRange($range->getName());
                            $ast->getConditions()->accept($visitor);
                        }
                    } catch (\Exception $e) {
                        $range->setRequired();
                    }
                }
            }
		}
        
        /**
		 * Make the range required when it's used inside the WHERE clause
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function setRangesNotRequiredThroughWhere(AstRetrieve $ast): void {
            if ($ast->getConditions() !== null) {
                foreach ($ast->getRanges() as $range) {
                    try {
                        if ($range->isRequired()) {
                            $visitor = new ContainsCheckIsNullForRange($range->getName());
                            $ast->getConditions()->accept($visitor);
                        }
                    } catch (\Exception $e) {
                        $range->setRequired(false);
                    }
                }
            }
		}
	 
		/**
	     * Returns the main range of the range list. E.g. the first one without a join property
	     * @param AstRetrieve $ast
	     * @return AstRangeDatabase|null
	     */
	    private function getMainRange(AstRetrieve $ast): ?AstRangeDatabase {
		    foreach($ast->getRanges() as $range) {
				// Skip non database ranges
			    if (!$range instanceof AstRangeDatabase) {
				    continue;
		        }
			
				// If the join property is absent, this is what we'll use for FROM
			    if ($range->getJoinProperty() == null) {
				    return $range;
			    }
		    }
		    
		    return null;
	    }
		
		/**
		 * Deze functie stelt 'range'-objecten in als verplicht, gebaseerd op bepaalde voorwaarden.
		 * Het controleert de 'joinProperty' van elk 'range'-object in de gegeven 'AstRetrieve'-instantie.
		 * @param AstRetrieve $ast Een object dat de te controleren 'range'-objecten bevat.
		 * @return void
		 */
		private function setRangesRequiredThroughRequiredRelationAnnotation(AstRetrieve $ast): void {
			$mainRange = $this->getMainRange($ast);
			
			foreach ($ast->getRanges() as $range) {
				// Verifieer of 'joinProperty' een valide 'AstExpression' is met 'AstIdentifier' aan beide zijden.
				if (!$this->isExpressionWithTwoIdentifiers($range->getJoinProperty())) {
					continue;
				}
				
				// Haal linker en rechterdeel van de join property op
				$joinProperty = $range->getJoinProperty();
				$left = $joinProperty->getLeft();
				$right = $joinProperty->getRight();
				
				// Draai de twee om, als het rechterdeel de huidige range target
				if ($right->getEntityName() == $range->getEntityName()) {
					$tmp = $left;
					$left = $right;
					$right = $tmp;
				}
				
				// Als het linkerdeel niet de huidige range hit, dan kan hij zeker niet required worden
				if ($left->getEntityName() !== $range->getEntityName()) {
					continue;
				}
				
				// Zoek een RequiredRelation annotatie op
				$this->setRangeRequiredIfNeeded($mainRange, $range, $left, $right);
			}
		}
		
		/**
		 * Controleert dat er geen operaties zoals '+' worden uitgevoerd op hele entities.
		 * Dit kan alleen maar op properties.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function validateNoExpressionsAllowedOnEntitiesValidator(AstRetrieve $ast): void {
			// Creëert een nieuwe instantie van 'EntityExistenceValidator' met de bereiken die uit het AST-object worden opgehaald.
			$rangeProcessor = new NoExpressionsAllowedOnEntitiesValidator();
			
			// Het AST-object accepteert de 'rangeProcessor' voor verdere verwerking.
			$ast->accept($rangeProcessor);
		}
	    
	    
	    /**
	     * Sets the appropriate child relationship between parent and item nodes in the AST.
	     * This helper method is used when processing the 'exists' operator to properly
	     * structure the Abstract Syntax Tree (AST).
	     * @param AstInterface $parent     The parent node in the AST
	     * @param AstInterface $item       The child node to be linked to the parent
	     * @param bool $parentLeft         Flag indicating if the item should be set as the left child
	     *                                 (true = left child, false = right child)
	     * @return void
	     */
	    private function handleExistsOperatorHelperSetParent(AstInterface $parent, AstInterface $item, bool $parentLeft): void {
		    // Special case: If parent is an AstRetrieve node, set the item as its conditions
		    if ($parent instanceof AstRetrieve) {
			    $parent->setConditions($item);
		    }
		    
			// If parentLeft flag is true, set the item as the left child of the parent
		    elseif ($parentLeft) {
			    $parent->setLeft($item);
		    }
		    
			// Otherwise, set the item as the right child of the parent
		    else {
			    $parent->setRight($item);
		    }
	    }
		
	    /**
	     * Handles the EXISTS operator in Abstract Syntax Tree (AST) transformations
	     *
	     * Recursively processes EXISTS operators by:
	     * 1. Handling nested AND/OR operations
	     * 2. Replacing EXISTS nodes with their conditions
	     * 3. Updating parent node connections
	     * @param AstInterface|null $parent Parent node in AST
	     * @param AstInterface $item Current node being processed
	     * @param array &$list Reference to list being populated
	     * @param bool $parentLeft Whether current item is left child of parent
	     */
	    private function handleExistsOperatorHelper(?AstInterface $parent, AstInterface $item, array &$list, bool $parentLeft = false): void {
		    // Process left branch for AND/OR operations
		    if ($item->getLeft() instanceof AstBinaryOperator) {
			    $this->handleExistsOperatorHelper($item, $item->getLeft(), $list, true);
		    }
		    
		    // Process right branch for AND/OR operations
		    if ($item->getRight() instanceof AstBinaryOperator) {
			    $this->handleExistsOperatorHelper($item, $item->getRight(), $list, false);
		    }
		    
		    // Get left and right nodes
		    $left = $item->getLeft();
		    $right = $item->getRight();

			// Special case for 'exist AND/OR exists' as only condition
		    if ($parent instanceof AstRetrieve && $left instanceof AstExists && $right instanceof AstExists) {
			    $list[] = $left;
			    $list[] = $right;
				$parent->setConditions(null);
				return;
		    }
		    
		    // Handle EXISTS operator in left branch
		    if ($left instanceof AstExists) {
			    $list[] = $left;
				$this->handleExistsOperatorHelperSetParent($parent, $right, $parentLeft);
		    }
		    
		    // Handle EXISTS operator in right branch
		    if ($right instanceof AstExists) {
			    $list[] = $right;
			    $this->handleExistsOperatorHelperSetParent($parent, $left, $parentLeft);
		    }
	    }
		
	    /**
	     * This function locates all uses of EXISTS(<entity>) in the WHERE sections.
	     * It then removes the keyword from the query (because it can't be translated to SQL).
	     * For every EXISTS it sets the accompanied range to mandatory. This forces an INNER JOIN.
	     * @param AstRetrieve $ast
	     * @return void
	     */
		private function handleExistsOperator(AstRetrieve $ast): void {
			// Fetch the conditions from the AST
			$conditions = $ast->getConditions();
			
			// No conditions. Do nothing
			if ($conditions === null) {
				return;
			}
			
			// If the only condition is AstExists. Clear the conditions.
			// If the condition is 'exists AND exists' or 'exists OR exists' clear the condition.
			// Otherwise use recursion to fetch and remove all exists operators.
			$astExistsList = [];

			if ($conditions instanceof AstExists) {
				$astExistsList = [$conditions];
				$ast->setConditions(null);
			} elseif ($conditions instanceof AstBinaryOperator) {
				$this->handleExistsOperatorHelper($ast, $conditions, $astExistsList);
			}

			// Set all targeted ranges to mandatory
			foreach ($astExistsList as $exists) {
				$existsRange = $exists->getEntity()->getRange();
				
				foreach($ast->getRanges() as $range) {
					if ($range->getName() == $existsRange->getName()) {
						$range->setRequired();
						continue 2;
					}
				}
			}
		}
	    
	    /**
	     * Adds referenced field values to the retrieve query's value list.
	     * This helps ensure that fields used in join conditions are included in the query results.
	     * @param AstRetrieve $ast The abstract syntax tree of the retrieve query
	     * @return void
	     */
	    private function addReferencedValuesToValuesList(AstRetrieve $ast): void {
		    // Do nothing when the query has no conditions - no references to gather
		    if ($ast->getConditions() === null) {
			    return;
		    }
		    
		    // Create a visitor that collects all field identifiers referenced in join conditions
		    // The visitor pattern traverses the AST nodes and gathers referenced values
		    $visitor = new GatherReferenceJoinValues();
		    $ast->getConditions()->accept($visitor);
		    
		    // Iterate through all the gathered identifiers and add them to the query's value list
		    foreach($visitor->getIdentifiers() as $identifier) {
			    // Clone the identifier to avoid modifying the original reference in the conditions
			    $clonedIdentifier = $identifier->deepClone();
			    
			    // Create an alias for the identifier using its complete name
			    // This ensures the field appears in the result set with its full reference name
			    $alias = new AstAlias($identifier->getCompleteName(), $clonedIdentifier);
				$alias->setVisibleInResult(false);
			    
			    // Add the aliased identifier to the query's value list
			    $ast->addValue($alias);
		    }
	    }
		
		/**
		 * Validates the given AstRetrieve object.
		 * @param AstRetrieve $ast The AstRetrieve object to be validated.
		 * @return void
		 * @throws QuelException
		 */
		private function validateAstRetrieve(AstRetrieve $ast): void {
			// Voeg macro's in
			$this->plugMacros($ast);
			
			// Valideer dat aangemaakte ranges uniek zijn
			$this->validateNoDuplicateRanges($ast);
			
			// Zoekt identifiers op die worden aangesproken op hun range naam, en vervangt deze door de entity naam.
			// De range wordt in het range-gedeelte van de entity gezet.
			$this->processRanges($ast);
			
			// Zoekt entities op die worden aangesproken op hun alias. Het is niet mogelijk om condities
			// te bouwen op hele entities, dus dit geeft later een foutmelding.
			$this->processMacros($ast);
			
			// Als entities op hun eigen naam worden aangesproken, maak dan impliciet een range aan.
			$this->plugRanges($ast);
			
			// Valideer dat er tenminste één range is zonder via clausule
			$this->validateAtLeastOneRangeExistsWithoutViaClause($ast);
			
			// Ranges mogen alleen andere ranges aanspreken. Dit wordt hier gevalideerd
			$this->ensureRangesOnlyReferenceOtherRanges($ast);
			
			// Voeg namespaces toe aan entities
			$this->addNamespacesToEntities($ast);
			
			// Valideer dat aangesproken entities bestaan
			$this->validateEntitiesExist($ast);
			
			// Als er directe relaties worden gebruikt in de 'via' clausule, valideer deze dan op geldigheid
			$this->validateRangeViaRelations($ast);
			
			// Zoekt aangesproken relaties op in de 'via' clausule, en zet ze om in property lookups
			$this->transformViaRelationsIntoProperties($ast);
			
			// Valideer dat properties binnen entities bestaan
			$this->validateProperties($ast);
			
			// Valideer dat er geen hele entities worden gebruikt als condities.
			$this->validateNoExpressionsAllowedOnEntitiesValidator($ast);
			
			// Voeg 'alias patterns' toe indien nodig. Deze patterns maken het makkelijk
			// om gegevens uit het SQL-resultaat te hydrateren.
			$this->plugAliasPatterns($ast);

			// Analyse ranges and set the required flag if its 'via' clause matches a RequiredRelation
			$this->setRangesRequiredThroughRequiredRelationAnnotation($ast);

			// Analyse query and set the required flag if a range is used in the where clause
			$this->setRangesRequiredThroughWhere($ast);
	
			// Analyse where and clear the required flag if 'is null' used on the join column
			$this->setRangesNotRequiredThroughWhere($ast);
			
			// Handle exists() operator
			$this->handleExistsOperator($ast);
			
			// Add identifiers that are referenced in the WHERE statement, to the values list
			$this->addReferencedValuesToValuesList($ast);
		}
		
		/**
		 * @param AstRetrieve $ast
		 * @return void
		 * @throws QuelException
		 */
		private function validateAtLeastOneRangeExistsWithoutViaClause(AstRetrieve $ast): void {
			$databaseRangeCount = 0;
			$nonDatabaseRangeCount = 0;
			
			foreach($ast->getRanges() as $range) {
				// Skip JSON ranges
				if (!$range instanceof AstRangeDatabase) {
					++$nonDatabaseRangeCount;
					continue;
				}
				
				// If the range has no 'via', the condition is met
				if ($range->getJoinProperty() === null) {
					return;
				}
				
				// Increase databaseRangeCount
				++$databaseRangeCount;
			}

			// Only
			if ($databaseRangeCount > 0) {
				throw new QuelException("The query must include at least one range definition without a 'via' clause. This serves as the 'FROM' clause in SQL and is essential for defining the data source for the query.");
			}
		}
		
		/**
		 * Ensures that ranges only refer to other ranges.
		 * @param AstRetrieve $ast The AST object.
		 * @return void
		 * @throws QuelException
		 */
		private function ensureRangesOnlyReferenceOtherRanges(AstRetrieve $ast): void {
			$rangeOnlyOtherRanges = new RangeOnlyReferencesOtherRanges();
			
			foreach ($ast->getRanges() as $range) {
				try {
					$joinProperty = $range->getJoinProperty();
					
					if ($joinProperty !== null) {
						$joinProperty->accept($rangeOnlyOtherRanges);
					}
				} catch (QuelException $e) {
					throw new QuelException(sprintf($e->getMessage(), $range->getName()));
				}
			}
		}
		
		/**
		 * Ensures that ranges only refer to other ranges.
		 * @param AstRetrieve $ast The AST object.
		 * @return void
		 * @throws QuelException
		 */
		private function validateRangeViaRelations(AstRetrieve $ast): void {
			foreach ($ast->getRanges() as $range) {
				try {
					$joinProperty = $range->getJoinProperty();
					
					if ($joinProperty !== null) {
						$rangeOnlyOtherRanges = new ValidateRelationInViaValid($this->entityStore, $range->getEntityName(), $range->getName());
						$joinProperty->accept($rangeOnlyOtherRanges);
					}
				} catch (QuelException $e) {
					throw new QuelException(sprintf($e->getMessage(), $range->getName()));
				}
			}
		}
		
		
		/**
		 * Default way of adding pagination
		 * @param AstRetrieve $e
		 * @param array $parameters
		 * @param array $primaryKeyInfo
		 * @return void
		 */
		private function addPaginationDataToQueryDefault(AstRetrieve &$e, array $parameters, array $primaryKeyInfo): void {
			// Bewaar de originele query waardes om later te herstellen.
			$originalValues = $e->getValues();
			$originalUnique = $e->getUnique();
			
			// Forceer de query om unieke resultaten te retourneren.
			$e->setUnique(true);
			
			// Maak een nieuw AST-element voor de primaire sleutel.
			$astIdentifier = new AstIdentifier($primaryKeyInfo['entityName']);
			$astIdentifier->setNext(new AstIdentifier($primaryKeyInfo['primaryKey']));
			$e->setValues([new AstAlias("primary", $astIdentifier)]);
			
			// Converteer de aangepaste AstRetrieve naar SQL en voer uit.
			$sql = $this->convertToSQL($e, $parameters);
			$primaryKeys = $this->connection->GetCol($sql, $parameters);
			$this->fullQueryResultCount = count($primaryKeys);
			
			// Filter de primaire sleutels voor de specifieke paginatie window.
			$primaryKeysFiltered = array_slice($primaryKeys, $e->getWindow() * $e->getWindowSize(), $e->getWindowSize());
			$newParameters = array_map(function($item) { return new AstNumber($item); }, $primaryKeysFiltered);
			
			// Herstel de originele query waarden.
			$e->setValues($originalValues);
			$e->setUnique($originalUnique);
			
			// Kijk of AstIn al in de query voorkomt. Zo ja, vervang dan de parameters
			try {
				$visitor = new GetMainEntityInAst($astIdentifier);
				$e->getConditions()->accept($visitor);
			} catch (GetMainEntityInAstException $exception) {
				$exception->getAstObject()->setParameters($newParameters);
				return;
			}
			
			// Creeër een AstIn-condition met de gefilterde primaire sleutels.
			$astIn = new AstIn($astIdentifier, $newParameters);
			
			// Voeg de nieuwe condition toe aan de bestaande conditions of vervang deze.
			if ($e->getConditions() === null) {
				$e->setConditions($astIn);
			} else {
				$e->setConditions(new AstBinaryOperator($e->getConditions(), $astIn, "AND"));
			}
		}
		
		/**
		 * Directly manipulate the values in 'IN()' without extra queries
		 * @param AstRetrieve $e
		 * @param array $parameters
		 * @param array $primaryKeyInfo
		 * @return void
		 */
		private function addPaginationDataToQuerySkipInValidation(AstRetrieve &$e, array $parameters, array $primaryKeyInfo): void {
			try {
				// Maak een AstIdentifier waarmee we kunnen zoeken naar een IN()
				$astIdentifier = new AstIdentifier($primaryKeyInfo['entityName']);
				$astIdentifier->setNext(new AstIdentifier($primaryKeyInfo['primaryKey']));
				
				// Zoek de IN() op in de query. An exception thrown here means it's found and is not an error
				$visitor = new GetMainEntityInAst($astIdentifier);
				$e->getConditions()->accept($visitor);
				
				// Als de IN() niet is gevonden, ga dan door met default logica
				$this->addPaginationDataToQueryDefault($e, $parameters, $primaryKeyInfo);
			} catch (GetMainEntityInAstException $exception) {
				$astObject = $exception->getAstObject();
				
				// Sla de lengte van de parameters op
				$this->fullQueryResultCount = count($astObject->getParameters());
				
				// Pas de IN() lijst aan
				$primaryKeysFiltered = array_slice($astObject->getParameters(), $e->getWindow() * $e->getWindowSize(), $e->getWindowSize());
				$astObject->setParameters($primaryKeysFiltered);
			}
		}
		
		/**
		 * Adds pagination data to an AstRetrieve query by manipulating the query
		 * to only retrieve the primary keys of the requested page.
		 * This is done by first transforming the query to only return the primary keys,
		 * then retrieving the relevant subset of primary keys based on the pagination parameters,
		 * and finally restoring the original query with a modified condition that only contains the filtered keys.
		 * @param AstRetrieve $e
		 * @param array $parameters
		 * @return void
		 */
		private function addPaginationDataToQuery(AstRetrieve &$e, array $parameters): void {
			// Check and retrieve the primary key information.
			$primaryKeyInfo = $this->entityStore->fetchPrimaryKeyOfMainRange($e);
			
			if ($primaryKeyInfo === null) {
				return;
			}
			
			// If the compiler directive @SkipInValidation is provided, skip the extra
			// Queries and work directly with the IN values
			$compilerDirectives = $e->getDirectives();
			
			if (isset($compilerDirectives['InValuesAreFinal']) && ($compilerDirectives['InValuesAreFinal'] === true)) {
				$this->addPaginationDataToQuerySkipInValidation($e, $parameters, $primaryKeyInfo);
			} else {
				$this->addPaginationDataToQueryDefault($e, $parameters, $primaryKeyInfo);
			}
		}
		
		/**
         * Parses a Quel query and returns its AST representation.
         * This function takes a Quel query string and processes it through a lexer and parser
         * to generate an Abstract Syntax Tree (AST) representation of the query. It returns
         * the AST if the parsing is successful and the AST is of type `AstRetrieve`.
         * Otherwise, it returns null.
         * @param string $query The Quel query string.
		 * @param array $parameters
		 * @return AstRetrieve|null The AST representation of the query or null if parsing fails.
         * @throws QuelException If there is a problem during lexing or parsing.
         */
        public function parse(string $query, array $parameters=[]): ?AstRetrieve {
            try {
                // Initialize the lexer with the query to tokenize the input.
                $lexer = new Lexer($query);
                
                // Initialize the parser with the lexer to interpret the tokens and create the AST.
                $parser = new Parser($lexer);
                
                // Parse the query to generate the AST.
                $ast = $parser->parse();
                
                // Check if the generated AST is of type AstRetrieve.
                // If it is not, handle the unexpected AST type by returning null.
                if (!$ast instanceof AstRetrieve) {
                    // Handle unexpected AST type, log error, or throw exception
                    return null;
                }
                
                // Validate the retrieved AST to ensure it meets the expected criteria.
                $this->validateAstRetrieve($ast);
				
				// Als de query paginering gebruikt ('window x using page_size y'), dan moeten we
				// de query aanpassen om te bepalen welke primary keys vallen binnen het window.
				if (($ast->getWindow() !== null) && !$ast->getSortInApplicationLogic()) {
					$this->addPaginationDataToQuery($ast, $parameters);
				}
				
				// Return the valid AST.
                return $ast;
            } catch (LexerException | ParserException $e) {
                // Catch lexer and parser exceptions, wrap them in a QuelException, and rethrow.
                throw new QuelException($e->getMessage());
            }
        }

		/**
		 * Convert AstRetrieve node to SQL
		 * @param AstRetrieve $retrieve
		 * @param array $parameters
		 * @return string
		 */
		public function convertToSQL(AstRetrieve $retrieve, array &$parameters): string {
			$quelToSQL = new QuelToSQL($this->entityStore, $parameters);
			return $quelToSQL->convertToSQL($retrieve);
		}
		
		/**
		 * Returns the full query results count when paginating
		 * @return int
		 */
		public function getFullQueryResultCount(): int {
			return $this->fullQueryResultCount;
		}
	}