<?php // phpcs:ignoreFile
/**
 * Fixture: a `//` comment line sits between the docblock and the guard.
 *
 * @package Newspack_Manager_Admin\Tests
 */

/**
 * Allow the fixture's comment-gap feature.
 *
 * @constant NEWSPACK_FIXTURE_COMMENT_GAP
 * @type     bool
 */
// Note: this comment sits between the docblock and the guard.
if ( defined( 'NEWSPACK_FIXTURE_COMMENT_GAP' ) ) {
	echo 'x';
}
