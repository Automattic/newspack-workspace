<?php // phpcs:ignoreFile
/**
 * Fixture: a documented constant.
 *
 * @package Newspack_Manager_Admin\Tests
 */

/**
 * Allow reader sync.
 *
 * Second paragraph of the description.
 *
 * @constant NEWSPACK_FIXTURE_ALLOW_SYNC
 * @type     bool
 * @default  false
 * @status   draft
 *
 * @example define( 'NEWSPACK_FIXTURE_ALLOW_SYNC', true );
 */
if ( defined( 'NEWSPACK_FIXTURE_ALLOW_SYNC' ) && NEWSPACK_FIXTURE_ALLOW_SYNC ) {
	echo 'on';
}
