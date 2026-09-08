<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for the ActiveCampaign contact-lists and local-lists reads.
 *
 * Background: ActiveCampaign reads a contact's lists in a second request,
 * `contacts/<id>/contactLists`, after the contact lookup. Answering that
 * request's failure with an empty array made "could not read the lists" look
 * like "on no lists" to every caller, and the login refresh in newspack-plugin
 * then stored an empty selection and synced it to the ESP. The read now
 * reports its own failure as a WP_Error. A contact that cannot be read still
 * answers an empty array: the login refresh tells that case apart with a
 * contact read of its own, and the subscribe paths rely on it to treat a new
 * reader as a contact on no lists.
 *
 * The local lists (tags) are read the same way, in a `contacts/<id>/contactTags`
 * request, and that read's failure is reported as well: answered with an empty
 * array, it dropped the reader's local lists from the combined read the login
 * refresh stores and syncs. A contact that does not exist has no local lists,
 * for the reason above.
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;

/**
 * Test the ActiveCampaign contact-lists and local-lists reads.
 */
class ActiveCampaignContactListsTest extends WP_UnitTestCase {

	/**
	 * Email of the mocked contact.
	 *
	 * @var string
	 */
	const CONTACT_EMAIL = 'listed@example.com';

	/**
	 * Canned result for the contactLists request: a decoded body array
	 * (returned as a 200), an int HTTP status (returned with an empty body), or
	 * a WP_Error (returned as a transport failure).
	 *
	 * @var array|int|WP_Error
	 */
	private $contact_lists_response;

	/**
	 * Canned result for the contactTags request, in the same shapes as
	 * $contact_lists_response.
	 *
	 * @var array|int|WP_Error
	 */
	private $contact_tags_response;

	/**
	 * Whether the contact lookup resolves the contact.
	 *
	 * @var bool
	 */
	private $contact_found = true;

	/**
	 * Set up: select the provider, configure credentials and intercept all
	 * outbound HTTP.
	 */
	public function set_up() {
		parent::set_up();
		$this->contact_found          = true;
		$this->contact_lists_response = [ 'contactLists' => [] ];
		$this->contact_tags_response  = [ 'contactTags' => [] ];
		Newspack_Newsletters::set_service_provider( 'active_campaign' );
		Newspack_Newsletters_Active_Campaign::instance()->set_api_credentials(
			[
				'url' => 'https://example.api-us1.com',
				'key' => 'test-key',
			]
		);
		Newspack_Newsletters_Active_Campaign::instance()->clear_contact_data( self::CONTACT_EMAIL );
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		// The provider caches contact lookups per email on the (singleton)
		// instance, so drop the entry rather than leak it across tests.
		Newspack_Newsletters_Active_Campaign::instance()->clear_contact_data( self::CONTACT_EMAIL );
		parent::tear_down();
	}

	/**
	 * Intercept outbound requests and play an AC account with one contact.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    HTTP request arguments.
	 * @param string $url     Request URL.
	 *
	 * @return array|WP_Error
	 */
	public function mock_http( $preempt, $args, $url ) {
		$respond = function ( $body, $code = 200 ) {
			return [
				'response' => [
					'code'    => $code,
					'message' => 200 === $code ? 'OK' : 'Internal Server Error',
				],
				'body'     => wp_json_encode( $body ),
			];
		};

		$canned = function ( $response ) use ( $respond ) {
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( is_int( $response ) ) {
				return $respond( [], $response );
			}
			return $respond( $response );
		};

		if ( false !== strpos( $url, '/api/3/contacts/101/contactLists' ) ) {
			return $canned( $this->contact_lists_response );
		}
		// The local-lists read that follows the lists read.
		if ( false !== strpos( $url, '/api/3/contacts/101/contactTags' ) ) {
			return $canned( $this->contact_tags_response );
		}
		// The search by email resolving the contact id.
		if ( false !== strpos( $url, '/api/3/contacts' ) ) {
			return $respond(
				[
					'contacts' => $this->contact_found ? [
						[
							'id'    => '101',
							'email' => self::CONTACT_EMAIL,
						],
					] : [],
				]
			);
		}
		return $respond( [] );
	}

	/**
	 * The lists a contact is subscribed to are the ones the ESP marks active.
	 */
	public function test_returns_the_lists_the_contact_is_subscribed_to() {
		$this->contact_lists_response = [
			'contactLists' => [
				[
					'list'   => '3',
					'status' => '1',
				],
				[
					'list'   => '4',
					'status' => '2',
				],
				[
					'list'   => '5',
					'status' => 1,
				],
			],
		];

		$this->assertSame( [ '3', '5' ], Newspack_Newsletters_Active_Campaign::instance()->get_contact_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * A contactLists request that fails in transport is an error, not a
	 * contact on no lists.
	 */
	public function test_a_failed_contact_lists_request_is_an_error() {
		$this->contact_lists_response = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$this->assertWPError( Newspack_Newsletters_Active_Campaign::instance()->get_contact_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * A contactLists request the ESP rejects is an error too.
	 */
	public function test_a_rejected_contact_lists_request_is_an_error() {
		$this->contact_lists_response = 500;

		$this->assertWPError( Newspack_Newsletters_Active_Campaign::instance()->get_contact_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * A response without the lists in it says nothing about the contact's
	 * lists, so it is an error rather than an empty set.
	 */
	public function test_a_malformed_contact_lists_response_is_an_error() {
		$this->contact_lists_response = [ 'unexpected' => true ];

		$this->assertWPError( Newspack_Newsletters_Active_Campaign::instance()->get_contact_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * A contact that cannot be read keeps answering an empty array, like the
	 * other providers: the login refresh tells that case apart with its own
	 * contact read, and the subscribe paths treat a new reader as a contact on
	 * no lists.
	 */
	public function test_an_unreadable_contact_still_answers_an_empty_array() {
		$this->contact_found = false;

		$this->assertSame( [], Newspack_Newsletters_Active_Campaign::instance()->get_contact_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * Create a local list configured for ActiveCampaign under the given tag.
	 *
	 * @param int $tag_id The ActiveCampaign tag ID.
	 *
	 * @return string The list's public ID.
	 */
	private function create_local_list( $tag_id ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => 'Local list ' . $tag_id,
				'post_type'   => Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta(
			$post_id,
			Subscription_List::META_KEY,
			[
				'active_campaign' => [
					'list'     => 'ac_list',
					'tag_id'   => $tag_id,
					'tag_name' => 'AC Tag ' . $tag_id,
				],
			]
		);
		Subscription_Lists::flush_cache();
		return ( new Subscription_List( $post_id ) )->get_public_id();
	}

	/**
	 * A local list the contact carries as a tag is part of the combined read,
	 * next to the ESP's own lists.
	 */
	public function test_the_local_lists_the_contact_carries_are_part_of_its_combined_lists() {
		$public_id                    = $this->create_local_list( 13 );
		$this->contact_lists_response = [
			'contactLists' => [
				[
					'list'   => '3',
					'status' => '1',
				],
			],
		];
		$this->contact_tags_response  = [ 'contactTags' => [ [ 'tag' => '13' ] ] ];

		$this->assertSame( [ '3', $public_id ], Newspack_Newsletters_Active_Campaign::instance()->get_contact_combined_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * A contactTags request that fails in transport is an error, not a
	 * contact on no local lists.
	 */
	public function test_a_failed_contact_tags_request_is_an_error() {
		$this->contact_tags_response = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$this->assertWPError( Newspack_Newsletters_Active_Campaign::instance()->get_contact_local_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * A response without the tags in it says nothing about the contact's
	 * local lists, so it is an error rather than an empty set.
	 */
	public function test_a_malformed_contact_tags_response_is_an_error() {
		$this->contact_tags_response = [ 'unexpected' => true ];

		$this->assertWPError( Newspack_Newsletters_Active_Campaign::instance()->get_contact_local_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * A contact that does not exist has no local lists, the way it has no
	 * lists: the subscribe paths treat a new reader as a contact on no lists.
	 */
	public function test_a_missing_contact_has_no_local_lists() {
		$this->contact_found = false;

		$this->assertSame( [], Newspack_Newsletters_Active_Campaign::instance()->get_contact_local_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * The combined read is what callers store and sync, so a failed local-lists
	 * read makes it an error rather than a set with the local lists missing.
	 */
	public function test_combined_lists_report_a_failed_local_lists_read() {
		$this->create_local_list( 13 );
		$this->contact_lists_response = [
			'contactLists' => [
				[
					'list'   => '3',
					'status' => '1',
				],
			],
		];
		$this->contact_tags_response  = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$this->assertWPError( Newspack_Newsletters_Active_Campaign::instance()->get_contact_combined_lists( self::CONTACT_EMAIL ) );
	}

	/**
	 * What to add and remove is worked out against the contact's current
	 * lists, so an update over a failed read reports the failure instead of
	 * computing the change against an empty set.
	 */
	public function test_update_lists_reports_a_failed_list_read() {
		$this->contact_lists_response = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$this->assertWPError( Newspack_Newsletters_Contacts::update_lists( self::CONTACT_EMAIL, [ '3' ], 'Test' ) );
	}

	/**
	 * The lists the My Account page offers pass through a filter documented as
	 * answering an error as well, and an error there is a failed load like a
	 * failed lists read: the notice is shown and the form is not.
	 */
	public function test_my_account_treats_an_unreadable_lists_config_as_a_failed_load() {
		$user_id = self::factory()->user->create( [ 'user_email' => self::CONTACT_EMAIL ] );
		update_user_meta( $user_id, Newspack_Newsletters_Subscription::EMAIL_VERIFIED_META, [ self::CONTACT_EMAIL ] );
		wp_set_current_user( $user_id );
		$fail = function () {
			return new WP_Error( 'blocked', 'blocked' );
		};
		add_filter( 'newspack_newsletters_manage_newsletters_available_lists', $fail );

		ob_start();
		Newspack_Newsletters_Subscription::endpoint_content();
		$output = ob_get_clean();

		remove_filter( 'newspack_newsletters_manage_newsletters_available_lists', $fail );
		wp_set_current_user( 0 );

		$this->assertStringContainsString( 'could not be loaded', $output );
		$this->assertStringNotContainsString( 'newspack-newsletters__lists', $output );
	}
}
