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
	 * A list removed by the sign-up filter never reaches upsert().
	 */
	public function test_subscribe_drops_lists_removed_by_the_filter() {
		$seen = null;
		$spy  = function ( $lists ) use ( &$seen ) {
			$seen = $lists;
		};
		$drop = function ( $lists ) {
			return array_diff( $lists, [ 'list2' ] );
		};
		add_action( 'newspack_newsletters_pre_add_contact', $spy );
		add_filter( 'newspack_newsletters_subscribe_lists', $drop );

		Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], [ 'list2', 'list1' ] );

		remove_filter( 'newspack_newsletters_subscribe_lists', $drop );
		remove_action( 'newspack_newsletters_pre_add_contact', $spy );

		$this->assertSame( [ 'list1' ], $seen, 'Only the surviving list reaches upsert(), reindexed.' );
	}

	/**
	 * A sign-up that loses only some of its lists still succeeds for the reader, so
	 * it leaves a structured log entry for support: the address, and the lists
	 * requested and allowed.
	 */
	public function test_subscribe_logs_a_narrowed_sign_up() {
		$entries = [];
		$spy     = function ( $code, $context, $payload ) use ( &$entries ) {
			if ( 'newspack_newsletters_sign_up_lists_narrowed' === $code ) {
				$entries[] = [ $context, $payload ];
			}
		};
		$drop    = function ( $lists ) {
			return array_diff( $lists, [ 'list2' ] );
		};
		add_action( 'newspack_log', $spy, 10, 3 );
		add_filter( 'newspack_newsletters_subscribe_lists', $drop );

		Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], [ 'list2', 'list1' ], false, 'Test context' );

		remove_filter( 'newspack_newsletters_subscribe_lists', $drop );
		remove_action( 'newspack_log', $spy, 10 );

		$this->assertCount( 1, $entries, 'The narrowing is logged once.' );
		$this->assertSame( 'Test context', $entries[0][0] );
		$this->assertSame( [ 'list2', 'list1' ], $entries[0][1]['data']['requested'] );
		$this->assertSame( [ 'list1' ], $entries[0][1]['data']['allowed'] );
		$this->assertSame( 'test@example.com', $entries[0][1]['user_email'] );
	}

	/**
	 * When the filter removes every requested list, subscribe() returns an error
	 * and nothing reaches upsert().
	 */
	public function test_subscribe_errors_when_the_filter_removes_every_list() {
		$reached_upsert = false;
		$spy            = function () use ( &$reached_upsert ) {
			$reached_upsert = true;
		};
		add_action( 'newspack_newsletters_pre_add_contact', $spy );
		add_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );

		$result = Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], [ 'list1' ] );

		remove_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );
		remove_action( 'newspack_newsletters_pre_add_contact', $spy );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_newsletters_lists_not_allowed', $result->get_error_code() );
		$this->assertFalse( $reached_upsert );
	}

	/**
	 * The filter is handed the contact and the context of the sign-up, for logging
	 * and for comparing the contact against the requester.
	 */
	public function test_subscribe_filter_receives_contact_and_context() {
		$args = [];
		$spy  = function ( $lists, $contact, $context ) use ( &$args ) {
			$args = [ $lists, $contact['email'], $context ];
			return $lists;
		};
		add_filter( 'newspack_newsletters_subscribe_lists', $spy, 10, 3 );

		Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], [ 'list1' ], false, 'Test context' );

		remove_filter( 'newspack_newsletters_subscribe_lists', $spy, 10 );

		$this->assertSame( [ [ 'list1' ], 'test@example.com', 'Test context' ], $args );
	}

	/**
	 * A callback can take lists away but can't add any: opting a contact in to a
	 * list they never asked for is a consent problem.
	 */
	public function test_subscribe_filter_cannot_add_lists() {
		$seen = null;
		$spy  = function ( $lists ) use ( &$seen ) {
			$seen = $lists;
		};
		$add  = function ( $lists ) {
			return array_merge( $lists, [ 'list2' ] );
		};
		add_action( 'newspack_newsletters_pre_add_contact', $spy );
		add_filter( 'newspack_newsletters_subscribe_lists', $add );

		Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], [ 'list1' ] );

		remove_filter( 'newspack_newsletters_subscribe_lists', $add );
		remove_action( 'newspack_newsletters_pre_add_contact', $spy );

		$this->assertSame( [ 'list1' ], $seen );
	}

	/**
	 * A single ID passed as a string is filtered like an array of one, including
	 * an ID that PHP treats as empty.
	 */
	public function test_subscribe_filters_a_single_list_id() {
		$seen_by_filter = [];
		$spy            = function ( $lists ) use ( &$seen_by_filter ) {
			$seen_by_filter[] = $lists;
			return [];
		};
		add_filter( 'newspack_newsletters_subscribe_lists', $spy );

		$first  = Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], 'list1' );
		$second = Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], '0' );

		remove_filter( 'newspack_newsletters_subscribe_lists', $spy );

		$this->assertSame( [ [ 'list1' ], [ '0' ] ], $seen_by_filter );
		$this->assertSame( 'newspack_newsletters_lists_not_allowed', $first->get_error_code() );
		$this->assertSame( 'newspack_newsletters_lists_not_allowed', $second->get_error_code() );
	}

	/**
	 * A callback that returns something other than an array refuses the sign-up
	 * rather than letting every list through.
	 */
	public function test_subscribe_errors_when_the_filter_returns_a_non_array() {
		add_filter( 'newspack_newsletters_subscribe_lists', '__return_false' );
		$result = Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], [ 'list1' ] );
		remove_filter( 'newspack_newsletters_subscribe_lists', '__return_false' );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_newsletters_lists_not_allowed', $result->get_error_code() );
	}

	/**
	 * Lists that were already checked skip the filter. This is how a queued
	 * sign-up is replayed: the replay can't be relied on to carry the reader's
	 * session, so it can't judge the lists again.
	 */
	public function test_subscribe_skips_the_filter_for_checked_lists() {
		$seen = null;
		$spy  = function ( $lists ) use ( &$seen ) {
			$seen = $lists;
		};
		add_action( 'newspack_newsletters_pre_add_contact', $spy );
		add_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );

		$result = Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], [ 'list1' ], false, 'Test context', true );

		remove_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );
		remove_action( 'newspack_newsletters_pre_add_contact', $spy );

		$this->assertNotWPError( $result );
		$this->assertSame( [ 'list1' ], $seen );
	}

	/**
	 * A queued sign-up is replayed without the lists filter. Its lists were checked
	 * when it was queued, in the reader's own request. The replay arrives by a
	 * loopback request or from cron, and neither can be relied on to carry the
	 * reader's session to check them by.
	 */
	public function test_queued_sign_up_is_replayed_without_the_filter() {
		if ( ! defined( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED' ) ) {
			define( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED', true );
		}
		$email = 'queued-replay@example.com';
		// Keyed by email so any other queued sign-up the replay picks up can't
		// change what this test reads.
		$reached_upsert = [];
		$spy            = function ( $lists, $contact ) use ( &$reached_upsert ) {
			$reached_upsert[ $contact['email'] ] = $lists;
		};
		add_action( 'newspack_newsletters_pre_add_contact', $spy, 10, 2 );

		// No filter is hooked yet, so the request passes its check and is queued.
		$queued = Newspack_Newsletters_Contacts::subscribe( [ 'email' => $email ], [ 'list1' ], true );
		$this->assertTrue( $queued, 'The sign-up is queued, not run.' );
		$this->assertArrayNotHasKey( $email, $reached_upsert );

		// Now a filter that would refuse every list. The replay must not consult it.
		add_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );
		Newspack_Newsletters_Subscription::process_subscription_intents();
		remove_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );
		remove_action( 'newspack_newsletters_pre_add_contact', $spy, 10 );

		$this->assertSame( [ 'list1' ], $reached_upsert[ $email ] ?? null );
	}

	/**
	 * A sign-up queued with no lists is replayed with no lists. Post meta saves
	 * "no lists" (false) as an empty string, and the replay skips the sign-up check,
	 * so it must not hand that string on to be looked up as a list with an empty ID.
	 */
	public function test_queued_sign_up_with_no_lists_is_replayed_with_no_lists() {
		if ( ! defined( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED' ) ) {
			define( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED', true );
		}
		$email = 'queued-no-lists@example.com';
		// Keyed by email so any other queued sign-up the replay picks up can't
		// change what this test reads.
		$reached_upsert = [];
		$spy            = function ( $lists, $contact ) use ( &$reached_upsert ) {
			$reached_upsert[ $contact['email'] ] = $lists;
		};
		add_action( 'newspack_newsletters_pre_add_contact', $spy, 10, 2 );

		$queued = Newspack_Newsletters_Contacts::subscribe( [ 'email' => $email ], false, true );
		$this->assertTrue( $queued, 'The sign-up is queued, not run.' );

		Newspack_Newsletters_Subscription::process_subscription_intents();

		remove_action( 'newspack_newsletters_pre_add_contact', $spy, 10 );
		// The ESP has nothing to subscribe the contact to, so the sign-up stays
		// queued. Remove it so it can't be replayed by another test.
		$this->take_queued_sign_up_lists( $email );

		$this->assertArrayHasKey( $email, $reached_upsert, 'The queued sign-up was replayed.' );
		$this->assertFalse( $reached_upsert[ $email ], 'upsert() gets false, not a list with an empty ID.' );
	}

	/**
	 * Only an intent post can be replayed. Replaying skips the lists filter, so it
	 * must not be reachable with the ID of an arbitrary post.
	 */
	public function test_only_an_intent_post_can_be_replayed() {
		$email   = 'not-an-intent@example.com';
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		update_post_meta( $post_id, 'contact', [ 'email' => $email ] );
		update_post_meta( $post_id, 'lists', [ 'list1' ] );
		update_post_meta( $post_id, 'context', 'Test context' );

		$reached_upsert = [];
		$spy            = function ( $lists, $contact ) use ( &$reached_upsert ) {
			$reached_upsert[] = $contact['email'];
		};
		add_action( 'newspack_newsletters_pre_add_contact', $spy, 10, 2 );

		Newspack_Newsletters_Subscription::process_subscription_intents( $post_id );

		remove_action( 'newspack_newsletters_pre_add_contact', $spy, 10 );

		$this->assertNotContains( $email, $reached_upsert, 'A post that is not an intent is not replayed.' );
		$this->assertInstanceOf( WP_Post::class, get_post( $post_id ), 'The post is left alone.' );
	}

	/**
	 * Only `true` marks lists as already checked. A stray truthy value must not turn
	 * the check off.
	 */
	public function test_subscribe_checks_lists_unless_checked_is_exactly_true() {
		add_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );

		$result = Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], [ 'list1' ], false, 'Test context', 'yes' );

		remove_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_newsletters_lists_not_allowed', $result->get_error_code() );
	}

	/**
	 * Get the lists of each queued sign-up for an email address, and remove those
	 * sign-ups.
	 *
	 * Removing them in the same step stops a failed assertion from leaving a queued
	 * sign-up behind for another test to replay.
	 *
	 * @param string $email Email address of the sign-up.
	 *
	 * @return array[] The stored lists of each queued sign-up for that address.
	 */
	private function take_queued_sign_up_lists( $email ) {
		$taken      = [];
		$intent_ids = get_posts(
			[
				'post_type'   => Newspack_Newsletters_Subscription::SUBSCRIPTION_INTENT_CPT,
				'numberposts' => 100,
				'fields'      => 'ids',
			]
		);
		foreach ( $intent_ids as $intent_id ) {
			$contact = get_post_meta( $intent_id, 'contact', true );
			if ( is_array( $contact ) && isset( $contact['email'] ) && $email === $contact['email'] ) {
				$taken[] = get_post_meta( $intent_id, 'lists', true );
				wp_delete_post( $intent_id, true );
			}
		}
		return $taken;
	}

	/**
	 * The filter runs before a sign-up is queued, so the queued sign-up holds only
	 * the lists the filter kept. The replay doesn't filter again, which makes this
	 * the only check a reader's sign-up gets.
	 */
	public function test_queued_sign_up_stores_only_the_lists_the_filter_kept() {
		if ( ! defined( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED' ) ) {
			define( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED', true );
		}
		$email = 'queued-filtered@example.com';
		$drop  = function ( $lists ) {
			return array_diff( $lists, [ 'list2' ] );
		};
		add_filter( 'newspack_newsletters_subscribe_lists', $drop );

		$result = Newspack_Newsletters_Contacts::subscribe( [ 'email' => $email ], [ 'list1', 'list2' ], true );

		remove_filter( 'newspack_newsletters_subscribe_lists', $drop );
		$stored = $this->take_queued_sign_up_lists( $email );

		$this->assertTrue( $result, 'The sign-up is queued, not run.' );
		$this->assertSame( [ [ 'list1' ] ], $stored, 'The queued sign-up holds only the list the filter kept.' );
	}

	/**
	 * A sign-up the filter refuses in full is never queued.
	 */
	public function test_refused_sign_up_is_not_queued() {
		if ( ! defined( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED' ) ) {
			define( 'NEWSPACK_NEWSLETTERS_ASYNC_SUBSCRIPTION_ENABLED', true );
		}
		$email = 'queued-refused@example.com';
		add_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );

		$result = Newspack_Newsletters_Contacts::subscribe( [ 'email' => $email ], [ 'list1' ], true );

		remove_filter( 'newspack_newsletters_subscribe_lists', '__return_empty_array' );
		$stored = $this->take_queued_sign_up_lists( $email );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_newsletters_lists_not_allowed', $result->get_error_code() );
		$this->assertSame( [], $stored, 'Nothing is queued for a refused sign-up.' );
	}

	/**
	 * Requests that ask for lists but name none that can be looked up.
	 *
	 * @return array
	 */
	public static function requests_with_no_usable_list_id() {
		return [
			'empty string'           => [ '' ],
			'list of an empty ID'    => [ [ '' ] ],
			'list of false'          => [ [ false ] ],
			'list of null'           => [ [ null ] ],
			'list of a nested array' => [ [ [ 'list1' ] ] ],
		];
	}

	/**
	 * A request that asks for lists but names none that can be looked up is
	 * refused, with no filter hooked and nothing reaching upsert(). Skipping the
	 * check for it would hand upsert() a value the filter never saw.
	 *
	 * @dataProvider requests_with_no_usable_list_id
	 *
	 * @param mixed $lists Lists as passed to subscribe().
	 */
	public function test_subscribe_refuses_a_request_with_no_usable_list_id( $lists ) {
		$reached_upsert = false;
		$spy            = function () use ( &$reached_upsert ) {
			$reached_upsert = true;
		};
		add_action( 'newspack_newsletters_pre_add_contact', $spy );

		$result = Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], $lists );

		remove_action( 'newspack_newsletters_pre_add_contact', $spy );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_newsletters_lists_not_allowed', $result->get_error_code() );
		$this->assertFalse( $reached_upsert );
	}

	/**
	 * Ways a caller says "no lists", with the value upsert() should receive.
	 *
	 * @return array
	 */
	public static function requests_for_no_lists() {
		return [
			'empty array' => [ [], [] ],
			'false'       => [ false, false ],
			'null'        => [ null, false ],
		];
	}

	/**
	 * An empty array, false and null all mean "no lists". None has anything to check,
	 * so the filter stays out of it and the request still reaches upsert(). False and
	 * null reach it as false, which it documents; null would otherwise be wrapped
	 * into a list with an empty ID. What the ESP says about a contact with no lists
	 * is its own business, so the result is not asserted.
	 *
	 * @dataProvider requests_for_no_lists
	 *
	 * @param array|false|null $lists    Lists as passed to subscribe().
	 * @param array|false      $expected The lists upsert() should receive.
	 */
	public function test_subscribe_with_no_lists_skips_the_check_and_reaches_upsert( $lists, $expected ) {
		$filter_calls = 0;
		$count        = function ( $requested ) use ( &$filter_calls ) {
			++$filter_calls;
			return $requested;
		};
		$seen         = 'upsert() was not reached';
		$spy          = function ( $upsert_lists ) use ( &$seen ) {
			$seen = $upsert_lists;
		};
		add_filter( 'newspack_newsletters_subscribe_lists', $count );
		add_action( 'newspack_newsletters_pre_add_contact', $spy );

		Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], $lists );

		remove_action( 'newspack_newsletters_pre_add_contact', $spy );
		remove_filter( 'newspack_newsletters_subscribe_lists', $count );

		$this->assertSame( 0, $filter_calls, 'There is nothing to check.' );
		$this->assertSame( $expected, $seen, 'upsert() is reached with the lists in the form it documents.' );
	}

	/**
	 * Create a remote list post, as sync does for a list that lives in the ESP.
	 *
	 * @param string $remote_id The list's ID in the ESP.
	 *
	 * @return int The list's post ID.
	 */
	private function create_remote_list_post( $remote_id ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => 'Canonical ID test list',
				'post_type'   => Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);
		$list    = new Subscription_List( $post_id );
		$list->set_remote_id( $remote_id );
		$list->set_type( 'remote' );
		$list->set_provider( 'mailchimp' );
		return $post_id;
	}

	/**
	 * Remove a list post made by a test.
	 *
	 * @param int $post_id The list's post ID.
	 */
	private function delete_list_post( $post_id ) {
		wp_delete_post( $post_id, true );
		Subscription_Lists::flush_cache();
	}

	/**
	 * Get the list IDs the sign-up filter receives for a request. The callback
	 * refuses the sign-up, so nothing reaches the ESP.
	 *
	 * @param mixed $lists Lists as passed to subscribe().
	 *
	 * @return string[]|null
	 */
	private function get_lists_seen_by_the_filter( $lists ) {
		$seen = null;
		$spy  = function ( $requested ) use ( &$seen ) {
			$seen = $requested;
			return [];
		};
		add_filter( 'newspack_newsletters_subscribe_lists', $spy );
		Newspack_Newsletters_Contacts::subscribe( [ 'email' => 'test@example.com' ], $lists );
		remove_filter( 'newspack_newsletters_subscribe_lists', $spy );
		return $seen;
	}

	/**
	 * A remote list asked for under a spelling that still resolves to it reaches the
	 * filter as its own public ID, the string a callback compares against. upsert()
	 * resolves the same spelling to the same list.
	 */
	public function test_subscribe_filter_sees_a_remote_list_under_its_public_id() {
		$post_id = $this->create_remote_list_post( 'canonical-remote-id' );

		$seen = $this->get_lists_seen_by_the_filter( ' canonical-remote-id' );

		$this->delete_list_post( $post_id );

		$this->assertSame( [ 'canonical-remote-id' ], $seen );
	}

	/**
	 * Two spellings of one list count once, so a callback can't be shown a list
	 * under one spelling and have the other slip past it.
	 */
	public function test_subscribe_filter_sees_one_list_once_under_two_spellings() {
		$post_id = $this->create_remote_list_post( 'canonical-remote-id' );

		$seen = $this->get_lists_seen_by_the_filter( [ 'canonical-remote-id', ' canonical-remote-id' ] );

		$this->delete_list_post( $post_id );

		$this->assertSame( [ 'canonical-remote-id' ], $seen );
	}

	/**
	 * A local list is known by `newspack-<post ID>`. Padding the number with zeros,
	 * or ending the ID with a newline, still resolves to the list, so the filter
	 * is shown the plain form.
	 */
	public function test_subscribe_filter_sees_a_local_list_under_its_public_id() {
		$post_id = wp_insert_post(
			[
				'post_title'  => 'Local canonical ID test list',
				'post_type'   => Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);
		$public_id = 'newspack-' . $post_id;

		$padded  = $this->get_lists_seen_by_the_filter( 'newspack-0' . $post_id );
		$newline = $this->get_lists_seen_by_the_filter( $public_id . "\n" );

		$this->delete_list_post( $post_id );

		$this->assertSame( [ $public_id ], $padded );
		$this->assertSame( [ $public_id ], $newline );
	}

	/**
	 * A remote list with no ID stored has no public ID to swap in, so the ID the
	 * caller gave reaches the filter as it is, never as an empty string.
	 */
	public function test_subscribe_filter_sees_a_list_with_no_public_id_under_the_id_given() {
		$post_id = $this->create_remote_list_post( '' );

		$seen = $this->get_lists_seen_by_the_filter( 'newspack-' . $post_id );

		$this->delete_list_post( $post_id );

		$this->assertSame( [ 'newspack-' . $post_id ], $seen );
	}

	/**
	 * An ID that matches no list reaches the filter as given. upsert() reports it
	 * as invalid.
	 */
	public function test_subscribe_filter_sees_an_unknown_list_id_unchanged() {
		$this->assertSame( [ 'not-a-list' ], $this->get_lists_seen_by_the_filter( 'not-a-list' ) );
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
