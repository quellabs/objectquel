<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * Marker interface for the AST node types Parser::parse() can produce as
	 * its top-level result — a `retrieve` query, one of the DDL statements
	 * (create table/index, destroy table/index), or one of the write-verb
	 * statements (append/replace/delete). Nothing else in the AST implements
	 * this; a clause or expression node nested inside one of these is a plain
	 * AstInterface, never an AstStatement.
	 *
	 * Lets QueryExecutor's dispatch (executeQuery()/explainQuery()) and
	 * Parser::parse() itself declare their result as "a top-level statement"
	 * without spelling out the full type union at every such signature.
	 */
	interface AstStatement extends AstInterface {
	}
