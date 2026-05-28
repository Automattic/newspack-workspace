<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Class Newsletters Test ConstantContact Usage Reports
 *
 * @package Newspack_Newsletters
 */

/**
 * Test ConstantContact Usage Reports.
 */
class ConstantContactEspCommonMethodsTest extends Abstract_ESP_Tests {

	/**
	 * The current provider slug.
	 *
	 * @var string
	 */
	protected static $provider = 'constant_contact';

	/**
	 * Set up before class
	 */
	public static function set_up_before_class() {
		Newspack_Newsletters_Constant_Contact::instance()->set_api_credentials(
			[
				'api_key'    => 'asdasd',
				'api_secret' => 'asdasd',
			]
		);
	}

	/**
	 * Sets up the required filters for the get_contact_lists test
	 *
	 * @param string $email The email to search for.
	 * @return void
	 */
	public function set_up_test_get_contact_lists( $email ) {
		add_filter( 'pre_http_request', [ __CLASS__, 'get_contact_lists_mock_response' ], 10, 3 );
		self::$expected_endpoints_reached = [ 'GET: contacts' ];
	}

	/**
	 * Tears down the required filters for the get_contact_lists test
	 *
	 * @param string $email The email to search for.
	 * @return void
	 */
	public function tear_down_test_get_contact_lists( $email ) {
		remove_filter( 'pre_http_request', [ __CLASS__, 'get_contact_lists_mock_response' ], 10, 3 );
	}

	/**
	 * Mock responses
	 *
	 * @param array  $response The api response.
	 * @param array  $args The arguments passed to the endpoint.
	 * @param string $url The endpoint url.
	 * @return array
	 */
	public static function get_contact_lists_mock_response( $response, $args, $url ) {
		$expected_request = false;

		$parsed_url  = wp_parse_url( $url );
		$parsed_args = wp_parse_args( $parsed_url['query'] );

		// Force an error if any of the below parameters change to ensure tests will be updated if the request changes.
		if (
			'api.cc.email' !== $parsed_url['host'] ||
			'/v3/contacts' !== $parsed_url['path'] ||
			'custom_fields,list_memberships,taggings' !== $parsed_args['include'] ||
			'all' !== $parsed_args['status']
		) {
			return [ 'error' ];
		}

		$base_member_response = [
			'contact_id'       => '123',
			'email_address'    => $parsed_args['email'] ?? '',
			'taggings'         => [],
			'list_memberships' => [],
			'custom_fields'    => [
				'field_id' => 'value',
			],
		];


		$response = [
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];

		$body = null;

		if ( 'found-4@example.com' === $parsed_args['email'] ) {
			// Simulates a response of a contact with 2 lists.
			$expected_request = true;
			$member = $base_member_response;
			$member['list_memberships'] = [ 'list1' ];
			$member['taggings'] = [ 'list2', 'list3', 'list4' ];
			$body = (object) [ 'contacts' => [ (object) $member ] ];


		} elseif ( 'found@example.com' === $parsed_args['email'] ) {
			// Simulates a response of a contact with 2 lists.
			$expected_request = true;
			$member = $base_member_response;
			$member['list_memberships'] = [ 'list1' ];
			$member['taggings'] = [ 'list2' ];
			$body = (object) [ 'contacts' => [ (object) $member ] ];


		} elseif ( 'found-empty@example.com' === $parsed_args['email'] ) {
			// Simulates a response of a contact with zero lists.
			$expected_request = true;
			$body = (object) [ 'contacts' => [ (object) $base_member_response ] ];

		} elseif ( 'not-found@example.com' === $parsed_args['email'] ) {
			// Simulates a response of a contact not found.
			$expected_request = true;
			$body = (object) [ 'contacts' => [] ];

		} elseif ( 'failure@example.com' === $parsed_args['email'] ) {
			// Simulates an error response from the API.
			$expected_request = true;
			$body = [
				(object) [
					'error_key'     => 'error_key',
					'error_message' => 'error_message',
				],
			];
			$response['response'] = [
				'code'    => 400,
				'message' => 'Error',
			];
		}

		if ( $expected_request ) {
			self::$endpoints_reached[] = 'GET: contacts';
		}

		$response['body'] = wp_json_encode( $body );

		return $response;
	}
}
