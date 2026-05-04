<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator\Generator;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	
	/**
	 * Generates PHP source code for proxy classes.
	 * Shared by both the file-based and runtime proxy strategies.
	 */
	class ProxyCodeGenerator {
		
		/** The entity store, used to look up entity metadata such as identifier keys. */
		private EntityStore $entityStore;
		
		/** Reflection handler for inspecting entity methods, parameters, and return types. */
		private ReflectionHandler $reflectionHandler;
		
		/**
		 * Primitive scalar and pseudo-types that do not require a leading backslash.
		 */
		private const array SCALAR_TYPES = [
			"int", "float", "bool", "string", "array", "object", "resource", "null",
			"callable", "iterable", "mixed", "false", "true", "void", "static", "never",
		];
		
		/**
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
			$this->reflectionHandler = $entityStore->getReflectionHandler();
		}
		
		/**
		 * Generates the complete proxy class source for a given entity.
		 * @param string $entity Fully-qualified entity class name
		 * @param string $namespace Proxy namespace to use
		 * @param string|null $overrideClassName Use a different class name (for runtime proxies)
		 * @return string
		 */
		public function makeProxy(string $entity, string $namespace, ?string $overrideClassName = null): string {
			$shortName = $overrideClassName ?? $this->getClassNameWithoutNamespace($entity);
			$docComment = $this->reflectionHandler->getDocComment($entity);
			
			return $this->renderTemplate(
				$namespace,
				$docComment,
				$shortName,
				$entity,
				$this->makeProxyMethods($entity)
			);
		}
		
		/**
		 * Returns the class name without its namespace prefix.
		 * @param string $classNameWithNamespace
		 * @return string
		 */
		public function getClassNameWithoutNamespace(string $classNameWithNamespace): string {
			$pos = strrpos($classNameWithNamespace, '\\');
			return $pos === false ? $classNameWithNamespace : substr($classNameWithNamespace, $pos + 1);
		}
		
		/**
		 * Returns the Heredoc template used to render a file-based proxy class.
		 * The autoloader is responsible for loading the parent entity class;
		 * no include_once is emitted.
		 * @param string $namespace Target proxy namespace
		 * @param string $docComment Doc-comment from the entity class (may be empty)
		 * @param string $shortName Class name without namespace
		 * @param string $fqcn Fully-qualified entity class name
		 * @param string $methods Generated proxy method body
		 * @return string
		 */
		private function renderTemplate(
			string $namespace,
			string $docComment,
			string $shortName,
			string $fqcn,
			string $methods
		): string {
			// Normalise the class doc comment: strip per-line leading whitespace so it sits
			// flush with the class declaration regardless of the source file's indentation.
			$normalizedDocComment = $this->normalizeDocComment($docComment, '');
			
			// The backslash before the FQCN must sit outside the interpolation block;
			// otherwise PHP treats \{ as an escape sequence and emits it literally.
			$extends = '\\' . $fqcn;
			
			return trim(<<<PHP
<?php

namespace {$namespace};

{$normalizedDocComment}
class {$shortName} extends {$extends} implements \Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface {
{$methods}
}
PHP
			);
		}
		
		/**
		 * Strips leading whitespace from every line of a doc comment so it renders
		 * flush with the target indentation in the generated file, regardless of
		 * how it was indented in the original source.
		 * @param string $docComment
		 * @param string $indent
		 * @return string
		 */
		private function normalizeDocComment(string $docComment, string $indent = ''): string {
			if ($docComment === '') {
				return '';
			}
			
			// Strip all leading whitespace from each line, then re-apply the target indent.
			// Lines starting with '* ' (the interior doc comment lines) get an extra leading
			// space so they render as ' * ' rather than '* ', matching PSR-5 doc comment style.
			$lines = explode("\n", $docComment);
			
			$normalized = array_map(function (string $line) use ($indent): string {
				$stripped = ltrim($line);
				
				if ($stripped === '') {
					return '';
				}
				
				// Interior and closing doc comment lines: preserve the conventional ' * ' / ' */' format.
				if (str_starts_with($stripped, '* ') || $stripped === '*' || $stripped === '*/') {
					return $indent . ' ' . $stripped;
				}
				
				return $indent . $stripped;
			}, $lines);
			
			return implode("\n", $normalized);
		}
		
		/**
		 * Converts a reflected type string to a valid PHP type hint, handling union
		 * types (int|string), intersection types (Countable&Iterator), nullable
		 * shorthand (?T), and class names that require a leading backslash.
		 * @param string $type The raw type string from reflection
		 * @param bool $nullable Whether the type is nullable (adds ? prefix for simple types)
		 * @return string
		 */
		private function typeToString(string $type, bool $nullable): string {
			if ($type === '') {
				return '';
			}
			
			// Union or intersection types — qualify each individual segment that needs it,
			// but never add a nullable ? prefix (the type already expresses nullability
			// explicitly if it contains "null").
			if (str_contains($type, '|') || str_contains($type, '&')) {
				$separator = str_contains($type, '|') ? '|' : '&';
				$parts = explode($separator, $type);
				
				$qualifiedParts = array_map(function (string $part) {
					$trimmed = trim($part);
					
					// Preserve leading backslash when already fully qualified
					if (str_starts_with($trimmed, '\\')) {
						return $trimmed;
					}
					
					return in_array($trimmed, self::SCALAR_TYPES, true) ? $trimmed : "\\{$trimmed}";
				}, $parts);
				
				return implode($separator, $qualifiedParts);
			}
			
			// mixed and void cannot be nullable
			if (in_array($type, ['mixed', 'void', 'never'], true)) {
				return $type;
			}
			
			// self, static, parent — pass through without a leading backslash
			if (in_array($type, ['self', 'static', 'parent'], true)) {
				return $nullable ? "?{$type}" : $type;
			}
			
			// Preserve an already-fully-qualified class name
			if (str_starts_with($type, '\\')) {
				return $nullable ? "?{$type}" : $type;
			}
			
			$result = in_array($type, self::SCALAR_TYPES, true) ? $type : "\\{$type}";
			return $nullable ? "?{$result}" : $result;
		}
		
		/**
		 * Builds the constructor parameter string for forwarding arguments to the
		 * parent entity constructor.
		 * @param string $entity Fully-qualified entity class name
		 * @return array{declaration: string, passthrough: string}
		 */
		private function buildParentConstructorArgs(string $entity): array {
			if (!$this->reflectionHandler->hasConstructor($entity)) {
				return ['declaration' => '', 'passthrough' => ''];
			}
			
			$parameters = $this->reflectionHandler->getMethodParameters($entity, '__construct');
			$declaration = [];
			$passthrough = [];
			
			foreach ($parameters as $parameter) {
				$parameterType = $this->typeToString($parameter['type'], $parameter['nullable']);
				
				if (!$parameter['has_default']) {
					$declaration[] = ltrim("{$parameterType} \${$parameter['name']}");
				} elseif ($parameter['default'] === null) {
					$declaration[] = ltrim("{$parameterType} \${$parameter['name']} = null");
				} elseif ($parameter['type'] === 'string') {
					$escaped = addslashes($parameter['default']);
					$declaration[] = ltrim("{$parameterType} \${$parameter['name']} = '{$escaped}'");
				} else {
					$declaration[] = ltrim("{$parameterType} \${$parameter['name']} = {$parameter['default']}");
				}
				
				$passthrough[] = "\${$parameter['name']}";
			}
			
			return [
				'declaration' => implode(', ', $declaration),
				'passthrough' => implode(', ', $passthrough),
			];
		}
		
		/**
		 * Generates the PHP source for all proxy methods of the given entity.
		 * @param string $entity Fully-qualified entity class name
		 * @return string
		 */
		private function makeProxyMethods(string $entity): string {
			$result = [];
			
			$identifierKeys = $this->entityStore->getIdentifierKeys($entity);
			$identifierKeysGetterMethod = 'get' . ucfirst($identifierKeys[0]);
			
			['declaration' => $ctorDeclaration, 'passthrough' => $ctorPassthrough] =
				$this->buildParentConstructorArgs($entity);
			
			// Only forward args to parent when the entity actually declares a constructor.
			// When there are no parameters, omit the call entirely rather than emitting
			// a bare parent::__construct() that may not exist on the entity.
			if ($ctorPassthrough !== '') {
				$parentCtorCall = "parent::__construct({$ctorPassthrough});";
			} elseif ($this->reflectionHandler->hasConstructor($entity)) {
				$parentCtorCall = "parent::__construct();";
			} else {
				$parentCtorCall = "";
			}
			
			// Append entity constructor args after $entityManager only when present,
			// avoiding a trailing comma in the signature when the entity has none.
			$ctorExtraArgs = $ctorDeclaration !== '' ? ", {$ctorDeclaration}" : '';
			
			// The backslash before the FQCN must sit outside the interpolation block;
			// otherwise PHP treats \{ as an escape sequence and emits it literally.
			$entityClass = '\\' . $entity;
			
			$result[] = <<<PHP

    /**
     * The EntityManager instance used to lazy-load entity data from the database.
     * @var \Quellabs\ObjectQuel\EntityManager
     */
    protected \Quellabs\ObjectQuel\EntityManager \$entityManager;

    /**
     * Whether the proxy has been initialized with actual entity data.
     * @var bool
     */
    protected bool \$initialized;

    /**
     * Creates an uninitialised proxy. Entity data is loaded on first access.
     * @param \Quellabs\ObjectQuel\EntityManager \$entityManager
     */
    public function __construct(\Quellabs\ObjectQuel\EntityManager \$entityManager{$ctorExtraArgs}) {
        \$this->entityManager = \$entityManager;
        \$this->initialized   = false;
        {$parentCtorCall}
    }

    /**
     * Loads the full entity from the database on first access.
     * @return void
     */
    protected function doInitialize(): void {
        \$this->entityManager->find({$entityClass}::class, \$this->{$identifierKeysGetterMethod}());
        \$this->setInitialized();
    }

    /**
     * Returns true when the proxy has been populated with entity data.
     * @return bool
     */
    public function isInitialized(): bool {
        return \$this->initialized;
    }

    /**
     * Marks the proxy as initialised.
     * @return void
     */
    public function setInitialized(): void {
        \$this->initialized = true;
    }
PHP;
			
			foreach ($this->reflectionHandler->getMethods($entity) as $method) {
				// Skip the constructor and the primary-key getter (called before initialization)
				if (in_array($method, ['__construct', $identifierKeysGetterMethod], true)) {
					continue;
				}
				
				// Private methods are not accessible from the proxy subclass, so skip them.
				$visibility = $this->reflectionHandler->getMethodVisibility($entity, $method);
				
				if ($visibility === 'private') {
					continue;
				}
				
				// Gather everything needed to reconstruct the method signature in the proxy.
				$returnType = $this->reflectionHandler->getMethodReturnType($entity, $method);
				$returnTypeNullable = $this->reflectionHandler->methodReturnTypeIsNullable($entity, $method);
				$docComment = $this->normalizeDocComment($this->reflectionHandler->getMethodDocComment($entity, $method), '    ');
				$parameters = $this->reflectionHandler->getMethodParameters($entity, $method);
				
				$parameterList = [];  // typed declarations, e.g. "string $name = 'foo'"
				$parameterNames = [];  // bare names for the parent:: call, e.g. "$name"
				
				foreach ($parameters as $parameter) {
					$parameterType = $this->typeToString($parameter['type'], $parameter['nullable']);
					$parameterNames[] = "\${$parameter['name']}";
					
					// Build the typed declaration, handling the three possible default-value forms:
					// no default, explicit null, string literal (needs escaping), or scalar/expression.
					if (!$parameter['has_default']) {
						$parameterList[] = ltrim("{$parameterType} \${$parameter['name']}");
					} elseif ($parameter['default'] === null) {
						$parameterList[] = ltrim("{$parameterType} \${$parameter['name']} = null");
					} elseif ($parameter['type'] === 'string') {
						$escaped = addslashes($parameter['default']);
						$parameterList[] = ltrim("{$parameterType} \${$parameter['name']} = '{$escaped}'");
					} else {
						$parameterList[] = ltrim("{$parameterType} \${$parameter['name']} = {$parameter['default']}");
					}
				}
				
				$parameterString = implode(', ', $parameterList);
				$parameterNamesString = implode(', ', $parameterNames);
				$returnTypeString = $this->typeToString($returnType, $returnTypeNullable);
				$returnTypeHint = $returnTypeString !== '' ? ": {$returnTypeString}" : '';
				
				// Void and never must not have a return statement
				$returnsValue = $returnTypeString !== '' && !in_array($returnTypeString, ['void', 'never'], true);
				$returnStatement = $returnsValue ? 'return ' : '';
				
				// Add function
				$result[] = <<<PHP

{$docComment}
    {$visibility} function {$method}({$parameterString}){$returnTypeHint} {
        \$this->doInitialize();
        {$returnStatement}parent::{$method}({$parameterNamesString});
    }
PHP;
			}
			
			return implode("\n", $result);
		}
	}