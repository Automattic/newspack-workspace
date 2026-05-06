<?php
/**
 * Tests for the ESP integration's configure_incoming_field() behavior.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integrations\ESP;
use Newspack\Reader_Activation\Integrations\Incoming_Field;

require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-mocks.php';

/**
 * Tests for the ESP integration.
 *
 * @group esp_integration
 */
class Test_ESP extends \WP_UnitTestCase {

	/**
	 * Cleanup state set up by individual tests so failures don't leak across cases.
	 */
	public function tear_down() {
		\Newspack_Newsletters_Contacts::reset_calls();
		remove_all_filters( 'newspack_ras_metadata_keys' );
		remove_all_filters( 'newspack_ras_metadata_prefix' );
		\delete_option( 'newspack_integration_incoming_fields_esp' );
		parent::tear_down();
	}

	/**
	 * Build an ESP instance with `get_master_list_id()` stubbed to return the given list id,
	 * so the test can exercise field-fetching paths without staging full newsletter settings.
	 *
	 * @param string $list_id The master list id to return from the stub.
	 * @return ESP
	 */
	private function make_esp_with_master_list( $list_id = 'test-list' ) {
		return new class( $list_id ) extends ESP {
			/**
			 * Stubbed master list id returned by get_master_list_id().
			 *
			 * @var string
			 */
			private $stub_list_id;

			/**
			 * Capture the list id supplied by the test, then run the parent constructor
			 * so $this->id (and the option-key prefix that depends on it) is set up.
			 *
			 * @param string $list_id The id to return.
			 */
			public function __construct( $list_id ) {
				$this->stub_list_id = $list_id;
				parent::__construct();
			}

			/**
			 * Bypass real master-list-id resolution.
			 *
			 * @return string
			 */
			public function get_master_list_id() {
				return $this->stub_list_id;
			}
		};
	}

	/**
	 * Invoke the protected configure_incoming_field() method on an ESP instance.
	 *
	 * @param ESP            $esp   The ESP integration instance.
	 * @param Incoming_Field $field The field to configure.
	 * @return Incoming_Field
	 */
	private function invoke_configure( ESP $esp, Incoming_Field $field ) {
		$method = new \ReflectionMethod( ESP::class, 'configure_incoming_field' );
		$method->setAccessible( true );
		return $method->invoke( $esp, $field );
	}

	/**
	 * A full schema drives every Incoming_Field setter.
	 */
	public function test_configure_applies_full_schema() {
		$raw = [
			'key'                 => 'membership_level',
			'name'                => 'Membership Level',
			'value_type'          => 'string',
			'matching_function'   => 'list__in',
			'options'             => [
				[
					'value' => 'gold',
					'label' => 'Gold',
				],
			],
			'description'         => 'Reader membership tier.',
			'is_access_rule'      => true,
			'is_segment_criteria' => true,
		];
		$configured = $this->invoke_configure( new ESP(), new Incoming_Field( 'membership_level', $raw ) );

		$this->assertSame( 'Membership Level', $configured->get_name() );
		$this->assertSame( 'string', $configured->get_value_type() );
		$this->assertSame( 'list__in', $configured->get_matching_function() );
		$this->assertSame( $raw['options'], $configured->get_options() );
		$this->assertSame( 'Reader membership tier.', $configured->get_description() );
		$this->assertTrue( $configured->is_access_rule() );
		$this->assertTrue( $configured->is_segment_criteria() );
	}

	/**
	 * An empty schema leaves all Incoming_Field defaults untouched.
	 */
	public function test_configure_keeps_defaults_when_schema_is_empty() {
		$configured = $this->invoke_configure( new ESP(), new Incoming_Field( 'plain_field', [] ) );

		$this->assertSame( 'plain_field', $configured->get_name() );
		$this->assertSame( 'string', $configured->get_value_type() );
		$this->assertSame( 'default', $configured->get_matching_function() );
		$this->assertSame( [], $configured->get_options() );
		$this->assertSame( '', $configured->get_description() );
		$this->assertFalse( $configured->is_access_rule() );
		$this->assertFalse( $configured->is_segment_criteria() );
	}

	/**
	 * Mis-typed schema values are ignored rather than propagated into the Incoming_Field.
	 */
	public function test_configure_ignores_invalid_types() {
		$raw = [
			'name'              => [ 'not', 'a', 'string' ],
			'value_type'        => new \stdClass(),
			'matching_function' => [],
			'options'           => 'not-an-array',
			'description'       => new \stdClass(),
		];
		$configured = $this->invoke_configure( new ESP(), new Incoming_Field( 'weird', $raw ) );

		$this->assertSame( 'weird', $configured->get_name() );
		$this->assertSame( 'string', $configured->get_value_type() );
		$this->assertSame( 'default', $configured->get_matching_function() );
		$this->assertSame( [], $configured->get_options() );
		$this->assertSame( '', $configured->get_description() );
	}

	/**
	 * The promotion flags are parsed with wp_validate_boolean() so the string "false" stays false.
	 */
	public function test_configure_uses_strict_boolean_parsing() {
		$false_raw   = [
			'is_access_rule'      => 'false',
			'is_segment_criteria' => 'false',
		];
		$false_field = $this->invoke_configure( new ESP(), new Incoming_Field( 'f', $false_raw ) );
		$this->assertFalse( $false_field->is_access_rule() );
		$this->assertFalse( $false_field->is_segment_criteria() );

		$truthy_raw   = [
			'is_access_rule'      => 'yes',
			'is_segment_criteria' => '1',
		];
		$truthy_field = $this->invoke_configure( new ESP(), new Incoming_Field( 't', $truthy_raw ) );
		$this->assertTrue( $truthy_field->is_access_rule() );
		$this->assertTrue( $truthy_field->is_segment_criteria() );
	}

	/**
	 * Boolean flags can be reset to false by the schema, not just set to true.
	 *
	 * Pre-set the field to true via the constructor, then run configure with the
	 * schema explicitly setting the flags to false. Symmetric assignment means the
	 * setter fires regardless of truthiness, so the field ends up false.
	 */
	public function test_configure_can_reset_boolean_flags_to_false() {
		// Construct with raw_data carrying explicit-false, then flip the flags on so
		// configure_incoming_field() has work to do. Same proof as a reflection-based
		// raw_data injection, no protected-property coupling.
		$pre_set = ( new Incoming_Field(
			'flagged',
			[
				'is_access_rule'      => false,
				'is_segment_criteria' => false,
			]
		) )
			->set_is_access_rule( true )
			->set_is_segment_criteria( true );
		$this->assertTrue( $pre_set->is_access_rule(), 'Sanity: field starts with the flag on.' );
		$this->assertTrue( $pre_set->is_segment_criteria() );

		$configured = $this->invoke_configure( new ESP(), $pre_set );

		$this->assertFalse( $configured->is_access_rule() );
		$this->assertFalse( $configured->is_segment_criteria() );
	}

	/**
	 * Each available incoming field is piped through configure_incoming_field().
	 */
	public function test_get_available_incoming_fields_applies_configuration() {
		\Newspack_Newsletters_Contacts::$fields_fixture = [
			[
				'key'                 => 'org',
				'name'                => 'Organization',
				'value_type'          => 'string',
				'matching_function'   => 'default',
				'is_access_rule'      => true,
				'is_segment_criteria' => true,
			],
			[
				'key'        => 'is_vip',
				'name'       => 'VIP',
				'value_type' => 'boolean',
			],
		];

		$esp    = $this->make_esp_with_master_list();
		$result = $esp->get_available_incoming_fields();

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		$this->assertSame( 'Organization', $result[0]->get_name() );
		$this->assertTrue( $result[0]->is_access_rule() );
		$this->assertTrue( $result[0]->is_segment_criteria() );

		$this->assertSame( 'VIP', $result[1]->get_name() );
		$this->assertSame( 'boolean', $result[1]->get_value_type() );
		$this->assertFalse( $result[1]->is_access_rule() );
	}

	/**
	 * Filtered incoming fields are matched against the field `name` (the ESP-side
	 * label) so outgoing-sync fields are filtered out even though incoming `key` is
	 * now a stable machine identifier (Mailchimp `tag`, ActiveCampaign `perstag`).
	 */
	public function test_get_filtered_incoming_fields_excludes_outgoing_by_name() {
		\Newspack_Newsletters_Contacts::$fields_fixture = [
			[
				'key'  => 'MMERGE7',
				'name' => 'NP_Account',
			],
			[
				'key'  => 'MMERGE8',
				'name' => 'NP_First Name',
			],
			[
				'key'  => 'CUSTOM1',
				'name' => 'Custom Field',
			],
		];

		$esp = $this->make_esp_with_master_list();

		add_filter(
			'newspack_ras_metadata_keys',
			function () {
				return [
					'account'    => 'Account',
					'first_name' => 'First Name',
				];
			}
		);
		add_filter(
			'newspack_ras_metadata_prefix',
			function () {
				return 'NP_';
			}
		);

		$result = $esp->get_filtered_incoming_fields();

		$this->assertCount( 1, $result );
		$this->assertSame( 'CUSTOM1', $result[0]->get_key() );
		$this->assertSame( 'Custom Field', $result[0]->get_name() );
	}

	/**
	 * Legacy stored entries (saved before the schema expansion) are rebuilt on
	 * read by overlaying the live provider schema, so admins don't have to
	 * re-save the integrations page after upgrade for the field to render with
	 * correct promotion / options / value_type.
	 */
	public function test_get_enabled_incoming_fields_rebuilds_legacy_entries_from_live_schema() {
		// Pre-rename storage shape: raw_data is empty (or only contains the bare key).
		\update_option(
			'newspack_integration_incoming_fields_esp',
			[
				'membership_level' => [],
			]
		);

		// Live provider returns the new schema for the same key.
		\Newspack_Newsletters_Contacts::$fields_fixture = [
			[
				'key'                 => 'membership_level',
				'name'                => 'Membership Level',
				'value_type'          => 'string',
				'matching_function'   => 'list__in',
				'options'             => [
					[
						'value' => 'gold',
						'label' => 'Gold',
					],
				],
				'is_access_rule'      => true,
				'is_segment_criteria' => true,
			],
		];

		$result = $this->make_esp_with_master_list()->get_enabled_incoming_fields();

		$this->assertCount( 1, $result );
		$this->assertSame( 'membership_level', $result[0]->get_key() );
		$this->assertSame( 'Membership Level', $result[0]->get_name(), 'name should come from live schema' );
		$this->assertSame( 'list__in', $result[0]->get_matching_function() );
		$this->assertTrue( $result[0]->is_access_rule() );
		$this->assertTrue( $result[0]->is_segment_criteria() );
		$this->assertNotEmpty( $result[0]->get_options() );
	}

	/**
	 * Stored entries already carrying schema keys are passed through without
	 * triggering the live-schema rebuild (which would issue an unnecessary API
	 * call).
	 */
	public function test_get_enabled_incoming_fields_does_not_rebuild_post_rename_entries() {
		\update_option(
			'newspack_integration_incoming_fields_esp',
			[
				'membership_level' => [
					'name'           => 'Stored Name',
					'value_type'     => 'string',
					'is_access_rule' => true,
				],
			]
		);

		// Populate the live fixture with a different name; if the rebuild path runs
		// it would overlay the live schema and the stored name would lose.
		\Newspack_Newsletters_Contacts::$fields_fixture = [
			[
				'key'            => 'membership_level',
				'name'           => 'Live Name (should not appear)',
				'is_access_rule' => false,
			],
		];

		$result = $this->make_esp_with_master_list()->get_enabled_incoming_fields();

		$this->assertCount( 1, $result );
		$this->assertSame( 'Stored Name', $result[0]->get_name(), 'stored schema should be preserved (rebuild not invoked)' );
		$this->assertTrue( $result[0]->is_access_rule(), 'stored is_access_rule should be preserved (rebuild not invoked)' );
	}

	/**
	 * If the live fetch fails (network error / WP_Error), fall back to stored
	 * raw_data unchanged rather than dropping the field or duplicating the
	 * failure to every callsite.
	 */
	public function test_get_enabled_incoming_fields_falls_back_when_live_fetch_fails() {
		\update_option(
			'newspack_integration_incoming_fields_esp',
			[
				'legacy_field' => [],
			]
		);

		\Newspack_Newsletters_Contacts::$fields_fixture = new \WP_Error( 'fetch_failed', 'API down' );

		$result = $this->make_esp_with_master_list()->get_enabled_incoming_fields();

		$this->assertCount( 1, $result );
		$this->assertSame( 'legacy_field', $result[0]->get_key() );
		$this->assertSame( 'legacy_field', $result[0]->get_name(), 'falls back to key when live fetch fails' );
		$this->assertFalse( $result[0]->is_access_rule() );
	}

	/**
	 * Entries without a usable string `key` are skipped rather than producing malformed fields.
	 */
	public function test_get_available_incoming_fields_skips_entries_without_usable_key() {
		\Newspack_Newsletters_Contacts::$fields_fixture = [
			[
				'key'  => 'good',
				'name' => 'Good',
			],
			[
				'name' => 'Missing key',
			],
			[
				'key'  => '',
				'name' => 'Empty key',
			],
			[
				'key'  => [ 'not', 'a', 'string' ],
				'name' => 'Non-string key',
			],
			'not-an-array',
		];

		$esp    = $this->make_esp_with_master_list();
		$result = $esp->get_available_incoming_fields();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'good', $result[0]->get_key() );
	}
}
