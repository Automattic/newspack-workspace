<?php
/**
 * Unit tests for the Print_Section wizard class.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Wizards\Newspack\Print_Section
 */

namespace Newspack\Tests\Unit\Indesign_Exporter;

use WP_UnitTestCase;
use WP_Error;
use WP_REST_Request;
use Newspack\Optional_Modules;
use Newspack\Optional_Modules\InDesign_Exporter;
use Newspack\Wizards\Newspack\Print_Section;

/**
 * Tests for the Print_Section wizard class.
 */
class Test_Print_Section extends WP_UnitTestCase {
	/**
	 * Section instance under test.
	 *
	 * @var Print_Section
	 */
	private $section;

	/**
	 * Reset relevant options between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Optional_Modules::OPTION_NAME );
		delete_option( InDesign_Exporter::PLATFORM_OPTION );
		delete_option( InDesign_Exporter::POST_TYPES_OPTION );
		delete_option( InDesign_Exporter::EXCLUDE_CAPTIONS_OPTION );
		$this->section = new Print_Section();
	}

	/**
	 * Test that api_get_print_settings returns the expected keys with default values.
	 */
	public function test_api_get_print_settings_returns_all_keys() {
		$result = $this->section->api_get_print_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'module_enabled_print', $result );
		$this->assertArrayHasKey( 'indesign_platform', $result );
		$this->assertArrayHasKey( 'indesign_post_types', $result );
		$this->assertArrayHasKey( 'available_post_types', $result );

		$this->assertFalse( $result['module_enabled_print'] );
		$this->assertSame( 'auto', $result['indesign_platform'] );
		$this->assertSame( [ 'post' ], $result['indesign_post_types'] );
		$this->assertIsArray( $result['available_post_types'] );
	}

	/**
	 * Test that enabling the module persists the optional-module setting.
	 */
	public function test_api_update_print_settings_enables_module() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertSame( true, $result['module_enabled_print'] );
		$this->assertTrue( Optional_Modules::is_optional_module_active( InDesign_Exporter::MODULE_NAME ) );
	}

	/**
	 * Test that disabling the module persists the optional-module setting.
	 */
	public function test_api_update_print_settings_disables_module() {
		Optional_Modules::activate_optional_module( InDesign_Exporter::MODULE_NAME );

		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', false );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertSame( false, $result['module_enabled_print'] );
		$this->assertFalse( Optional_Modules::is_optional_module_active( InDesign_Exporter::MODULE_NAME ) );
	}

	/**
	 * Test that a non-boolean module_enabled_print value is rejected.
	 */
	public function test_api_update_print_settings_rejects_non_boolean_module() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', 'yes' );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test that a valid platform value is persisted.
	 */
	public function test_api_update_print_settings_persists_platform() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_platform', 'mac' );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertSame( 'mac', $result['indesign_platform'] );
		$this->assertSame( 'mac', get_option( InDesign_Exporter::PLATFORM_OPTION ) );
	}

	/**
	 * Test that an invalid platform value is rejected.
	 */
	public function test_api_update_print_settings_rejects_invalid_platform() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_platform', 'linux' );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test that an invalid platform value does NOT trigger a module toggle.
	 *
	 * Regression test for an earlier bug where the module was activated
	 * before parameter validation finished.
	 */
	public function test_api_update_print_settings_validates_before_module_toggle() {
		$this->assertFalse( Optional_Modules::is_optional_module_active( InDesign_Exporter::MODULE_NAME ) );

		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_platform', 'linux' );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( Optional_Modules::is_optional_module_active( InDesign_Exporter::MODULE_NAME ) );
	}

	/**
	 * Test that a valid post types array is persisted.
	 */
	public function test_api_update_print_settings_persists_post_types() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_post_types', [ 'post', 'page' ] );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertSame( [ 'post', 'page' ], $result['indesign_post_types'] );
		$this->assertSame( [ 'post', 'page' ], get_option( InDesign_Exporter::POST_TYPES_OPTION ) );
	}

	/**
	 * Test that a non-array post types value is rejected.
	 */
	public function test_api_update_print_settings_rejects_non_array_post_types() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_post_types', 'post' );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test that a post type outside the available list is rejected with a 400,
	 * rather than silently dropped, so the stored value never diverges from the
	 * effective one.
	 */
	public function test_api_update_print_settings_rejects_unavailable_post_types() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_post_types', [ 'post', 'no_such_cpt' ] );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test that a non-string post type entry is rejected with a 400.
	 */
	public function test_api_update_print_settings_rejects_non_string_post_types() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_post_types', [ 'post', 42 ] );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test that a nested-array entry is rejected with a 400. Validation must run
	 * before dedupe: array_unique() stringifies elements while comparing, so
	 * deduping first would raise "Array to string conversion" on this input.
	 */
	public function test_api_update_print_settings_rejects_nested_array_post_types() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_post_types', [ 'post', [ 'post' ] ] );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test that a valid but duplicated selection is de-duplicated before saving.
	 */
	public function test_api_update_print_settings_dedupes_post_types() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_post_types', [ 'post', 'page', 'post' ] );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertSame( [ 'post', 'page' ], $result['indesign_post_types'] );
		$this->assertSame( [ 'post', 'page' ], get_option( InDesign_Exporter::POST_TYPES_OPTION ) );
	}

	/**
	 * Test that api_get_print_settings exposes the exclude-captions setting,
	 * defaulting to false.
	 */
	public function test_api_get_print_settings_includes_exclude_captions_default() {
		$result = $this->section->api_get_print_settings();

		$this->assertArrayHasKey( 'indesign_exclude_captions', $result );
		$this->assertFalse( $result['indesign_exclude_captions'] );
	}

	/**
	 * Test that a boolean exclude-captions value is persisted.
	 */
	public function test_api_update_print_settings_persists_exclude_captions() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_exclude_captions', true );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertTrue( $result['indesign_exclude_captions'] );
		$this->assertTrue( InDesign_Exporter::get_exclude_captions_setting() );
	}

	/**
	 * Test that a non-boolean exclude-captions value is rejected.
	 */
	public function test_api_update_print_settings_rejects_non_boolean_exclude_captions() {
		$request = new WP_REST_Request();
		$request->set_param( 'module_enabled_print', true );
		$request->set_param( 'indesign_exclude_captions', 'yes' );

		$result = $this->section->api_update_print_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}
}
