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
		$property = new ReflectionProperty( Newspack_Newsletters::class, 'provider' );
		$property->setAccessible( true );
		return $property;
	}
}
