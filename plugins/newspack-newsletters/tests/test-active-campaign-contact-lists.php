<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for the ActiveCampaign contact-lists read.
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
 * @package Newspack_Newsletters
 */

/**
 * Test the ActiveCampaign contact-lists read.
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

		if ( false !== strpos( $url, '/api/3/contacts/101/contactLists' ) ) {
			if ( is_wp_error( $this->contact_lists_response ) ) {
				return $this->contact_lists_response;
			}
			if ( is_int( $this->contact_lists_response ) ) {
				return $respond( [], $this->contact_lists_response );
			}
			return $respond( $this->contact_lists_response );
		}
		// The local-lists read that follows a successful lists read.
		if ( false !== strpos( $url, '/api/3/contacts/101/contactTags' ) ) {
			return $respond( [ 'contactTags' => [] ] );
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
