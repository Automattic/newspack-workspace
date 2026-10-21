<?php // phpcs:ignoreFile
/**
 * Fixture: a guard called with a mixed-case function name.
 *
 * PHP function names are case-insensitive, so Defined(...) is exactly as
 * real a guard as defined(...); the constant name after it stays
 * case-sensitive, so a lowercase name is not a real guard at all.
 *
 * @package Newspack_Manager_Admin\Tests
 */

/**
 * Allow the fixture's mixed-case-guard feature.
 *
 * @constant NEWSPACK_FIXTURE_MIXEDCASE
 * @type     bool
 */
if ( Defined( 'NEWSPACK_FIXTURE_MIXEDCASE' ) ) {
	echo 'x';
}

if ( defined( 'newspack_fixture_lowercase_name' ) ) {
	echo 'x';
}
