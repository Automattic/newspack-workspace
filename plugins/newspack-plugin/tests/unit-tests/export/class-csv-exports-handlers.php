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
	 * Intercept wp_die() so the handlers' bail-out paths are assertable.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'WC_ABSPATH' ) ) {
			// CSV_Exports::load_exporter_dependencies() only reads this to
			// locate WooCommerce's batch-exporter abstract, which wc-mocks.php
			// has already defined — so the path itself is never used.
			define( 'WC_ABSPATH', '/woocommerce/' );
		}
		add_filter( 'wp_die_handler', [ $this, 'get_die_handler' ] );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_die_handler' ] );
	}

	/**
	 * Clean up the request superglobals and filters.
	 */
	public function tear_down() {
		remove_filter( 'wp_die_handler', [ $this, 'get_die_handler' ] );
		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_die_handler' ] );
		remove_filter( 'wp_doing_ajax', '__return_true' );
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
		$this->login_as_export_capable_admin();
		$this->assertFalse( class_exists( 'WooCommerce' ), 'This test asserts the WooCommerce-inactive behavior.' );
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
		$_POST    = [
			'export'   => 'subscriptions',
			'security' => wp_create_nonce( CSV_Exports::AJAX_NONCE_ACTION ),
			'step'     => 2,
			'filename' => CSV_Exports::generate_export_filename( 'users' ),
		];
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$response = $this->run_ajax_export();

		$this->assertTrue( $response['success'] );
		// No subscriptions exist, so the run completes on its first step and
		// hands back a download link naming the file it actually wrote.
		$this->assertSame( 'done', $response['data']['step'] );
		wp_parse_str( (string) wp_parse_url( $response['data']['url'], PHP_URL_QUERY ), $query );
		$download_filename = $query['filename'] ?? '';
		$this->assertStringStartsWith( 'newspack-subscriptions-export-', $download_filename );
	}
}
