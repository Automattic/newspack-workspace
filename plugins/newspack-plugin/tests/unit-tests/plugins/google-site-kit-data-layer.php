<?php
/**
 * Tests for mirroring GA4 custom parameters into the dataLayer for GTM.
 *
 * @package Newspack\Tests
 */

use Newspack\GoogleSiteKit;

/**
 * Test the dataLayer push that exposes Newspack's GA4 params to a publisher's
 * Google Tag Manager container.
 *
 * @group GoogleSiteKit_Data_Layer
 */
class Newspack_Test_GoogleSiteKit_Data_Layer extends WP_UnitTestCase {

	/**
	 * Clean up the test-only filter between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_ga4_data_layer_params' );
		parent::tear_down();
	}

	/**
	 * With nothing to push, the builder returns an empty string so the hook can
	 * skip printing an inert <script> tag.
	 */
	public function test_empty_params_produce_no_script() {
		$this->assertSame( '', GoogleSiteKit::get_data_layer_inline_script( [] ) );
	}

	/**
	 * The script initializes the dataLayer defensively and pushes the params as a
	 * single JSON object, so GTM Data Layer Variables can read each key.
	 */
	public function test_script_pushes_params_as_data_layer_object() {
		$data_layer_script = GoogleSiteKit::get_data_layer_inline_script(
			[
				'logged_in' => 'no',
				'is_reader' => 'no',
			]
		);

		$this->assertStringContainsString( 'window.dataLayer = window.dataLayer || [];', $data_layer_script );
		$this->assertStringContainsString( 'window.dataLayer.push(', $data_layer_script );
		$this->assertStringContainsString( '"logged_in":"no"', $data_layer_script );
		$this->assertStringContainsString( '"is_reader":"no"', $data_layer_script );
	}

	/**
	 * A parameter value carrying `</script>` (e.g. a crafted author name or
	 * category) must be hex-escaped so it cannot break out of the inline tag.
	 */
	public function test_script_escapes_closing_script_tag_in_values() {
		$data_layer_script = GoogleSiteKit::get_data_layer_inline_script(
			[ 'author' => 'Evil</script><script>alert(1)</script>' ]
		);

		// No raw tag markers survive, so the value cannot break out of the inline script.
		$this->assertStringNotContainsString( '</script>', $data_layer_script );
		$this->assertStringNotContainsString( '<script>', $data_layer_script );
		// The angle brackets are hex-escaped by JSON_HEX_TAG ("<").
		$this->assertStringContainsString( chr( 92 ) . 'u003C', $data_layer_script );
		// The value is preserved as encoded data, not stripped.
		$this->assertStringContainsString( 'alert(1)', $data_layer_script );
	}

	/**
	 * The dataLayer params can be filtered, mirroring `newspack_ga4_custom_parameters`
	 * for the gtag path, so integrations (or a publisher) can add/adjust keys.
	 */
	public function test_data_layer_params_filter_applies() {
		add_filter(
			'newspack_ga4_data_layer_params',
			function ( $params ) {
				$params['brand'] = 'Example Brand';
				return $params;
			}
		);

		$data_layer_params = GoogleSiteKit::get_data_layer_params();

		$this->assertArrayHasKey( 'brand', $data_layer_params );
		$this->assertSame( 'Example Brand', $data_layer_params['brand'] );
	}

	/**
	 * The hashed email is sent to Site Kit's own gtag config but deliberately kept
	 * out of the dataLayer, so it is not exposed to every third-party tag in the
	 * publisher's GTM container.
	 */
	public function test_email_hash_is_excluded_from_data_layer() {
		$user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
		wp_set_current_user( $user_id );

		// The gtag set (get_custom_event_parameters) does include the hashed email...
		$gtag_params = GoogleSiteKit::get_custom_event_parameters();
		$this->assertArrayHasKey( 'email_hash', $gtag_params );

		// ...but the dataLayer set must not.
		$data_layer_params = GoogleSiteKit::get_data_layer_params();
		$this->assertArrayNotHasKey( 'email_hash', $data_layer_params );
		$this->assertArrayHasKey( 'logged_in', $data_layer_params );
	}

	/**
	 * The exclusion is enforced after the filter runs, so a filter cannot re-introduce
	 * `email_hash` into the dataLayer.
	 */
	public function test_filter_cannot_reintroduce_email_hash() {
		add_filter(
			'newspack_ga4_data_layer_params',
			function ( $params ) {
				$params['email_hash'] = 'should-not-survive';
				return $params;
			}
		);

		$data_layer_params = GoogleSiteKit::get_data_layer_params();

		$this->assertArrayNotHasKey( 'email_hash', $data_layer_params );
	}

	/**
	 * The whole point of the fix: `logged_in` always carries an explicit value, so an
	 * anonymous reader is sent `no` (not omitted, which GA4 reports as `(not set)`).
	 */
	public function test_logged_in_param_is_explicit_for_anonymous_and_authenticated() {
		wp_set_current_user( 0 );
		$anonymous_params = GoogleSiteKit::get_custom_event_parameters();
		$this->assertSame( 'no', $anonymous_params['logged_in'] );

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$authenticated_params = GoogleSiteKit::get_custom_event_parameters();
		$this->assertSame( 'yes', $authenticated_params['logged_in'] );
	}
}
