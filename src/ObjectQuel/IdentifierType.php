<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	enum IdentifierType {
		case RangeRoot;
		case RangeProperty;
		case EntityReference;
		case EntityRoot;
		case SubqueryRoot;
		case JsonRoot;
		case EntityProperty;
		case EntityRelation;
		case Relation;
		case JsonField;
		case Unresolved;
	}