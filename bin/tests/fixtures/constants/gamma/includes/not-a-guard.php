<?php // phpcs:ignoreFile
/**
 * Fixture: a defined()-like substring without a word boundary is not a guard.
 *
 * @package Newspack_Manager_Admin\Tests
 */

if ( some_predefined( 'NEWSPACK_FIXTURE_NOT_A_GUARD' ) ) {
	echo 'x';
}
