<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Class Newsletters Test ActiveCampaign Usage Reports
 *
 * @package Newspack_Newsletters
 */

/**
 * Test ActiveCampaign Usage Reports.
 */
class ActiveCampaignApiRequestsTest extends WP_UnitTestCase {


	/**
	 * The mock api response
	 *
	 * @var array
	 */
	private $api_response = [];

	/**
	 * Set up.
	 */
	public function set_up() {
		add_filter( 'pre_http_request', array( $this, 'filter_api_response' ), 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'filter_api_response' ), 10 );
	}

	/**
	 * Filter API Response.
	 *
	 * @param array|WP_Error $response The response or WP_Error object.
	 * @param array          $parsed_args Array of HTTP request arguments.
	 * @param string         $url The request URL.
	 *
	 * @return array|WP_Error
	 */
	public function filter_api_response( $response, $parsed_args, $url ) {
		return $this->api_response;
	}

	/**
	 * Make request.
	 *
	 * @return mixed
	 */
	private function make_request() {
		$ac = Newspack_Newsletters_Active_Campaign::instance();
		$ac->set_api_credentials(
			[
				'url' => 'doesnt_matter',
				'key' => 'doesnt_matter',
			]
		);
		return $ac->api_v3_request( 'doesnt_matter', 'GET' );
	}

	/**
	 * Test successful request.
	 */
	public function test_successful_request() {
		$body = [
			'contacts'  => 1,
			'lists'     => 2,
			'campaigns' => 3,
		];
		$this->api_response = array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $body ),
		);

		$returned_data = $this->make_request();

		$this->assertEquals( $body, $returned_data );
	}

	/**
	 * Test payment required request.
	 */
	public function test_payment_required_request() {

		$this->api_response = array(
			'response' => array(
				'code'    => 402,
				'message' => 'Payment required',
			),
			'body'     => null,
		);

		$returned_data = $this->make_request();

		$this->assertTrue( is_wp_error( $returned_data ) );
		$this->assertEquals( 402, $returned_data->get_error_code() );
		$this->assertEquals( 'Payment required', $returned_data->get_error_message() );
	}

	/**
	 * Test request with many errors.
	 */
	public function test_errors_request() {
		$body = [
			'errors' => [
				[
					'code'  => 422,
					'title' => 'Title 1',
				],
				[
					// without a code, as descibed in https://developers.activecampaign.com/reference/errors.
					'title' => 'Title 2',
				],
			],
		];
		$this->api_response = array(
			'response' => array(
				// Not sure if this is a valid response code, but it's just for testing purposes, as the code was not checking for the response code and I want to keep it as it was.
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $body ),
		);

		$returned_data = $this->make_request();

		$this->assertTrue( is_wp_error( $returned_data ) );
		$this->assertEquals( 2, count( $returned_data->get_error_messages() ) );

		$this->assertContains( 'Title 1', $returned_data->get_error_messages() );
		$this->assertContains( 422, $returned_data->get_error_codes() );
		$this->assertContains( 'Title 2', $returned_data->get_error_messages() );
	}
}
