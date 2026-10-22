<?php
/**
 * Class Test_Content_Gate_Access_Records
 *
 * @package Newspack_Network
 */

use Newspack_Network\Content_Gate\Access;
use Newspack_Network\Hub\Node as Hub_Node;
use Newspack_Network\Incoming_Events\Product_Updated;
use Newspack_Network\Incoming_Events\Reader_Product_Changed;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Site_Role;
use Newspack_Network\Utils\Sites;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Test which network records satisfy a subscription rule.
 */
class Test_Content_Gate_Access_Records extends WP_UnitTestCase {

	const SITE_ONE = 'http://site1';

	const SITE_TWO = 'http://site2';

	/**
	 * Reader under test.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Local product tagged with the Network ID 'premium'.
	 *
	 * @var int
	 */
	private $local_premium;

	/**
	 * Local product with no Network ID.
	 *
	 * @var int
	 */
	private $local_no_network;

	/**
	 * A reader, two local products, the other sites' synced products, and the site names.
	 */
	public function set_up() {
		parent::set_up();

		$this->user_id          = self::factory()->user->create();
		$this->local_premium    = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$this->local_no_network = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $this->local_premium, Product_Admin::NETWORK_ID_META_KEY, 'premium' );

		update_option(
			Product_Updated::OPTION_NAME,
			[
				self::SITE_ONE => [
					100 => [ 'network_id' => 'premium' ],
					101 => [ 'network_id' => 'basic' ],
					102 => [ 'network_id' => '' ],
				],
				self::SITE_TWO => [
					200 => [ 'network_id' => 'premium' ],
				],
			],
			false
		);

		// A node whose hub is site2 and whose only other node is site1.
		update_option( Site_Role::OPTION_NAME, Site_Role::NODE_ROLE );
		update_option( 'newspack_node_hub_url', self::SITE_TWO );
		update_option( 'newspack_network_hub_name', 'Hub Gazette' );
		update_option(
			Hub_Node::HUB_NODES_SYNCED_OPTION,
			[
				[
					'id'    => 2,
					'title' => 'Node Daily',
					'url'   => self::SITE_ONE,
				],
			]
		);
		Sites::reset_cache();
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		delete_option( Product_Updated::OPTION_NAME );
		delete_option( Site_Role::OPTION_NAME );
		delete_option( 'newspack_node_hub_url' );
		delete_option( 'newspack_network_hub_name' );
		delete_option( Hub_Node::HUB_NODES_SYNCED_OPTION );
		Sites::reset_cache();
		parent::tear_down();
	}

	/**
	 * Store the subscriptions the reader owns on other sites.
	 *
	 * @param array $subscriptions Site URL => [ id => subscription ].
	 */
	private function own_subscriptions( $subscriptions ) {
		update_user_meta( $this->user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, $subscriptions );
	}

	/**
	 * A subscription as the bridge stores it.
	 *
	 * @param int    $id         Subscription ID on its own site.
	 * @param string $status     Status.
	 * @param int    $product_id Product ID on its own site.
	 * @return array
	 */
	private function subscription( $id, $status, $product_id ) {
		return [
			'id'       => $id,
			'status'   => $status,
			'products' => [ $product_id => [ 'id' => $product_id ] ],
		];
	}

	/**
	 * Store the seats the reader holds on other sites' group subscriptions.
	 *
	 * @param array $seats Site URL => [ seat, … ].
	 */
	private function hold_seats( $seats ) {
		$records = [];
		foreach ( $seats as $site => $site_seats ) {
			foreach ( $site_seats as $seat ) {
				$records[ $site ][ 'group:' . $seat['id'] ] = $seat;
			}
		}
		update_user_meta( $this->user_id, Reader_Product_Changed::USER_PRODUCTS_META_KEY, $records );
	}

	/**
	 * A seat as the bridge stores it.
	 *
	 * @param int    $id         Group subscription ID on its own site.
	 * @param string $status     Status.
	 * @param int    $product_id Product ID on its own site.
	 * @return array
	 */
	private function seat( $id, $status, $product_id ) {
		return [
			'type'     => 'group',
			'id'       => $id,
			'status'   => $status,
			'products' => [ $product_id => [ 'id' => $product_id ] ],
		];
	}

	/**
	 * An owned subscription and a seat both match, and strict mode keeps only the owned one.
	 */
	public function test_matches_owned_subscription_and_seat() {
		$this->own_subscriptions( [ self::SITE_ONE => [ 500 => $this->subscription( 500, 'active', 100 ) ] ] );
		$this->hold_seats( [ self::SITE_TWO => [ $this->seat( 900, 'active', 200 ) ] ] );

		$this->assertSame(
			[
				[
					'site' => self::SITE_ONE,
					'id'   => 500,
					'type' => 'subscription',
				],
				[
					'site' => self::SITE_TWO,
					'id'   => 900,
					'type' => 'group',
				],
			],
			Access::get_network_subscription_matches( $this->user_id, [ $this->local_premium ] )
		);

		$this->assertSame(
			[
				[
					'site' => self::SITE_ONE,
					'id'   => 500,
					'type' => 'subscription',
				],
			],
			Access::get_network_subscription_matches( $this->user_id, [ $this->local_premium ], true )
		);
	}

	/**
	 * Records come back in the order the meta lists them, subscriptions before seats.
	 */
	public function test_matches_keep_meta_order() {
		$this->own_subscriptions(
			[
				self::SITE_TWO => [ 700 => $this->subscription( 700, 'pending-cancel', 200 ) ],
				self::SITE_ONE => [
					600 => $this->subscription( 600, 'active', 100 ),
					500 => $this->subscription( 500, 'active', 100 ),
				],
			]
		);

		$this->assertSame(
			[ 700, 600, 500 ],
			wp_list_pluck( Access::get_network_subscription_matches( $this->user_id, [ $this->local_premium ] ), 'id' )
		);
	}

	/**
	 * Inactive and unmatched subscriptions and seats are left out.
	 */
	public function test_cancelled_and_unmatched_are_excluded() {
		$this->own_subscriptions(
			[
				self::SITE_TWO => [ 501 => $this->subscription( 501, 'cancelled', 200 ) ],
				self::SITE_ONE => [
					502 => $this->subscription( 502, 'active', 102 ),
					503 => $this->subscription( 503, 'active', 101 ),
					504 => $this->subscription( 504, 'on-hold', 100 ),
				],
			]
		);
		$this->hold_seats( [ self::SITE_TWO => [ $this->seat( 901, 'cancelled', 200 ) ] ] );

		$this->assertSame( [], Access::get_network_subscription_matches( $this->user_id, [ $this->local_premium ] ) );
	}

	/**
	 * A required product with no Network ID matches nothing.
	 */
	public function test_product_without_network_id_matches_nothing() {
		$this->own_subscriptions( [ self::SITE_ONE => [ 500 => $this->subscription( 500, 'active', 100 ) ] ] );

		$this->assertSame( [], Access::get_network_subscription_matches( $this->user_id, [ $this->local_no_network ] ) );
	}

	/**
	 * A rule with no products matches nothing, and the filter adds nothing.
	 */
	public function test_empty_product_ids_returns_nothing() {
		$this->own_subscriptions( [ self::SITE_ONE => [ 500 => $this->subscription( 500, 'active', 100 ) ] ] );

		$this->assertSame( [], Access::get_network_subscription_matches( $this->user_id, [] ) );
		$this->assertSame( [], apply_filters( 'newspack_access_rules_network_subscription_records', [], $this->user_id, [] ) );
	}

	/**
	 * The filter appends each match as a record with the site's name and edit link.
	 */
	public function test_records_filter_shape() {
		$this->own_subscriptions( [ self::SITE_ONE => [ 500 => $this->subscription( 500, 'active', 100 ) ] ] );
		$this->hold_seats( [ self::SITE_TWO => [ $this->seat( 900, 'active', 200 ) ] ] );

		$this->assertSame(
			[
				[
					'id'        => 500,
					'site_url'  => self::SITE_ONE,
					'site_name' => Sites::get_site_name( self::SITE_ONE ),
					'edit_url'  => 'http://site1/wp-admin/post.php?post=500&action=edit',
				],
				[
					'id'        => 900,
					'site_url'  => self::SITE_TWO,
					'site_name' => Sites::get_site_name( self::SITE_TWO ),
					'edit_url'  => 'http://site2/wp-admin/post.php?post=900&action=edit',
				],
			],
			apply_filters( 'newspack_access_rules_network_subscription_records', [], $this->user_id, [ $this->local_premium ] )
		);
		$this->assertSame( 'Node Daily', Sites::get_site_name( self::SITE_ONE ) );
		$this->assertSame( 'Hub Gazette', Sites::get_site_name( self::SITE_TWO ) );
	}

	/**
	 * Records already on the list stay first, and a reader with no match adds nothing.
	 */
	public function test_records_filter_keeps_existing_records() {
		$local = [
			'id'       => 12,
			'edit_url' => 'http://local.example.test/wp-admin/post.php?post=12&action=edit',
		];

		$this->assertSame( [ $local ], apply_filters( 'newspack_access_rules_network_subscription_records', [ $local ], $this->user_id, [ $this->local_premium ] ) );

		$this->own_subscriptions( [ self::SITE_ONE => [ 500 => $this->subscription( 500, 'active', 100 ) ] ] );

		$records = apply_filters( 'newspack_access_rules_network_subscription_records', [ $local ], $this->user_id, [ $this->local_premium ] );
		$this->assertSame( [ 12, 500 ], wp_list_pluck( $records, 'id' ) );
	}

	/**
	 * The gate check and the record list agree, strict or not.
	 */
	public function test_check_network_subscriptions_agrees_with_matches() {
		$this->hold_seats( [ self::SITE_TWO => [ $this->seat( 900, 'active', 200 ) ] ] );

		$this->assertTrue( Access::check_network_subscriptions( false, $this->user_id, [ $this->local_premium ] ) );
		$this->assertFalse( Access::check_network_subscriptions( false, $this->user_id, [ $this->local_premium ], true ) );
		$this->assertTrue( Access::check_network_subscriptions( true, $this->user_id, [ $this->local_premium ], true ) );
		$this->assertFalse( Access::check_network_subscriptions( false, $this->user_id, [] ) );
	}
}
