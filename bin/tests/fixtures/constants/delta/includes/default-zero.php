<?php // phpcs:ignoreFile
/**
 * Fixture: a constant documented with `@default 0`.
 *
 * empty( '0' ) is true in PHP, so a naive empty()-based merge drops this
 * default entirely instead of keeping the documented zero.
 *
 * @package Newspack_Manager_Admin\Tests
 */

/**
 * Allow the fixture's zero-default feature.
 *
 * @constant NEWSPACK_FIXTURE_DEFAULT_ZERO
 * @type     int
 * @default  0
 * @status   stable
 */
if ( defined( 'NEWSPACK_FIXTURE_DEFAULT_ZERO' ) ) {
	echo 'x';
}
