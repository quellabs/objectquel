<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	/**
	 * Classifies the role of an identifier encountered during query parsing or resolution.
	 *
	 * During query analysis, identifiers (e.g. `u`, `u.name`, `order.items[0].price`)
	 * are encountered before their full context is known. This enum tracks what kind
	 * of thing each identifier refers to, so that subsequent resolution, validation,
	 * and code generation steps can handle them correctly.
	 */
	enum IdentifierType {
		
		/**
		 * Initial state. Remains unresolved after the resolution phase = query error.
		 */
		case Unresolved;
		
		/** Root alias of an entity range variable. */
		case EntityRoot;
		
		/** Property path on an entity range variable. */
		case EntityProperty;
		
		/** Reference to another entity via a relation or join. */
		case EntityReference;
		
		/** Root alias of a subquery range variable. */
		case SubqueryRoot;
		
		/** Property path on a subquery range variable. */
		case SubqueryProperty;
		
		/** Root of a JSON column or JSON-typed expression. */
		case JsonRoot;
		
		/** Path expression into a JSON root. */
		case JsonProperty;
		
	}