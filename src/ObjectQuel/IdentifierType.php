<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	enum IdentifierType {
		case RangeRoot;
		case EntityRoot;
		case Property;
		case Relation;
		case JsonField;
		case Unresolved;
	}