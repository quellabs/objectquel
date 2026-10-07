<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AttachmentEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentNaming;

	/**
	 * Lowers an attachment to a PostgreSQL trigger. PostgreSQL triggers can't call a routine
	 * directly, so a small, deterministically named `RETURNS trigger` helper function is
	 * generated to do it — owned by this attachment, never meant to be called by EQUEL users.
	 */
	class PostgresEventAttachmentLowering extends EventAttachmentLowering {

		/**
		 * @return string
		 */
		protected function engineName(): string {
			return 'PostgreSQL';
		}

		/**
		 * @param AstEventAttachment $attachment The attachment
		 * @param string $alias The attachment's resolved alias
		 * @return list<string> The helper `CREATE FUNCTION` and the `CREATE TRIGGER` that uses it
		 * @throws EntityResolutionException
		 */
		public function render(AstEventAttachment $attachment, string $alias): array {
			$helperName = $this->quoter->quoteRoutineName(
				EventAttachmentNaming::helperFunctionName($this->triggerName($attachment, $alias)),
				$this->routineSchema
			);
			$routineCall = $this->quoter->quoteRoutineName($attachment->getRoutineName(), $this->routineSchema);

			$arguments = implode(', ', array_map(
				fn(array $arg) => strtoupper($arg['row']) . '.' . $this->quoter->quoteIdentifier($arg['column']),
				$this->expandArguments($attachment)
			));

			$returns = $attachment->getEvent() === AttachmentEvent::Delete ? 'OLD' : 'NEW';
			$body = "BEGIN\n\tCALL {$routineCall}({$arguments});\n\tRETURN {$returns};\nEND;";
			$tag = $this->dollarQuoteTag($body);
			$helper = "CREATE FUNCTION {$helperName}()\nRETURNS trigger\nLANGUAGE plpgsql\nAS {$tag}\n{$body}\n{$tag};";

			$triggerName = $this->quoter->quoteIdentifier($this->triggerName($attachment, $alias));
			$table = $this->quotedTable($attachment);
			$event = $this->sqlEvent($attachment->getEvent());
			$trigger = "CREATE TRIGGER {$triggerName}\nAFTER {$event} ON {$table}\nFOR EACH ROW EXECUTE FUNCTION {$helperName}();";

			return [$helper, $trigger];
		}

		/**
		 * @param string $table Physical table the attachment is on
		 * @param string $alias The attachment's alias
		 * @return list<string> DROP TRIGGER, then its helper's DROP FUNCTION (the design doc's
		 *         removal order — never the other way, since the trigger still references the
		 *         helper until it is itself gone)
		 */
		public function renderDestroy(string $table, string $alias): array {
			$triggerName = EventAttachmentNaming::triggerName($table, $alias);
			$helperName = $this->quoter->quoteIdentifier(EventAttachmentNaming::helperFunctionName($triggerName));
			$quotedTrigger = $this->quoter->quoteIdentifier($triggerName);
			$quotedTable = $this->quoter->quoteRoutineName($table, $this->routineSchema);

			return [
				"DROP TRIGGER {$quotedTrigger} ON {$quotedTable};",
				"DROP FUNCTION {$helperName}();",
			];
		}

		/**
		 * Picks a dollar-quote tag that doesn't occur in the body.
		 * @param string $body Function body
		 * @return string Tag such as `$body$`
		 */
		private function dollarQuoteTag(string $body): string {
			$tag = '$body$';

			for ($suffix = 1; str_contains($body, $tag); $suffix++) {
				$tag = "\$body{$suffix}\$";
			}

			return $tag;
		}
	}
