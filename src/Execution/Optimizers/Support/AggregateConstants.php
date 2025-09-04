<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers\Support;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	class AggregateConstants {
		
		/** @var array<class-string<AstInterface>> Supported aggregate node classes. */
		public const array AGGREGATE_NODE_TYPES = [
			AstSum::class,
			AstSumU::class,
			AstCount::class,
			AstCountU::class,
			AstAvg::class,
			AstAvgU::class,
			AstMin::class,
			AstMax::class,
		];
		
		/** @var array<class-string<AstInterface>> DISTINCT-capable aggregate classes. */
		public const array DISTINCT_AGGREGATE_TYPES = [
			AstSumU::class,
			AstAvgU::class,
			AstCountU::class,
		];
	}
	
