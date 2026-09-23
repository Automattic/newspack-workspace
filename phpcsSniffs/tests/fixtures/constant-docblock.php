<?php
/**
 * Fixture for phpcsSniffs.Constants.ConstantDocblock.
 *
 * Every guard below is annotated with the verdict the sniff must reach.
 * phpcsSniffs/tests/constant-docblock-test.sh asserts that the three ERROR
 * lines are reported and that nothing else is.
 *
 * Excluded from the monorepo ruleset by phpcs.xml's */tests/fixtures/*
 * pattern, since the point of the file is to hold code the sniff rejects.
 *
 * @package phpcsSniffs
 */

/**
 * Enables the adjacent thing.
 *
 * @constant NEWSPACK_ADJACENT
 * @type     bool
 * @default  Disabled
 * @status   draft
 *
 * @example define( 'NEWSPACK_ADJACENT', true );
 */
if ( defined( 'NEWSPACK_ADJACENT' ) ) { // OK: docblock immediately above.
	echo 1;
}

if ( defined( 'NEWSPACK_ELSEWHERE' ) ) { // OK: docblock further down the file.
	echo 2;
}

if ( defined( 'NEWSPACK_UNDOCUMENTED' ) ) { // ERROR: no docblock anywhere.
	echo 3;
}

if ( \defined( 'NEWSPACK_QUALIFIED' ) ) { // ERROR: \defined() is the global function.
	echo 4;
}

if ( Defined( 'NEWSPACK_MIXED_CASE' ) ) { // ERROR: PHP function names are case-insensitive.
	echo 5;
}

if ( defined( 'SOME_OTHER_CONSTANT' ) ) { // OK: outside the NEWSPACK_ namespace.
	echo 6;
}

$newspack_fixture_name = 'NEWSPACK_DYNAMIC';
if ( defined( $newspack_fixture_name ) ) { // OK: argument is not a literal.
	echo 7;
}

$newspack_fixture_suffix = '_A';
if ( defined( 'NEWSPACK_CONCAT' . $newspack_fixture_suffix ) ) { // OK: concatenation, not a bare literal.
	echo 8;
}

$newspack_fixture_obj = new stdClass();
if ( $newspack_fixture_obj->defined( 'NEWSPACK_METHOD' ) ) { // OK: a method that merely ends in defined.
	echo 9;
}

// OK: a guard quoted inside a comment -- defined( 'NEWSPACK_IN_COMMENT' ).
echo "defined( 'NEWSPACK_IN_STRING' )"; // OK: a guard quoted inside a string.

/**
 * Enables the other thing.
 *
 * @constant NEWSPACK_ELSEWHERE
 * @type     bool
 * @default  Disabled
 * @status   draft
 *
 * @example define( 'NEWSPACK_ELSEWHERE', true );
 */
function newspack_fixture_noop() {}
