<?php // phpcs:ignoreFile
/**
 * Fixture: a guard called with a leading namespace separator.
 *
 * `\defined(...)` tokenizes as a single T_NAME_FULLY_QUALIFIED token
 * (`\defined`), not T_STRING, so it needs its own callee check.
 *
 * @package Newspack_Manager_Admin\Tests
 */

/**
 * Allow the fixture's fully-qualified-guard feature.
 *
 * @constant NEWSPACK_FIXTURE_FULLY_QUALIFIED
 * @type     bool
 */
if ( \defined( 'NEWSPACK_FIXTURE_FULLY_QUALIFIED' ) ) {
	echo 'x';
}
