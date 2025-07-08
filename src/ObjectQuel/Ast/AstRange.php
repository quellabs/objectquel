<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\FindIdentifier;
	
	/**
	 * Class AstRange
	 * AstRange klasse is verantwoordelijk voor het definiëren van een bereik in de AST (Abstract Syntax Tree).
	 */
	class AstRange extends Ast {
		
		// Alias voor het bereik
		private string $name;
		
		// True als de relatie optioneel is. E.g. of het om een LEFT JOIN gaat.
		private bool $required;
		
		/**
		 * AstRange constructor.
		 * @param string $name De naam voor dit bereik.
		 */
		public function __construct(string $name, bool $required=false) {
			$this->name = $name;
			$this->required = $required;
		}
		
		/**
		 * Haal de alias voor dit bereik op.
		 * @return string De alias van dit bereik.
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * De via expressie geeft aan op welk velden gejoined moet worden
		 * @return AstInterface|null
		 */
		public function getJoinProperty(): ?AstInterface {
			return null;
		}
		
		
		/**
		 * Maakt de relatie verplicht
		 * @var bool $required
		 * @return void
		 */
		public function setRequired(bool $required=true): void {
			$this->required = $required;
		}
		
		/**
		 * True als de relatie verplicht is. E.g. het gaat om een INNER JOIN.
		 * @return bool
		 */
		public function isRequired(): bool {
			return $this->required;
		}
	}