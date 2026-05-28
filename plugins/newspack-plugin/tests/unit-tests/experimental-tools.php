<?php
/**
 * Tests the Experimental Tools framework.
 *
 * @package Newspack\Tests
 */

use Newspack\Experimental_Tools;

/**
 * Tests the Experimental Tools framework.
 */
class Newspack_Test_Experimental_Tools extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Experimental_Tools::OPTION_NAME );
		remove_all_filters( 'newspack_experimental_tools' );
	}

	/**
	 * Register a test tool via the filter.
	 *
	 * @param array $overrides Optional overrides for the tool definition.
	 * @return string The tool slug.
	 */
	private function register_test_tool( $overrides = [] ) {
		$tool_slug = 'test-tool';
		$tool_def  = array_merge(
			[
				'slug'        => $tool_slug,
				'label'       => 'Test Tool',
				'description' => 'A tool for testing.',
				'fields'      => [
					[
						'type'    => 'text',
						'key'     => 'api_key',
						'label'   => 'API Key',
						'default' => 'default-key',
					],
					[
						'type'  => 'display',
						'key'   => 'status',
						'label' => 'Status',
						'value' => 'OK',
					],
				],
			],
			$overrides
		);
		add_filter(
			'newspack_experimental_tools',
			function ( $tools ) use ( $tool_def ) {
				$tools[] = $tool_def;
				return $tools;
			}
		);
		return $tool_slug;
	}

	/**
	 * Tools registered via filter appear in get_tools().
	 */
	public function test_filter_registration() {
		$slug  = $this->register_test_tool();
		$tools = Experimental_Tools::get_tools();

		$this->assertCount( 1, $tools );
		$this->assertEquals( $slug, $tools[0]['slug'] );
		$this->assertEquals( 'Test Tool', $tools[0]['label'] );
	}

	/**
	 * Tools start disabled and can be toggled on.
	 */
	public function test_toggle_on() {
		$slug = $this->register_test_tool();

		$this->assertFalse( Experimental_Tools::is_tool_enabled( $slug ) );

		Experimental_Tools::toggle_tool( $slug, true );

		$this->assertTrue( Experimental_Tools::is_tool_enabled( $slug ) );
	}

	/**
	 * Toggling off a previously enabled tool works.
	 */
	public function test_toggle_off() {
		$slug = $this->register_test_tool();
		Experimental_Tools::toggle_tool( $slug, true );
		Experimental_Tools::toggle_tool( $slug, false );

		$this->assertFalse( Experimental_Tools::is_tool_enabled( $slug ) );
	}

	/**
	 * Toggle records the timestamp and user who enabled.
	 */
	public function test_toggle_records_metadata() {
		$slug = $this->register_test_tool();
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		Experimental_Tools::toggle_tool( $slug, true );
		$settings = Experimental_Tools::get_tool_settings( $slug );

		$this->assertEquals( $user, $settings['enabled_by'] );
		$this->assertIsInt( $settings['enabled_at'] );
		$this->assertGreaterThan( 0, $settings['enabled_at'] );
	}

	/**
	 * Saving fields stores only declared keys and ignores display/unknown keys.
	 */
	public function test_save_fields_filters_keys() {
		$slug = $this->register_test_tool();
		Experimental_Tools::toggle_tool( $slug, true );

		Experimental_Tools::save_tool_fields(
			$slug,
			[
				'api_key' => 'my-secret',
				'status'  => 'should-be-ignored',    // Display field.
				'unknown' => 'also-ignored',          // Not declared.
			]
		);

		$settings = Experimental_Tools::get_tool_settings( $slug );

		$this->assertEquals( 'my-secret', $settings['fields']['api_key'] );
		$this->assertArrayNotHasKey( 'status', $settings['fields'] );
		$this->assertArrayNotHasKey( 'unknown', $settings['fields'] );
	}

	/**
	 * Saved field values are merged into the tool's fields in get_tools().
	 */
	public function test_saved_values_appear_in_get_tools() {
		$slug = $this->register_test_tool();
		Experimental_Tools::toggle_tool( $slug, true );
		Experimental_Tools::save_tool_fields( $slug, [ 'api_key' => 'saved-value' ] );

		$tools     = Experimental_Tools::get_tools();
		$text_field = $tools[0]['fields'][0];

		$this->assertEquals( 'api_key', $text_field['key'] );
		$this->assertEquals( 'saved-value', $text_field['value'] );
	}

	/**
	 * Usage tracking increments per-user counters.
	 */
	public function test_track_usage() {
		$slug    = $this->register_test_tool();
		$user_id = self::factory()->user->create();

		Experimental_Tools::track_usage( $slug, $user_id );
		Experimental_Tools::track_usage( $slug, $user_id );
		Experimental_Tools::track_usage( $slug, $user_id );

		$this->assertEquals( 3, Experimental_Tools::get_usage_count( $slug ) );
	}

	/**
	 * Returns empty when no tools are registered.
	 */
	public function test_empty_when_no_tools_registered() {
		$tools = Experimental_Tools::get_tools();
		$this->assertEmpty( $tools );
	}

	/**
	 * Toggling a non-registered slug still creates an entry (no validation
	 * against registered tools at the storage layer). The REST endpoint
	 * handles validation separately.
	 */
	public function test_toggle_unregistered_slug_creates_entry() {
		Experimental_Tools::toggle_tool( 'unregistered', true );
		$this->assertTrue( Experimental_Tools::is_tool_enabled( 'unregistered' ) );
	}

	/**
	 * The newspack_experimental_tool_fields_saved action fires with correct data.
	 */
	public function test_fields_saved_action_fires() {
		$slug          = $this->register_test_tool();
		$captured_slug = null;
		$captured_fields = null;

		add_action(
			'newspack_experimental_tool_fields_saved',
			function ( $action_slug, $fields ) use ( &$captured_slug, &$captured_fields ) {
				$captured_slug   = $action_slug;
				$captured_fields = $fields;
			},
			10,
			2
		);

		Experimental_Tools::save_tool_fields( $slug, [ 'api_key' => 'hook-test' ] );

		$this->assertEquals( $slug, $captured_slug );
		$this->assertEquals( 'hook-test', $captured_fields['api_key'] );
	}

	/**
	 * Per-user usage count returns correct values for individual users.
	 */
	public function test_per_user_usage_count() {
		$slug   = $this->register_test_tool();
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();

		Experimental_Tools::track_usage( $slug, $user_a );
		Experimental_Tools::track_usage( $slug, $user_a );
		Experimental_Tools::track_usage( $slug, $user_b );

		$this->assertEquals( 2, Experimental_Tools::get_user_usage_count( $slug, $user_a ) );
		$this->assertEquals( 1, Experimental_Tools::get_user_usage_count( $slug, $user_b ) );
		// Total across all users.
		$this->assertEquals( 3, Experimental_Tools::get_usage_count( $slug ) );

		// Seed an older daily bucket and verify it's excluded with a narrow window.
		$all_settings = get_option( Experimental_Tools::OPTION_NAME, [] );
		$old_date     = gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS );
		$all_settings[ $slug ]['users'][ (string) $user_a ]['daily'][ $old_date ] = 7;
		update_option( Experimental_Tools::OPTION_NAME, $all_settings );

		// 5-day window excludes the 10-day-old bucket.
		$this->assertEquals( 2, Experimental_Tools::get_user_usage_count( $slug, $user_a, 5 ) );
		// Full retention window includes it.
		$this->assertEquals( 9, Experimental_Tools::get_user_usage_count( $slug, $user_a, Experimental_Tools::USAGE_RETENTION_DAYS ) );
	}
}
