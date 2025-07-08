<?php
	
	/**
	 * ObjectQuel - A Sophisticated Object-Relational Mapping (ORM) System
	 *
	 * ObjectQuel is an ORM that brings a fresh approach to database interaction,
	 * featuring a unique query language, a streamlined architecture, and powerful
	 * entity relationship management. It implements the Data Mapper pattern for
	 * clear separation between domain models and underlying database structures.
	 *
	 * @author      Floris van den Berg
	 * @copyright   Copyright (c) 2025 ObjectQuel
	 * @license     MIT
	 * @version     1.0.0
	 * @package     Quellabs\ObjectQuel
	 */
	
	namespace Quellabs\ObjectQuel;
	
	use Quellabs\ObjectQuel\ReflectionManagement\BasicEnum;
	
	class DirtyState extends BasicEnum {
		const None = 0;
		const Dirty = 1;
		const New = 2;
		const Deleted = 3;
		const NotManaged = 4;
	}