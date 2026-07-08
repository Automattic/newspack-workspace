<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests the field-scoped, list-less CLI sync options (NPPD-1883).
 *
 * Covers the two orthogonal `wp newspack esp sync` flags:
 *   --skip-lists : upsert with $lists === false (create, don't subscribe).
 *   --fields=... : compute + push only the requested metadata fields.
 *
 * @package Newspack\Tests
 */

use Newspack\Content_Gate;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Metadata;
use Newspack\Reader_Activation\Sync\Legacy_Metadata;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Content_Gate as Content_Gate_Metadata;

require_once __DIR__ . '/../../mocks/newsletters-mocks.php';
require_once __DIR__ . '/../integrations/class-failing-sample-integration.php';

/**
 * Field-scoped / list-less sync options.
 *
 * @group Contact_Sync_Options
 */
class Test_Contact_Sync_Options extends WP_UnitTestCase {

	/**
	 * Schema version restored in tear_down().
	 *
	 * @var string
	 */
	private static $original_version;

	/**
	 * Verified reader user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * The three Content Access field labels this backfill targets.
	 *
	 * @var string[]
	 */
	private $content_access_labels = [ 'Content Access', 'Content Access Source', 'Content Access Group' ];

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		// Feature flag + sync-allowed constants. Defines leak process-wide, but
		// these only *enable* behavior so they can't neutralize other tests.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		if ( ! defined( 'NEWSPACK_ALLOW_READER_SYNC' ) ) {
			define( 'NEWSPACK_ALLOW_READER_SYNC', true );
		}
		if ( ! defined( 'NEWSPACK_FORCE_ALLOW_ESP_SYNC' ) ) {
			define( 'NEWSPACK_FORCE_ALLOW_ESP_SYNC', true );
		}
		self::$original_version = Metadata::$version;
	}

	public function set_up() {
		parent::set_up();
		Content_Gate_Metadata::reset_cache();
		Newspack_Newsletters_Contacts::reset_calls();
		Metadata::$version = 'legacy';

		$this->user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
		Reader_Activation::set_reader_verified( $this->user_id );

		// Configure the ESP master list so ESP::push_contact_data() can sync.
		$esp = Integrations::get_integration( 'esp' );
		$esp->update_settings_field_value( 'mailchimp_audience_id', '123' );
	}

	public function tear_down() {
		Metadata::$version = self::$original_version;
		Content_Gate_Metadata::reset_cache();
		Failing_Sample_Integration::reset();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );
		}
		parent::tear_down();
	}

	/**
	 * Create a published gate with active custom access rules.
	 *
	 * @param array  $access_rules Access rules array.
	 * @param string $title        Optional gate title.
	 * @return int Gate post ID.
	 */
	private function create_custom_access_gate( $access_rules, $title = 'Options Gate' ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		update_post_meta(
			$gate_id,
			'custom_access',
			[
				'active'       => true,
				'access_rules' => $access_rules,
			]
		);
		return $gate_id;
	}

	/**
	 * An email-domain rule the seeded reader (reader@example.com) passes.
	 *
	 * @return array
	 */
	private function passing_email_domain_rules() {
		return [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
	}

	/**
	 * Invoke a private static method of Contact_Sync via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @return mixed
	 */
	private function invoke_contact_sync( $method, array $args ) {
		$reflection_method = new \ReflectionMethod( Contact_Sync::class, $method );
		$reflection_method->setAccessible( true );
		return $reflection_method->invoke( null, ...$args );
	}

	public function test_resolve_field_labels_accepts_raw_keys_and_labels() {
		$resolved = Metadata::resolve_field_labels( [ 'Content_Access', 'Content Access Source' ] );
		$this->assertSame(
			[ 'Content Access', 'Content Access Source' ],
			$resolved,
			'Raw keys and labels must both canonicalize to the display label.'
		);
	}

	public function test_resolve_field_labels_is_case_insensitive() {
		$resolved = Metadata::resolve_field_labels( [ 'content access', 'CONTENT ACCESS GROUP' ] );
		$this->assertSame( [ 'Content Access', 'Content Access Group' ], $resolved );
	}

	public function test_resolve_field_labels_dedupes_synonymous_tokens() {
		// registration_page and current_page_url both map to 'Registration Page'.
		$resolved = Metadata::resolve_field_labels( [ 'registration_page', 'current_page_url' ] );
		$this->assertSame( [ 'Registration Page' ], $resolved, 'Synonymous raw keys must collapse to one label.' );
	}

	public function test_resolve_field_labels_errors_on_unknown_token() {
		$result = Metadata::resolve_field_labels( [ 'Not A Real Field' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_esp_sync_unknown_field', $result->get_error_code() );
	}

	public function test_resolve_field_labels_errors_distinctly_on_unavailable_token() {
		// Force a "known but unavailable" field deterministically. Dropping "Account"
		// from the AVAILABLE set only (get_all_fields( true )) via the metadata-keys
		// filter leaves it present in get_all_fields( false ) but absent from ( true ),
		// the exact shape a feature-flag/plugin-gated field takes. In CI every real
		// field is available, so relying on one would leave this branch untested.
		$drop_from_available = function ( $keys, $only_available ) {
			if ( $only_available ) {
				unset( $keys['account'] );
			}
			return $keys;
		};
		add_filter( 'newspack_ras_metadata_keys', $drop_from_available, 10, 2 );

		$result = Metadata::resolve_field_labels( [ 'Account' ] );

		remove_filter( 'newspack_ras_metadata_keys', $drop_from_available, 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'newspack_esp_sync_unavailable_field',
			$result->get_error_code(),
			'A known-but-unavailable field must return a distinct error code from an unknown one.'
		);
	}

	public function test_compute_legacy_only_returns_requested_content_access_fields() {
		$this->create_custom_access_gate( $this->passing_email_domain_rules() );

		$contact = Metadata::get_contact_with_metadata( $this->user_id, $this->content_access_labels );

		$this->assertArrayHasKey( 'NP_Content Access', $contact['metadata'] );
		$this->assertArrayNotHasKey(
			'NP_Account',
			$contact['metadata'],
			'Legacy_Basic must be skipped when only Content Access fields are requested.'
		);
	}

	public function test_compute_legacy_total_paid_still_runs_legacy_basic() {
		$this->create_custom_access_gate( $this->passing_email_domain_rules() );

		// 'Total Paid' is a payment-field label valued by Legacy_Basic (not Legacy_Payment),
		// so the special case must run Legacy_Basic and skip Content_Gate.
		$contact = Metadata::get_contact_with_metadata( $this->user_id, [ 'Total Paid' ] );

		$this->assertArrayHasKey(
			'NP_Account',
			$contact['metadata'],
			'Requesting a payment field must still run Legacy_Basic (which computes all legacy fields).'
		);
		$this->assertArrayNotHasKey(
			'NP_Content Access',
			$contact['metadata'],
			'Content_Gate must be skipped when only a payment field is requested.'
		);
	}

	public function test_compute_v1_returns_raw_content_access_keys_only() {
		Metadata::$version = '1.0';
		Content_Gate_Metadata::reset_cache();
		$this->create_custom_access_gate( $this->passing_email_domain_rules() );

		$contact = Metadata::get_contact_with_metadata( $this->user_id, $this->content_access_labels );

		$this->assertArrayHasKey( 'Content_Access', $contact['metadata'], 'v1 mode returns raw (unprefixed) keys.' );
		$this->assertArrayNotHasKey( 'Registration_Date', $contact['metadata'], 'Non-requested classes must be skipped in v1 mode.' );
	}

	public function test_prepare_contact_for_integration_keeps_only_requested_fields() {
		$esp     = Integrations::get_integration( 'esp' );
		$options = [
			'skip_lists' => false,
			'fields'     => $this->content_access_labels,
		];

		// Legacy-shaped contact: metadata already prefixed, with an extra basic field and a name.
		$contact = [
			'email'    => 'reader@example.com',
			'name'     => 'Real Name',
			'metadata' => [
				'NP_Content Access'        => 'Yes',
				'NP_Content Access Source' => 'domain',
				'NP_Account'               => '42',
				'status_if_new'            => 'subscribed',
			],
		];

		$prepared = $this->invoke_contact_sync( 'prepare_contact_for_integration', [ $esp, $contact, $options ] );

		$this->assertSame( 'reader@example.com', $prepared['email'], 'Email must be preserved.' );
		$this->assertArrayNotHasKey( 'name', $prepared, 'Name must be stripped when field-scoping.' );
		$this->assertSame(
			[ 'NP_Content Access', 'NP_Content Access Source' ],
			array_keys( $prepared['metadata'] ),
			'Only requested, prefixed metadata keys survive; NP_Account and status_if_new are dropped.'
		);
	}

	public function test_prepare_contact_for_integration_matches_utm_prefix_labels() {
		$esp     = Integrations::get_integration( 'esp' );
		$options = [
			'skip_lists' => false,
			'fields'     => [ 'Signup UTM: ' ],
		];

		$contact = [
			'email'    => 'reader@example.com',
			'metadata' => [
				'NP_Signup UTM: source' => 'newsletter',
				'NP_Account'            => '42',
			],
		];

		$prepared = $this->invoke_contact_sync( 'prepare_contact_for_integration', [ $esp, $contact, $options ] );

		$this->assertSame(
			[ 'NP_Signup UTM: source' ],
			array_keys( $prepared['metadata'] ),
			'A label ending in ": " must match its suffixed UTM keys.'
		);
	}

	public function test_skip_lists_passes_false_master_list_to_upsert() {
		$esp     = Integrations::get_integration( 'esp' );
		$contact = [
			'email'    => 'reader@example.com',
			'metadata' => [ 'NP_Content Access' => 'Yes' ],
		];

		$esp->push_contact_data( $contact, 'ctx', null, [ 'skip_lists' => true ] );

		$this->assertCount( 1, Newspack_Newsletters_Contacts::$upsert_calls );
		$this->assertFalse(
			Newspack_Newsletters_Contacts::$upsert_calls[0]['master_list_id'],
			'--skip-lists must upsert with a false master list id.'
		);
	}

	public function test_without_skip_lists_passes_configured_master_list() {
		$esp     = Integrations::get_integration( 'esp' );
		$contact = [
			'email'    => 'reader@example.com',
			'metadata' => [ 'NP_Content Access' => 'Yes' ],
		];

		$esp->push_contact_data( $contact, 'ctx', null, [] );

		$this->assertSame(
			'123',
			Newspack_Newsletters_Contacts::$upsert_calls[0]['master_list_id'],
			'Without --skip-lists the configured master list id must be used.'
		);
	}

	public function test_custom_options_suppress_retry_scheduling() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail = true;
		$integration = new Failing_Sample_Integration( 'failing_opts', 'Failing Opts' );
		Integrations::register( $integration );
		Integrations::enable( 'failing_opts' );

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$contact = [
			'email'    => 'reader@example.com',
			'metadata' => [ 'NP_Content Access' => 'Yes' ],
		];
		$this->invoke_contact_sync(
			'push_to_integrations',
			[
				$contact,
				'ctx',
				null,
				[
					'skip_lists' => true,
					'fields'     => $this->content_access_labels,
				],
			]
		);

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'failing_opts' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'Custom-option syncs must not schedule auto-retries.' );
	}

	public function test_default_options_still_schedule_retry() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
		Failing_Sample_Integration::reset();
		Failing_Sample_Integration::$should_fail = true;
		$integration = new Failing_Sample_Integration( 'failing_default', 'Failing Default' );
		Integrations::register( $integration );
		Integrations::enable( 'failing_default' );

		as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );

		$contact = [
			'email'    => 'reader@example.com',
			'metadata' => [ 'NP_Content Access' => 'Yes' ],
		];
		$this->invoke_contact_sync( 'push_to_integrations', [ $contact, 'ctx', null, [] ] );

		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_HOOK,
				'group'  => Integrations::get_action_group( 'failing_default' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertNotEmpty( $pending, 'Default syncs must preserve the existing auto-retry behavior.' );
	}

	public function test_dry_run_with_options_makes_no_upsert() {
		$this->create_custom_access_gate( $this->passing_email_domain_rules() );

		$result = Contact_Sync::sync_contact(
			$this->user_id,
			'ctx',
			true, // dry run.
			[
				'skip_lists' => true,
				'fields'     => $this->content_access_labels,
			]
		);

		$this->assertTrue( $result, 'Dry-run returns true.' );
		$this->assertEmpty( Newspack_Newsletters_Contacts::$upsert_calls, 'Dry-run must not push to the ESP.' );
	}

	public function test_backfill_shape_is_email_plus_three_content_access_fields_listless() {
		$this->create_custom_access_gate( $this->passing_email_domain_rules() );
		$esp     = Integrations::get_integration( 'esp' );
		$options = [
			'skip_lists' => true,
			'fields'     => $this->content_access_labels,
		];

		$contact  = Metadata::get_contact_with_metadata( $this->user_id, $options['fields'] );
		$prepared = $this->invoke_contact_sync( 'prepare_contact_for_integration', [ $esp, $contact, $options ] );
		$esp->push_contact_data( $prepared, 'Content Access backfill (NPPD-1883)', null, $options );

		$this->assertCount( 1, Newspack_Newsletters_Contacts::$upsert_calls );
		$call = Newspack_Newsletters_Contacts::$upsert_calls[0];

		$this->assertFalse( $call['master_list_id'], 'Backfill must be list-less.' );
		$this->assertSame( 'reader@example.com', $call['contact']['email'] );
		$this->assertArrayNotHasKey( 'name', $call['contact'], 'Reader name must not be rewritten.' );
		$this->assertEqualsCanonicalizing(
			[ 'NP_Content Access', 'NP_Content Access Source', 'NP_Content Access Group' ],
			array_keys( $call['contact']['metadata'] ),
			'Only the three prefixed Content Access fields are pushed.'
		);
	}
}
