<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests the `wp newspack esp sync` option parser (NPPD-1883).
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\RAS_Contact_Sync;
use Newspack\Reader_Activation\Integrations;

require_once dirname( __DIR__, 3 ) . '/includes/cli/class-ras-contact-sync.php';
require_once dirname( __DIR__ ) . '/integrations/class-failing-sample-integration.php';
require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-mocks.php';

/**
 * Pre-flight parsing of --skip-lists / --fields.
 *
 * @group Contact_Sync_Options
 */
class Test_RAS_Contact_Sync_Options extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		// Content Access fields must be available for the resolver to accept them.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Invoke the private static parse_sync_options() via reflection.
	 *
	 * @param array       $assoc_args     Associative CLI args.
	 * @param string|null $integration_id Optional integration scope.
	 * @return array|\WP_Error
	 */
	private function parse( array $assoc_args, $integration_id = null ) {
		$parse_method = new \ReflectionMethod( RAS_Contact_Sync::class, 'parse_sync_options' );
		$parse_method->setAccessible( true );
		return $parse_method->invoke( null, $assoc_args, $integration_id );
	}

	public function test_defaults_when_no_options_passed() {
		$options = $this->parse( [] );
		$this->assertSame(
			[
				'skip_lists' => false,
				'fields'     => null,
			],
			$options
		);
	}

	public function test_skip_lists_flag_sets_true() {
		$options = $this->parse( [ 'skip-lists' => true ] );
		$this->assertTrue( $options['skip_lists'] );
		$this->assertNull( $options['fields'] );
	}

	/**
	 * Mailchimp rejects a list-less upsert before writing any metadata, so a
	 * --skip-lists backfill would push nothing. The pre-flight must catch this and
	 * fail with an actionable error rather than let every contact fail at push time.
	 */
	public function test_skip_lists_errors_on_mailchimp_provider() {
		update_option( 'newspack_newsletters_service_provider', 'mailchimp' );

		$result = $this->parse( [ 'skip-lists' => true ] );

		delete_option( 'newspack_newsletters_service_provider' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_esp_sync_skip_lists_mailchimp', $result->get_error_code() );
	}

	/**
	 * A non-Mailchimp provider must NOT trip the --skip-lists pre-flight guard.
	 */
	public function test_skip_lists_allowed_on_non_mailchimp_provider() {
		update_option( 'newspack_newsletters_service_provider', 'active_campaign' );

		$options = $this->parse( [ 'skip-lists' => true ] );

		delete_option( 'newspack_newsletters_service_provider' );

		$this->assertIsArray( $options );
		$this->assertTrue( $options['skip_lists'] );
	}

	/**
	 * The Mailchimp guard concerns the ESP integration only. Once --integration
	 * scopes the run to a non-ESP integration, the site's ESP takes no part in
	 * it and must not block the run (NPPD-2076 review).
	 */
	public function test_skip_lists_guard_skipped_when_scoped_to_non_esp_integration() {
		Integrations::register( new Failing_Sample_Integration( 'skip_lists_mock', 'Skip Lists Mock' ) );
		Integrations::enable( 'skip_lists_mock' );
		update_option( 'newspack_newsletters_service_provider', 'mailchimp' );

		$scoped   = $this->parse( [ 'skip-lists' => true ], 'skip_lists_mock' );
		$unscoped = $this->parse( [ 'skip-lists' => true ] );

		delete_option( 'newspack_newsletters_service_provider' );
		Integrations::disable( 'skip_lists_mock' );

		$this->assertIsArray( $scoped, 'A run scoped away from the ESP is unaffected by the ESP guard.' );
		$this->assertTrue( $scoped['skip_lists'] );
		$this->assertInstanceOf( \WP_Error::class, $unscoped, 'An unscoped run still includes the ESP, so the guard applies.' );
		$this->assertSame( 'newspack_esp_sync_skip_lists_mailchimp', $unscoped->get_error_code() );
	}

	/**
	 * Scoping explicitly TO the ESP keeps the guard.
	 */
	public function test_skip_lists_guard_applies_when_scoped_to_the_esp() {
		update_option( 'newspack_newsletters_service_provider', 'mailchimp' );

		$result = $this->parse( [ 'skip-lists' => true ], 'esp' );

		delete_option( 'newspack_newsletters_service_provider' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_esp_sync_skip_lists_mailchimp', $result->get_error_code() );
	}

	/**
	 * Regression: the parser must thread the RESOLVED labels into
	 * `$options['fields']`. A prior version resolved and validated the tokens but
	 * left `fields` null, so no compute/push scoping actually happened.
	 */
	public function test_fields_are_threaded_as_resolved_labels() {
		$options = $this->parse( [ 'fields' => 'content access,Content_Access_Source' ] );
		$this->assertIsArray( $options );
		$this->assertSame(
			[ 'Content Access', 'Content Access Source' ],
			$options['fields'],
			'--fields tokens must be resolved to canonical labels and stored in options[fields].'
		);
	}

	public function test_unknown_field_returns_wp_error() {
		$result = $this->parse( [ 'fields' => 'Definitely Not A Field' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_esp_sync_unknown_field', $result->get_error_code() );
	}

	/**
	 * A requested field that resolves fine but is NOT enabled as an outgoing field
	 * on an active, configured integration must hard-fail the pre-flight (rather
	 * than let the run silently push empty metadata to that integration).
	 */
	public function test_field_not_enabled_on_integration_returns_wp_error() {
		Failing_Sample_Integration::reset();
		$integration = new Failing_Sample_Integration( 'preflight_mock', 'Preflight Mock' );
		Integrations::register( $integration );
		Integrations::enable( 'preflight_mock' );
		// Enable only "Account" — "Content Access" is intentionally omitted.
		$integration->update_enabled_outgoing_fields( [ 'Account' ] );

		$result = $this->parse( [ 'fields' => 'Content Access' ] );

		Integrations::disable( 'preflight_mock' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_esp_sync_fields_not_enabled', $result->get_error_code() );
	}

	/**
	 * With --integration, the --fields pre-flight must validate against the
	 * target integration only — another active integration missing the field
	 * must not fail the run (NPPD-2076).
	 */
	public function test_fields_validation_scopes_to_target_integration() {
		Failing_Sample_Integration::reset();
		$with_field = new Failing_Sample_Integration( 'scoped_ok', 'Scoped OK' );
		$without    = new Failing_Sample_Integration( 'scoped_missing', 'Scoped Missing' );
		Integrations::register( $with_field );
		Integrations::register( $without );
		Integrations::enable( 'scoped_ok' );
		Integrations::enable( 'scoped_missing' );
		$with_field->update_enabled_outgoing_fields( [ 'Content Access' ] );
		$without->update_enabled_outgoing_fields( [ 'Account' ] );

		$unscoped = $this->parse( [ 'fields' => 'Content Access' ] );
		$scoped   = $this->parse( [ 'fields' => 'Content Access' ], 'scoped_ok' );

		Integrations::disable( 'scoped_ok' );
		Integrations::disable( 'scoped_missing' );

		$this->assertInstanceOf( \WP_Error::class, $unscoped, 'Unscoped: scoped_missing lacks the field, so pre-flight fails.' );
		$this->assertIsArray( $scoped, 'Scoped to scoped_ok: pre-flight passes.' );
		$this->assertSame( [ 'Content Access' ], $scoped['fields'] );
	}
}
