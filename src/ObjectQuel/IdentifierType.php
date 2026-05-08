<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	enum IdentifierType {
		// Entities
		case EntityReference;
		case EntityRoot;
		case EntityProperty;
		
		// Subqueries
		case SubqueryRoot;
		case SubqueryProperty;
		
		// Json
		case JsonRoot;
		case JsonProperty;

		// Unknown
		case Unresolved;
	}