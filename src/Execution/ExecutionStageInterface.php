<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	interface ExecutionStageInterface {
		public function getName(): string;
		public function hasResultProcessor(): bool;
		public function getResultProcessor(): ?callable;
		public function getStaticParams(): array;
	}