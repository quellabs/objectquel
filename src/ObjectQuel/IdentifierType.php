<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	enum IdentifierType {
		case RangeRoot;
		case RangeProperty;
		case EntityRoot;
		case EntityProperty;
		case EntityRelation;
		case Relation;
		case JsonField;
		case Unresolved;
	}