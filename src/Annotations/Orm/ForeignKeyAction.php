<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;

	/**
	 * @Annotation
	 * Declares the ON DELETE / ON UPDATE behavior of an @Orm\ForeignKey on the same
	 * property. Optional — a bare @Orm\ForeignKey with no ForeignKeyAction gets the
	 * safe defaults (RESTRICT / NO ACTION).
	 *
	 * Deliberately independent of @Orm\Cascade: this configures what the database
	 * constraint itself does, not whether the ORM also walks the relation in PHP.
	 * The two are unrelated and can be combined freely, or used alone — a plain
	 * scalar @Orm\Column-backed FK column (no ManyToOne/OneToOne, no Cascade) can
	 * still declare a real ON DELETE CASCADE/SET NULL here.
	 *
	 * Example: @Orm\ForeignKeyAction(onDelete="CASCADE", onUpdate="RESTRICT")
	 */
	class ForeignKeyAction implements AnnotationInterface {

		/**
		 * Contains all parameters defined in the annotation
		 * @var array<string, mixed>
		 */
		protected array $parameters;

		/** @var array<int, string> Valid values for onDelete/onUpdate */
		private const array VALID_ACTIONS = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];

		private string $onDelete;
		private string $onUpdate;

		/**
		 * ForeignKeyAction constructor.
		 * @param array<string, mixed> $parameters Array of parameters from the annotation
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$onDelete = $parameters['onDelete'] ?? 'RESTRICT';
			$onUpdate = $parameters['onUpdate'] ?? 'NO ACTION';

			if (!is_string($onDelete) || !in_array($onDelete, self::VALID_ACTIONS, true)) {
				throw new \InvalidArgumentException(
					"ForeignKeyAction: 'onDelete' must be one of: " . implode(', ', self::VALID_ACTIONS)
				);
			}

			if (!is_string($onUpdate) || !in_array($onUpdate, self::VALID_ACTIONS, true)) {
				throw new \InvalidArgumentException(
					"ForeignKeyAction: 'onUpdate' must be one of: " . implode(', ', self::VALID_ACTIONS)
				);
			}

			$this->parameters = $parameters;
			$this->onDelete = $onDelete;
			$this->onUpdate = $onUpdate;
		}

		/**
		 * Returns the parameters for this annotation
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return $this->parameters;
		}

		/**
		 * Returns the ON DELETE action (default: "RESTRICT")
		 * @return string One of: RESTRICT, CASCADE, SET NULL, NO ACTION
		 */
		public function getOnDelete(): string {
			return $this->onDelete;
		}

		/**
		 * Returns the ON UPDATE action (default: "NO ACTION")
		 * @return string One of: RESTRICT, CASCADE, SET NULL, NO ACTION
		 */
		public function getOnUpdate(): string {
			return $this->onUpdate;
		}
	}
