<?php
/**
 * Fixture for phpcsSniffs.Constants.ConstantDocblock.
 *
 * Every guard below is annotated with the verdict the sniff must reach.
 * phpcsSniffs/tests/constant-docblock-test.sh asserts that the three ERROR
 * lines are reported and that nothing else is.
 *
 * Excluded from the monorepo ruleset by the tests/fixtures exclude-pattern in
 * phpcs.xml, since the point of the file is to hold code the sniff rejects.
 * That pattern is described rather than written out: a literal closing-comment
 * delimiter inside this docblock would end it early and break the file.
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

/**
 * Guards that name their constant through a class constant.
 */
class Newspack_Fixture_Indirect_Guards {
	const DOCUMENTED_FLAG = 'NEWSPACK_INDIRECT_DOCUMENTED';
	const BARE_FLAG       = 'NEWSPACK_INDIRECT_UNDOCUMENTED';
	const NON_NEWSPACK    = 'SOMETHING_ELSE_ENTIRELY';
	const BUILT_FLAG      = 'NEWSPACK_INDIRECT_' . 'BUILT';

	/**
	 * Documented elsewhere in this file, so the guard is satisfied.
	 *
	 * @constant NEWSPACK_INDIRECT_DOCUMENTED
	 * @type     bool
	 * @default  Off
	 * @status   draft
	 *
	 * @example define( 'NEWSPACK_INDIRECT_DOCUMENTED', true );
	 *
	 * @return bool
	 */
	public static function documented(): bool {
		return defined( self::DOCUMENTED_FLAG ); // OK: the const resolves to a documented name.
	}

	/**
	 * No docblock names this one.
	 *
	 * @return bool
	 */
	public static function undocumented(): bool {
		return defined( self::BARE_FLAG ); // ERROR: resolves to an undocumented NEWSPACK_ name.
	}

	/**
	 * Same shape, reached through static:: instead of self::.
	 *
	 * @return bool
	 */
	public static function late_bound(): bool {
		return defined( static::BARE_FLAG ); // ERROR: static:: resolves the same way.
	}

	/**
	 * A class constant holding a name outside the NEWSPACK_ namespace.
	 *
	 * @return bool
	 */
	public static function other_namespace(): bool {
		return defined( self::NON_NEWSPACK ); // OK: not a NEWSPACK_ constant.
	}

	/**
	 * A class constant built by concatenation cannot be resolved.
	 *
	 * @return bool
	 */
	public static function built(): bool {
		return defined( self::BUILT_FLAG ); // OK: not a plain literal, so nothing is assumed.
	}

	/**
	 * A class constant this file never declares.
	 *
	 * @return bool
	 */
	public static function undeclared(): bool {
		return defined( self::NEVER_DECLARED_HERE ); // OK: unresolvable, so nothing is assumed.
	}

	/**
	 * A bare global constant reference, with no :: at all.
	 *
	 * @return bool
	 */
	public static function bare_reference(): bool {
		return defined( NEWSPACK_BARE_REFERENCE ); // OK: not a literal, and nothing to resolve it against.
	}

	/**
	 * A static method that merely ends in defined is not the guard.
	 *
	 * @return bool
	 */
	public static function not_the_function(): bool {
		return Newspack_Fixture_Indirect_Guards::defined( self::BARE_FLAG ); // OK: a method call, not defined().
	}

	/**
	 * Stand-in so the call above resolves.
	 *
	 * @param string $flag Flag name.
	 * @return bool
	 */
	public static function defined( string $flag ): bool {
		return '' !== $flag;
	}
}

/**
 * A second class in the same file, reusing member names the class above also
 * declares. Each guard has to resolve against its own class, not against
 * whichever declaration the file happened to reach first.
 */
class Newspack_Fixture_Colliding_Guards {
	const DOCUMENTED_FLAG = 'NEWSPACK_INDIRECT_SECOND_CLASS';

	/**
	 * Same member name as the class above, holding a name nothing documents.
	 *
	 * @return bool
	 */
	public static function own(): bool {
		return defined( self::DOCUMENTED_FLAG ); // ERROR: this class's value is undocumented.
	}

	/**
	 * An explicit reference to the other class's documented member.
	 *
	 * @return bool
	 */
	public static function other_documented(): bool {
		return defined( Newspack_Fixture_Indirect_Guards::DOCUMENTED_FLAG ); // OK: resolves to the documented name.
	}

	/**
	 * An explicit reference to the other class's undocumented member.
	 *
	 * @return bool
	 */
	public static function other_undocumented(): bool {
		return defined( Newspack_Fixture_Indirect_Guards::BARE_FLAG ); // ERROR: resolves to an undocumented name.
	}
}

/**
 * Inherits the colliding member, and reaches it through parent::.
 */
class Newspack_Fixture_Inheriting_Guards extends Newspack_Fixture_Colliding_Guards {
	/**
	 * The parent is declared in this file, so the member resolves.
	 *
	 * @return bool
	 */
	public static function inherited(): bool {
		return defined( parent::DOCUMENTED_FLAG ); // ERROR: the parent's value is undocumented.
	}
}
