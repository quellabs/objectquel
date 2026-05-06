<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	
	final class PrimaryKeyInfo {
		public function __construct(
			public readonly AstRange $range,
			public readonly string   $entityName,
			public readonly string   $primaryKey,
		) {
		}
	}