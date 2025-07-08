<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	interface AstVisitorInterface {
		public function visitNode(AstInterface $node);
	}