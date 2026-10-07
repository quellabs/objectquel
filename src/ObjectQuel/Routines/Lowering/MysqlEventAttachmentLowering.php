<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventAttachmentNaming;

	/**
	 * Lowers an attachment to a MySQL/MariaDB trigger that calls the routine directly.
	 * `CALL routine(args);` is itself a single valid statement, so the trigger body needs no
	 * BEGIN/END wrapper.
	 */
	class MysqlEventAttachmentLowering extends EventAttachmentLowering {

		/**
		 * @return string
		 */
		protected function engineName(): string {
			return $this->platform->getDatabaseType() === 'mariadb' ? 'MariaDB' : 'MySQL';
		}

		/**
		 * @param AstEventAttachment $attachment The attachment
		 * @param string $alias The attachment's resolved alias
		 * @return list<string> The CREATE TRIGGER statement
		 * @throws EntityResolutionException
		 */
		public function render(AstEventAttachment $attachment, string $alias): array {
			$triggerName = $this->quoter->quoteIdentifier($this->triggerName($attachment, $alias));
			$table = $this->quotedTable($attachment);
			$event = $this->sqlEvent($attachment->getEvent());
			$routineCall = $this->quoter->quoteRoutineName($attachment->getCall()->getName(), null);

			$arguments = implode(', ', array_map(
				fn(array $arg) => strtoupper($arg['row']) . '.' . $this->quoter->quoteIdentifier($arg['column']),
				$this->expandArguments($attachment)
			));

			return ["CREATE TRIGGER {$triggerName}\nAFTER {$event} ON {$table}\nFOR EACH ROW\nCALL {$routineCall}({$arguments});"];
		}

		/**
		 * @param string $table Physical table the attachment is on
		 * @param string $alias The attachment's alias
		 * @return list<string> The DROP TRIGGER statement
		 */
		public function renderDestroy(string $table, string $alias): array {
			$triggerName = $this->quoter->quoteIdentifier(EventAttachmentNaming::triggerName($table, $alias));
			return ["DROP TRIGGER {$triggerName};"];
		}
	}
