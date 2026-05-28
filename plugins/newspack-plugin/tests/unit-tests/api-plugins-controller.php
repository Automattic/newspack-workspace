<?php
/**
 * Tests the plugins API functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\API\Plugins_Controller;
use Newspack\Plugin_Manager;

/**
 * Test plugin API endpoints functionality.
 */
class Newspack_Test_Plugins_Controller extends WP_UnitTestCase {

	/**
	 * Plugin slug/folder.
	 *
	 * @var string
	 */
	protected $api_namespace = '/newspack/v1';

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $administrator;

	/**
	 * Set up stuff for testing API requests.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->administrator = $this->factory->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * Test that the routes are all registered.
	 */
	public function test_register_route() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( $this->api_namespace . '/plugins', $routes );
	}

	/**
	 * Test unauthorized users can't retrieve plugins info.
	 */
	public function test_get_plugins_unauthorized() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', $this->api_namespace . '/plugins' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test retrieving plugins info.
	 */
	public function test_get_plugins_authorized() {
		wp_set_current_user( $this->administrator );
		$request  = new WP_REST_Request( 'GET', $this->api_namespace . '/plugins' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jetpack', $data );

		$expected_jetpack_info = [
			'Name'        => 'Jetpack',
			'Description' => 'Bring the power of the WordPress.com cloud to your self-hosted WordPress. Jetpack enables you to connect your blog to a WordPress.com account to use the powerful features normally only available to WordPress.com users.',
			'Author'      => 'Automattic',
			'PluginURI'   => 'https://jetpack.com/',
			'AuthorURI'   => 'https://automattic.com/',
			'Download'    => 'wporg',
			'TextDomain'  => '',
			'DomainPath'  => '',
			'EditPath'    => 'admin.php?page=jetpack',
			'HandoffLink' => 'http://example.org/wp-admin/admin.php?page=jetpack',
			'Slug'        => 'jetpack',
			'Status'      => 'uninstalled',
			'Version'     => '',
		];
		$this->assertEquals( $expected_jetpack_info, $data['jetpack'] );
	}

	/**
	 * Test handoff to URL requires destinationUrl.
	 */
	public function test_handoff_to_url_missing_destination() {
		wp_set_current_user( $this->administrator );
		$request  = new WP_REST_Request( 'POST', $this->api_namespace . '/handoff' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test handoff to URL rejects external URLs.
	 */
	public function test_handoff_to_url_rejects_external_url() {
		wp_set_current_user( $this->administrator );
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/handoff' );
		$request->set_param( 'destinationUrl', 'https://evil.example/steal-tokens' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test handoff to URL rejects the javascript: scheme.
	 */
	public function test_handoff_to_url_rejects_javascript_scheme() {
		wp_set_current_user( $this->administrator );
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/handoff' );
		$request->set_param( 'destinationUrl', 'javascript:alert(1)' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test handoff to URL rejects protocol-relative URLs pointing off-site.
	 */
	public function test_handoff_to_url_rejects_protocol_relative_url() {
		wp_set_current_user( $this->administrator );
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/handoff' );
		$request->set_param( 'destinationUrl', '//evil.example/x' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test handoff to URL rejects external handoffReturnUrl.
	 */
	public function test_handoff_to_url_rejects_external_return_url() {
		wp_set_current_user( $this->administrator );
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/handoff' );
		$request->set_param( 'destinationUrl', '/wp-admin/admin.php?page=newspack-dashboard' );
		$request->set_param( 'handoffReturnUrl', 'https://evil.example/return' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test handoff to URL stores options and returns the link.
	 */
	public function test_handoff_to_url_success() {
		wp_set_current_user( $this->administrator );
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/handoff' );
		$request->set_param( 'destinationUrl', '/wp-admin/admin.php?page=newspack-dashboard' );
		$request->set_param( 'bannerText', 'Come back when done.' );
		$request->set_param( 'bannerButtonText', 'Back to Plugin' );
		$request->set_param( 'handoffReturnUrl', 'http://example.org/wp-admin/admin.php?page=newspack-settings' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'HandoffLink', $response->get_data() );

		$this->assertEquals( 'url', get_option( NEWSPACK_HANDOFF ) );
		$this->assertEquals( 'Come back when done.', get_option( NEWSPACK_HANDOFF_BANNER_TEXT ) );
		$this->assertEquals( 'Back to Plugin', get_option( NEWSPACK_HANDOFF_BANNER_BUTTON_TEXT ) );
		$this->assertEquals( 'newspack-dashboard', get_option( NEWSPACK_HANDOFF_DESTINATION_PAGE ) );
	}

	/**
	 * Test handoff to URL is inaccessible without authentication.
	 */
	public function test_handoff_to_url_unauthorized() {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', $this->api_namespace . '/handoff' );
		$request->set_param( 'destinationUrl', '/wp-admin/admin.php?page=newspack-dashboard' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test the schema.
	 */
	public function test_schema() {
		$request  = new WP_REST_Request( 'OPTIONS', $this->api_namespace . '/plugins' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$schema = $response->get_data();
		$this->assertEquals( 'string', $schema['schema']['properties']['Name']['type'] );
	}
}
