<?php
/**
 * Class Newsletters Test Contact_Add
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_Lists;
use Newspack\Newsletters\Subscription_List;

/**
 * Tests the Contacts Class.
 */
class Newsletters_Contacts_Test extends WP_UnitTestCase {

	/**
	 * Mock database
	 *
	 * @var array
	 */
	public static $database;

	/**
	 * Setup.
	 */
	public function set_up() {
		add_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get_response' ], 10, 3 );
		add_filter( 'mailchimp_mock_post', [ __CLASS__, 'mock_post_response' ], 10, 3 );
		add_filter( 'mailchimp_mock_put', [ __CLASS__, 'mock_put_response' ], 10, 3 );
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		remove_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get_response' ] );
		remove_filter( 'mailchimp_mock_post', [ __CLASS__, 'mock_post_response' ] );
		remove_filter( 'mailchimp_mock_put', [ __CLASS__, 'mock_put_response' ] );
	}

	/**
	 * Test set up.
	 */
	public static function set_up_before_class() {
		// Set an ESP.
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'test-us1' );

		self::$database = [
			'members' => [
				[
					'id'            => '123',
					'contact_id'    => '123',
					'email_address' => 'test1@example.com',
					'full_name'     => 'Test User',
					'list_id'       => 'test-list',
					'status'        => 'subscribed',
				],
			],
			'tags'    => [
				[
					'id'   => 42,
					'name' => 'Supertag',
				],
			],
		];

		Subscription_Lists::get_or_create_remote_list(
			[
				'id'    => 'list1',
				'title' => 'List 1',
			]
		);
		Subscription_Lists::get_or_create_remote_list(
			[
				'id'    => 'list2',
				'title' => 'List 2',
			]
		);
		Subscription_Lists::get_or_create_remote_list(
			[
				'id'    => Subscription_List::mailchimp_generate_public_id( 'group1', 'list1' ),
				'title' => 'Group 1',
			]
		);
		Subscription_Lists::get_or_create_remote_list(
			[
				'id'    => Subscription_List::mailchimp_generate_public_id( '42', 'list1', 'tag' ),
				'title' => 'Supertag',
			]
		);
		Subscription_Lists::get_or_create_remote_list(
			[
				'id'    => Subscription_List::mailchimp_generate_public_id( '42', 'list2', 'tag' ),
				'title' => 'Supertag',
			]
		);
	}

	/**
	 * Tear down class
	 */
	public static function tear_down_after_class() {
		global $wpdb;
		delete_option( 'newspack_mailchimp_api_key' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE post_type = %s" , Subscription_Lists::CPT ) ); // phpcs:ignore
	}

	public static function mock_get_response( $response, $endpoint, $args = [] ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( preg_match( '/lists\/.*\/merge-fields/', $endpoint ) ) {
			return [
				'merge_fields' => [
					[
						'tag'  => 'FNAME',
						'name' => 'Name',
					],
				],
			];
		}
		if ( preg_match( '/lists\/.*\/tag-search/', $endpoint ) ) {
			return [
				'tags' => self::$database['tags'],
			];
		}
		switch ( $endpoint ) {
			case 'search-members':
				$results = array_filter(
					self::$database['members'],
					function( $member ) use ( $args ) {
						return $member['email_address'] === $args['query'];
					}
				);
				return [ 'exact_matches' => [ 'members' => $results ] ];
			default:
				return [];
		}
		return [];
	}

	public static function mock_post_response( $response, $endpoint, $args = [] ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

		if ( preg_match( '/lists\/.*\/merge-fields/', $endpoint ) ) {
			return [
				'status' => 200,
				'tag'    => 'FNAME',
			];
		}
		return [
			'status' => 200,
		];
	}

	public static function mock_put_response( $response, $endpoint, $args = [] ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

		$members_endpoint = preg_match( '/lists\/(.*)\/members/', $endpoint, $matches );
		if ( $members_endpoint ) {
			$list_id = $matches[1];
			$result = [
				'status'  => 'pending',
				'list_id' => $list_id,
			];
			if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
				$result['tags'] = array_map(
					function( $tag_name ) {
						return [
							'id'   => 42,
							'name' => $tag_name,
						];
					},
					$args['tags']
				);
			}
			if ( isset( $args['interests'] ) && is_array( $args['interests'] ) ) {
				$result['interests'] = $args['interests'];
			}
			// Add tags and interests.
			return $result;
		}
		return [
			'status' => 200,
		];
	}

	/**
	 * Subscribe a contact to a single list synchronously.
	 */
	public function test_subscribe_contact() {
		$result = Newspack_Newsletters_Contacts::subscribe(
			[
				'email' => 'test@example.com',
			],
			[ 'list1' ]
		);

		$this->assertEquals(
			[
				'status'  => 'pending',
				'list_id' => 'list1',
			],
			$result
		);
	}

	/**
	 * Subscribe a contact to a single list assynchronously.
	 */
	public function test_subscribe_contact_async() {
		if ( ! defined( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED' ) ) {
			define( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED', true );
		}
		$result = Newspack_Newsletters_Contacts::subscribe(
			[
				'email' => 'test@example.com',
			],
			[ 'list1' ],
			true
		);

		// Async subscription should return true.
		$this->assertEquals(
			true,
			$result
		);

		// Hook into the async result.
		$async_result = null;
		add_action(
			'newspack_newsletters_contact_subscribed',
			function( $provider, $contact, $lists, $result, $is_updating, $context ) use ( &$async_result ) {
				$async_result = $result;
			},
			10,
			6
		);

		// Manually trigger subscription intents processing.
		Newspack_Newsletters_Subscription::process_subscription_intents();
		$this->assertEquals(
			[
				'status'  => 'pending',
				'list_id' => 'list1',
			],
			$async_result
		);
	}

	/**
	 * Upsert a contact to a single list.
	 */
	public function test_upsert_contact_to_single_list() {
		$result = Newspack_Newsletters_Contacts::upsert(
			[
				'email' => 'test@example.com',
			],
			[ 'list1' ]
		);
		$this->assertEquals(
			[
				'status'  => 'pending',
				'list_id' => 'list1',
			],
			$result
		);
	}

	/**
	 * Upsert a contact to multiple lists.
	 */
	public function test_upsert_contact_to_multiple_lists() {
		$result = Newspack_Newsletters_Contacts::upsert(
			[
				'email' => 'test@example.com',
			],
			[ 'list1', 'list2' ]
		);
		$this->assertEquals(
			[
				'status'  => 'pending',
				'list_id' => 'list2',
			],
			$result
		);
	}

	/**
	 * Upsert a contact to lists and sublists.
	 */
	public function test_upsert_contact_to_lists_and_sublists() {
		$result = Newspack_Newsletters_Contacts::upsert(
			[
				'email' => 'test@example.com',
			],
			[ 'list1', 'tag-42-list1', 'group-group1-list1' ]
		);
		$this->assertEquals(
			[
				'status'    => 'pending',
				'list_id'   => 'list1',
				'interests' => [ 'group1' => true ],
				'tags'      => [
					[
						'id'   => 42,
						'name' => 'Supertag',
					],
				],
			],
			$result
		);
	}

	/**
	 * Upsert a contact to multiple lists and sublists.
	 */
	public function test_upsert_contact_to_multiple_lists_and_sublists() {
		$result = Newspack_Newsletters_Contacts::upsert(
			[
				'email' => 'test@example.com',
			],
			[ 'list1', 'tag-42-list1', 'group-group1-list1', 'list2', 'tag-42-list2' ]
		);

		$this->assertEquals(
			[
				'status'  => 'pending',
				'list_id' => 'list2',
				'tags'    => [
					[
						'id'   => 42,
						'name' => 'Supertag',
					],
				],
			],
			$result
		);
	}
}
