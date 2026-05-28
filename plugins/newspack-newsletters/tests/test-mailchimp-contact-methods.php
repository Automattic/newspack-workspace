<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Newsletters Test Mailchimp Contact Methods
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_Lists;
use Newspack\Newsletters\Subscription_List;

/**
 * Test Mailchimp Contact Methods
 */
class MailchimpContactMethodsTest extends WP_UnitTestCase {

	/**
	 * Subscription Lists objects used in tests
	 *
	 * @var array
	 */
	public static $subscription_lists = [];

	/**
	 * Set up before class
	 */
	public static function set_up_before_class() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'test-us1' );
	}

	/**
	 * Create lists for testing
	 */
	private static function create_lists() {

		self::$subscription_lists = [
			'audience_1'               => Subscription_Lists::get_or_create_remote_list(
				[
					'id'    => 'list1',
					'title' => 'List 1',
				]
			),
			'group1_in_audience_1'     => Subscription_Lists::get_or_create_remote_list(
				[
					'id'    => Subscription_List::mailchimp_generate_public_id( 'group1', 'list1' ),
					'title' => 'Group 1',
				]
			),
			'group2_in_audience_1'     => Subscription_Lists::get_or_create_remote_list(
				[
					'id'    => Subscription_List::mailchimp_generate_public_id( 'group2', 'list1' ),
					'title' => 'Group 2',
				]
			),
			'tag1_in_audience_1'       => Subscription_Lists::get_or_create_remote_list(
				[
					'id'    => Subscription_List::mailchimp_generate_public_id( 'tag1', 'list1', 'tag' ),
					'title' => 'Tag 1',
				]
			),
			'tag2_in_audience_1'       => Subscription_Lists::get_or_create_remote_list(
				[
					'id'    => Subscription_List::mailchimp_generate_public_id( 'tag2', 'list1', 'tag' ),
					'title' => 'Tag 2',
				]
			),
			'local_list_in_audience_1' => self::create_local_list( 'local', 'list1' ),
			'audience_2'               => Subscription_Lists::get_or_create_remote_list(
				[
					'id'    => 'list2',
					'title' => 'List 2',
				]
			),
			'group_in_audience_2'      => Subscription_Lists::get_or_create_remote_list(
				[
					'id'    => Subscription_List::mailchimp_generate_public_id( 'group', 'list2' ),
					'title' => 'Group',
				]
			),
			'local_list_in_audience_2' => self::create_local_list( 'local', 'list2' ),
		];
	}

	/**
	 * Tear down class
	 */
	private static function delete_lists() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE post_type = %s" , Subscription_Lists::CPT ) ); // phpcs:ignore
	}

	/**
	 * Tear down class
	 */
	public static function tear_down_after_class() {
		delete_option( 'newspack_mailchimp_api_key' );
	}

	/**
	 * Helper to create local lists for testing
	 *
	 * @param string $list_id The ID of the group to create.
	 * @param string $audience_id The ID of the audience to create the group in.
	 * @return Subscription_List
	 */
	private static function create_local_list( $list_id, $audience_id ) {
		$post = wp_insert_post(
			[
				'post_title'  => $list_id,
				'post_type'   => Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);

		$subscription_list = new Subscription_List( $post );
		$subscription_list->set_type( 'local' );
		$subscription_list->update_current_provider_settings( $audience_id, $list_id, $list_id );

		return $subscription_list;
	}

	/**
	 * Get a reflection of a private method from the class for testing.
	 *
	 * @param string $name Method name.
	 * @return ReflectionMethod
	 */
	protected static function get_private_method( $name ) {
		$method = new ReflectionMethod( 'Newspack_Newsletters_Mailchimp', $name );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * Data provider for test_get_status_for_payload
	 *
	 * @return array
	 */
	public function get_status_payload_data() {
		return [
			'empty'                  => [
				[],
				null,
				[ 'status' => 'subscribed' ],
			],
			'empty_metadata'         => [
				[
					'metadata' => [],
				],
				null,
				[ 'status' => 'subscribed' ],
			],
			'empty_status'           => [
				[
					'metadata' => [
						'status' => '',
					],
				],
				null,
				[ 'status' => 'subscribed' ],
			],
			'only_status'            => [
				[
					'metadata' => [
						'status' => 'transactional',
					],
				],
				null,
				[
					'status' => 'transactional',
				],
			],
			'only status if new'     => [
				[
					'metadata' => [
						'status_if_new' => 'transactional',
					],
				],
				null,
				[
					'status_if_new' => 'transactional',
				],
			],
			'both'                   => [
				[
					'metadata' => [
						'status_if_new' => 'transactional',
						'status'        => 'subscribed',
					],
				],
				null,
				[
					'status_if_new' => 'transactional',
					'status'        => 'subscribed',
				],
			],
			'status_if_unsubscribed' => [
				[
					'existing_contact_data' => [
						'lists' => [
							'list1' => [
								'status' => 'unsubscribed',
							],
						],
					],
				],
				'list1',
				[ 'status' => 'pending' ],
			],
		];
	}

	/**
	 * Test get_status_for_payload
	 *
	 * @param array       $arg1     Input data.
	 * @param string|null $arg2     Input data.
	 * @param array       $expected Expected output.
	 * @dataProvider get_status_payload_data
	 */
	public function test_get_status_for_payload( $arg1, $arg2, $expected ) {
		$method = self::get_private_method( 'get_status_for_payload' );
		$service = Newspack_Newsletters_Mailchimp::instance();
		$this->assertSame( $expected, $method->invoke( $service, $arg1, $arg2 ) );
	}

	/**
	 * Data provider for test_prepare_lists_to_add_contact
	 *
	 * @return array
	 */
	public function prepare_lists_to_add_contact_data() {
		return [
			[
				[
					'audience_1',
				],
				[
					'list1' => [
						'tags'      => [],
						'interests' => [],
					],
				],
			],
			[
				[
					'audience_1',
					'audience_2',
				],
				[
					'list1' => [
						'tags'      => [],
						'interests' => [],
					],
					'list2' => [
						'tags'      => [],
						'interests' => [],
					],
				],
			],
			[
				[
					'audience_1',
					'audience_2',
					'group1_in_audience_1',
					'local_list_in_audience_2',
					'tag2_in_audience_1',
				],
				[
					'list1' => [
						'tags'      => [
							'tag2_in_audience_1',
						],
						'interests' => [
							'group1_in_audience_1',
						],
					],
					'list2' => [
						'tags'      => [],
						'interests' => [
							'local_list_in_audience_2',
						],
					],
				],
			],
			[
				[
					'audience_1',
					'group1_in_audience_1',
					'group2_in_audience_1',
					'tag1_in_audience_1',
					'tag2_in_audience_1',
					'local_list_in_audience_1',
					'audience_2',
					'group_in_audience_2',
					'local_list_in_audience_2',
				],
				[
					'list1' => [
						'tags'      => [
							'tag1_in_audience_1',
							'tag2_in_audience_1',
						],
						'interests' => [
							'group1_in_audience_1',
							'group2_in_audience_1',
							'local_list_in_audience_1',
						],
					],
					'list2' => [
						'tags'      => [],
						'interests' => [
							'group_in_audience_2',
							'local_list_in_audience_2',
						],
					],
				],
			],
		];
	}

	/**
	 * Undocumented function
	 *
	 * @param Subscription_List[] $lists List of Subscription Lists objects.
	 * @param array               $expected Expected return of the function.
	 * @dataProvider prepare_lists_to_add_contact_data
	 * @return void
	 */
	public function test_prepare_lists_to_add_contact( $lists, $expected ) {
		self::create_lists();

		$lists_to_pass = [];
		foreach ( $lists as $list ) {
			$lists_to_pass[] = self::$subscription_lists[ $list ];
		}

		foreach ( $expected as $audience_id => $expected_list ) {
			$tag_real_values = [];
			foreach ( $expected_list['tags'] as $tag ) {
				$tag_real_values[] = self::$subscription_lists[ $tag ]->get_remote_name();
			}

			$interests_real_values = [];
			foreach ( $expected_list['interests'] as $interest ) {
				$interests_real_values[ self::$subscription_lists[ $interest ]->mailchimp_get_sublist_id() ] = true;
			}

			$expected[ $audience_id ] = [
				'tags'      => $tag_real_values,
				'interests' => $interests_real_values,
			];
		}

		$method = self::get_private_method( 'prepare_lists_to_add_contact' );
		$service = Newspack_Newsletters_Mailchimp::instance();

		$result = $method->invoke( $service, $lists_to_pass );
		$this->assertEquals( $expected, $result );
		self::delete_lists();
	}

	/**
	 * Mock responses for get_contact_data and get_contact_lists tests
	 *
	 * @param array  $response The api response.
	 * @param string $endpoint The endpoint being called.
	 * @param array  $args The arguments passed to the endpoint.
	 * @return array
	 */
	public static function get_contact_mock_response( $response, $endpoint, $args = [] ) {
		$expected_request = false;
		$base_member_response = [
			'full_name'     => 'Sample User',
			'list_id'       => 'list1',
			'email_address' => $args['query'] ?? '',
			'id'            => '123',
			'contact_id'    => 'aaa',
			'status'        => 'subscribed',
		];
		$base_success_response = [
			'exact_matches' => [
				'members' => [],
			],
			// We always return something in the full_search key to make sure it is ignored.
			'full_search'   => [
				'members' => [
					[
						'full_name'     => 'Ignored User',
						'list_id'       => 'ignoredlist1',
						'email_address' => 'ignored@example.com',
						'id'            => '1234',
						'contact_id'    => 'aaaa',
						'status'        => 'subscribed',
					],
				],
			],
		];

		$response = false;

		if ( 'search-members' === $endpoint ) {
			if ( 'two-audiences-one-tag@example.com' === $args['query'] ) {

				$response = $base_success_response;
				$response['exact_matches']['members'][] = $base_member_response;
				$response['exact_matches']['members'][] = $base_member_response;
				$response['exact_matches']['members'][0]['tags'] = [
					[
						'id'   => 'tag-1',
						'name' => 'tag-1',
					],
				];

				$response['exact_matches']['members'][1]['list_id'] = 'list2';
				$response['exact_matches']['members'][1]['tags'] = [
					[
						'id'   => 'tag-2',
						'name' => 'tag-2',
					],
				];
				$response['exact_matches']['members'][1]['id'] = '456';
				$response['exact_matches']['members'][1]['contact_id'] = 'bbb';

			} elseif ( 'one-audience-tag-and-group@example.com' === $args['query'] ) {

				$response = $base_success_response;
				$response['exact_matches']['members'][] = $base_member_response;
				$response['exact_matches']['members'][0]['tags'] = [
					[
						'id'   => 'tag-1',
						'name' => 'tag-1',
					],
				];
				$response['exact_matches']['members'][0]['interests'] = [
					'interest-1' => true,
					'interest-2' => false,
				];

				// some noise to make sure it doesn't change anything.
				$response['exact_matches']['members'][0]['noise'] = 'noise';

			} elseif ( 'found-empty@example.com' === $args['query'] ) {
				// Simulates a response of a contact with zero lists.
				$response = $base_success_response;
				$response['exact_matches']['members'][] = $base_member_response;
				$response['exact_matches']['members'][0]['status'] = 'unsubscribed';

			} elseif ( 'not-found@example.com' === $args['query'] ) {
				// Simulates a response of a contact not found.
				$response = $base_success_response;

			} elseif ( 'failure@example.com' === $args['query'] ) {
				// Simulates an error response from the API.
				$response = [
					'type'     => 'https://mailchimp.com/developer/marketing/docs/errors/',
					'title'    => 'Resource Not Found',
					'status'   => 404,
					'detail'   => 'The requested resource could not be found.',
					'instance' => '995c5cb0-3280-4a6e-808b-3b096d0bb219',
				];
			}
		}
		return $response;
	}

	/**
	 * Data provider for test_get_contact_data
	 *
	 * @return array
	 */
	public function get_contact_data_data_provider() {
		return [
			[
				'two-audiences-one-tag@example.com',
				[
					'full_name'     => 'Sample User',
					'email_address' => 'two-audiences-one-tag@example.com',
					'id'            => '123',
					'interests'     => [],
					'tags'          => [
						'list1' => [
							[
								'id'   => 'tag-1',
								'name' => 'tag-1',
							],
						],
						'list2' => [
							[
								'id'   => 'tag-2',
								'name' => 'tag-2',
							],
						],
					],
					'lists'         => [
						'list1' => [
							'id'         => '123',
							'contact_id' => 'aaa',
							'status'     => 'subscribed',
						],
						'list2' => [
							'id'         => '456',
							'contact_id' => 'bbb',
							'status'     => 'subscribed',
						],
					],
					'merge_fields'  => [],
				],
			],
			[
				'one-audience-tag-and-group@example.com',
				[
					'full_name'     => 'Sample User',
					'email_address' => 'one-audience-tag-and-group@example.com',
					'id'            => '123',
					'interests'     => [
						'list1' => [
							'interest-1' => true,
							'interest-2' => false,
						],
					],
					'tags'          => [
						'list1' => [
							[
								'id'   => 'tag-1',
								'name' => 'tag-1',
							],
						],
					],
					'lists'         => [
						'list1' => [
							'id'         => '123',
							'contact_id' => 'aaa',
							'status'     => 'subscribed',
						],
					],
					'merge_fields'  => [],
				],
			],
			[
				'not-found@example.com',
				false,
			],
			[
				'failure@example.com',
				false,
			],
		];
	}

	/**
	 * Tests the get_contact_data method
	 *
	 * @param string      $email The email to search for.
	 * @param false|array $expected The expected contact data or false if an error is expected.
	 * @dataProvider get_contact_data_data_provider
	 * @return void
	 */
	public function test_get_contact_data( $email, $expected ) {
		$provider = Newspack_Newsletters::get_service_provider();

		add_filter( 'mailchimp_mock_get', [ __CLASS__, 'get_contact_mock_response' ], 10, 3 );

		$contact_data = $provider->get_contact_data( $email );

		if ( false !== $expected ) {
			$this->assertEquals( $expected, $contact_data );
		} else {
			$this->assertTrue( is_wp_error( $contact_data ) );
		}

		remove_filter( 'mailchimp_mock_get', [ __CLASS__, 'get_contact_mock_response' ] );
	}

	/**
	 * Data provider for test_get_contact_lists
	 *
	 * @return array
	 */
	public function get_contact_lists_data_provider() {
		return [
			[
				'two-audiences-one-tag@example.com',
				[
					'list1',
					'list2',
					'tag-tag-1-list1',
					'tag-tag-2-list2',
				],
			],
			[
				'one-audience-tag-and-group@example.com',
				[
					'list1',
					'group-interest-1-list1',
					'tag-tag-1-list1',
				],
			],
			[
				'found-empty@example.com',
				[],
			],
			[
				'not-found@example.com',
				[],
			],
			[
				'failure@example.com',
				[],
			],
		];
	}

	/**
	 * Tests the get_contact_lists method
	 *
	 * @param string      $email The email to search for.
	 * @param false|array $expected The expected contact lists or false if an error is expected.
	 * @dataProvider get_contact_lists_data_provider
	 * @return void
	 */
	public function test_get_contact_lists_lists( $email, $expected ) {
		$provider = Newspack_Newsletters::get_service_provider();

		add_filter( 'mailchimp_mock_get', [ __CLASS__, 'get_contact_mock_response' ], 10, 3 );

		$contact_lists = $provider->get_contact_lists( $email );

		if ( false !== $expected ) {
			$this->assertEquals( $expected, $contact_lists );
		} else {
			$this->assertTrue( is_wp_error( $contact_lists ) );
		}

		remove_filter( 'mailchimp_mock_get', [ __CLASS__, 'get_contact_mock_response' ] );
	}
}
