<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\FindPropertyRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

	/**
	 * Finds which of a routine's declared ranges an embedded statement reads.
	 * Every embedded statement is handed all ranges declared before it, but the
	 * query pipeline cross-joins any range it is given, so callers narrow the list.
	 */
	class RoutineRangeReferences extends FindPropertyRange {

		/**
		 * Ranges named by root identifiers, or reached through an unqualified property with exactly one owner.
		 * @param AstInterface $node Node to search; ranges attached to it are not searched
		 * @param AstRange[] $ranges Declared ranges
		 * @return array<string, AstRange> Referenced ranges keyed by name
		 * @throws EntityResolutionException
		 */
		public function direct(AstInterface $node, array $ranges): array {
			$rangesByName = [];

			foreach ($ranges as $range) {
				$rangesByName[$range->getName()] = $range;
			}

			$referenced = [];

			foreach ($this->rootIdentifiers($node) as $identifier) {
				$range = $rangesByName[$identifier->getName()] ?? null;

				if ($range !== null) {
					$referenced[$range->getName()] = $range;
					continue;
				}

				// Bare unqualified property (routine variables are typed already)
				if ($identifier->getNext() === null && $identifier->getType() === IdentifierType::Unresolved) {
					$matches = $this->findRanges($identifier->getName(), $ranges);

					if (count($matches) === 1) {
						$referenced[$matches[0]->getName()] = $matches[0];
					}
				}
			}

			return $referenced;
		}

		/**
		 * direct() plus every range the referenced ranges' `via` conditions depend on.
		 * @param AstInterface $node Node to search; ranges attached to it are not searched
		 * @param AstRange[] $ranges Declared ranges
		 * @return array<string, AstRange> Referenced ranges keyed by name
		 * @throws EntityResolutionException
		 */
		public function withJoinDependencies(AstInterface $node, array $ranges): array {
			$referenced = $this->direct($node, $ranges);
			$pending = $referenced;

			while (!empty($pending)) {
				$range = array_pop($pending);
				$joinProperty = $range->getJoinProperty();

				if ($joinProperty === null) {
					continue;
				}

				foreach ($this->direct($joinProperty, $ranges) as $name => $dependency) {
					if (!isset($referenced[$name])) {
						$referenced[$name] = $dependency;
						$pending[] = $dependency;
					}
				}
			}

			return $referenced;
		}

		/**
		 * @param AstInterface $node Node to search
		 * @return AstIdentifier[] Identifiers that start a chain
		 */
		private function rootIdentifiers(AstInterface $node): array {
			$collector = new CollectNodes(AstIdentifier::class);

			if ($node instanceof AstRetrieve) {
				$node->acceptWithoutRanges($collector);
			} else {
				$node->accept($collector);
			}

			return array_values(array_filter(
				$collector->getCollectedNodes(),
				fn(AstIdentifier $identifier) => !$identifier->getParent() instanceof AstIdentifier
			));
		}
	}
