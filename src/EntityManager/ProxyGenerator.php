<?php
	
	namespace Services\EntityManager;
	
	use Services\Kernel\ReflectionHandler;
	
	class ProxyGenerator {
		
		protected EntityStore $entityStore;
		protected ReflectionHandler $reflectionHandler;
		protected \Services\AnnotationsReader\AnnotationsReader $annotationReader;
		protected string|bool $servicesPath;
		protected array $types;
		protected array $proxyList;
		
		/**
		 * ProxyHandler constructor
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
			$this->reflectionHandler = $entityStore->getReflectionHandler();
			$this->annotationReader = $entityStore->getAnnotationReader();
			$this->servicesPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . "..");
			$this->types = ["int", "float", "bool", "string", "array", "object", "resource", "null", "callable", "iterable", "mixed", "false", "void", "static"];
			$this->proxyList = [];
			
			$this->initializeProxies();
		}
		
		/**
		 * Deze functie initialiseert alle entiteiten in de "Entity"-directory.
		 * @return void
		 */
		private function initializeProxies(): void {
			// Het pad naar de "Entity"-directory.
			$proxyDirectory = __DIR__ . DIRECTORY_SEPARATOR . "Proxies";
			$entityDirectory = $this->servicesPath . DIRECTORY_SEPARATOR . "Entity";
			
			// Ophalen van alle bestandsnamen in de "Entity"-directory.
			$entityFiles = scandir($entityDirectory);
			
			// Itereren over alle bestanden in de "Entity"-directory.
			foreach ($entityFiles as $fileName) {
				// Overslaan als het bestand geen PHP-bestand is.
				if (!$this->isPHPFile($fileName)) {
					continue;
				}
				
				// Construeren van de entiteitsnaam op basis van de bestandsnaam.
				$entityName = $this->constructEntityName($fileName);
				
				// Overslaan als het niet als een entiteit wordt herkend.
				if (!$this->isEntity($entityName)) {
					continue;
				}
				
				// Controleer of de Proxy bestaat in de proxies folder
				$entityFilePath = $entityDirectory . DIRECTORY_SEPARATOR . $fileName;
				$proxyFilePath = $proxyDirectory . DIRECTORY_SEPARATOR . $fileName;
				
				if (
					!file_exists($proxyFilePath) ||
					filemtime($entityFilePath) > filemtime($proxyFilePath)
				) {
					$proxyContents = $this->makeProxy($entityName);
					file_put_contents($proxyFilePath, $proxyContents);
				}
				
				// Keep the proxy in the list
				$this->proxyList[$entityName] = $this->constructProxyName($fileName);
			}
		}
		
		/**
		 * Controleert of het opgegeven bestand een PHP-bestand is.
		 * @param string $fileName Naam van het bestand.
		 * @return bool True als het een PHP-bestand is, anders false.
		 */
		private function isPHPFile(string $fileName): bool {
			$fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
			return ($fileExtension === 'php');
		}
		
		/**
		 * Checks if the entity is an ORM table
		 * @param string $entityName
		 * @return bool
		 */
		private function isEntity(string $entityName): bool {
			$annotations = $this->annotationReader->getClassAnnotations($entityName);
			return array_key_exists("Orm\\Table", $annotations);
		}
		
		/**
		 * Constructs the full entity name
		 * @param string $fileName
		 * @return string
		 */
		private function constructEntityName(string $fileName): string {
			return "Services\\Entity\\" . substr($fileName, 0, strpos($fileName, ".php"));
		}
		
		/**
		 * Constructs the full proxy entity name
		 * @param string $fileName
		 * @return string
		 */
		private function constructProxyName(string $fileName): string {
			return "Services\\EntityManager\\Proxies\\" . substr($fileName, 0, strpos($fileName, ".php"));
		}
		
		/**
		 * Returns the proxy template
		 * @return string
		 */
		protected function getTemplate(): string {
			return "
<?php
	namespace Services\EntityManager\Proxies;
	
	include_once('%s');
	
	%s
	class %s extends \%s implements \Services\EntityManager\ProxyInterface {
		%s
	}
";
		}
		
		/**
		 * Haalt de klassenaam van een gegeven entiteit op, zonder de namespace.
		 * @param mixed $classNameWithNamespace De entiteit waarvan we de klassenaam willen ophalen.
		 * @return string De klassenaam zonder de namespace.
		 */
		protected function getClassNameWithoutNamespace(mixed $classNameWithNamespace): string {
			return ltrim(strrchr($classNameWithNamespace, '\\'), '\\');
		}
		
		/**
		 * Maakt een stringrepresentatie van de eigenschappen van een gegeven entiteit,
		 * inclusief hun types, zichtbaarheid en documentatiecommentaar.
		 * @param mixed $entity De entiteit waarvan de eigenschappen worden opgehaald.
		 * @return string Een samengevoegde string die de eigenschappen van de entiteit beschrijft.
		 */
		protected function makeProxyProperties(mixed $entity): string {
			$result = [];
			
			// Loop door alle eigenschappen van de entiteit.
			foreach ($this->reflectionHandler->getProperties($entity) as $property) {
				// Haal het type, de zichtbaarheid en het documentatiecommentaar van elke eigenschap op.
				$type = $this->reflectionHandler->getPropertyType($entity, $property);
				$visibility = $this->reflectionHandler->getPropertyVisibility($entity, $property);
				$docComment = $this->reflectionHandler->getPropertyDocComment($entity, $property);
				
				// Voeg de informatie toe aan het resultaat.
				$result[] = "
					{$docComment}
					{$visibility} {$type} \${$property}
		        ";
			}
			
			// Voeg alle regels samen en retourneer als één string.
			return implode("\n", $result);
		}
		
		/**
		 * Convert a type to its string representation.
		 * @param string $type      The type to convert.
		 * @param bool $nullable    Indicates if the type can be null.
		 * @return string The string representation of the type.
		 */
		protected function typeToString(string $type, bool $nullable): string {
			// Special case for type 'mixed'
			if ($type === 'mixed') {
				return "mixed";
			}
			
			// Check if the type is not empty.
			$result = "";

			if ($type !== "") {
				// Check if the type is in the predefined types list.
				// If not, prepend it with a backslash.
				if (!in_array($type, $this->types)) {
					$result = "\\{$type}";
				} else {
					$result = $type;
				}
				
				// If the type is nullable, prepend it with a question mark.
				if ($nullable && ($result !== 'mixed')) {
					$result = "?{$result}";
				}
			}
			
			return $result;
		}
		
		/**
		 * Maakt een stringrepresentatie van de methods van een gegeven entiteit,
		 * inclusief hun types, zichtbaarheid en documentatiecommentaar.
		 * @param mixed $entity De entiteit waarvan de eigenschappen worden opgehaald.
		 * @return string Een samengevoegde string die de eigenschappen van de entiteit beschrijft.
		 */
		protected function makeProxyMethods(mixed $entity): string {
			$result = [];
			
			// Haal identifier keys op
			$identifierKeys = $this->entityStore->getIdentifierKeys($entity);
			$identifierKeysGetterMethod = 'get' . ucfirst($identifierKeys[0]);
			$hasConstructor = $this->reflectionHandler->hasConstructor($entity);
			$constructorParentCode = $hasConstructor ? "parent::__construct();" : "";
			
			// Voeg de constructor toe en de lazy load functie toe
			$result[] = "
				private \$entityManager;
				private \$initialized;
				
				public function __construct(\\Services\\EntityManager\\EntityManager \$entityManager) {
					\$this->entityManager = \$entityManager;
					\$this->initialized = false;
					{$constructorParentCode}
				}
				
				protected function doInitialize() {
					\$this->entityManager->find(\\{$entity}::class, \$this->{$identifierKeysGetterMethod}());
					\$this->setInitialized();
				}

				public function isInitialized(): bool {
					return \$this->initialized;
				}

				public function setInitialized(): void {
					\$this->initialized = true;
				}
			";
			
			// Loop door alle methoden van het gegeven object om proxy-methoden te genereren.
			foreach ($this->reflectionHandler->getMethods($entity) as $method) {
				// Sla de constructor en primary key getter over
				if (in_array($method, ["__construct", $identifierKeysGetterMethod])) {
					continue;
				}
				
				// Sla private functies over
				$visibility = $this->reflectionHandler->getMethodVisibility($entity, $method);
				
				if ($visibility === "private") {
					continue;
				}
				
				// Verkrijg belangrijke informatie over de methode via reflectie.
				$returnType = $this->reflectionHandler->getMethodReturnType($entity, $method);
				$returnTypeNullable = $this->reflectionHandler->methodReturnTypeIsNullable($entity, $method);
				$docComment = $this->reflectionHandler->getMethodDocComment($entity, $method);
				
				// Initialiseer een array om de parameterlijst op te bouwen.
				$parameterList = [];
				$parameters = $this->reflectionHandler->getMethodParameters($entity, $method);
				
				// Loop door de parameters en bouw de lijst op.
				foreach ($parameters as $parameter) {
					$parameterType = $this->typeToString($parameter["type"], $parameter["nullable"]);
					
					if (!$parameter["has_default"]) {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}";
					} elseif ($parameter["default"] === null) {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}=NULL";
					} elseif ($parameterType == "string") {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}='{$parameter["default"]}'";
					} else {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}={$parameter["default"]}";
					}
				}
				
				// Maak de uiteindelijke parameterlijst en parameter naam lijst.
				$parameterString = implode(",", $parameterList);
				$parameterNamesString = implode(",", array_map(function ($e) { return "\${$e}";}, array_column($parameters, "name")));
				$returnTypeString = $this->typeToString($returnType, $returnTypeNullable);
				$returnTypeString = !empty($returnTypeString) ? ": {$returnTypeString}" : "";
				
				// Functies die void retourneren, hebben geen return-statement. Anders crasht de boel.
				if (str_contains($returnTypeString, "void")) {
					$returnStatement = "";
				} else {
					$returnStatement = "return ";
				}
				
				// Voeg de proxy methode toe aan de resultatenlijst.
				$result[] = "
					{$docComment}
					{$visibility} function {$method}({$parameterString}){$returnTypeString} {
						\$this->doInitialize();
						{$returnStatement}parent::{$method}({$parameterNamesString});
					}
		        ";
			}
			
			// Voeg alle gegenereerde proxy-methoden samen tot één string en retourneer deze.
			return implode("\n", $result);
		}
		
		/**
		 * Create the contents of the proxy file for the given entity
		 * @param $entity
		 * @return string
		 */
		private function makeProxy($entity): string {
			$class = is_object($entity) ? get_class($entity) : $entity;
			
			return trim(sprintf(
				$this->getTemplate(),
				$this->reflectionHandler->getFilename($class),
				$this->reflectionHandler->getDocComment($class),
				$this->getClassNameWithoutNamespace($class),
				$class,
				$this->makeProxyMethods($class),
			));
		}
		
		/**
		 * Fetches the proxy for the given entity
		 * @param $entity
		 * @return mixed|null
		 */
		public function getProxyClass($entity): mixed {
			$class = is_object($entity) ? get_class($entity) : $entity;
			return $this->proxyList[$class] ?? null;
		}
	}