<?php
/**
 * Tests for the signed REST requests the Hub sends to a Node.
 *
 * @package Newspack_Network
 */

use Newspack_Network\Crypto;
use Newspack_Network\Rest_Authenticaton;
use Newspack_Network\Used_Nonces;
use Newspack_Network\Node\Integrity_Check_Endpoints;

/**
 * Test Rest_Authenticaton signature verification.
 *
 * @group rest-authentication
 */
class TestRestAuthentication extends \WP_UnitTestCase {

	/**
	 * The secret shared by the Hub and this Node.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Create the nonce table before the per-test transaction; see
	 * {@see TestHubWebhook::wpSetUpBeforeClass()} for why.
	 *
	 * @param \WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		Used_Nonces::get_table_name();
	}

	/**
	 * Configure the Node's secret.
	 */
	public function set_up() {
		parent::set_up();
		$this->secret_key = Crypto::generate_secret_key();
		update_option( 'newspack_node_secret_key', $this->secret_key );
	}

	/**
	 * Build a request carrying the given signature headers.
	 *
	 * @param array  $headers Signature headers.
	 * @param string $route   Route to request.
	 * @return WP_REST_Request
	 */
	private function signed_request( $headers, $route = '/newspack-network/v1/info' ) {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $request;
	}

	/**
	 * A signature is accepted once, and a second request carrying it is refused.
	 */
	public function test_signature_is_accepted_once() {
		$headers = Rest_Authenticaton::generate_signature_headers( 'info', $this->secret_key );

		$this->assertTrue( Rest_Authenticaton::verify_signature( $this->signed_request( $headers ), 'info', $this->secret_key ) );

		$repeat = Rest_Authenticaton::verify_signature( $this->signed_request( $headers ), 'info', $this->secret_key );
		$this->assertWPError( $repeat );
		$this->assertSame( 'Signature already used', $repeat->get_error_message() );
	}

	/**
	 * Distinct signatures for the same endpoint are each accepted.
	 */
	public function test_fresh_signatures_are_each_accepted() {
		for ( $i = 0; $i < 3; $i++ ) {
			$headers = Rest_Authenticaton::generate_signature_headers( 'info', $this->secret_key );
			$this->assertTrue( Rest_Authenticaton::verify_signature( $this->signed_request( $headers ), 'info', $this->secret_key ) );
		}
	}

	/**
	 * A signature refused for another reason stays usable for the request it was made for.
	 */
	public function test_refused_signature_does_not_use_up_its_nonce() {
		$headers = Rest_Authenticaton::generate_signature_headers( 'info', $this->secret_key );

		$mismatch = Rest_Authenticaton::verify_signature( $this->signed_request( $headers ), 'integrity-check', $this->secret_key );
		$this->assertWPError( $mismatch );
		$this->assertSame( 'Signature mismatch', $mismatch->get_error_message() );

		$this->assertTrue( Rest_Authenticaton::verify_signature( $this->signed_request( $headers ), 'info', $this->secret_key ) );
	}

	/**
	 * A request accepted for one endpoint is not thereby accepted for another.
	 */
	public function test_accepted_request_is_not_accepted_for_another_endpoint() {
		$request = $this->signed_request( Rest_Authenticaton::generate_signature_headers( 'info', $this->secret_key ) );

		$this->assertTrue( Rest_Authenticaton::verify_signature( $request, 'info', $this->secret_key ) );
		$this->assertTrue( Rest_Authenticaton::verify_signature( $request, 'info', $this->secret_key ), 'The same request verifies again for its own endpoint.' );
		$this->assertWPError( Rest_Authenticaton::verify_signature( $request, 'integrity-check', $this->secret_key ) );
	}

	/**
	 * A Hub on an earlier version still signs a salt; its signatures verify.
	 */
	public function test_signature_from_earlier_hub_is_accepted() {
		$headers = $this->legacy_headers( 'info' );

		$this->assertTrue( Rest_Authenticaton::verify_signature( $this->signed_request( $headers ), 'info', $this->secret_key ) );
	}

	/**
	 * A signature the nonce store cannot record is refused rather than accepted unrecorded.
	 */
	public function test_signature_is_refused_when_its_nonce_cannot_be_recorded() {
		$headers = Rest_Authenticaton::generate_signature_headers( 'info', $this->secret_key );
		$table   = Used_Nonces::get_table_name();
		$block   = function ( $query ) use ( $table ) {
			return 0 === stripos( $query, "INSERT INTO `$table`" ) ? '' : $query;
		};

		add_filter( 'query', $block );
		$result = Rest_Authenticaton::verify_signature( $this->signed_request( $headers ), 'info', $this->secret_key );
		remove_filter( 'query', $block );

		$this->assertWPError( $result );
		$this->assertSame( 'Could not record signature', $result->get_error_message() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	/**
	 * Through a real route: the first request is served, and core's Allow header
	 * still lists GET, which re-runs the permission callback on the same request.
	 * A second request with the same headers is refused.
	 */
	public function test_route_serves_a_signature_once() {
		$GLOBALS['wp_rest_server'] = null;
		$server                    = rest_get_server();
		Integrity_Check_Endpoints::register_routes();

		$route   = '/newspack-network/v1/integrity-check/sync-status';
		$headers = Rest_Authenticaton::generate_signature_headers( 'integrity-check', $this->secret_key );

		$request  = $this->signed_request( $headers, $route );
		$response = rest_do_request( $request );
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), $server, $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'GET', $response->get_headers()['Allow'] ?? null );

		$repeat = rest_do_request( $this->signed_request( $headers, $route ) );
		$this->assertTrue( $repeat->is_error() );
		$this->assertSame( 'Signature already used', $repeat->as_error()->get_error_message() );
		$this->assertSame( 401, $repeat->get_status() );
	}

	/**
	 * The WooCommerce read routes verify in rest_request_before_callbacks; a second
	 * request with the same headers is refused there too.
	 */
	public function test_woo_read_route_accepts_a_signature_once() {
		$headers = Rest_Authenticaton::generate_signature_headers( 'get-woo-orders', $this->secret_key );

		$first = Rest_Authenticaton::rest_pre_dispatch( null, [], $this->signed_request( $headers, '/wc/v3/orders/1' ) );
		$this->assertNull( $first );

		$repeat = Rest_Authenticaton::rest_pre_dispatch( null, [], $this->signed_request( $headers, '/wc/v3/orders/2' ) );
		$this->assertWPError( $repeat );
		$this->assertSame( 'Signature already used', $repeat->get_error_message() );
	}

	/**
	 * Headers that do not decrypt are refused before any read access is granted.
	 */
	public function test_undecryptable_signature_is_refused_on_woo_read_route() {
		$headers = [
			'X-NP-Network-Signature' => str_repeat( 'ab', 40 ),
			'X-NP-Network-Nonce'     => Crypto::generate_nonce(),
		];

		$result = Rest_Authenticaton::rest_pre_dispatch( null, [], $this->signed_request( $headers, '/wc/v3/orders/1' ) );

		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertFalse( has_filter( 'woocommerce_rest_check_permissions', [ Rest_Authenticaton::class, 'allow_woo_read_endpoints' ] ) );
	}

	/**
	 * Signature headers in the format earlier Hub versions send.
	 *
	 * @param string $endpoint_id Endpoint ID.
	 * @return array
	 */
	private function legacy_headers( $endpoint_id ) {
		$nonce = Crypto::generate_nonce();
		$body  = wp_json_encode(
			[
				'timestamp'   => time(),
				'salt'        => wp_generate_password( 12, false ),
				'endpoint_id' => $endpoint_id,
			]
		);
		return [
			'X-NP-Network-Signature' => Crypto::encrypt_message( $body, $this->secret_key, $nonce ),
			'X-NP-Network-Nonce'     => $nonce,
		];
	}
}
