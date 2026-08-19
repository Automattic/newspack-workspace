<?php
/**
 * Tests Newspack_Newsletters_Subscription::get_lists().
 *
 * @package Newspack_Newsletters
 */

/**
 * The return value of get_lists() is served verbatim by the
 * `newspack-newsletters/v1/lists` REST route and mapped over by the Newsletters
 * settings and Audience configuration screens, so its shape is a contract: a
 * JSON array of list objects, with no holes and no nulls.
 */
class Subscription_Get_Lists_Test extends WP_UnitTestCase {

	/**
	 * Provider slug the stubbed lists are stored under.
	 */
	const PROVIDER = 'active_campaign';

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
	 * Whether the incomplete-payload filter is currently installed.
	 *
	 * @var bool
	 */
	private $incomplete_filter_added = false;

	/**
	 * Point the plugin at the provider slug and start from a cold list cache.
	 *
	 * The option is written directly rather than through set_service_provider(),
	 * which also memoizes a provider instance in a static property. Static state
	 * survives the per-test transaction, so touching it here would displace the
	 * value stub_provider_lists() records as the original. The option itself
	 * needs no restoring: WP_UnitTestCase_Base opens a transaction in set_up()
	 * and rolls it back in tear_down().
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'newspack_newsletters_service_provider', self::PROVIDER );
		$this->clear_lists_cache();
	}

	/**
	 * Restore the real provider instance and leave no cached lists behind.
	 */
	public function tear_down() {
		if ( $this->provider_replaced ) {
			$this->provider_property()->setValue( null, $this->original_provider );
			$this->provider_replaced = false;
		}
		if ( $this->incomplete_filter_added ) {
			remove_filter( 'newspack_newsletters_lists_are_complete', '__return_false' );
			$this->incomplete_filter_added = false;
		}
		$this->clear_lists_cache();
		parent::tear_down();
	}

	/**
	 * An ESP payload where a list in the middle carries an empty name, as
	 * ActiveCampaign returns for an unnamed list.
	 *
	 * @return array[]
	 */
	private function lists_with_an_unnamed_list_in_the_middle() {
		return [
			[
				'id'               => '9001',
				'name'             => 'Weekly digest',
				'subscriber_count' => 12,
			],
			[
				'id'               => '9002',
				'name'             => '',
				'subscriber_count' => 0,
			],
			[
				'id'               => '9003',
				'name'             => 'Breaking news',
				'subscriber_count' => 34,
			],
		];
	}

	/**
	 * An unnamed list is dropped rather than passed through as a null entry.
	 * The admin screens map over this payload and read `list.name` directly, so
	 * a null in the array unmounts the whole settings page.
	 */
	public function test_unnamed_remote_list_is_dropped() {
		$this->stub_provider_lists( $this->lists_with_an_unnamed_list_in_the_middle() );

		$result = Newspack_Newsletters_Subscription::get_lists();

		$this->assertNotWPError( $result );
		$this->assertNotContains( null, $result, 'A list the ESP left unnamed must not survive as a null entry.' );

		$ids = wp_list_pluck( $result, 'id' );
		$this->assertContains( '9001', $ids );
		$this->assertContains( '9003', $ids );
		$this->assertNotContains( '9002', $ids, 'The unnamed list must not be returned.' );
	}

	/**
	 * Dropping a list must not leave a hole in the array's keys: a sparse PHP
	 * array is serialized as a JSON object, and the admin screens call .map()
	 * on the response.
	 */
	public function test_result_is_still_a_json_array_after_a_list_is_dropped() {
		$this->stub_provider_lists( $this->lists_with_an_unnamed_list_in_the_middle() );

		$result = Newspack_Newsletters_Subscription::get_lists();

		$this->assertNotWPError( $result );
		$this->assertSame(
			range( 0, count( $result ) - 1 ),
			array_keys( $result ),
			'Keys must stay sequential so the REST response is a JSON array.'
		);
		$this->assertStringStartsWith( '[', (string) wp_json_encode( $result ) );
	}

	/**
	 * A whitespace-only name must be dropped like an empty one. It is not
	 * caught by `empty()`, so it would otherwise reach
	 * Subscription_Lists::get_or_create_remote_list(), which throws rather
	 * than returning a WP_Error — and the outer catch turns that into a
	 * WP_Error for the whole payload, emptying the screen instead of dropping
	 * one row.
	 */
	public function test_whitespace_named_list_is_dropped_without_failing_the_payload() {
		$this->stub_provider_lists(
			[
				[
					'id'   => '9001',
					'name' => 'Weekly digest',
				],
				[
					'id'   => '9002',
					'name' => '   ',
				],
				[
					'id'   => '9003',
					'name' => 'Breaking news',
				],
			]
		);

		$result = Newspack_Newsletters_Subscription::get_lists();

		$this->assertNotWPError( $result, 'One badly-named list must not fail the whole payload.' );

		$ids = wp_list_pluck( $result, 'id' );
		$this->assertContains( '9001', $ids );
		$this->assertContains( '9003', $ids );
		$this->assertNotContains( '9002', $ids, 'The whitespace-named list must not be returned.' );
	}

	/**
	 * "0" is a legitimate list name that `empty()` rejects.
	 * Subscription_Lists::get_or_create_remote_list() goes out of its way to
	 * accept it, so this guard must not disagree.
	 */
	public function test_list_named_zero_is_kept() {
		$this->stub_provider_lists(
			[
				[
					'id'   => '9004',
					'name' => '0',
				],
			]
		);

		$result = Newspack_Newsletters_Subscription::get_lists();

		$this->assertNotWPError( $result );
		$this->assertContains( '9004', wp_list_pluck( $result, 'id' ), 'A list named "0" must survive the guard.' );
	}

	/**
	 * A provider that could only report part of its lists (a cold ESP cache, say)
	 * must not authorize deleting the ones it could not see. The garbage
	 * collector deletes every stored remote list absent from the payload, so
	 * acting on a partial read destroys configured lists.
	 */
	public function test_incomplete_payload_does_not_garbage_collect_stored_lists() {
		$stored = $this->create_remote_list( '9999' );

		$this->stub_provider_lists(
			[
				[
					'id'   => '9001',
					'name' => 'Weekly digest',
				],
			]
		);
		$this->mark_lists_incomplete();

		$result = Newspack_Newsletters_Subscription::get_lists();

		$this->assertNotWPError( $result );
		$this->assertSame(
			'publish',
			get_post_status( $stored ),
			'A list missing from an incomplete payload must survive.'
		);
	}

	/**
	 * An incomplete payload must not be cached either, or the screen keeps
	 * serving the partial set for the whole TTL even once the ESP data lands.
	 */
	public function test_incomplete_payload_is_not_cached() {
		$this->stub_provider_lists(
			[
				[
					'id'   => '9001',
					'name' => 'Weekly digest',
				],
			]
		);
		$this->mark_lists_incomplete();

		Newspack_Newsletters_Subscription::get_lists();

		$this->assertFalse(
			get_transient( Newspack_Newsletters_Subscription::LISTS_CACHE_PREFIX . self::PROVIDER ),
			'An incomplete payload must not be cached.'
		);
	}

	/**
	 * The guard must stay out of the way of a complete read: it still caches,
	 * and it still cleans up lists the ESP no longer has.
	 */
	public function test_complete_payload_still_caches_and_garbage_collects() {
		$stored = $this->create_remote_list( '9999' );

		$this->stub_provider_lists(
			[
				[
					'id'   => '9001',
					'name' => 'Weekly digest',
				],
			]
		);

		$result = Newspack_Newsletters_Subscription::get_lists();

		$this->assertNotWPError( $result );
		$this->assertNotFalse(
			get_transient( Newspack_Newsletters_Subscription::LISTS_CACHE_PREFIX . self::PROVIDER ),
			'A complete payload must still be cached.'
		);
		$this->assertNotSame(
			'publish',
			get_post_status( $stored ),
			'A complete payload must still garbage collect lists the ESP dropped.'
		);
	}

	/**
	 * Declare the next read partial, the way a provider does when its own cache
	 * could not serve every list.
	 *
	 * @return void
	 */
	private function mark_lists_incomplete() {
		add_filter( 'newspack_newsletters_lists_are_complete', '__return_false' );
		$this->incomplete_filter_added = true;
	}

	/**
	 * Store a remote list for the provider under test.
	 *
	 * @param string $remote_id The list ID in the ESP.
	 * @return int The Subscription_List post ID.
	 */
	private function create_remote_list( $remote_id ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => 'Stored list ' . $remote_id,
				'post_type'   => \Newspack\Newsletters\Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);
		$list    = new \Newspack\Newsletters\Subscription_List( $post_id );
		$list->set_remote_id( $remote_id );
		$list->set_type( 'remote' );
		$list->set_provider( self::PROVIDER );
		return $post_id;
	}

	/**
	 * Install a provider double whose get_lists() returns a fixed payload.
	 *
	 * @param array[] $lists Lists the stubbed ESP should return.
	 * @return void
	 */
	private function stub_provider_lists( array $lists ) {
		$provider = $this->getMockBuilder( Newspack_Newsletters_Service_Provider::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'get_lists' ] )
			->getMockForAbstractClass();
		$provider->method( 'get_lists' )->willReturn( $lists );
		// Subscription_Lists::garbage_collector() reads the slug off the provider
		// to decide which stored lists are in scope; left unset it matches none,
		// and the collector silently becomes a no-op.
		$provider->service = self::PROVIDER;

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

	/**
	 * Drop the cached list payload for the provider under test.
	 *
	 * @return void
	 */
	private function clear_lists_cache() {
		delete_transient( Newspack_Newsletters_Subscription::LISTS_CACHE_PREFIX . self::PROVIDER );
	}
}
