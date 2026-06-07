<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;

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
		
		/** @var array<class-string<AstInterface>> NOT-DISTINCT-capable aggregate classes. */
		public const array NOT_DISTINCT_AGGREGATE_TYPES = [
			AstSum::class,
			AstCount::class,
			AstAvg::class,
			AstMin::class,
			AstMax::class
		];

		/** @var array<class-string<AstInterface>> DISTINCT-capable aggregate classes. */
		public const array DISTINCT_AGGREGATE_TYPES = [
			AstSumU::class,
			AstAvgU::class,
			AstCountU::class,
		];
	}
	
