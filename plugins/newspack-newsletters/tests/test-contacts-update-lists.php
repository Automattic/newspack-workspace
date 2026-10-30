<?php
/**
 * Tests Newspack_Newsletters_Contacts::update_lists().
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;

/**
 * Lists an existing contact selects are narrowed by the
 * `newspack_newsletters_contact_lists` filter, as a new contact's are, and the
 * lists they leave are not.
 */
class Contacts_Update_Lists_Test extends WP_UnitTestCase {

	/**
	 * Provider slug the test lists are configured for.
	 */
	const PROVIDER = 'active_campaign';

	/**
	 * Contact whose lists are updated.
	 */
	const EMAIL = 'reader@example.test';

	/**
	 * Provider instance displaced by the test double, restored on tear down.
	 *
	 * @var mixed
	 */
	private $original_provider = null;

	/**
	 * Whether a test double is currently installed.
	 *
	 * @var bool
	 */
	private $provider_replaced = false;

	/**
	 * Lists the provider double reports the contact as already on.
	 *
	 * @var string[]
	 */
	private $current_lists = [];

	/**
	 * Lists each write to the provider double added and removed, in order.
	 *
	 * @var array[]
	 */
	private $writes = [];

	/**
	 * Arguments each run of the test's list filter received, in order.
	 *
	 * @var array[]
	 */
	private $filter_calls = [];

	/**
	 * Create two active lists for the provider and install the provider double.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'newspack_newsletters_service_provider', self::PROVIDER );
		$this->create_remote_list( 'open-list' );
		$this->create_remote_list( 'restricted-list' );
		$this->install_provider_double();
		Newspack_Newsletters_Subscription::reset_lists_config_cache();
	}

	/**
	 * Restore the real provider instance.
	 */
	public function tear_down() {
		if ( $this->provider_replaced ) {
			$this->provider_property()->setValue( null, $this->original_provider );
			$this->provider_replaced = false;
		}
		Newspack_Newsletters_Subscription::reset_lists_config_cache();
		parent::tear_down();
	}

	/**
	 * A list the filter drops is not added, while the rest of the selection is,
	 * and the filter receives the contact and provider as it does for upsert().
	 */
	public function test_additions_are_narrowed_by_the_contact_lists_filter() {
		$this->add_list_filter(
			function ( $lists ) {
				return array_values( array_diff( $lists, [ 'restricted-list' ] ) );
			}
		);

		$result = Newspack_Newsletters_Contacts::update_lists( self::EMAIL, [ 'open-list', 'restricted-list' ] );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( [ 'open-list' ], $this->writes[0]['add'] );
		$this->assertSame( [], $this->writes[0]['remove'] );

		$this->assertCount( 1, $this->filter_calls );
		$this->assertEqualsCanonicalizing( [ 'open-list', 'restricted-list' ], $this->filter_calls[0]['lists'] );
		$this->assertSame( [ 'email' => self::EMAIL ], $this->filter_calls[0]['contact'] );
		$this->assertSame( self::PROVIDER, $this->filter_calls[0]['provider'] );
	}

	/**
	 * A callback that appends a list cannot add one the contact did not select.
	 */
	public function test_filter_cannot_add_an_unselected_list() {
		$this->add_list_filter(
			function ( $lists ) {
				$lists[] = 'restricted-list';
				return $lists;
			}
		);

		Newspack_Newsletters_Contacts::update_lists( self::EMAIL, [ 'open-list' ] );

		$this->assertCount( 1, $this->writes );
		$this->assertSame( [ 'open-list' ], $this->writes[0]['add'] );
	}

	/**
	 * Leaving lists never runs the filter, so a contact can leave a list even
	 * when the filter would refuse every list to them.
	 */
	public function test_removals_are_not_filtered() {
		$this->current_lists = [ 'open-list', 'restricted-list' ];
		$this->add_list_filter(
			function () {
				return [];
			}
		);

		$result = Newspack_Newsletters_Contacts::update_lists( self::EMAIL, [] );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( [], $this->writes[0]['add'] );
		$this->assertEqualsCanonicalizing( [ 'open-list', 'restricted-list' ], $this->writes[0]['remove'] );
		$this->assertSame( [], $this->filter_calls );
	}

	/**
	 * When the filter drops every addition and nothing is left to remove,
	 * nothing is written and the method reports no change.
	 */
	public function test_nothing_is_written_when_every_addition_is_dropped() {
		$this->add_list_filter(
			function ( $lists ) {
				return array_values( array_diff( $lists, [ 'restricted-list' ] ) );
			}
		);

		$result = Newspack_Newsletters_Contacts::update_lists( self::EMAIL, [ 'restricted-list' ] );

		$this->assertFalse( $result );
		$this->assertSame( [], $this->writes );
	}

	/**
	 * A removal still goes through when the filter drops every addition beside it.
	 */
	public function test_removal_is_written_when_the_addition_beside_it_is_dropped() {
		$this->current_lists = [ 'open-list' ];
		$this->add_list_filter(
			function ( $lists ) {
				return array_values( array_diff( $lists, [ 'restricted-list' ] ) );
			}
		);

		$result = Newspack_Newsletters_Contacts::update_lists( self::EMAIL, [ 'restricted-list' ] );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( [], $this->writes[0]['add'] );
		$this->assertSame( [ 'open-list' ], $this->writes[0]['remove'] );
	}

	/**
	 * A numeric list ID is kept when the filter returns it as a string, as
	 * callbacks reading the selection from a request do.
	 */
	public function test_numeric_list_id_is_kept_when_returned_as_a_string() {
		$this->create_remote_list( '1234' );
		Newspack_Newsletters_Subscription::reset_lists_config_cache();
		$this->add_list_filter(
			function () {
				return [ '1234' ];
			}
		);

		Newspack_Newsletters_Contacts::update_lists( self::EMAIL, [ '1234', 'restricted-list' ] );

		$this->assertCount( 1, $this->writes );
		$this->assertEquals( [ '1234' ], $this->writes[0]['add'] );
	}

	/**
	 * The helper callers use to report additions returns what update_lists()
	 * writes, so a report never names a list the filter dropped.
	 */
	public function test_filter_lists_to_add_returns_what_is_written() {
		$this->add_list_filter(
			function ( $lists ) {
				return array_values( array_diff( $lists, [ 'restricted-list' ] ) );
			}
		);

		$reported = Newspack_Newsletters_Contacts::filter_lists_to_add( [ 'open-list', 'restricted-list' ], self::EMAIL );
		Newspack_Newsletters_Contacts::update_lists( self::EMAIL, [ 'open-list', 'restricted-list' ] );

		$this->assertSame( [ 'open-list' ], array_values( $reported ) );
		$this->assertSame( $this->writes[0]['add'], array_values( $reported ) );
	}

	/**
	 * Saving the My Account form reports only the additions that were made, so
	 * the confirmation never names a list the filter dropped.
	 *
	 * The save runs the real handler against this suite's provider double. The
	 * request, the signed-in user and the redirect are set up here and undone in
	 * `finally`, and the redirect is caught before the handler's `exit`.
	 *
	 * @throws Exception Anything the handler throws other than the redirect.
	 */
	public function test_my_account_save_reports_only_lists_added() {
		if ( class_exists( 'Newspack\My_Account' ) || function_exists( 'wc_add_notice' ) || class_exists( 'Newspack\Newspack_UI' ) ) {
			$this->markTestSkipped( 'Another plugin is loaded that would scope the save to a real account page or store its notice.' );
		}
		$this->create_remote_list( 'other-list' );
		Newspack_Newsletters_Subscription::reset_lists_config_cache();
		$this->current_lists = [ 'open-list' ];
		$this->add_list_filter(
			function ( $lists ) {
				return array_values( array_diff( $lists, [ 'restricted-list' ] ) );
			}
		);

		$user_id = self::factory()->user->create( [ 'user_email' => self::EMAIL ] );
		update_user_meta( $user_id, Newspack_Newsletters_Subscription::EMAIL_VERIFIED_META, [ self::EMAIL ] );

		$saved_post    = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$saved_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved_user    = get_current_user_id();
		$saved_referer = $_SERVER['HTTP_REFERER'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saved to restore verbatim.
		$interrupt     = new class() extends Exception {
			/**
			 * The location the handler redirected to.
			 *
			 * @var string
			 */
			public $location = '';
		};
		$on_redirect   = function ( $location ) use ( $interrupt ) {
			$interrupt->location = $location;
			throw $interrupt;
		};
		$caught        = null;

		try {
			wp_set_current_user( $user_id );
			$_POST = [
				Newspack_Newsletters_Subscription::SUBSCRIPTION_UPDATE => wp_create_nonce( Newspack_Newsletters_Subscription::SUBSCRIPTION_UPDATE ),
				'lists' => [ 'open-list', 'other-list', 'restricted-list' ],
			];
			$_REQUEST                = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test request setup.
			$_SERVER['HTTP_REFERER'] = home_url( '/my-account/newsletters/' );
			add_filter( 'wp_redirect', $on_redirect );

			try {
				Newspack_Newsletters_Subscription::process_subscription_update();
			} catch ( Exception $e ) {
				if ( $e !== $interrupt ) {
					throw $e;
				}
				$caught = $e;
			}
		} finally {
			remove_filter( 'wp_redirect', $on_redirect );
			$_POST    = $saved_post;
			$_REQUEST = $saved_request;
			if ( null === $saved_referer ) {
				unset( $_SERVER['HTTP_REFERER'] );
			} else {
				$_SERVER['HTTP_REFERER'] = $saved_referer;
			}
			wp_set_current_user( $saved_user );
		}

		$this->assertNotNull( $caught, 'The save should redirect after adding a list.' );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( [ 'other-list' ], $this->writes[0]['add'] );
		$query = [];
		wp_parse_str( (string) wp_parse_url( $caught->location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'other-list', $query[ Newspack_Newsletters_Subscription::SUBSCRIPTION_UPDATE . '_subscribed' ] ?? null );
	}

	/**
	 * Register a `newspack_newsletters_contact_lists` callback that records its
	 * arguments before delegating to $callback. WP_UnitTestCase restores hooks
	 * on tear down, so the callback does not outlive the test.
	 *
	 * @param callable $callback Receives the lists and returns the filtered lists.
	 * @return void
	 */
	private function add_list_filter( callable $callback ) {
		add_filter(
			'newspack_newsletters_contact_lists',
			function ( $lists, $contact = null, $provider = null ) use ( $callback ) {
				$this->filter_calls[] = [
					'lists'    => $lists,
					'contact'  => $contact,
					'provider' => $provider,
				];
				return $callback( $lists );
			},
			10,
			3
		);
	}

	/**
	 * Create an active remote list for the test provider.
	 *
	 * @param string $remote_id The list's ID in the ESP, which is also its public ID.
	 * @return void
	 */
	private function create_remote_list( $remote_id ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => $remote_id,
				'post_type'   => Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);

		$list = new Subscription_List( $post_id );
		$list->set_remote_id( $remote_id );
		$list->set_type( 'remote' );
		$list->set_provider( self::PROVIDER );
	}

	/**
	 * Install a provider double that reports $this->current_lists as the
	 * contact's lists and records each list write instead of calling an ESP.
	 *
	 * @return void
	 */
	private function install_provider_double() {
		$provider = $this->getMockBuilder( Newspack_Newsletters_Service_Provider::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_contact_combined_lists', 'update_contact_lists_handling_local' ] )
			->getMockForAbstractClass();

		$provider->service = self::PROVIDER;
		$provider->method( 'get_contact_combined_lists' )->willReturnCallback(
			function () {
				return $this->current_lists;
			}
		);
		$provider->method( 'update_contact_lists_handling_local' )->willReturnCallback(
			function ( $email, $lists_to_add = [], $lists_to_remove = [] ) {
				$this->writes[] = [
					'email'  => $email,
					'add'    => array_values( $lists_to_add ),
					'remove' => array_values( $lists_to_remove ),
				];
				return true;
			}
		);

		// Newspack_Newsletters memoizes the provider in a protected static and
		// only ever fills it from a registered slug, which would build a real
		// ESP client. Reflection is the only seam for handing it a double.
		$property                = $this->provider_property();
		$this->original_provider = $property->getValue();
		$property->setValue( null, $provider );
		$this->provider_replaced = true;
	}

	/**
	 * Accessor for the memoized provider property.
	 *
	 * @return ReflectionProperty
	 */
	private function provider_property() {
		return new ReflectionProperty( Newspack_Newsletters::class, 'provider' );
	}
}
