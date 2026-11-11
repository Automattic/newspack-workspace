<?php
/**
 * Tests the profile endpoint location hints.
 *
 * @package Newspack\Tests
 */

/**
 * Test the profile endpoint location hints and social labels.
 */
class Profile_Location_Test extends WP_UnitTestCase {

	/**
	 * Set up the REST server and an administrator.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Dispatch the profile request and return the response data.
	 *
	 * @return array
	 */
	private function get_profile() {
		global $wp_rest_server;
		$response = $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/newspack/v1/profile' ) );
		return $response->get_data();
	}

	/**
	 * Location hints carry the site locale and the country currency map.
	 */
	public function test_location_hints_carry_locale_and_currency_map() {
		$hints = $this->get_profile()['location_hints'];
		$this->assertSame( 'GBP', $hints['country_currencies']['GB'] );
		$this->assertSame( 'EUR', $hints['country_currencies']['FR'] );
		$this->assertSame( get_locale(), $hints['site_locale'] );
	}

	/**
	 * Location hints name the store currency the identity import set, and nothing when it set none.
	 */
	public function test_location_hints_name_the_imported_currency() {
		delete_option( Newspack\Starter_Content::IMPORTED_CURRENCY_OPTION );
		$this->assertSame( '', $this->get_profile()['location_hints']['imported_currency'] );
		update_option( Newspack\Starter_Content::IMPORTED_CURRENCY_OPTION, 'EUR' );
		$this->assertSame( 'EUR', $this->get_profile()['location_hints']['imported_currency'] );
	}

	/**
	 * Saved values are read back from the raw options.
	 */
	public function test_profile_reads_saved_country_and_currency() {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );
		$response = $this->get_profile();
		$this->assertSame( 'US:CA', $response['profile']['countrystate'] );
		$this->assertSame( 'USD', $response['profile']['currency'] );
	}

	/**
	 * Social labels use the current brand names.
	 */
	public function test_social_labels_use_current_brand_names() {
		$labels = wp_list_pluck( $this->get_profile()['wpseo_fields'], 'label', 'key' );
		$this->assertSame( 'X', $labels['twitter_site'] );
		$this->assertSame( 'LinkedIn', $labels['linkedin_url'] );
	}
}
