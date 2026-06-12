<?php
/**
 * Tests the Redirection integration.
 *
 * @package Newspack\Tests
 */

use Newspack\Redirection;

/**
 * Test the Redirection integration class.
 */
class Newspack_Test_Redirection extends WP_UnitTestCase {

	/**
	 * Remove our filters between tests so registrations don't leak.
	 */
	public function tear_down() {
		parent::tear_down();
		remove_all_filters( 'redirection_log_data' );
		remove_all_filters( 'redirection_404_data' );
		remove_all_filters( 'redirection_log_404' );
		remove_all_filters( 'redirection_redirect_counter' );
		remove_all_filters( 'option_redirection_options' );
		remove_all_filters( 'newspack_redirection_logging_enabled' );
		remove_all_filters( 'newspack_redirection_hit_tracking_enabled' );
	}

	/**
	 * Logging is disabled by default (filter resolves false).
	 */
	public function test_logging_disabled_by_default() {
		$this->assertFalse( Redirection::is_logging_enabled() );
	}

	/**
	 * Hit tracking is left enabled by default (filter resolves true).
	 */
	public function test_hit_tracking_enabled_by_default() {
		$this->assertTrue( Redirection::is_hit_tracking_enabled() );
	}

	/**
	 * The logging filter can re-enable logging.
	 */
	public function test_logging_filter_can_reenable() {
		add_filter( 'newspack_redirection_logging_enabled', '__return_true' );
		$this->assertTrue( Redirection::is_logging_enabled() );
	}

	/**
	 * The hit-tracking filter can opt into suppression.
	 */
	public function test_hit_tracking_filter_can_disable() {
		add_filter( 'newspack_redirection_hit_tracking_enabled', '__return_false' );
		$this->assertFalse( Redirection::is_hit_tracking_enabled() );
	}
}
