<?php // phpcs:ignoreFile
/**
 * Fixture: docblock names a different constant.
 *
 * @package Newspack_Manager_Admin\Tests
 */

/**
 * This docblock is about something else.
 *
 * @constant NEWSPACK_FIXTURE_SOMETHING_ELSE
 * @type     bool
 */
if ( defined( 'NEWSPACK_FIXTURE_MISMATCH' ) ) {
	echo 'x';
}
