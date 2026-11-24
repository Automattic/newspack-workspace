<?php
/**
 * Tests the ads settings functionality.
 *
 * @package Newspack\Tests
 */

use Newspack_Ads\Settings;

/**
 * Test ads settings functionality.
 */
class SettingsTest extends WP_UnitTestCase {
	/**
	 * Sample settings list for tests
	 *
	 * @var array
	 */
	private static $settings_list = [
		[
			'description' => 'A setting section',
			'help'        => 'A setting section description or help text',
			'section'     => 'test_section',
			'key'         => 'active',
			'type'        => 'boolean',
			'default'     => false,
			'public'      => true,
		],
		[
			'description' => 'My first field',
			'help'        => 'Help text',
			'section'     => 'test_section',
			'key'         => 'first_field',
			'type'        => 'boolean',
			'default'     => true,
			'public'      => true,
		],
		[
			'description' => 'My number field',
			'help'        => 'Help text',
			'section'     => 'test_section',
			'key'         => 'number_field',
			'type'        => 'integer',
			'default'     => true,
			'public'      => true,
		],
		[
			'description' => 'A private field',
			'help'        => 'Help text',
			'section'     => 'test_section',
			'key'         => 'private_field',
			'type'        => 'string',
			'default'     => '',
			'public'      => false,
		],
		[
			'description' => 'A select field',
			'help'        => 'Help text',
			'section'     => 'test_section',
			'key'         => 'select_field',
			'type'        => 'string',
			'options'     => [
				[
					'value' => 'option1',
					'name'  => 'Option 1',
				],
				[
					'value' => 'option2',
					'name'  => 'Option 2',
				],
			],
		],
		[
			'description' => 'A multiple select field',
			'help'        => 'Help text',
			'section'     => 'test_section',
			'key'         => 'multi_select_field',
			'type'        => 'string',
			'multiple'    => true,
			'options'     => [
				[
					'value' => 'option1',
					'name'  => 'Option 1',
				],
				[
					'value' => 'option2',
					'name'  => 'Option 2',
				],
			],
		],
		[
			'description' => 'A field without type',
			'help'        => 'Help text',
			'section'     => 'test_section',
			'key'         => 'typeless_field',
		],
	];

	/**
	 * Add sample settings list.
	 *
	 * @param array $settings_list List of settings.
	 *
	 * @return array Updated settings list.
	 */
	public static function set_settings_list( $settings_list ) {
		return array_merge( $settings_list, self::$settings_list );
	}
  
	/**
	 * Test that the returned settings values does not contain non-public values.
	 */
	public function test_get_public_settings() {
		add_filter( 'newspack_ads_settings_list', [ __CLASS__, 'set_settings_list' ] );
		$settings = Settings::get_settings( 'test_section', true );
		self::assertFalse(
			isset( $settings['private_field'] ),
			'Private settings should not be returned'
		);
	}

	/**
	 * Test that the values are properly updated with configured type.
	 */
	public function test_update_value() {
		add_filter( 'newspack_ads_settings_list', [ __CLASS__, 'set_settings_list' ] );
		$values = [
			'active'        => '1',
			'number_field'  => '200',
			'private_field' => true,
		];
		Settings::update_section( 'test_section', $values );
		$settings = Settings::get_settings( 'test_section' );
		self::assertSame(
			$settings['active'],
			true,
			'Boolean value should be updated with proper type'
		);
		self::assertSame(
			$settings['number_field'],
			200,
			'Integer value should be updated with proper type'
		);
		self::assertSame(
			$settings['private_field'],
			'1',
			'String value should be updated with proper type'
		);
	}

	/**
	 * Test that a value update should be restricted to available options.
	 */
	public function test_update_value_within_options() {
		add_filter( 'newspack_ads_settings_list', [ __CLASS__, 'set_settings_list' ] );

		// Outside of options.
		$values = [
			'select_field' => 'not_an_option',
		];
		$result = Settings::update_section( 'test_section', $values );
		self::assertTrue(
			is_wp_error( $result ),
			'Should not update a value outside of existing options.'
		);

		// Within options.
		$values   = [
			'select_field' => 'option2',
		];
		$result   = Settings::update_section( 'test_section', $values );
		$settings = Settings::get_settings( 'test_section' );
		self::assertSame(
			$settings['select_field'],
			'option2',
			'Should update a value within existing options.'
		);
	}

	/**
	 * Build a settings update request for the sanitize callback.
	 *
	 * @param string $section Section name.
	 *
	 * @return WP_REST_Request
	 */
	private static function get_settings_request( $section = 'test_section' ) {
		$request = new WP_REST_Request( 'POST', '/newspack-ads/v1/settings' );
		$request->set_param( 'section', $section );
		return $request;
	}

	/**
	 * Test that sanitizing an unregistered setting key returns an error instead of a fatal.
	 */
	public function test_sanitize_unknown_setting() {
		add_filter( 'newspack_ads_settings_list', [ __CLASS__, 'set_settings_list' ] );
		$result = Settings::api_sanitize_settings( [ 'a' => 'b' ], self::get_settings_request() );
		self::assertWPError( $result, 'Unknown setting key should return an error.' );

		$result = Settings::api_sanitize_settings( [ 'number_field' => '200' ], self::get_settings_request( 'not_a_section' ) );
		self::assertWPError( $result, 'Known key in an unknown section should return an error.' );
	}

	/**
	 * Test that a scalar value for a multiple select setting returns an error instead of a fatal.
	 */
	public function test_sanitize_multiple_requires_array() {
		add_filter( 'newspack_ads_settings_list', [ __CLASS__, 'set_settings_list' ] );
		$result = Settings::api_sanitize_settings( [ 'multi_select_field' => 'option1' ], self::get_settings_request() );
		self::assertWPError( $result, 'Scalar value for a multiple setting should return an error.' );

		$result = Settings::api_sanitize_settings( [ 'multi_select_field' => [ 'option1' ] ], self::get_settings_request() );
		self::assertSame( [ 'multi_select_field' => [ 'option1' ] ], $result );
	}

	/**
	 * Test the REST endpoint returns a 400, not a fatal, for an unknown setting from an unauthenticated user.
	 */
	public function test_rest_unknown_setting_unauthenticated() {
		global $wp_rest_server;
		add_filter( 'newspack_ads_settings_list', [ __CLASS__, 'set_settings_list' ] );
		wp_set_current_user( 0 );
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );

		$request = self::get_settings_request( 'foo' );
		$request->set_param( 'settings', [ 'a' => 'b' ] );
		$response = $wp_rest_server->dispatch( $request );
		self::assertSame( 400, $response->get_status() );

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Test that a setting should always have a default type.
	 */
	public function test_setting_default_type() {
		add_filter( 'newspack_ads_settings_list', [ __CLASS__, 'set_settings_list' ] );
		$config = Settings::get_setting_config( 'test_section', 'typeless_field' );
		self::assertTrue(
			isset( $config['type'] ),
			'Should have a default type'
		);
		self::assertSame(
			$config['type'],
			'string',
			'Should have a default type of string'
		);
	}
}
