<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Represents a table that could potentially serve as the query anchor.
	 */
	class AnchorCandidate {
		private int $index;
		private AstRange $range;
		private int $priorityScore;
		private string $strategy;
		private bool $viable;
		
		public function __construct(
			int      $index,
			AstRange $range,
			int      $priorityScore,
			string   $strategy,
			bool     $viable
		) {
			$this->viable = $viable;
			$this->strategy = $strategy;
			$this->priorityScore = $priorityScore;
			$this->range = $range;
			$this->index = $index;
		}
		
		public function getIndex(): int {
			return $this->index;
		}
		
		public function getRange(): AstRange {
			return $this->range;
		}
		
		public function getPriorityScore(): int {
			return $this->priorityScore;
		}
		
		public function getStrategy(): string {
			return $this->strategy;
		}
		
		public function isViable(): bool {
			return $this->viable;
		}
	}