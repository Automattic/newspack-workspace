<?php
/**
 * Class TestIntegrityCheckEndpoints
 *
 * @package Newspack_Network
 */

use Newspack_Network\Node\Integrity_Check_Endpoints;

/**
 * Test the Integrity Check Endpoints class.
 */
class TestIntegrityCheckEndpoints extends WP_UnitTestCase {

	/**
	 * Test route registration
	 */
	public function test_register_routes() {
		// Clear any existing routes.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		// Register our routes.
		Integrity_Check_Endpoints::register_routes();

		$registered_routes = rest_get_server()->get_routes();

		// Check that our routes are registered.
		$this->assertArrayHasKey( '/newspack-network/v1/integrity-check/hash', $registered_routes );
		$this->assertArrayHasKey( '/newspack-network/v1/integrity-check/memberships', $registered_routes );
		$this->assertArrayHasKey( '/newspack-network/v1/integrity-check/range-hash', $registered_routes );
		$this->assertArrayHasKey( '/newspack-network/v1/integrity-check/range-data', $registered_routes );
	}

	/**
	 * Test route arguments for range endpoints
	 */
	public function test_range_route_arguments() {
		// Clear any existing routes.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		// Register our routes.
		Integrity_Check_Endpoints::register_routes();

		$registered_routes = rest_get_server()->get_routes();

		// Check range-hash route arguments.
		$range_hash_route_config = $registered_routes['/newspack-network/v1/integrity-check/range-hash'][0];
		$this->assertArrayHasKey( 'args', $range_hash_route_config );
		$this->assertArrayHasKey( 'start', $range_hash_route_config['args'] );
		$this->assertArrayHasKey( 'end', $range_hash_route_config['args'] );
		$this->assertArrayHasKey( 'max', $range_hash_route_config['args'] );

		// Check that start and end are required.
		$this->assertTrue( $range_hash_route_config['args']['start']['required'] );
		$this->assertTrue( $range_hash_route_config['args']['end']['required'] );
		$this->assertFalse( $range_hash_route_config['args']['max']['required'] );

		// Check range-data route arguments.
		$range_data_route_config = $registered_routes['/newspack-network/v1/integrity-check/range-data'][0];
		$this->assertArrayHasKey( 'args', $range_data_route_config );
		$this->assertArrayHasKey( 'start', $range_data_route_config['args'] );
		$this->assertArrayHasKey( 'end', $range_data_route_config['args'] );
		$this->assertArrayHasKey( 'max', $range_data_route_config['args'] );
	}

	/**
	 * Test basic request handling
	 */
	public function test_handle_requests() {
		// Test hash request.
		$hash_request = new WP_REST_Request( 'GET', '/newspack-network/v1/integrity-check/hash' );
		$hash_response = Integrity_Check_Endpoints::handle_hash_request( $hash_request );
		$hash_response_data = $hash_response->get_data();
		$this->assertArrayHasKey( 'hash', $hash_response_data );
		$this->assertArrayHasKey( 'count', $hash_response_data );

		// Test memberships request.
		$memberships_request = new WP_REST_Request( 'GET', '/newspack-network/v1/integrity-check/memberships' );
		$memberships_response = Integrity_Check_Endpoints::handle_memberships_request( $memberships_request );
		$memberships_response_data = $memberships_response->get_data();
		$this->assertArrayHasKey( 'memberships', $memberships_response_data );
		$this->assertArrayHasKey( 'count', $memberships_response_data );
		$this->assertIsArray( $memberships_response_data['memberships'] );
	}

	/**
	 * Test range request handling
	 */
	public function test_handle_range_requests() {
		// Test range hash request.
		$range_hash_request = new WP_REST_Request( 'GET', '/newspack-network/v1/integrity-check/range-hash' );
		$range_hash_request->set_param( 'start', 'a@example.com' );
		$range_hash_request->set_param( 'end', 'z@example.com' );
		$range_hash_response = Integrity_Check_Endpoints::handle_range_hash_request( $range_hash_request );
		$range_hash_response_data = $range_hash_response->get_data();
		$this->assertArrayHasKey( 'hash', $range_hash_response_data );
		$this->assertArrayHasKey( 'start', $range_hash_response_data );
		$this->assertArrayHasKey( 'end', $range_hash_response_data );
		$this->assertArrayHasKey( 'count', $range_hash_response_data );
		$this->assertEquals( 'a@example.com', $range_hash_response_data['start'] );
		$this->assertEquals( 'z@example.com', $range_hash_response_data['end'] );

		// Test range data request.
		$range_data_request = new WP_REST_Request( 'GET', '/newspack-network/v1/integrity-check/range-data' );
		$range_data_request->set_param( 'start', 'b@example.com' );
		$range_data_request->set_param( 'end', 'y@example.com' );
		$range_data_response = Integrity_Check_Endpoints::handle_range_data_request( $range_data_request );
		$range_data_response_data = $range_data_response->get_data();
		$this->assertArrayHasKey( 'memberships', $range_data_response_data );
		$this->assertArrayHasKey( 'start', $range_data_response_data );
		$this->assertArrayHasKey( 'end', $range_data_response_data );
		$this->assertArrayHasKey( 'count', $range_data_response_data );
		$this->assertEquals( 'b@example.com', $range_data_response_data['start'] );
		$this->assertEquals( 'y@example.com', $range_data_response_data['end'] );
		$this->assertIsArray( $range_data_response_data['memberships'] );

		// Test case insensitive email handling.
		$case_insensitive_request = new WP_REST_Request( 'GET', '/newspack-network/v1/integrity-check/range-hash' );
		$case_insensitive_request->set_param( 'start', 'A@EXAMPLE.COM' );
		$case_insensitive_request->set_param( 'end', 'Z@EXAMPLE.COM' );
		$case_insensitive_response = Integrity_Check_Endpoints::handle_range_hash_request( $case_insensitive_request );
		$case_insensitive_response_data = $case_insensitive_response->get_data();
		$this->assertEquals( 'a@example.com', $case_insensitive_response_data['start'] );
		$this->assertEquals( 'z@example.com', $case_insensitive_response_data['end'] );

		// Test max parameter handling.
		$max_parameter_request = new WP_REST_Request( 'GET', '/newspack-network/v1/integrity-check/range-data' );
		$max_parameter_request->set_param( 'start', 'a@example.com' );
		$max_parameter_request->set_param( 'end', 'z@example.com' );
		$max_parameter_request->set_param( 'max', 50 );
		$max_parameter_response = Integrity_Check_Endpoints::handle_range_data_request( $max_parameter_request );
		$max_parameter_response_data = $max_parameter_response->get_data();
		$this->assertArrayHasKey( 'memberships', $max_parameter_response_data );
		$this->assertArrayHasKey( 'count', $max_parameter_response_data );
		$this->assertLessThanOrEqual( 50, $max_parameter_response_data['count'] );
	}
}
