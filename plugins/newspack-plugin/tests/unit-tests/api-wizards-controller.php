<?php
/**
 * Tests the wizards API functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\API\Wizards_Controller;

/**
 * Test wizards API endpoints functionality.
 */
class Newspack_Test_Wizards_Controller extends WP_UnitTestCase {

	/**
	 * Route we're testing.
	 *
	 * @var string
	 */
	protected $api_route = '/newspack/v1/wizards/newspack-dashboard';

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
	 * Test that the routes are registered.
	 */
	public function test_register_route() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/newspack/v1/wizards/(?P<slug>[\a-z]+)', $routes );
	}

	/**
	 * Test unauthorized users can't retrieve wizards info.
	 */
	public function test_get_wizard_unauthorized() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', $this->api_route );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test retrieving plugins info.
	 */
	public function test_get_wizard_authorized() {
		wp_set_current_user( $this->administrator );
		$request  = new WP_REST_Request( 'GET', $this->api_route );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'Newspack', $data['name'] );
	}

	/**
	 * Test that marking a wizard as complete works.
	 */
	public function test_set_wizard_completion() {
		wp_set_current_user( $this->administrator );

		// Before setting completed.
		$request  = new WP_REST_Request( 'GET', $this->api_route );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( false, $data['completed'] );

		// Set completed.
		$request = new WP_REST_Request( 'POST', $this->api_route . '/complete' );
		$this->server->dispatch( $request );

		// Verify it saved.
		$request  = new WP_REST_Request( 'GET', $this->api_route );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( true, $data['completed'] );
	}
}
