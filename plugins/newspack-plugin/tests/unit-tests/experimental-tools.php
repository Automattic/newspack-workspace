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
	 * Register a tool with one plain field and one declaring its own sanitizer.
	 *
	 * @param callable|string $callback The sanitize_callback to declare.
	 * @return string The tool slug.
	 */
	private function register_tool_with_callback( $callback ) {
		return $this->register_test_tool(
			[
				'fields' => [
					[
						'type' => 'textarea',
						'key'  => 'plain',
					],
					[
						'type'              => 'textarea',
						'key'               => 'declared',
						'sanitize_callback' => $callback,
					],
				],
			]
		);
	}

	/**
	 * A field's own sanitizer replaces the default, and only for that field.
	 *
	 * The default reads a percent sign followed by two hex digits as a URL-encoded
	 * octet and deletes it, so %CACHE_KEY% is stored as CHE_KEY%. That is what a
	 * tool storing placeholders needs to escape, and what every other field keeps.
	 */
	public function test_a_declared_callback_replaces_the_default_for_that_field_only() {
		$slug = $this->register_tool_with_callback( 'wp_kses_post' );

		Experimental_Tools::save_tool_fields(
			$slug,
			[
				'plain'    => 'Use %CACHE_KEY% here',
				'declared' => 'Use %CACHE_KEY% here',
			]
		);

		$fields = Experimental_Tools::get_tool_settings( $slug )['fields'];

		$this->assertSame( 'Use CHE_KEY% here', $fields['plain'], 'An undeclared field keeps the default.' );
		$this->assertSame( 'Use %CACHE_KEY% here', $fields['declared'], "The field's own sanitizer runs instead." );
	}

	/**
	 * A callback that cannot be called falls back to the default rather than
	 * fataling or storing raw input. Silent would hide a registration typo behind
	 * the very sanitizer the callback was declared to escape.
	 */
	public function test_an_uncallable_callback_falls_back_to_the_default() {
		$this->setExpectedIncorrectUsage( 'Newspack\\Experimental_Tools::sanitize_field_value' );

		$slug = $this->register_tool_with_callback( 'newspack_no_such_sanitizer' );

		Experimental_Tools::save_tool_fields( $slug, [ 'declared' => 'Use %CACHE_KEY% here' ] );

		$this->assertSame(
			'Use CHE_KEY% here',
			Experimental_Tools::get_tool_settings( $slug )['fields']['declared'],
			"The default sanitizer's fingerprint proves which one ran."
		);
	}

	/**
	 * A non-scalar value is not storable, and a malformed request must not wipe
	 * what was saved. The route validates that `fields` is an object but nothing
	 * about the values inside it, so this is reachable. The typed callback also
	 * pins that it is never handed one.
	 */
	public function test_a_non_scalar_value_leaves_the_stored_value_alone() {
		$slug = $this->register_tool_with_callback(
			function ( string $value ) {
				return $value;
			}
		);

		Experimental_Tools::save_tool_fields( $slug, [ 'declared' => 'what was written' ] );
		Experimental_Tools::save_tool_fields( $slug, [ 'declared' => [ 'an', 'array' ] ] );

		$this->assertSame(
			'what was written',
			Experimental_Tools::get_tool_settings( $slug )['fields']['declared'],
			'A malformed request must not overwrite a saved value.'
		);
	}

	/**
	 * A non-scalar return cannot reach the option either: the stored value is
	 * merged back into the field definition and sent over REST.
	 */
	public function test_a_non_scalar_return_is_stored_as_an_empty_string() {
		$slug = $this->register_tool_with_callback(
			function () {
				return [ 'not', 'a', 'scalar' ];
			}
		);

		Experimental_Tools::save_tool_fields( $slug, [ 'declared' => 'anything' ] );

		$this->assertSame( '', Experimental_Tools::get_tool_settings( $slug )['fields']['declared'] );
	}

	/**
	 * The callback is server-side only. A callable would serialize into the REST
	 * response as an object's public properties, or as an opaque object for a
	 * closure, and the UI has no use for either.
	 */
	public function test_the_callback_is_not_exposed_in_the_rest_payload() {
		$this->register_tool_with_callback( 'wp_kses_post' );

		$fields = Experimental_Tools::get_tools()[0]['fields'];

		$this->assertArrayNotHasKey( 'sanitize_callback', $fields[1] );
		$this->assertSame( 'declared', $fields[1]['key'], 'The rest of the definition still travels.' );
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
