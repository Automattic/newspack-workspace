<?php
/**
 * Class TestComplimentaryAccessSync
 *
 * @package Newspack_Network
 */

use Newspack_Network\Woocommerce\Events;
use Newspack_Network\Incoming_Events\Order_Changed;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Hub\Admin\Woo as Woo_Admin;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;
use Newspack_Network\Hub\Stores\Orders;
use Newspack_Network\Hub\Stores\Subscriptions;

require_once dirname( __DIR__ ) . '/mocks/class-complimentary-access.php';
require_once dirname( __DIR__ ) . '/mocks/wc-order-functions.php';
require_once dirname( __DIR__ ) . '/mocks/wcs-functions.php';
require_once dirname( __DIR__ ) . '/mocks/class-newspack-network-test-comp-order.php';

/**
 * How complimentary access reaches the hub and its lists.
 */
class TestComplimentaryAccessSync extends WP_UnitTestCase {

	/**
	 * Whether the hub list hooks were registered before a test ran.
	 *
	 * @var bool
	 */
	private $hooks_added_before;

	/**
	 * Note the hub list's static hook flag, which a test may set.
	 */
	public function set_up() {
		parent::set_up();
		$this->hooks_added_before = $this->hooks_added_property()->getValue();
	}

	/**
	 * Clear the registered stand-ins and the list-screen state a test set.
	 */
	public function tear_down() {
		unset( $GLOBALS['newspack_network_test_orders'], $GLOBALS['newspack_network_test_group'], $GLOBALS['pagenow'], $_GET['post_type'], $_GET[ Woo_Admin::COMPLIMENTARY_FILTER_PARAM ] );
		$this->hooks_added_property()->setValue( null, $this->hooks_added_before );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The hub list's flag for its shared hooks, which outlives a test.
	 *
	 * @return ReflectionProperty
	 */
	private function hooks_added_property() {
		$hooks_added_property = new ReflectionProperty( Woo_Admin::class, 'hooks_added' );
		$hooks_added_property->setAccessible( true );
		return $hooks_added_property;
	}

	/**
	 * The payload says whether the subscription or order carries the stamp.
	 */
	public function test_payload_carries_the_complimentary_stamp() {
		$comp = new Newspack_Network_Test_Comp_Order( 42, 'active', true );
		$paid = new Newspack_Network_Test_Comp_Order( 43, 'active' );

		$this->assertTrue( Events::subscription_changed( 42, 'pending', 'active', $comp )['is_complimentary'] );
		$this->assertFalse( Events::item_changed( 43, 'pending', 'completed', $paid )['is_complimentary'] );
	}

	/**
	 * A restamp changes no status, so it resends each subscription and its orders as they
	 * stand. A subscription or order since deleted is skipped.
	 */
	public function test_restamp_resends_subscriptions_and_their_orders() {
		$GLOBALS['newspack_network_test_group']['subscriptions'][42] = new Newspack_Network_Test_Comp_Order( 42, 'active', true, [ 50, 51 ] );
		$GLOBALS['newspack_network_test_orders'][50]                 = new Newspack_Network_Test_Comp_Order( 50, 'completed', true );

		$events = Events::get_restamp_events( [ 42, 99 ] );

		$this->assertSame( [ 'newspack_node_subscription_changed', 'newspack_node_order_changed' ], array_column( $events, 'action' ) );
		$this->assertSame( [ 42, 50 ], array_column( array_column( $events, 'data' ), 'id' ) );
		foreach ( $events as $event ) {
			$this->assertSame( $event['data']['status_before'], $event['data']['status_after'] );
			$this->assertTrue( $event['data']['is_complimentary'] );
		}
	}

	/**
	 * A subscription event from a node.
	 *
	 * @param int  $id      Remote subscription ID.
	 * @param bool $is_comp Whether the node sent it as complimentary.
	 * @return Subscription_Changed
	 */
	private function subscription_event( $id, $is_comp ) {
		$data = [
			'id'               => $id,
			'email'            => 'comp-reader@example.test',
			'status_after'     => 'active',
			'is_complimentary' => $is_comp,
		];
		return new Subscription_Changed( 'https://node.example.test', $data, time() );
	}

	/**
	 * The hub stores the marker on subscriptions and orders, and follows it when a later
	 * event clears it.
	 */
	public function test_hub_stores_the_marker() {
		$comp = Subscriptions::persist( $this->subscription_event( 1, true ) );
		$this->assertSame( '1', get_post_meta( $comp, 'is_complimentary', true ) );

		Subscriptions::persist( $this->subscription_event( 1, false ) );
		$this->assertSame( '0', get_post_meta( $comp, 'is_complimentary', true ) );

		$order = Orders::persist(
			new Order_Changed(
				'https://node.example.test',
				[
					'id'               => 7,
					'email'            => 'comp-reader@example.test',
					'status_after'     => 'completed',
					'is_complimentary' => true,
				],
				time()
			)
		);
		$this->assertSame( '1', get_post_meta( $order, 'is_complimentary', true ) );
	}

	/**
	 * The list's Complimentary filter, run through the list screen's own query hooks. It
	 * narrows a search rather than widening it: the search is its own OR group, ANDed with
	 * the filter. A row stored before the hub kept the marker counts as not complimentary.
	 */
	public function test_list_filter() {
		// All three match the search: the events share the reader's email.
		$comp   = Subscriptions::persist( $this->subscription_event( 1, true ) );
		$paid   = Subscriptions::persist( $this->subscription_event( 2, false ) );
		$legacy = Subscriptions::persist( $this->subscription_event( 3, false ) );
		delete_post_meta( $legacy, 'is_complimentary' );

		\Newspack_Network\Hub\Admin\Subscriptions::init();
		add_action( 'parse_query', [ \Newspack_Network\Hub\Admin\Subscriptions::class, 'parse_query' ] );
		add_action( 'pre_get_posts', [ Woo_Admin::class, 'pre_get_posts' ] );
		set_current_screen( 'edit-' . Subscriptions_DB::POST_TYPE_SLUG );
		$GLOBALS['pagenow'] = 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_GET['post_type']  = Subscriptions_DB::POST_TYPE_SLUG;

		$list_ids = function ( $mode ) {
			$_GET[ Woo_Admin::COMPLIMENTARY_FILTER_PARAM ] = $mode;
			$list_query                                    = new WP_Query();
			$GLOBALS['wp_the_query']                       = $list_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return $list_query->query(
				[
					'post_type'   => Subscriptions_DB::POST_TYPE_SLUG,
					'post_status' => 'any',
					's'           => 'comp-reader',
					'fields'      => 'ids',
					'orderby'     => 'ID',
					'order'       => 'ASC',
				]
			);
		};

		$this->assertSame( [ $comp, $paid, $legacy ], $list_ids( 'all' ) );
		$this->assertSame( [ $comp ], $list_ids( 'only' ) );
		$this->assertSame( [ $paid, $legacy ], $list_ids( 'exclude' ) );
	}

	/**
	 * A restamp resend changes no status, so it must not create a reader account on the
	 * other sites: a reader with no account there has had it deleted, or never earned one
	 * through a real status change.
	 */
	public function test_restamp_resend_creates_no_account_on_other_sites() {
		$resend = new Subscription_Changed(
			'https://node.example.test',
			[
				'id'               => 42,
				'email'            => 'resent-reader@example.test',
				'status_before'    => 'active',
				'status_after'     => 'active',
				'is_complimentary' => true,
			],
			time()
		);
		$resend->process_in_node();

		$this->assertFalse( get_user_by( 'email', 'resent-reader@example.test' ) );
	}
}
