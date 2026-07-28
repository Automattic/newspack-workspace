<?php
/**
 * Tests the CSV export request handlers (AJAX step + file download).
 *
 * @package Newspack\Tests
 */

use Newspack\CSV_Exports;

require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';

/**
 * These two handlers carry the whole authorization story for a PII export, so
 * they are exercised directly rather than through their helpers: nonce
 * verification, the per-type capability re-check on download, the filename ↔
 * type binding, and the expired-file response.
 *
 * @group csv-export
 */
class Newspack_Test_CSV_Export_Handlers extends WP_UnitTestCase {

	/**
	 * Message passed to the last intercepted wp_die().
	 *
	 * @var string
	 */
	private $die_message = '';

	/**
	 * HTTP status passed to the last intercepted wp_die().
	 *
	 * @var int
	 */
	private $die_status = 0;

	/**
	 * Reset the WCS mock fixtures the exporter pages over, and intercept
	 * wp_die() so the handlers' bail-out paths are assertable.
	 */
	public function set_up() {
		parent::set_up();
		// Other suites populate these globals and don't clear them, so the
		// result set an export sees here would otherwise depend on file order.
		global $subscriptions_database, $wcs_mock_hpos_enabled;
		$subscriptions_database = [];
		$wcs_mock_hpos_enabled  = false;
		unset( $GLOBALS['wcs_mock_orders_with_meta_query_result'] );
		add_filter( 'wp_die_handler', [ $this, 'get_die_handler' ] );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_die_handler' ] );
	}

	/**
	 * Clean up the request superglobals, filters and staged export files.
	 */
	public function tear_down() {
		remove_filter( 'wp_die_handler', [ $this, 'get_die_handler' ] );
		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_die_handler' ] );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'woocommerce_newspack_subscriptions_export_batch_limit' );
		newspack_test_remove_export_files();
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	/**
	 * Filter callback returning the recording die handler.
	 *
	 * @return callable
	 */
	public function get_die_handler() {
		return [ $this, 'record_die' ];
	}

	/**
	 * Record a wp_die() call and abort the handler under test.
	 *
	 * @param string|WP_Error $message Die message.
	 * @param string          $title   Die title.
	 * @param array|int       $args    Die args, or a bare status code.
	 * @throws WPDieException Always, to unwind the handler as wp_die() would.
	 */
	public function record_die( $message, $title = '', $args = [] ) {
		$this->die_message = is_scalar( $message ) ? (string) $message : '';
		$response          = is_array( $args ) ? ( $args['response'] ?? 0 ) : $args;
		$this->die_status  = is_numeric( $response ) ? (int) $response : 0;
		throw new WPDieException( $this->die_message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Assert that a callback bails out via wp_die() with a given status and message.
	 *
	 * @param int      $status   Expected HTTP status.
	 * @param string   $needle   Expected substring of the die message.
	 * @param callable $callback The handler invocation.
	 */
	private function assert_dies_with( $status, $needle, callable $callback ) {
		$this->die_message = '';
		$this->die_status  = 0;
		try {
			$callback();
		} catch ( WPDieException $e ) {
			$this->assertSame( $status, $this->die_status, "Died with: {$this->die_message}" );
			$this->assertStringContainsString( $needle, $this->die_message );
			return;
		}
		$this->fail( 'The handler was expected to bail out via wp_die().' );
	}

	/**
	 * Run ajax_export() and return the decoded JSON response.
	 *
	 * @return array|null
	 */
	private function run_ajax_export() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		try {
			CSV_Exports::ajax_export();
		} catch ( WPDieException $e ) {
			// wp_send_json_*() ends with wp_die(); the payload is already echoed.
			unset( $e );
		}
		return json_decode( ob_get_clean(), true );
	}

	/**
	 * Log in as an administrator who can run the subscriptions export.
	 *
	 * @return int User ID.
	 */
	private function login_as_export_capable_admin() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		// manage_woocommerce is granted by WooCommerce, which isn't loaded here.
		wp_get_current_user()->add_cap( 'manage_woocommerce' );
		return $user_id;
	}

	/**
	 * Seed an AJAX export step request.
	 *
	 * @param int    $step     Step number.
	 * @param string $filename Filename to echo back, if any.
	 */
	private function seed_ajax_request( $step, $filename = '' ) {
		$_POST = [
			'export'   => 'subscriptions',
			'security' => wp_create_nonce( CSV_Exports::AJAX_NONCE_ACTION ),
			'step'     => $step,
		];
		if ( '' !== $filename ) {
			$_POST['filename'] = $filename;
		}
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Seed a download request.
	 *
	 * @param string $type     Export type.
	 * @param string $filename Filename to request.
	 * @param string $nonce    Nonce to send.
	 */
	private function seed_download_request( $type, $filename, $nonce ) {
		$_GET = [
			'action'   => CSV_Exports::DOWNLOAD_ACTION,
			'nonce'    => $nonce,
			'export'   => $type,
			'filename' => $filename,
		];
	}

	/**
	 * A download link with a bad nonce is refused before anything else happens.
	 */
	public function test_download_rejects_invalid_nonce() {
		$this->login_as_export_capable_admin();
		$this->seed_download_request( 'subscriptions', CSV_Exports::generate_export_filename( 'subscriptions' ), 'not-a-nonce' );

		$this->assert_dies_with( 403, 'Invalid download link', [ CSV_Exports::class, 'download_export_file' ] );
	}

	/**
	 * The download re-checks the capability rather than trusting the nonce: a
	 * link forwarded to (or a session downgraded to) a user without export
	 * rights must not serve the file.
	 */
	public function test_download_rejects_user_without_capability() {
		$this->login_as_export_capable_admin();
		$nonce = wp_create_nonce( CSV_Exports::DOWNLOAD_NONCE_ACTION );
		$this->seed_download_request( 'subscriptions', CSV_Exports::generate_export_filename( 'subscriptions' ), $nonce );

		// Same session, capability revoked (e.g. role changed mid-export).
		wp_get_current_user()->remove_cap( 'manage_woocommerce' );
		wp_get_current_user()->remove_role( 'administrator' );

		$this->assert_dies_with( 403, 'permission', [ CSV_Exports::class, 'download_export_file' ] );
	}

	/**
	 * Capability checks are per-type, so a filename minted for one export type
	 * must not be downloadable through another type's code path.
	 */
	public function test_download_rejects_cross_type_filename() {
		$this->login_as_export_capable_admin();
		$this->seed_download_request(
			'subscriptions',
			CSV_Exports::generate_export_filename( 'users' ),
			wp_create_nonce( CSV_Exports::DOWNLOAD_NONCE_ACTION )
		);

		$this->assert_dies_with( 403, 'Invalid download link', [ CSV_Exports::class, 'download_export_file' ] );
	}

	/**
	 * A served export is deleted on send, so replaying the link must report an
	 * expired download rather than quietly streaming a headers-only CSV.
	 */
	public function test_download_reports_expired_file() {
		$this->login_as_export_capable_admin();
		$this->seed_download_request(
			'subscriptions',
			CSV_Exports::generate_export_filename( 'subscriptions' ),
			wp_create_nonce( CSV_Exports::DOWNLOAD_NONCE_ACTION )
		);

		$this->assert_dies_with( 410, 'expired', [ CSV_Exports::class, 'download_export_file' ] );
	}

	/**
	 * The users export requires WooCommerce to be active (its CSV carries WC
	 * billing PII), so an administrator with every capability is still refused
	 * while WooCommerce is absent.
	 */
	public function test_download_users_export_requires_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			// Another suite (tests/unit-tests/my-account.php) eval()s a
			// WooCommerce shim, which can't be undone for the rest of the
			// process. Skip rather than depend on file ordering.
			$this->markTestSkipped( 'A WooCommerce class shim is already defined in this process.' );
		}
		$this->login_as_export_capable_admin();
		$this->seed_download_request(
			'users',
			CSV_Exports::generate_export_filename( 'users' ),
			wp_create_nonce( CSV_Exports::DOWNLOAD_NONCE_ACTION )
		);

		$this->assert_dies_with( 403, 'permission', [ CSV_Exports::class, 'download_export_file' ] );
	}

	/**
	 * A request with no valid nonce never reaches the exporter.
	 */
	public function test_ajax_export_rejects_invalid_nonce() {
		$this->login_as_export_capable_admin();
		$_POST    = [
			'export'   => 'subscriptions',
			'security' => 'not-a-nonce',
		];
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->run_ajax_export();
		$this->assertSame( 403, $this->die_status, 'check_ajax_referer() must refuse the request.' );
	}

	/**
	 * A valid nonce is not enough: the export capability is checked per type.
	 */
	public function test_ajax_export_rejects_user_without_capability() {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		$_POST    = [
			'export'   => 'subscriptions',
			'security' => wp_create_nonce( CSV_Exports::AJAX_NONCE_ACTION ),
		];
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$response = $this->run_ajax_export();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $response['data']['message'] );
	}

	/**
	 * The filename is minted by the server on step 1 and only echoed back by
	 * later steps. A step-2 request carrying another type's filename must not
	 * adopt it — the run restarts under a filename of its own type instead.
	 */
	public function test_ajax_export_does_not_adopt_a_cross_type_filename() {
		$this->login_as_export_capable_admin();
		$this->seed_ajax_request( 2, CSV_Exports::generate_export_filename( 'users' ) );

		$response = $this->run_ajax_export();

		$this->assertTrue( $response['success'] );
		// No subscriptions exist, so the run completes on its first step and
		// hands back a download link naming the file it actually wrote.
		$this->assertSame( 'done', $response['data']['step'] );
		wp_parse_str( (string) wp_parse_url( $response['data']['url'], PHP_URL_QUERY ), $query );
		$download_filename = $query['filename'] ?? '';
		$this->assertStringStartsWith( 'newspack-subscriptions-export-', $download_filename );
	}

	/**
	 * A single already-exported row leaving the set mid-run is the likeliest
	 * shrinkage and the one the percentage hides: the WC counter assumes every
	 * prior page was full, so the terminal empty page puts it back at exactly
	 * 100 over a file that is a row short. The publisher must still be told the
	 * snapshot may be incomplete, and the run's pinned total must not outlive
	 * the run.
	 */
	public function test_ajax_export_flags_a_run_that_ended_short() {
		global $subscriptions_database;
		$this->login_as_export_capable_admin();
		// Only the fields the mock has no default for: this test is about
		// paging, not row contents (the exporter suite covers those).
		for ( $i = 1; $i <= 4; $i++ ) {
			wcs_create_subscription(
				[
					'id'               => $i,
					'status'           => 'active',
					'total'            => '25.00',
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'customer_id'      => 0,
				]
			);
		}
		// Two rows per step, so the run spans several AJAX steps (the handler
		// never calls set_limit(); WC's batch-limit filter is the seam).
		add_filter( 'woocommerce_newspack_subscriptions_export_batch_limit', fn() => 2 );

		$this->seed_ajax_request( 1 );
		$step_one = $this->run_ajax_export();
		$this->assertSame( 2, $step_one['data']['step'] );
		$this->assertSame( 50, $step_one['data']['percentage'] );
		$filename = $step_one['data']['filename'];

		// One of the two exported rows leaves the filtered set, so the last two
		// rows now sit at offsets the run has already walked past.
		unset( $subscriptions_database[1] );

		$this->seed_ajax_request( 2, $filename );
		$step_two = $this->run_ajax_export();
		$this->assertSame( 3, $step_two['data']['step'], 'The pinned total must keep the run paging.' );

		$this->seed_ajax_request( 3, $filename );
		$step_three = $this->run_ajax_export();

		$this->assertSame( 'done', $step_three['data']['step'] );
		$this->assertNotEmpty(
			$step_three['data']['notice'],
			'A run ending on an empty page wrote fewer rows than it counted, whatever the percentage says.'
		);
		$this->assertFalse(
			get_transient( 'newspack_export_total_' . md5( $filename ) ),
			'The finished run must not leave its pinned total behind.'
		);
	}
}
