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
		
		// -------------------------------------------------------------------------
		// Unknown
		// -------------------------------------------------------------------------
		
		/**
		 * The identifier has not yet been resolved to a known category.
		 *
		 * This is the initial state assigned during parsing. An identifier that
		 * remains Unresolved after the resolution phase indicates a query error
		 * (unknown alias, misspelled property, etc.).
		 */
		case Unresolved;

		// -------------------------------------------------------------------------
		// Entities
		// -------------------------------------------------------------------------
		
		/**
		 * The root alias of an entity range variable.
		 */
		case EntityRoot;
		
		/**
		 * A property path rooted at an entity range variable.
		 */
		case EntityProperty;
		
		/**
		 * A reference to another entity, typically through a relation or join.
		 */
		case EntityReference;
		
		// -------------------------------------------------------------------------
		// Subqueries
		// -------------------------------------------------------------------------
		
		/**
		 * The root alias of a subquery range variable.
		 */
		case SubqueryRoot;
		
		/**
		 * A property path rooted at a subquery range variable.
		 */
		case SubqueryProperty;
		
		// -------------------------------------------------------------------------
		// JSON
		// -------------------------------------------------------------------------
		
		/**
		 * The root of a JSON column or JSON-typed expression.
		 */
		case JsonRoot;
		
		/**
		 * A path expression into a JSON root.
		 */
		case JsonProperty;
		
	}