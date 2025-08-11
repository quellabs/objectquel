<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * AST visitor implementation that checks for the presence of specific node types
	 * during tree traversal. This visitor is designed to detect unwanted or restricted
	 * node types within an Abstract Syntax Tree and throw an exception when found.
	 */
	class ContainsNode implements AstVisitorInterface {
		
		/**
		 * Array of node type class names to check for during AST traversal
		 * @var array Array of fully qualified class names representing restricted node types
		 */
		private array $nodeTypes;
		
		/**
		 * Constructor - Initialize the visitor with node types to detect
		 * @param array $nodeTypes Array of class names (strings) representing the node types
		 *                         that should trigger an exception when encountered during traversal
		 */
		public function __construct(array $nodeTypes) {
			$this->nodeTypes = $nodeTypes;
		}
		
		/**
		 * This method is called for each node during AST traversal. It checks if the current
		 * node is an instance of the restricted node types specified in the constructor.
		 * If a match is found, it immediately throws an exception to halt processing.
		 * @param AstInterface $node The current AST node being visited
		 * @return void This method doesn't return a value, but may throw an exception
		 * @throws \Exception Thrown when the node matches one of the restricted types,
		 *                   with a message indicating which node type was found
		 */
		public function visitNode(AstInterface $node): void {
			// Iterate through each restricted node type
			foreach ($this->nodeTypes as $nodeType) {
				// Check if the current node is an instance of the restricted type
				// Using is_a() for type checking (supports inheritance)
				if (is_a($node, $nodeType)) {
					// Throw exception immediately when restricted node type is found
					throw new \Exception(basename($nodeType));
				}
			}
		}
	}