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

	/**
	 * Define a red_get_options() stub so the guard passes.
	 *
	 * Matches the released Redirection (5.5.2), which exposes the
	 * red_get_options() function rather than a Red_Options class (trunk-only).
	 * Note: the stubbed symbol persists process-wide (PHP can't undefine it).
	 */
	private function stub_redirection_present() {
		if ( ! function_exists( 'red_get_options' ) ) {
			eval( 'function red_get_options() { return []; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
	}

	/**
	 * With logging disabled (default), register() adds the three hard-stop filters,
	 * and each returns false (suppressing the log write).
	 */
	public function test_register_adds_log_suppression_filters() {
		$this->stub_redirection_present();
		Redirection::register();

		$this->assertSame( 10, has_filter( 'redirection_log_data', '__return_false' ) );
		$this->assertSame( 10, has_filter( 'redirection_404_data', '__return_false' ) );
		$this->assertSame( 10, has_filter( 'redirection_log_404', '__return_false' ) );

		$this->assertFalse( apply_filters( 'redirection_log_data', [ 'url' => '/x' ] ) );
		$this->assertFalse( apply_filters( 'redirection_404_data', [ 'url' => '/x' ] ) );
	}

	/**
	 * Hit tracking is NOT suppressed by default.
	 */
	public function test_register_leaves_hit_tracking_alone_by_default() {
		$this->stub_redirection_present();
		Redirection::register();
		$this->assertFalse( has_filter( 'redirection_redirect_counter', '__return_false' ) );
	}

	/**
	 * Opting hit tracking out adds the counter-suppression filter.
	 */
	public function test_register_suppresses_hit_tracking_when_opted_out() {
		add_filter( 'newspack_redirection_hit_tracking_enabled', '__return_false' );
		$this->stub_redirection_present();
		Redirection::register();
		$this->assertSame( 10, has_filter( 'redirection_redirect_counter', '__return_false' ) );
	}

	/**
	 * When logging is re-enabled, no log-suppression filters are added.
	 */
	public function test_register_skips_suppression_when_logging_enabled() {
		add_filter( 'newspack_redirection_logging_enabled', '__return_true' );
		$this->stub_redirection_present();
		Redirection::register();
		$this->assertFalse( has_filter( 'redirection_log_data', '__return_false' ) );
		$this->assertFalse( has_filter( 'option_redirection_options', [ Redirection::class, 'force_logging_off_in_options' ] ) );
	}

	/**
	 * The option override forces both expiries to -1 and preserves other keys.
	 */
	public function test_option_override_forces_expiries_and_preserves_keys() {
		$value  = [
			'expire_redirect' => 7,
			'expire_404'      => 30,
			'track_hits'      => true,
			'monitor_post'    => 5,
		];
		$result = Redirection::force_logging_off_in_options( $value );

		$this->assertSame( -1, $result['expire_redirect'] );
		$this->assertSame( -1, $result['expire_404'] );
		$this->assertTrue( $result['track_hits'] );   // Untouched.
		$this->assertSame( 5, $result['monitor_post'] ); // Untouched.
	}

	/**
	 * A non-array value (fresh site, no saved row) is returned untouched.
	 */
	public function test_option_override_passes_non_array_through() {
		$this->assertFalse( Redirection::force_logging_off_in_options( false ) );
		$this->assertSame( '', Redirection::force_logging_off_in_options( '' ) );
	}

	/**
	 * No drift notice when Redirection's log classes are present.
	 */
	public function test_no_drift_notice_when_log_classes_present() {
		if ( ! class_exists( 'Red_Redirect_Log' ) ) {
			eval( 'class Red_Redirect_Log {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		if ( ! class_exists( 'Red_404_Log' ) ) {
			eval( 'class Red_404_Log {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		$this->assertSame( '', Redirection::get_drift_notice_html() );
	}

	/**
	 * The drift detector reports drift when the log classes are missing.
	 * (Tested via the pure detector, since the stub classes can't be unloaded.)
	 */
	public function test_drift_detected_when_log_classes_missing() {
		$this->assertTrue( Redirection::is_logging_surface_missing( false, false ) );
		$this->assertTrue( Redirection::is_logging_surface_missing( true, false ) );
		$this->assertTrue( Redirection::is_logging_surface_missing( false, true ) );
		$this->assertFalse( Redirection::is_logging_surface_missing( true, true ) );
	}
}
