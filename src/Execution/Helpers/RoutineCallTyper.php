<?php

	namespace Quellabs\ObjectQuel\Execution\Helpers;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\RoutineSignature;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

	/**
	 * Resolves routine calls in a query using the database's routine metadata.
	 */
	class RoutineCallTyper {

		/**
		 * Creates a typer that reads routine metadata through the adapter.
		 * @param DatabaseAdapter $connection Connection whose routine catalog is read
		 * @return void
		 */
		public function __construct(private readonly DatabaseAdapter $connection) {
		}

		/**
		 * Sets the return type of every expression call, looking each routine up once.
		 * @param AstInterface $query Query whose calls are typed in place
		 * @param bool $skipStatementCalls Whether standalone calls may name procedures
		 * @return void
		 * @throws QuelException When a routine is missing or ambiguous, is a procedure, or the lookup fails
		 */
		public function typeCalls(AstInterface $query, bool $skipStatementCalls = false): void {
			$collector = new CollectNodes(AstRoutineCall::class);
			$query->accept($collector);

			/** @var array<string, RoutineSignature> $signatures */
			$signatures = [];

			foreach ($collector->getCollectedNodes() as $call) {
				$name = $call->getName();
				$signatures[$name] ??= $this->connection->getRoutineSignature($name);
				$isStatementCall = $skipStatementCalls && $call->getParent() instanceof AstCall;

				// Unlike an ordinary procedure, a trigger-declared routine has no call path at
				// all outside an event attachment — not even as a standalone statement call.
				if ($signatures[$name]->isTrigger) {
					throw new QuelException("'{$name}' is declared 'trigger', which can only be invoked through an event attachment, not called directly.", 'routine_call_error');
				}

				if ($isStatementCall) {
					continue;
				}

				if ($signatures[$name]->isProcedure) {
					throw new QuelException("'{$name}' is a procedure, which returns no value; only a function can be called inside a query.", 'routine_call_error');
				}

				$call->setRoutineReturnType($signatures[$name]->returnType);
			}
		}

	}
