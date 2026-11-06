<?php
/**
 * Tests for the Subscribers wizard read endpoints (subscribers, avatars, plans).
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\Reader_Data;
use Newspack\Subscribers_Donors;
use Newspack\WooCommerce_Products;

/**
 * GET /wizard/newspack-subscribers/subscribers.
 *
 * @group WooCommerce_Subscriptions_Integration
 * @group subscribers-wizard
 */
class Test_Subscribers_Wizard_Subscribers_Endpoint extends WP_UnitTestCase {

	const ROUTE = '/newspack/v1/wizard/newspack-subscribers/subscribers';

	/**
	 * A token shared by every reader this test creates, so list queries can be
	 * scoped to just them regardless of other users in the fixture database.
	 *
	 * @var string
	 */
	private $scope_token;

	/**
	 * Track created user IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Group-product IDs by product name, so every group sold on one product shares
	 * it — the shape the product exclusion exists for.
	 *
	 * @var int[]
	 */
	private $group_product_ids = [];

	/**
	 * Callbacks this test attached to the network filters, as [ hook, callback ]
	 * pairs, so tear_down() detaches exactly what the test added.
	 *
	 * @var array[]
	 */
	private $network_callbacks = [];

	/**
	 * Callbacks hooked to wcs_get_users_subscriptions by create_gifted_subscription()
	 * and by tests that reshape a reader's list, detached in tear_down().
	 *
	 * @var callable[]
	 */
	private $gift_filters = [];

	/**
	 * Include the WC mocks before the class boots.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 3 ) . '/mocks/wcs-gifting-mock.php';
		// Stands in for the Newsletters plugin's list registry, which the endpoint
		// resolves subscribed list IDs against for the Newsletters column.
		require_once dirname( __DIR__, 3 ) . '/mocks/newsletters-namespaced-mocks.php';
		if ( ! post_type_exists( \Newspack\Newsletters\Subscription_Lists::CPT ) ) {
			register_post_type( \Newspack\Newsletters\Subscription_Lists::CPT );
		}
		// The wizard rides the Access Control feature flag; enable it so its routes register.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Reset the mock databases and register REST routes.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
		$this->user_ids         = [];
		$this->group_product_ids = [];
		$this->network_callbacks = [];
		$this->gift_filters     = [];
		// The gifting stub's recipient map is static and shared with other test files.
		\WCS_Gifting::$recipients = [];
		$this->scope_token      = 'scope' . wp_generate_password( 8, false );
		Donations::reset_flagged_donation_product_ids_cache();
		Group_Subscription::reset_cache();
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down: reset databases and delete users.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
		foreach ( $this->gift_filters as $gift_filter ) {
			remove_filter( 'wcs_get_users_subscriptions', $gift_filter, 10 );
		}
		$this->gift_filters       = [];
		\WCS_Gifting::$recipients = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		foreach ( $this->network_callbacks as list( $hook, $callback ) ) {
			remove_filter( $hook, $callback );
		}
		$this->network_callbacks = [];
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		parent::tear_down();
	}

	/**
	 * Create a reader user (display name carries the scope token) and track it.
	 *
	 * @param string $name       Human name, prefixed with the scope token in display_name.
	 * @param string $role       Optional role.
	 * @param string $registered Optional 'Y-m-d H:i:s' registration date. Fixture users
	 *                           otherwise all register in the same second, which makes
	 *                           member-since ordering unobservable.
	 *
	 * @return int The new user ID.
	 */
	private function create_reader( string $name = 'Reader', string $role = 'subscriber', string $registered = '' ): int {
		$suffix = wp_generate_password( 6, false );
		$args   = [
			'user_login'   => 'reader-' . $suffix,
			'user_pass'    => wp_generate_password(),
			'user_email'   => 'reader-' . $suffix . '@test.com',
			'display_name' => $this->scope_token . ' ' . $name,
			'role'         => $role,
		];
		// Set only when given, key by key rather than with a blanket array_filter():
		// that would equally drop a deliberately-empty `role`, which is the
		// documented way to create a role-less user.
		if ( $registered ) {
			$args['user_registered'] = $registered;
		}
		$user_id = wp_insert_user( $args );
		update_user_meta( $user_id, '_newspack_reader', true );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Create an admin and make it the current user.
	 *
	 * @return int The admin user ID.
	 */
	private function login_admin(): int {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->user_ids[] = $admin_id;
		wp_set_current_user( $admin_id );
		return $admin_id;
	}

	/**
	 * Create a group subscription owned by $owner_id, on a group-enabled product.
	 *
	 * Shaped the way production is: a publisher sells one group product, and each
	 * buyer names their own group, so a group's display name ("Acme Team") is not
	 * its product's name ("Team Plan"). A fixture with no product at all — or one
	 * whose group keeps the product's name, which is what an unnamed group falls
	 * back to — hides the very defect the plans endpoint has to avoid, because the
	 * group branch and the product branch of the filter then agree by accident.
	 *
	 * @param int    $owner_id     The owner user ID.
	 * @param int    $limit        Seat limit.
	 * @param string $status       Subscription status.
	 * @param string $product_name The backing group product's name.
	 *
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $owner_id, int $limit = 5, string $status = 'active', string $product_name = 'Team Plan' ): WC_Subscription {
		// One product per name across the whole fixture, because that is the shape the
		// exclusion exists for: many groups sold on a single product. Creating one per
		// call would give each group its own same-named product and let a per-product
		// bug pass.
		if ( ! isset( $this->group_product_ids[ $product_name ] ) ) {
			$this->group_product_ids[ $product_name ] = $this->create_subscription_product( $product_name, 'subscription', 0, true );
		}
		$product_id = $this->group_product_ids[ $product_name ];
		$sub        = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => $status,
				'billing_period' => 'month',
				'products'       => [ $product_id ],
				'items'          => self::line_items_for( $product_id ),
			]
		);
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', (string) $limit );
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', 'Acme Team' );
		return $sub;
	}

	/**
	 * Create a donation product: a subscription product by default, flagged as a
	 * donation the way the donation settings flag it.
	 *
	 * @param string $type WooCommerce product type, e.g. 'subscription' or 'simple'.
	 *
	 * @return int The product ID.
	 */
	private function create_donation_product( string $type = 'subscription' ): int {
		$product_id = $this->create_subscription_product( 'Donation', $type );
		update_post_meta( $product_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		return $product_id;
	}

	/**
	 * Create an order for a donation product and add it to the mock order database.
	 *
	 * @param int    $customer_id The donor's user ID.
	 * @param int    $product_id  The donation product ID.
	 * @param string $status      Order status.
	 * @param string $date_paid   Optional 'Y-m-d H:i:s' paid date; the mock defaults to now.
	 *
	 * @return WC_Order
	 */
	private function create_donation_order( int $customer_id, int $product_id, string $status = 'completed', string $date_paid = '' ): WC_Order {
		$data = [
			'customer_id' => $customer_id,
			'status'      => $status,
			'total'       => 25,
			'items'       => [
				new WC_Order_Item_Product(
					[
						'product_id' => $product_id,
						'quantity'   => 1,
					]
				),
			],
		];
		if ( '' !== $date_paid ) {
			$data['date_paid'] = $date_paid;
		}
		return new WC_Order( $data );
	}

	/**
	 * Complete a run of non-donation orders for a reader, all newer than any
	 * donation order the tests give them.
	 *
	 * @param int $customer_id The reader's user ID.
	 * @param int $product_id  A product that is not a donation.
	 * @param int $count       How many orders to create.
	 */
	private function create_newer_orders( int $customer_id, int $product_id, int $count ) {
		$first_day = strtotime( '2026-02-01 12:00:00 UTC' );
		for ( $i = 0; $i < $count; $i++ ) {
			$this->create_donation_order( $customer_id, $product_id, 'completed', gmdate( 'Y-m-d H:i:s', $first_day + $i * DAY_IN_SECONDS ) );
		}
	}

	/**
	 * Flag a reader as a donor, the way a completed donation does.
	 *
	 * @param int $user_id The reader's user ID.
	 */
	private function flag_donor( int $user_id ) {
		Reader_Data::set_is_donor( time(), [ 'user_id' => $user_id ] );
	}

	/**
	 * Flag a reader as a former donor, the way an ended recurring donation does.
	 *
	 * @param int $user_id The reader's user ID.
	 */
	private function flag_former_donor( int $user_id ) {
		Reader_Data::set_is_former_donor( time(), [ 'user_id' => $user_id ] );
	}

	/**
	 * One reader's row from the list.
	 *
	 * @param int $user_id The reader's user ID.
	 *
	 * @return array
	 */
	private function list_row( int $user_id ): array {
		return array_column( $this->dispatch()->get_data()['items'], null, 'id' )[ $user_id ];
	}

	/**
	 * Create a plain (non-group) individual subscription owned by $owner_id.
	 *
	 * @param int    $owner_id   The owner user ID.
	 * @param string $status     Subscription status.
	 * @param int    $product_id Optional product ID to link (so the plan filter can
	 *                           match it via wcs_get_subscriptions_for_product).
	 *
	 * @return WC_Subscription
	 */
	private function create_individual_subscription( int $owner_id, string $status = 'active', int $product_id = 0 ): WC_Subscription {
		$data = [
			'customer_id'    => $owner_id,
			'status'         => $status,
			'billing_period' => 'month',
		];
		if ( $product_id ) {
			$data['products'] = [ $product_id ];
			$data['items']    = self::line_items_for( $product_id );
		}
		return wcs_create_subscription( $data );
	}

	/**
	 * Create an active subscription bought by one reader as a gift for another.
	 *
	 * Mirrors WCS Gifting in production: the subscription stays owned by the
	 * purchaser, and the recipient sees it in wcs_get_users_subscriptions().
	 *
	 * @param int    $purchaser_id Purchaser user ID.
	 * @param int    $recipient_id Recipient user ID.
	 * @param string $status       Subscription status.
	 * @param int    $product_id   Optional product ID to link.
	 * @param array  $dates        Optional subscription dates, keyed by WCS date type.
	 *
	 * @return WC_Subscription
	 */
	private function create_gifted_subscription( int $purchaser_id, int $recipient_id, string $status = 'active', int $product_id = 0, array $dates = [] ): WC_Subscription {
		$data = [
			'customer_id'    => $purchaser_id,
			'status'         => $status,
			'billing_period' => 'month',
			'dates'          => $dates,
		];
		if ( $product_id ) {
			$data['products'] = [ $product_id ];
			$data['items']    = self::line_items_for( $product_id );
		}
		$subscription = wcs_create_subscription( $data );
		\WCS_Gifting::$recipients[ $subscription->get_id() ] = $recipient_id;

		$gift_filter = function ( $subscriptions, $user_id ) use ( $subscription, $recipient_id ) {
			if ( (int) $user_id === $recipient_id ) {
				$subscriptions[ $subscription->get_id() ] = $subscription;
			}
			return $subscriptions;
		};
		add_filter( 'wcs_get_users_subscriptions', $gift_filter, 10, 2 );
		$this->gift_filters[] = $gift_filter;

		return $subscription;
	}

	/**
	 * The line items a subscription on $product_id carries.
	 *
	 * The mock's `products` array only answers has_product(); the plan *name* a
	 * subscription displays is resolved from its line items
	 * (WooCommerce_Subscriptions::get_subscription_product_id), so a fixture
	 * without them shows a blank plan and can't tell one plan from another.
	 *
	 * @param int $product_id The subscribed product ID.
	 *
	 * @return WC_Order_Item_Product[]
	 */
	private static function line_items_for( int $product_id ): array {
		return [ new WC_Order_Item_Product( [ 'product_id' => $product_id ] ) ];
	}

	/**
	 * Create a subscription product as both a published WP post and a mock
	 * WC_Product under the same ID.
	 *
	 * Both halves are needed because the two sides of the plan filter read
	 * different sources: the plans endpoint enumerates products through
	 * wc_get_products(), while the subscribers endpoint resolves a plan name back
	 * to product IDs with a WP_Query on the post title.
	 *
	 * @param string $name          The product name (its post title).
	 * @param string $type          WooCommerce product type.
	 * @param int    $parent_id     Parent product ID, for a variation.
	 * @param bool   $group_enabled Whether the product is sold as a group subscription.
	 * @param string $status        Post status, so an unpublished product can be exercised.
	 *
	 * @return int The product ID.
	 */
	private function create_subscription_product( string $name, string $type = 'subscription', int $parent_id = 0, bool $group_enabled = false, string $status = 'publish' ): int {
		$product_id = self::factory()->post->create(
			[
				'post_type'   => 'subscription_variation' === $type ? 'product_variation' : 'product',
				'post_title'  => $name,
				'post_status' => $status,
				'post_parent' => $parent_id,
			]
		);
		$group_meta_key = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled';
		if ( $group_enabled ) {
			// On the post for the site-wide group-subscription lookup, and on the
			// mock product for get_product_settings() — production writes both.
			update_post_meta( $product_id, $group_meta_key, 'yes' );
		}
		wc_create_mock_product(
			[
				'id'        => $product_id,
				'name'      => $name,
				'type'      => $type,
				'parent_id' => $parent_id,
				'status'    => $status,
				'meta'      => $group_enabled ? [ $group_meta_key => 'yes' ] : [],
			]
		);
		if ( $parent_id ) {
			// The mock product is immutable, so adding a child means re-registering
			// the parent. Carry its status and group setting across or the rewrite
			// silently resets them.
			$parent         = wc_get_product( $parent_id );
			$parent_enabled = $parent->get_meta( $group_meta_key );
			wc_create_mock_product(
				[
					'id'       => $parent_id,
					'name'     => $parent->get_name(),
					'type'     => $parent->get_type(),
					'status'   => $parent->get_status(),
					'meta'     => $parent_enabled ? [ $group_meta_key => $parent_enabled ] : [],
					'children' => array_merge( $parent->get_children(), [ $product_id ] ),
				]
			);
		}
		return $product_id;
	}

	/**
	 * Dispatch the plans endpoint.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch_plans(): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-subscribers/plans' ) );
	}

	/**
	 * Stand in for newspack-network's subscription index, which this suite does not
	 * load. The callback is detached in tear_down().
	 *
	 * @param array[] $rows Index rows, as network_index_row() shapes them.
	 *
	 * @return void
	 */
	private function stub_network_index( array $rows ) {
		$callback = fn( $existing ) => array_merge( is_array( $existing ) ? $existing : [], $rows );
		add_filter( 'newspack_subscribers_network_subscription_index', $callback );
		$this->network_callbacks[] = [ 'newspack_subscribers_network_subscription_index', $callback ];
	}

	/**
	 * One row of the network subscription index: a subscription record plus the ID of
	 * the reader it belongs to.
	 *
	 * @param int    $user_id   The reader's user ID.
	 * @param string $status    The raw WooCommerce Subscriptions status.
	 * @param string $plan      The plan name on the site that manages the subscription.
	 * @param string $site_name The name of that site.
	 *
	 * @return array
	 */
	private function network_index_row( int $user_id, string $status, string $plan, string $site_name = 'Node Daily' ): array {
		static $next_id = 9000;
		return [
			'user_id'   => $user_id,
			'id'        => ++$next_id,
			'site_url'  => 'https://node-b.example.test',
			'site_name' => $site_name,
			'status'    => $status,
			'plan'      => $plan,
			'edit_url'  => 'https://node-b.example.test/wp-admin/post.php?action=edit',
		];
	}

	/**
	 * The reader IDs a subscribers request returns, sorted.
	 *
	 * @param array $params Query params for the request.
	 *
	 * @return int[]
	 */
	private function filtered_ids( array $params ): array {
		$ids = array_column( $this->dispatch( $params )->get_data()['items'], 'id' );
		sort( $ids );
		return $ids;
	}

	/**
	 * Create a newsletter subscription list the site knows the title of.
	 *
	 * @param string $title The list's display title.
	 *
	 * @return string The list's public ID, as stored on a subscribed reader.
	 */
	private function create_newsletter_list( string $title ): string {
		$post_id = self::factory()->post->create(
			[
				'post_type'  => \Newspack\Newsletters\Subscription_Lists::CPT,
				'post_title' => $title,
			]
		);
		return ( new \Newspack\Newsletters\Subscription_List( $post_id ) )->get_public_id();
	}

	/**
	 * Create a reader carrying every field this slice hydrates — tags, a newsletter
	 * subscription and an activity record — so a query-count measurement exercises
	 * all three lookups.
	 *
	 * @param string $name    Human name.
	 * @param string $list_id Public ID of the list to subscribe them to.
	 *
	 * @return int The new user ID.
	 */
	private function create_hydrated_reader( string $name, string $list_id ): int {
		$user_id = $this->create_reader( $name );
		update_user_meta( $user_id, \Newspack\Subscribers_Wizard::READER_TAGS_META, [ 'vip' ] );
		\Newspack\Reader_Data::update_item( $user_id, 'last_active', (string) ( strtotime( '2025-11-20 17:30:00' ) * 1000 ) );
		\Newspack\Reader_Data::update_item( $user_id, 'newsletter_subscribed_lists', wp_json_encode( [ $list_id ] ) );
		return $user_id;
	}

	/**
	 * The number of database queries one dispatch of the subscribers endpoint costs.
	 *
	 * Measured from a flushed object cache, because that is the state a real
	 * request starts in: without a persistent object cache every page load pays
	 * for its own lookups, so a per-row lookup that a warm in-process cache would
	 * hide is exactly what needs to show up here.
	 *
	 * @param array $params Query params to dispatch with.
	 *
	 * @return int Queries issued by the dispatch.
	 */
	private function measure_query_cost( array $params ): int {
		global $wpdb;
		// A throwaway run first, so any PHP-level static a first-ever request fills
		// isn't charged to whichever measurement happens to go first.
		wp_cache_flush();
		$this->dispatch( $params );
		wp_cache_flush();
		$queries_before = $wpdb->num_queries;
		$this->dispatch( $params );
		return $wpdb->num_queries - $queries_before;
	}

	/**
	 * Dispatch the subscribers endpoint, scoped to this test's readers via search.
	 *
	 * @param array $params Extra query params (page, per_page, orderby, order, status, plan, search).
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch( array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		if ( ! array_key_exists( 'search', $params ) ) {
			$params['search'] = $this->scope_token;
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * An admin gets the paginated envelope with each reader hydrated with the L0 fields.
	 */
	public function test_returns_hydrated_subscribers_for_admin() {
		$this->login_admin();
		$this->create_reader( 'Alice' );

		$response = $this->dispatch();
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'pages', $data );
		$this->assertSame( 1, $data['total'] );

		$item = $data['items'][0];
		foreach ( [ 'id', 'name', 'email', 'editUrl', 'status', 'memberSince', 'lastPayment', 'lastSeen', 'subscriptions', 'groups', 'tags', 'newsletters' ] as $key ) {
			$this->assertArrayHasKey( $key, $item, "Missing key: $key" );
		}
		$this->assertStringContainsString( 'Alice', $item['name'] );
		// The interim click-through target resolves to a native edit URL for an admin.
		$this->assertStringContainsString( 'user_id=' . $item['id'], $item['editUrl'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $item['memberSince'] );
	}

	/**
	 * The list paginates: total counts the whole scoped set; items are one page.
	 */
	public function test_paginates() {
		$this->login_admin();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_reader( 'R' . $i );
		}

		$page1 = $this->dispatch(
			[
				'per_page' => 2,
				'page'     => 1,
			] 
		)->get_data();
		$this->assertSame( 5, $page1['total'] );
		$this->assertSame( 3, $page1['pages'] );
		$this->assertCount( 2, $page1['items'] );

		$page3 = $this->dispatch(
			[
				'per_page' => 2,
				'page'     => 3,
			] 
		)->get_data();
		$this->assertCount( 1, $page3['items'] );
	}

	/**
	 * Sorting by name is honoured server-side.
	 */
	public function test_sorts_by_name() {
		$this->login_admin();
		$this->create_reader( 'Charlie' );
		$this->create_reader( 'Alice' );
		$this->create_reader( 'Bob' );

		$names = array_map(
			fn( $item ) => $item['name'],
			$this->dispatch(
				[
					'orderby' => 'name',
					'order'   => 'asc',
				] 
			)->get_data()['items']
		);
		$sorted = $names;
		sort( $sorted );
		$this->assertSame( $sorted, $names );
	}

	/**
	 * Sorting by member-since is honoured server-side — and it is the endpoint's
	 * default order, so the list's out-of-the-box ordering rides on it.
	 */
	public function test_sorts_by_member_since_by_default() {
		$this->login_admin();
		$oldest_id = $this->create_reader( 'Oldest', 'subscriber', '2020-01-01 00:00:00' );
		$middle_id = $this->create_reader( 'Middle', 'subscriber', '2022-06-15 00:00:00' );
		$newest_id = $this->create_reader( 'Newest', 'subscriber', '2024-11-30 00:00:00' );

		// No orderby/order params: the registered defaults are memberSince / desc.
		$descending = array_column( $this->dispatch()->get_data()['items'], 'id' );
		$this->assertSame( [ $newest_id, $middle_id, $oldest_id ], $descending );

		$ascending = array_column( $this->dispatch( [ 'order' => 'asc' ] )->get_data()['items'], 'id' );
		$this->assertSame( [ $oldest_id, $middle_id, $newest_id ], $ascending );

		// memberSince is hydrated from the same column.
		$this->assertSame( '2024-11-30', $this->dispatch()->get_data()['items'][0]['memberSince'] );
	}

	/**
	 * Search narrows the result set to matching readers.
	 */
	public function test_search_narrows_results() {
		$this->login_admin();
		$needle_id = $this->create_reader( 'Zephyr' );
		$this->create_reader( 'Someone' );

		$data = $this->dispatch( [ 'search' => 'Zephyr' ] )->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( $needle_id, $data['items'][0]['id'] );
	}

	/**
	 * Group memberships are hydrated with role, plan and status; the owner reads as
	 * owner and a plain member reads as member. Group subs do not leak into the
	 * individual `subscriptions` array.
	 */
	public function test_hydrates_group_roles() {
		$this->login_admin();
		$owner_id  = $this->create_reader( 'Owner' );
		$member_id = $this->create_reader( 'Member' );
		$sub       = $this->create_group_subscription( $owner_id );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $sub->get_id() );

		$items = $this->dispatch()->get_data()['items'];
		$by_id = [];
		foreach ( $items as $item ) {
			$by_id[ $item['id'] ] = $item;
		}

		$this->assertCount( 1, $by_id[ $owner_id ]['groups'] );
		$this->assertSame( 'owner', $by_id[ $owner_id ]['groups'][0]['role'] );
		$this->assertSame( 'Acme Team', $by_id[ $owner_id ]['groups'][0]['plan'] );
		$this->assertSame( 'active', $by_id[ $owner_id ]['groups'][0]['status'] );
		$this->assertArrayHasKey( 'editUrl', $by_id[ $owner_id ]['groups'][0], 'A group membership carries the subscription click target.' );
		$this->assertEmpty( $by_id[ $owner_id ]['subscriptions'], 'A group sub is not an individual subscription.' );

		$this->assertCount( 1, $by_id[ $member_id ]['groups'] );
		$this->assertSame( 'member', $by_id[ $member_id ]['groups'][0]['role'] );
	}

	/**
	 * A plain individual subscription is hydrated into `subscriptions` with its
	 * mapped status, and does not appear as a group.
	 */
	public function test_hydrates_individual_subscription() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Solo' );
		wcs_create_subscription(
			[
				'customer_id'    => $reader_id,
				'status'         => 'on-hold',
				'billing_period' => 'month',
			]
		);

		$data  = $this->dispatch( [ 'search' => $this->scope_token . ' Solo' ] )->get_data();
		$item  = $data['items'][0];
		$this->assertCount( 1, $item['subscriptions'] );
		$this->assertSame( 'on-hold', $item['subscriptions'][0]['status'] );
		$this->assertArrayHasKey( 'plan', $item['subscriptions'][0] );
		// The plan name is its own click target, distinct from the row's person
		// target; always present, empty when no edit URL resolves (as under the mock).
		$this->assertArrayHasKey( 'editUrl', $item['subscriptions'][0] );
		$this->assertIsString( $item['subscriptions'][0]['editUrl'] );
		$this->assertEmpty( $item['groups'] );
	}

	/**
	 * A subscription managed on another network site reaches the list row too: it
	 * sets the row's status and shows under the plan name with its site in
	 * brackets, so a reader with nothing local still shows as a subscriber.
	 */
	public function test_list_row_includes_a_subscription_managed_on_another_network_site() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Across' );
		// Stands in for newspack-network, which this suite does not load.
		$records  = [
			[
				'id'        => 500,
				'site_url'  => 'https://node-b.example.test',
				'site_name' => 'Node Daily',
				'status'    => 'active',
				'plan'      => 'Fan Membership',
				'edit_url'  => 'https://node-b.example.test/wp-admin/post.php?post=500&action=edit',
			],
		];
		$for_user = fn( $existing, $user_id ) => (int) $user_id === $reader_id ? $records : $existing;
		add_filter( 'newspack_subscribers_network_subscriptions', $for_user, 10, 2 );
		$this->network_callbacks[] = [ 'newspack_subscribers_network_subscriptions', $for_user ];

		$items = $this->dispatch()->get_data()['items'];

		$this->assertCount( 1, $items );
		$this->assertSame( $reader_id, $items[0]['id'] );
		$this->assertSame( 'active', $items[0]['status'] );
		$this->assertCount( 1, $items[0]['subscriptions'] );
		$this->assertSame( 'Fan Membership (Node Daily)', $items[0]['subscriptions'][0]['plan'] );
		$this->assertSame( 'Fan Membership', $items[0]['subscriptions'][0]['planName'], 'The bare plan name travels too, so the row can name the site outside the plan link.' );
		$this->assertSame( 'Node Daily', $items[0]['subscriptions'][0]['managedOn']['name'] );
	}

	/**
	 * The Active filter counts a subscription managed on another network site: a
	 * reader whose only subscription lives elsewhere shows an Active badge in the
	 * list, so filtering on Active has to return them, and `total` has to count them.
	 * The raw status is mapped like any other (pending-cancel is still entitled).
	 */
	public function test_status_filter_counts_a_subscription_managed_on_another_network_site() {
		$this->login_admin();
		$remote_only    = $this->create_reader( 'RemoteOnly' );
		$pending_cancel = $this->create_reader( 'RemotePendingCancel' );
		$remote_wait    = $this->create_reader( 'RemotePending' );
		$local_only     = $this->create_reader( 'LocalOnly' );
		$this->create_reader( 'NoSubscription' );
		$this->create_individual_subscription( $local_only, 'active' );
		$this->stub_network_index(
			[
				$this->network_index_row( $remote_only, 'active', 'Fan Membership' ),
				$this->network_index_row( $pending_cancel, 'pending-cancel', 'Fan Membership' ),
				$this->network_index_row( $remote_wait, 'pending', 'Fan Membership' ),
			]
		);

		$data     = $this->dispatch( [ 'status' => [ 'active' ] ] )->get_data();
		$ids      = array_column( $data['items'], 'id' );
		$expected = [ $remote_only, $pending_cancel, $local_only ];
		sort( $ids );
		sort( $expected );
		$this->assertSame( $expected, $ids, 'Readers whose only live subscription is on another site are in the Active filter.' );
		$this->assertSame( 3, $data['total'], 'The total counts the readers holding a subscription elsewhere.' );

		$this->assertSame( [ $remote_wait ], $this->filtered_ids( [ 'status' => [ 'pending' ] ] ), 'A pending subscription elsewhere matches the Pending filter, and only that one.' );
	}

	/**
	 * The Cancelled filter keeps the badge's reduction across sites: a cancelled plan
	 * qualifies a reader only when nothing live remains, wherever the live plan sits.
	 */
	public function test_cancelled_filter_reduces_across_local_and_network_subscriptions() {
		$this->login_admin();
		$remote_cancelled_local_live = $this->create_reader( 'RemoteCancelledLocalLive' );
		$remote_cancelled_only       = $this->create_reader( 'RemoteCancelledOnly' );
		$local_cancelled_remote_live = $this->create_reader( 'LocalCancelledRemoteLive' );
		$remote_expired_only         = $this->create_reader( 'RemoteExpiredOnly' );
		$this->create_individual_subscription( $remote_cancelled_local_live, 'active' );
		$this->create_individual_subscription( $local_cancelled_remote_live, 'cancelled' );
		$this->stub_network_index(
			[
				$this->network_index_row( $remote_cancelled_local_live, 'cancelled', 'Fan Membership' ),
				$this->network_index_row( $remote_cancelled_only, 'cancelled', 'Fan Membership' ),
				$this->network_index_row( $local_cancelled_remote_live, 'active', 'Fan Membership' ),
				$this->network_index_row( $remote_expired_only, 'expired', 'Fan Membership' ),
			]
		);

		$expected = [ $remote_cancelled_only, $remote_expired_only ];
		sort( $expected );
		$this->assertSame( $expected, $this->filtered_ids( [ 'status' => [ 'cancelled' ] ] ), 'Only readers with nothing live left, on any site, are cancelled; an expired subscription elsewhere counts as cancelled.' );

		$expected = [ $remote_cancelled_local_live, $local_cancelled_remote_live ];
		sort( $expected );
		$this->assertSame( $expected, $this->filtered_ids( [ 'status' => [ 'active' ] ] ), 'A live subscription on either side keeps the reader in Active.' );
	}

	/**
	 * A plan on another site goes by "Plan (Site)", so it never collides with a local
	 * product of the same name: each filter returns only its own holders.
	 */
	public function test_plan_filter_tells_a_network_plan_from_a_local_plan_of_the_same_name() {
		$this->login_admin();
		$product_id    = $this->create_subscription_product( 'Fan Membership' );
		$local_holder  = $this->create_reader( 'LocalHolder' );
		$remote_holder = $this->create_reader( 'RemoteHolder' );
		$this->create_individual_subscription( $local_holder, 'active', $product_id );
		$this->stub_network_index( [ $this->network_index_row( $remote_holder, 'active', 'Fan Membership' ) ] );

		$this->assertSame( [ $remote_holder ], $this->filtered_ids( [ 'plan' => [ 'Fan Membership (Node Daily)' ] ] ), 'The network label returns only the reader holding it elsewhere.' );
		$this->assertSame( [ $local_holder ], $this->filtered_ids( [ 'plan' => [ 'Fan Membership' ] ] ), 'The bare name returns only the reader holding the local product.' );
	}

	/**
	 * A cancelled network plan qualifies its holder only when nothing live remains,
	 * the rule customer_ids_for_plans() applies to local plans. A live subscription
	 * counts wherever it sits: on the same site, another one, or this one.
	 */
	public function test_a_cancelled_network_plan_matches_only_a_fully_churned_reader() {
		$this->login_admin();
		$churned         = $this->create_reader( 'Churned' );
		$moved_elsewhere = $this->create_reader( 'MovedElsewhere' );
		$moved_here      = $this->create_reader( 'MovedHere' );
		$this->create_individual_subscription( $moved_here, 'active' );
		$this->stub_network_index(
			[
				$this->network_index_row( $churned, 'cancelled', 'Fan Membership' ),
				$this->network_index_row( $moved_elsewhere, 'cancelled', 'Fan Membership' ),
				$this->network_index_row( $moved_elsewhere, 'active', 'Fan Membership Plus', 'Hub Gazette' ),
				$this->network_index_row( $moved_here, 'cancelled', 'Fan Membership' ),
			]
		);

		$this->assertSame( [ $churned ], $this->filtered_ids( [ 'plan' => [ 'Fan Membership (Node Daily)' ] ] ) );
		$this->assertSame( [ $moved_elsewhere ], $this->filtered_ids( [ 'plan' => [ 'Fan Membership Plus (Hub Gazette)' ] ] ) );
	}

	/**
	 * The plans endpoint offers each network plan once however many readers hold it,
	 * and every option filters back to its holders: the round trip /plans exists to
	 * guarantee holds for plans on other sites too.
	 */
	public function test_plans_endpoint_lists_a_network_plan_once_and_it_filters_to_its_holders() {
		$this->login_admin();
		$this->create_subscription_product( 'Digital Monthly' );
		$first  = $this->create_reader( 'NetworkHolderOne' );
		$second = $this->create_reader( 'NetworkHolderTwo' );
		$third  = $this->create_reader( 'NetworkHolderThree' );
		$this->stub_network_index(
			[
				$this->network_index_row( $first, 'active', 'Fan Membership' ),
				$this->network_index_row( $second, 'cancelled', 'Fan Membership' ),
				$this->network_index_row( $third, 'active', 'Fan Membership', 'Hub Gazette' ),
			]
		);

		$plans = $this->dispatch_plans()->get_data();

		$this->assertSame(
			[ 'Digital Monthly', 'Fan Membership (Hub Gazette)', 'Fan Membership (Node Daily)' ],
			$plans['items'],
			'Local plans and network plans, each once, in the one natural order.'
		);
		$this->assertSame( 3, $plans['total'] );

		$expected = [ $first, $second ];
		sort( $expected );
		$this->assertSame( $expected, $this->filtered_ids( [ 'plan' => [ 'Fan Membership (Node Daily)' ] ] ), 'Both holders of the Node Daily plan, whatever their status, are in its filter.' );
		$this->assertSame( [ $third ], $this->filtered_ids( [ 'plan' => [ 'Fan Membership (Hub Gazette)' ] ] ) );
	}

	/**
	 * A network plan whose site name holds a "<" still filters back to its holders.
	 *
	 * The filter sanitizes the submitted plan names, which rewrites a "<", so the option
	 * /plans offers has to be compared in the same sanitized form.
	 */
	public function test_a_network_plan_with_markup_in_its_site_name_filters_to_its_holders() {
		$this->login_admin();
		$holder = $this->create_reader( 'MarkupSiteHolder' );
		$this->stub_network_index( [ $this->network_index_row( $holder, 'active', 'Fan Membership', 'I <3 News' ) ] );

		$plans = $this->dispatch_plans()->get_data()['items'];

		$this->assertSame( [ 'Fan Membership (I <3 News)' ], $plans, '/plans offers the label as the row shows it.' );
		$this->assertSame( [ $holder ], $this->filtered_ids( [ 'plan' => [ $plans[0] ] ] ), 'The option returned by /plans filters to its holder.' );

		$sites = $this->dispatch_plans()->get_data()['sites'];
		$this->assertSame( [ 'I <3 News' ], $sites );
		$this->assertSame( [ $holder ], $this->filtered_ids( [ 'managed_on' => $sites ] ), 'The Managed on option returned by /plans filters to its holder.' );
	}

	/**
	 * The Managed on filter returns readers by where their subscriptions are managed:
	 * this site, or other network sites by name. /plans lists each network site once
	 * as an option, and any mix of options returns every reader matching one of them.
	 */
	public function test_managed_on_filter_matches_readers_by_where_their_subscriptions_are_managed() {
		$this->login_admin();
		$local_only = $this->create_reader( 'ManagedLocalOnly' );
		$node_only  = $this->create_reader( 'ManagedNodeOnly' );
		$hub_only   = $this->create_reader( 'ManagedHubOnly' );
		$both       = $this->create_reader( 'ManagedBoth' );
		$this->create_reader( 'ManagedNowhere' );
		$this->create_individual_subscription( $local_only, 'active' );
		$this->create_individual_subscription( $both, 'active' );
		$this->stub_network_index(
			[
				$this->network_index_row( $node_only, 'active', 'Fan Membership' ),
				$this->network_index_row( $hub_only, 'on-hold', 'Fan Membership', 'Hub Gazette' ),
				$this->network_index_row( $both, 'active', 'Fan Membership', 'Hub Gazette' ),
			]
		);

		$this->assertSame( [ 'Hub Gazette', 'Node Daily' ], $this->dispatch_plans()->get_data()['sites'], 'Each network site is offered once, in natural order.' );

		$expected = [ $local_only, $both ];
		sort( $expected );
		$this->assertSame( $expected, $this->filtered_ids( [ 'managed_on' => [ 'this-site' ] ] ), 'This site returns readers holding a local subscription, whatever they hold elsewhere.' );

		$this->assertSame( [ $node_only ], $this->filtered_ids( [ 'managed_on' => [ 'Node Daily' ] ] ) );

		$expected = [ $node_only, $hub_only, $both ];
		sort( $expected );
		$this->assertSame( $expected, $this->filtered_ids( [ 'managed_on' => [ 'Node Daily', 'Hub Gazette' ] ] ), 'Several sites return readers managed on any of them.' );
	}

	/**
	 * A cancelled subscription counts towards Managed on only when nothing live
	 * remains, as in the Subscription column, which hides it otherwise.
	 */
	public function test_managed_on_filter_skips_a_cancelled_subscription_while_a_live_one_remains() {
		$this->login_admin();
		$moved_here  = $this->create_reader( 'MovedHere' );
		$moved_there = $this->create_reader( 'MovedThere' );
		$churned     = $this->create_reader( 'ChurnedThere' );
		$this->create_individual_subscription( $moved_here, 'active' );
		$this->create_individual_subscription( $moved_there, 'cancelled' );
		$this->stub_network_index(
			[
				$this->network_index_row( $moved_here, 'cancelled', 'Fan Membership' ),
				$this->network_index_row( $moved_there, 'active', 'Fan Membership' ),
				$this->network_index_row( $churned, 'cancelled', 'Fan Membership' ),
			]
		);

		$expected = [ $moved_there, $churned ];
		sort( $expected );
		$this->assertSame( $expected, $this->filtered_ids( [ 'managed_on' => [ 'Node Daily' ] ] ), 'A reader who moved here no longer counts as managed on Node Daily.' );
		$this->assertSame( [ $moved_here ], $this->filtered_ids( [ 'managed_on' => [ 'this-site' ] ] ), 'A reader who moved away no longer counts as managed here.' );
	}

	/**
	 * The index is read by the same rules as the list row's network subscriptions, so
	 * a plan label can never read one way in the Subscription column and another in
	 * the filter: a padded site name is trimmed and a subscription with no plan falls
	 * back to its ID, in both. Whatever the row would drop, the index drops too.
	 */
	public function test_network_index_follows_the_same_record_rules_as_the_list_row() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Labelled' );
		$ghost_id  = $this->create_reader( 'Ghost' );

		$record = [
			'id'        => 42,
			'site_url'  => 'https://node-b.example.test',
			'site_name' => '  Node Daily  ',
			'status'    => 'active',
			'edit_url'  => 'https://node-b.example.test/wp-admin/post.php?action=edit',
		];
		// The same record answers both filters, the way newspack-network does.
		$for_user = fn( $existing, $user_id ) => (int) $user_id === $reader_id ? [ $record ] : $existing;
		add_filter( 'newspack_subscribers_network_subscriptions', $for_user, 10, 2 );
		$this->network_callbacks[] = [ 'newspack_subscribers_network_subscriptions', $for_user ];

		$valid     = array_merge( $record, [ 'user_id' => $reader_id ] );
		$ghost     = [
			'user_id'   => $ghost_id,
			'id'        => 77,
			'site_url'  => 'https://node-b.example.test',
			'site_name' => 'Node Daily',
			'status'    => 'active',
			'plan'      => 'Ghost',
			'edit_url'  => '',
		];
		$malformed = [
			'not an array',
			array_merge( $ghost, [ 'id' => 0 ] ),
			array_merge( $ghost, [ 'id' => 'abc' ] ),
			array_merge( $ghost, [ 'site_name' => '   ' ] ),
			array_merge( $ghost, [ 'site_name' => [ 'Node Daily' ] ] ),
			array_merge( $ghost, [ 'status' => '' ] ),
			array_merge( $ghost, [ 'status' => [ 'active' ] ] ),
			array_merge( $ghost, [ 'user_id' => 0 ] ),
			array_merge( $ghost, [ 'user_id' => 'abc' ] ),
			array_diff_key( $ghost, [ 'user_id' => true ] ),
		];
		$this->stub_network_index( array_merge( [ $valid ], $malformed ) );

		$row_plan = $this->dispatch( [ 'search' => $this->scope_token . ' Labelled' ] )->get_data()['items'][0]['subscriptions'][0]['plan'];
		$this->assertSame( 'Subscription #42 (Node Daily)', $row_plan );

		$this->assertSame( [ $row_plan ], $this->dispatch_plans()->get_data()['items'], 'The only option is the valid row\'s, under the label the row shows; nothing from a malformed row leaks in.' );
		$this->assertSame( [ $reader_id ], $this->filtered_ids( [ 'plan' => [ $row_plan ] ] ), 'The label on the row filters back to the reader.' );
		$this->assertSame( [ $reader_id ], $this->filtered_ids( [ 'status' => [ 'active' ] ] ), 'A malformed row puts nobody in a status filter.' );
	}

	/**
	 * An index that is not a list is ignored rather than fatal.
	 */
	public function test_a_network_index_that_is_not_a_list_is_ignored() {
		$this->login_admin();
		$this->create_subscription_product( 'Digital Monthly' );
		$callback = fn() => 'not a list';
		add_filter( 'newspack_subscribers_network_subscription_index', $callback );
		$this->network_callbacks[] = [ 'newspack_subscribers_network_subscription_index', $callback ];

		$this->assertSame( [ 'Digital Monthly' ], $this->dispatch_plans()->get_data()['items'] );
		$this->assertSame( [], $this->filtered_ids( [ 'status' => [ 'active' ] ] ) );
	}

	/**
	 * The index is bounded by the filter cap and read once per request, however many
	 * filters ask for it, and again on the next one: a new request must not reuse a
	 * stale memo.
	 */
	public function test_network_index_is_read_once_per_request_with_the_filter_cap() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Counted' );
		$calls     = [];
		$callback  = function ( $existing, $max_users ) use ( &$calls, $reader_id ) {
			$calls[] = $max_users;
			return array_merge( $existing, [ $this->network_index_row( $reader_id, 'active', 'Fan Membership' ) ] );
		};
		add_filter( 'newspack_subscribers_network_subscription_index', $callback, 10, 2 );
		$this->network_callbacks[] = [ 'newspack_subscribers_network_subscription_index', $callback ];

		$this->dispatch(
			[
				'status' => [ 'active', 'cancelled' ],
				'plan'   => [ 'Fan Membership (Node Daily)' ],
			]
		);
		$this->assertSame( [ \Newspack\Subscribers_Wizard::FILTER_INCLUDE_CAP ], $calls, 'One read, bounded by the filter cap, for a request with status and plan filters.' );

		$this->dispatch( [ 'status' => [ 'active' ] ] );
		$this->assertCount( 2, $calls, 'The next request reads the index afresh.' );
		$this->dispatch_plans();
		$this->assertCount( 3, $calls, 'So does the plans endpoint.' );
	}

	/**
	 * An index with no rows changes nothing, down to the byte: a site on the network
	 * with nobody subscribed elsewhere answers the plans and the filters exactly as a
	 * site that is not on it.
	 */
	public function test_an_empty_network_index_changes_no_response() {
		$this->login_admin();
		$product_id = $this->create_subscription_product( 'Digital Monthly' );
		$active     = $this->create_reader( 'Active' );
		$cancelled  = $this->create_reader( 'Cancelled' );
		$this->create_individual_subscription( $active, 'active', $product_id );
		$this->create_individual_subscription( $cancelled, 'cancelled', $product_id );
		$this->create_group_subscription( $this->create_reader( 'GroupOwner' ) );

		$requests = [
			[ 'status' => [ 'active' ] ],
			[ 'status' => [ 'cancelled' ] ],
			[ 'plan' => [ 'Digital Monthly' ] ],
			[
				'status' => [ 'active', 'cancelled' ],
				'plan'   => [ 'Acme Team' ],
			],
		];

		$without_plans    = $this->dispatch_plans()->get_data();
		$without_requests = array_map( fn( $params ) => $this->dispatch( $params )->get_data(), $requests );
		$this->assertNotEmpty( $without_plans['items'], 'The fixture really offers plans.' );
		$this->assertSame( 2, $without_requests[0]['total'], 'The fixture really filters: the individual and the group owner are active, the cancelled reader is not.' );

		$this->stub_network_index( [] );

		$this->assertSame( $without_plans, $this->dispatch_plans()->get_data() );
		foreach ( $requests as $i => $params ) {
			$this->assertSame( $without_requests[ $i ], $this->dispatch( $params )->get_data() );
		}
	}

	/**
	 * A status filter inverts to just the readers holding an individual
	 * subscription in a matching status; others are excluded via the include set.
	 */
	public function test_status_filter_inverts_to_matching_readers() {
		$this->login_admin();
		$active_id    = $this->create_reader( 'Active' );
		$cancelled_id = $this->create_reader( 'Cancelled' );
		$this->create_individual_subscription( $active_id, 'active' );
		$this->create_individual_subscription( $cancelled_id, 'cancelled' );

		$data = $this->dispatch( [ 'status' => [ 'cancelled' ] ] )->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( $cancelled_id, $data['items'][0]['id'] );
	}

	/**
	 * A status filter also matches readers who only inherit that status through a
	 * group they belong to (owner and members alike).
	 */
	public function test_status_filter_matches_group_members_via_inheritance() {
		$this->login_admin();
		$owner_id  = $this->create_reader( 'GroupOwner' );
		$member_id = $this->create_reader( 'GroupMember' );
		$outsider  = $this->create_reader( 'Outsider' );
		$sub       = $this->create_group_subscription( $owner_id, 5, 'on-hold' );
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $sub->get_id() );
		$this->create_individual_subscription( $outsider, 'active' );

		$ids = array_column( $this->dispatch( [ 'status' => [ 'on-hold' ] ] )->get_data()['items'], 'id' );
		sort( $ids );
		$expected = [ $owner_id, $member_id ];
		sort( $expected );
		$this->assertSame( $expected, $ids, 'Owner and member inherit the on-hold group status; the active outsider is excluded.' );
	}

	/**
	 * A plan filter inverts to the members of the matching group.
	 */
	public function test_plan_filter_inverts_to_group_members() {
		$this->login_admin();
		$owner_id  = $this->create_reader( 'PlanOwner' );
		$member_id = $this->create_reader( 'PlanMember' );
		$other_id  = $this->create_reader( 'OtherPlan' );
		$sub       = $this->create_group_subscription( $owner_id, 5 ); // plan name "Acme Team".
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $sub->get_id() );
		$this->create_individual_subscription( $other_id, 'active' );

		$ids = array_column( $this->dispatch( [ 'plan' => [ 'Acme Team' ] ] )->get_data()['items'], 'id' );
		sort( $ids );
		$expected = [ $owner_id, $member_id ];
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	/**
	 * A plan filter also inverts to the holders of a matching individual
	 * subscription: the plan name resolves to its product, and every reader with a
	 * subscription on that product is included (the product-name branch, distinct
	 * from the group-plan branch above).
	 */
	public function test_plan_filter_matches_individual_product_subscribers() {
		$this->login_admin();
		$product_id = self::factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => 'Digital Monthly',
				'post_status' => 'publish',
			]
		);
		$digital_id = $this->create_reader( 'DigitalReader' );
		$this->create_individual_subscription( $digital_id, 'active', $product_id );

		// A reader on a different, unnamed product must not match.
		$other_id = $this->create_reader( 'OtherReader' );
		$this->create_individual_subscription( $other_id, 'active' );

		$ids = array_column( $this->dispatch( [ 'plan' => [ 'Digital Monthly' ] ] )->get_data()['items'], 'id' );
		$this->assertSame( [ $digital_id ], $ids, 'Only the reader on the named product matches the plan filter.' );
	}

	/**
	 * A gifted subscription belongs to the recipient: it shows on their row with its
	 * status, and the purchaser — who holds nothing else — has no subscription and
	 * no status.
	 */
	public function test_gift_lists_under_recipient_not_purchaser() {
		$this->login_admin();
		$purchaser_id = $this->create_reader( 'Purchaser' );
		$recipient_id = $this->create_reader( 'Recipient' );
		$gift         = $this->create_gifted_subscription( $purchaser_id, $recipient_id );

		$by_id = array_column( $this->dispatch()->get_data()['items'], null, 'id' );

		$this->assertSame( [ $gift->get_id() ], array_column( $by_id[ $recipient_id ]['subscriptions'], 'id' ) );
		$this->assertSame( 'active', $by_id[ $recipient_id ]['status'] );
		$this->assertSame( [], $by_id[ $purchaser_id ]['subscriptions'], 'A purchaser does not hold the gift they bought.' );
		$this->assertSame( '', $by_id[ $purchaser_id ]['status'], 'A purchaser with nothing else is not Active.' );
	}

	/**
	 * A purchaser with their own subscription keeps it, and stays Active on the
	 * strength of it — the gift they bought neither joins nor replaces it.
	 */
	public function test_purchaser_with_own_subscription_stays_active() {
		$this->login_admin();
		$purchaser_id = $this->create_reader( 'Purchaser' );
		$recipient_id = $this->create_reader( 'Recipient' );
		$own          = $this->create_individual_subscription( $purchaser_id, 'active' );
		$this->create_gifted_subscription( $purchaser_id, $recipient_id );

		$by_id = array_column( $this->dispatch()->get_data()['items'], null, 'id' );

		$this->assertSame( [ $own->get_id() ], array_column( $by_id[ $purchaser_id ]['subscriptions'], 'id' ) );
		$this->assertSame( 'active', $by_id[ $purchaser_id ]['status'] );
	}

	/**
	 * The status and plan filters follow the recipient, matching the row the
	 * recipient sees: they include the recipient and leave out a purchaser who has
	 * nothing else.
	 */
	public function test_status_and_plan_filters_follow_recipient() {
		$this->login_admin();
		$product_id   = self::factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => 'Gift Digital',
				'post_status' => 'publish',
			]
		);
		$purchaser_id = $this->create_reader( 'Purchaser' );
		$recipient_id = $this->create_reader( 'Recipient' );
		$this->create_gifted_subscription( $purchaser_id, $recipient_id, 'active', $product_id );

		$status_ids = array_column( $this->dispatch( [ 'status' => [ 'active' ] ] )->get_data()['items'], 'id' );
		$this->assertSame( [ $recipient_id ], $status_ids, 'The status filter matches the recipient, not the purchaser.' );

		$plan_ids = array_column( $this->dispatch( [ 'plan' => [ 'Gift Digital' ] ] )->get_data()['items'], 'id' );
		$this->assertSame( [ $recipient_id ], $plan_ids, 'The plan filter matches the recipient, not the purchaser.' );
	}

	/**
	 * A recipient's last payment includes the gift's last paid order, since the
	 * gift is theirs but the order sits on the purchaser's account.
	 */
	public function test_recipient_last_payment_reflects_gift() {
		$this->login_admin();
		$purchaser_id = $this->create_reader( 'Purchaser' );
		$recipient_id = $this->create_reader( 'Recipient' );
		$this->create_gifted_subscription( $purchaser_id, $recipient_id, 'active', 0, [ 'last_order_date_paid' => '2026-07-01 09:00:00' ] );

		$by_id = array_column( $this->dispatch()->get_data()['items'], null, 'id' );

		$this->assertSame( '2026-07-01', $by_id[ $recipient_id ]['lastPayment'] );
		$this->assertNull( $by_id[ $purchaser_id ]['lastPayment'], 'The purchaser has no paid order of their own here.' );
	}

	/**
	 * Which of a recipient's two payments is more recent: the gift's or their own.
	 *
	 * @return array[] Each row: the gift's last paid date, the recipient's own
	 *                 order's paid date, and the date the list should show.
	 */
	public function data_recipient_last_payment_orders() {
		return [
			'gift is later'      => [ '2026-08-15 09:00:00', '2026-07-01 09:00:00', '2026-08-15' ],
			'own order is later' => [ '2026-06-10 09:00:00', '2026-07-01 09:00:00', '2026-07-01' ],
		];
	}

	/**
	 * A recipient who also paid for something themselves shows whichever payment is
	 * more recent. Both orders are covered: a reading of the recipient's own orders
	 * alone gets the first wrong, and letting the gift replace their own order gets
	 * the second wrong.
	 *
	 * @dataProvider data_recipient_last_payment_orders
	 *
	 * @param string $gift_paid      When the gift's last order was paid.
	 * @param string $own_order_paid When the recipient's own order was paid.
	 * @param string $expected       The date the list should show.
	 */
	public function test_recipient_last_payment_is_the_latest_of_own_order_and_gift( $gift_paid, $own_order_paid, $expected ) {
		$this->login_admin();
		$purchaser_id = $this->create_reader( 'Purchaser' );
		$recipient_id = $this->create_reader( 'Recipient' );
		$this->create_gifted_subscription( $purchaser_id, $recipient_id, 'active', 0, [ 'last_order_date_paid' => $gift_paid ] );
		wc_create_order(
			[
				'customer_id' => $recipient_id,
				'status'      => 'completed',
				'total'       => 12.5,
				'date_paid'   => $own_order_paid,
			]
		);

		$by_id = array_column( $this->dispatch()->get_data()['items'], null, 'id' );

		$this->assertSame( $expected, $by_id[ $recipient_id ]['lastPayment'] );
	}

	/**
	 * Combining status and plan filters narrows (AND): only readers matching both.
	 */
	public function test_combined_filters_narrow_with_and_semantics() {
		$this->login_admin();
		$acme_owner  = $this->create_reader( 'AcmeOwner' );
		$acme_member = $this->create_reader( 'AcmeMember' );
		$beta_member = $this->create_reader( 'BetaMember' );

		$acme = $this->create_group_subscription( $acme_owner, 5, 'active' ); // "Acme Team".
		add_user_meta( $acme_member, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $acme->get_id() );

		// A second active group on a different plan — matches the status axis but not the plan axis.
		$beta = $this->create_group_subscription( $this->create_reader( 'BetaOwner' ), 5, 'active' );
		$beta->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', 'Beta Team' );
		add_user_meta( $beta_member, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $beta->get_id() );

		// A cancelled group on the SAME Acme plan — matches the plan axis but not the status axis.
		$cancelled_acme        = $this->create_group_subscription( $this->create_reader( 'CancelledAcmeOwner' ), 5, 'cancelled' );
		$cancelled_acme_member = $this->create_reader( 'CancelledAcmeMember' );
		add_user_meta( $cancelled_acme_member, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $cancelled_acme->get_id() );

		$ids = array_column(
			$this->dispatch(
				[
					'status' => [ 'active' ],
					'plan'   => [ 'Acme Team' ],
				]
			)->get_data()['items'],
			'id'
		);
		$this->assertContains( $acme_owner, $ids );
		$this->assertContains( $acme_member, $ids );
		$this->assertNotContains( $beta_member, $ids, 'A Beta-Team member is active but not on the Acme plan, so the AND filter excludes them (plan axis narrows).' );
		$this->assertNotContains( $cancelled_acme_member, $ids, 'A cancelled Acme member is on the plan but not active, so the AND filter excludes them (status axis narrows).' );
	}

	/**
	 * A filter that matches nobody short-circuits to an empty envelope (total 0,
	 * pages 0) rather than falling through to an unfiltered query.
	 */
	public function test_filter_matching_nobody_returns_empty_envelope() {
		$this->login_admin();
		$this->create_individual_subscription( $this->create_reader( 'Active' ), 'active' );

		$data = $this->dispatch( [ 'status' => [ 'pending' ] ] )->get_data();
		$this->assertSame( [], $data['items'] );
		$this->assertSame( 0, $data['total'] );
		$this->assertSame( 0, $data['pages'] );
	}

	/**
	 * The subscriber-level status reduces across all a reader's subscriptions:
	 * a live status wins over a cancelled one, cancelled-only reads cancelled,
	 * and a reader with no subscription has no status.
	 */
	public function test_reduced_status_prefers_live_over_cancelled() {
		$this->login_admin();

		$mixed_id = $this->create_reader( 'Mixed' );
		$this->create_individual_subscription( $mixed_id, 'active' );
		$this->create_individual_subscription( $mixed_id, 'cancelled' );

		$churned_id = $this->create_reader( 'Churned' );
		$this->create_individual_subscription( $churned_id, 'cancelled' );

		$free_id = $this->create_reader( 'Free' );

		$by_id = [];
		foreach ( $this->dispatch()->get_data()['items'] as $item ) {
			$by_id[ $item['id'] ] = $item;
		}

		$this->assertSame( 'active', $by_id[ $mixed_id ]['status'], 'A live plan wins over a cancelled one.' );
		$this->assertSame( 'cancelled', $by_id[ $churned_id ]['status'], 'A fully churned reader reads cancelled.' );
		$this->assertSame( '', $by_id[ $free_id ]['status'], 'A reader with no subscription has no status.' );
	}

	/**
	 * The avatars endpoint short-circuits when avatars are disabled and otherwise
	 * returns a per-email URL map, ignoring blank/invalid addresses.
	 */
	public function test_avatars_endpoint() {
		$this->login_admin();

		update_option( 'show_avatars', false );
		$off_request = new WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-subscribers/avatars' );
		$off_request->set_param( 'emails', [ 'reader@test.com' ] );
		$this->assertFalse( rest_get_server()->dispatch( $off_request )->get_data()['show'] );

		update_option( 'show_avatars', true );
		$on_request = new WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-subscribers/avatars' );
		$on_request->set_param( 'emails', [ 'reader@test.com', '' ] );
		$data = rest_get_server()->dispatch( $on_request )->get_data();
		$this->assertTrue( $data['show'] );
		$this->assertArrayHasKey( 'reader@test.com', $data['avatars'] );
		$this->assertArrayNotHasKey( '', $data['avatars'], 'Blank emails are dropped.' );
	}

	/**
	 * The avatars endpoint bounds its inputs: `size` is enumerated to 16–512 (so a
	 * caller can't ask core for an arbitrarily large render) and the email batch is
	 * capped, so an oversized payload can't fan out into unbounded avatar lookups.
	 */
	public function test_avatars_endpoint_bounds_its_inputs() {
		$this->login_admin();
		update_option( 'show_avatars', true );

		foreach ( [ 8, 1024 ] as $out_of_range_size ) {
			$request = new WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-subscribers/avatars' );
			$request->set_param( 'emails', [ 'reader@test.com' ] );
			$request->set_param( 'size', $out_of_range_size );
			$this->assertSame(
				400,
				rest_get_server()->dispatch( $request )->get_status(),
				"A size of $out_of_range_size is outside the 16-512 range the endpoint accepts."
			);
		}

		$in_range = new WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-subscribers/avatars' );
		$in_range->set_param( 'emails', [ 'reader@test.com' ] );
		$in_range->set_param( 'size', 128 );
		$this->assertSame( 200, rest_get_server()->dispatch( $in_range )->get_status() );

		// One over the cap: the overflow is dropped rather than resolved. The
		// client batches larger sets, so nothing is lost end to end.
		$cap      = \Newspack\Subscribers_Wizard::AVATAR_BATCH_CAP;
		$oversize = new WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-subscribers/avatars' );
		$oversize->set_param( 'emails', array_map( fn( $i ) => "reader$i@test.com", range( 0, $cap ) ) );
		$avatars = rest_get_server()->dispatch( $oversize )->get_data()['avatars'];
		$this->assertCount( $cap, $avatars );
		$this->assertArrayHasKey( 'reader0@test.com', $avatars );
		$this->assertArrayNotHasKey( "reader$cap@test.com", $avatars, 'Emails past the cap are dropped.' );
	}

	/**
	 * The Cancelled filter matches only fully-churned readers: a reader who holds
	 * both a cancelled and a live subscription reads as live (the badge hides
	 * cancelled), so the filter must not surface them — otherwise the results
	 * contradict the displayed status.
	 */
	public function test_cancelled_filter_excludes_readers_with_a_live_plan() {
		$this->login_admin();

		$mixed_id = $this->create_reader( 'MixedChurn' );
		$this->create_individual_subscription( $mixed_id, 'active' );
		$this->create_individual_subscription( $mixed_id, 'cancelled' );

		$churned_id = $this->create_reader( 'FullyChurned' );
		$this->create_individual_subscription( $churned_id, 'cancelled' );

		$cancelled_ids = array_column( $this->dispatch( [ 'status' => [ 'cancelled' ] ] )->get_data()['items'], 'id' );
		$this->assertContains( $churned_id, $cancelled_ids, 'A fully-churned reader matches the Cancelled filter.' );
		$this->assertNotContains( $mixed_id, $cancelled_ids, 'A reader with a live plan is excluded from Cancelled, matching the badge display.' );

		// The same reader still matches the Active filter (the live axis is unaffected).
		$active_ids = array_column( $this->dispatch( [ 'status' => [ 'active' ] ] )->get_data()['items'], 'id' );
		$this->assertContains( $mixed_id, $active_ids );
	}

	/**
	 * The filter-side status map is the inverse of the display-side one, and both
	 * have to stay in step or a filter contradicts the badge it filters on. The
	 * display direction is pinned in test_reduced_status_prefers_live_over_cancelled;
	 * this pins the inverse, positively — deleting an entry from wcs_statuses_for()'s
	 * map must fail a test, not just narrow the results silently.
	 */
	public function test_status_filter_maps_wcs_statuses_positively() {
		$this->login_admin();

		// 'pending' => [ 'pending' ], via an individual subscription and via group
		// inheritance (a member holds no subscription of their own).
		$pending_individual = $this->create_reader( 'PendingIndividual' );
		$this->create_individual_subscription( $pending_individual, 'pending' );

		$pending_group  = $this->create_group_subscription( $this->create_reader( 'PendingGroupOwner' ), 5, 'pending' );
		$pending_member = $this->create_reader( 'PendingGroupMember' );
		add_user_meta( $pending_member, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $pending_group->get_id() );

		// 'on-hold' => [ 'on-hold', 'switched' ]: a mid-switch subscription displays
		// as on-hold, so the On hold filter has to reach it too.
		$switched = $this->create_reader( 'Switched' );
		$this->create_individual_subscription( $switched, 'switched' );

		// Active also covers pending-cancel, and Cancelled also covers expired.
		$pending_cancel = $this->create_reader( 'PendingCancel' );
		$this->create_individual_subscription( $pending_cancel, 'pending-cancel' );

		$expired = $this->create_reader( 'Expired' );
		$this->create_individual_subscription( $expired, 'expired' );

		$pending_ids = array_column( $this->dispatch( [ 'status' => [ 'pending' ] ] )->get_data()['items'], 'id' );
		$this->assertContains( $pending_individual, $pending_ids );
		$this->assertContains( $pending_member, $pending_ids, 'A member of a pending group inherits its status on the filter axis too.' );

		$on_hold_ids = array_column( $this->dispatch( [ 'status' => [ 'on-hold' ] ] )->get_data()['items'], 'id' );
		$this->assertContains( $switched, $on_hold_ids, 'A switched subscription displays as on-hold, so On hold must match it.' );

		$active_ids = array_column( $this->dispatch( [ 'status' => [ 'active' ] ] )->get_data()['items'], 'id' );
		$this->assertContains( $pending_cancel, $active_ids, 'A pending-cancel subscription is still live until it lapses.' );

		$cancelled_ids = array_column( $this->dispatch( [ 'status' => [ 'cancelled' ] ] )->get_data()['items'], 'id' );
		$this->assertContains( $expired, $cancelled_ids, 'An expired subscription displays as cancelled, so Cancelled must match it.' );
	}

	/**
	 * The status filter scans subscriptions a chunk at a time. The walk has to
	 * advance: wcs_get_subscriptions() strips a `paged` argument before building
	 * its query, so paging with it silently re-scans the first chunk forever —
	 * readers past the chunk boundary vanish from the results, and (worse) a
	 * reader whose only live plan sits past it is wrongly reported as churned.
	 *
	 * Seeds one full chunk of active filler, then two readers whose live plans can
	 * only be found in the second chunk, so a walk that doesn't advance fails both
	 * assertions rather than merely returning fewer rows.
	 */
	public function test_status_filter_scan_advances_past_the_first_chunk() {
		$this->login_admin();

		// Filler subscriptions are owned by customer IDs with no user behind them:
		// the scan only reads get_customer_id(), and skipping 500 user inserts keeps
		// this test cheap. They fill the first chunk of the live-status scan.
		for ( $i = 0; $i < \Newspack\Subscribers_Wizard::FILTER_SCAN_CHUNK; $i++ ) {
			$this->create_individual_subscription( 900000 + $i, 'active' );
		}

		// Only subscription is active and sits in the second chunk.
		$late_active = $this->create_reader( 'LateActive' );
		$this->create_individual_subscription( $late_active, 'active' );

		// Cancelled plan in reach, live plan past the boundary. The Cancelled filter
		// means fully churned, so finding the live plan is what excludes them — miss
		// it and a paying reader is reported as churned.
		$late_mixed = $this->create_reader( 'LateMixed' );
		$this->create_individual_subscription( $late_mixed, 'cancelled' );
		$this->create_individual_subscription( $late_mixed, 'active' );

		$active_ids = array_column( $this->dispatch( [ 'status' => [ 'active' ] ] )->get_data()['items'], 'id' );
		$this->assertContains( $late_active, $active_ids, 'A reader whose only subscription sits past the first chunk still matches Active.' );

		$cancelled_ids = array_column( $this->dispatch( [ 'status' => [ 'cancelled' ] ] )->get_data()['items'], 'id' );
		$this->assertNotContains( $late_mixed, $cancelled_ids, 'A live plan past the chunk boundary still disqualifies the reader from Cancelled.' );
	}

	/**
	 * The plans endpoint lists the site's plan names: every group's configured name
	 * plus the name of every published, non-group subscription product. For a
	 * variable subscription that means its variations only, never the parent —
	 * individual_plan_name() resolves a subscription through
	 * wcs_get_canonical_product_id() to the variation, so a row displays
	 * "Digital Bundle - Annual" and never "Digital Bundle". Offering the parent
	 * would match those same subscriptions (WooCommerce matches a variation line
	 * item's parent _product_id) and return rows whose Subscription column reads
	 * something else, breaking the round trip this endpoint exists to guarantee.
	 *
	 * Names are deduplicated and alphabetised; non-subscription products are not
	 * plans, and neither are the products groups are sold on.
	 */
	public function test_plans_endpoint_lists_group_and_product_plans() {
		$this->login_admin();

		// Two groups sharing one plan name plus a second name: the dropdown must
		// list a plan once however many groups are configured with it. All three are
		// sold on the "Team Plan" group product, which must not become an option.
		$this->create_group_subscription( $this->create_reader( 'AcmeOwnerOne' ) ); // "Acme Team".
		$this->create_group_subscription( $this->create_reader( 'AcmeOwnerTwo' ) ); // "Acme Team" again.
		$beta = $this->create_group_subscription( $this->create_reader( 'BetaOwner' ) );
		$beta->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', 'Beta Team' );

		$this->create_subscription_product( 'Digital Monthly' );
		$variable_parent_id = $this->create_subscription_product( 'Digital Bundle', 'variable-subscription' );
		$this->create_subscription_product( 'Digital Bundle - Annual', 'subscription_variation', $variable_parent_id );
		$this->create_subscription_product( 'Tote Bag', 'simple' );

		$plans = $this->dispatch_plans()->get_data();

		$this->assertSame(
			[ 'Acme Team', 'Beta Team', 'Digital Bundle - Annual', 'Digital Monthly' ],
			$plans['items'],
			'Group names and non-group subscription product/variation names, deduplicated and alphabetised.'
		);
		$this->assertNotContains(
			'Digital Bundle',
			$plans['items'],
			'A variable subscription parent is not an option: no row ever displays it, so filtering on it would return rows showing a variation name instead.'
		);
		$this->assertNotContains( 'Tote Bag', $plans['items'], 'A non-subscription product is not a plan.' );
		$this->assertNotContains(
			'Team Plan',
			$plans['items'],
			'The product a group is sold on is not an option: members display the group name, so filtering on the product would return only the owners and no row would read as the filtered name.'
		);
		$this->assertSame( 4, $plans['total'] );
	}

	/**
	 * A group product is excluded whether it is sold as a whole product or as a
	 * variation of one, because group enablement is per product/variation and a
	 * variation does not inherit its parent's setting.
	 *
	 * The variable parent is absent for a different reason than its group-enabled
	 * variation: the parent name is never displayed on a row, so it is not an option
	 * on any variable subscription, group-enabled or not.
	 */
	public function test_plans_endpoint_excludes_group_variations() {
		$this->login_admin();

		$bundle_id = $this->create_subscription_product( 'Campus Bundle', 'variable-subscription' );
		$this->create_subscription_product( 'Campus Bundle - Individual', 'subscription_variation', $bundle_id );
		$this->create_subscription_product( 'Campus Bundle - Site Licence', 'subscription_variation', $bundle_id, true );

		$items = $this->dispatch_plans()->get_data()['items'];

		$this->assertContains( 'Campus Bundle - Individual', $items );
		$this->assertNotContains( 'Campus Bundle - Site Licence', $items, 'A group-enabled variation is not an option.' );
		$this->assertNotContains( 'Campus Bundle', $items, 'The variable parent is not an option either — no row displays it.' );
	}

	/**
	 * A retired variation is not offered as a filter option.
	 *
	 * WooCommerce stores a variation with "Enabled" unchecked as `private`, and
	 * get_children() returns those alongside published ones — so a publisher
	 * retiring one tier while its existing subscribers keep it would otherwise get a
	 * dropdown entry that can never match: the filter's other half resolves names
	 * through a `publish`-only query. The admin picks a plan they can see in the
	 * Subscription column of the rows below, and is told nobody holds it.
	 *
	 * The sibling test above covers the same rule for a whole product; this is the
	 * variation level, which get_children() reaches by a different path.
	 */
	public function test_plans_endpoint_omits_a_retired_variation() {
		$this->login_admin();

		$bundle_id = $this->create_subscription_product( 'Campus Bundle', 'variable-subscription' );
		$this->create_subscription_product( 'Campus Bundle - Current', 'subscription_variation', $bundle_id );
		$this->create_subscription_product( 'Campus Bundle - Retired', 'subscription_variation', $bundle_id, false, 'private' );

		$items = $this->dispatch_plans()->get_data()['items'];

		$this->assertContains( 'Campus Bundle - Current', $items );
		$this->assertNotContains( 'Campus Bundle - Retired', $items, 'A disabled variation cannot be matched by the filter, so it must not be offered by it.' );
	}

	/**
	 * Only published products are offered. The filter's other half resolves a plan
	 * name through a `publish`-only WP_Query, so listing a draft product's name
	 * would put an option in the dropdown that can never match a reader — and
	 * nothing else in this suite would notice, since a dead option looks exactly
	 * like a plan nobody holds.
	 */
	public function test_plans_endpoint_omits_unpublished_products() {
		$this->login_admin();

		$this->create_subscription_product( 'Published Monthly' );
		$this->create_subscription_product( 'Draft Monthly', 'subscription', 0, false, 'draft' );

		$items = $this->dispatch_plans()->get_data()['items'];

		$this->assertContains( 'Published Monthly', $items );
		$this->assertNotContains( 'Draft Monthly', $items );
	}

	/**
	 * The contract that makes the filter work end to end: every name the plans
	 * endpoint hands the UI filters to exactly the readers whose Subscription column
	 * shows that name. If the two ever drift — a differently-shaped label here, a
	 * product status the filter's title lookup won't match there — the dropdown
	 * silently offers options that mislead, so this walks the whole list rather than
	 * spot-checking one entry, and asserts the *displayed* plan of every row it gets
	 * back rather than trusting the ids.
	 *
	 * The group here is production-shaped: sold on a "Team Plan" product and renamed
	 * to "Acme Team" by its buyer. Offering the product would satisfy an ids-only
	 * assertion (the owner does hold a subscription on it) while returning one of
	 * the three members and showing "Acme Team" in the column of the one it does
	 * return, so the display assertion below is what makes the test bite.
	 */
	public function test_plan_names_round_trip_through_the_subscriber_filter() {
		$this->login_admin();

		$group_owner  = $this->create_reader( 'RoundTripOwner' );
		$group_member = $this->create_reader( 'RoundTripMember' );
		$group        = $this->create_group_subscription( $group_owner ); // "Acme Team" on "Team Plan".
		add_user_meta( $group_member, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group->get_id() );

		$product_id     = $this->create_subscription_product( 'Digital Monthly' );
		$product_reader = $this->create_reader( 'RoundTripDigital' );
		$this->create_individual_subscription( $product_reader, 'active', $product_id );

		// A variation-backed subscriber, because offering and matching a variation run
		// through different code — get_children() offers it, and a product_variation
		// clause in product_ids_for_names() matches it. Asserted in separate tests,
		// either half could break while the other stayed green.
		$bundle_id        = $this->create_subscription_product( 'Digital Bundle', 'variable-subscription' );
		$variation_id     = $this->create_subscription_product( 'Digital Bundle - Annual', 'subscription_variation', $bundle_id );
		$variation_reader = $this->create_reader( 'RoundTripVariation' );
		$this->create_individual_subscription( $variation_reader, 'active', $variation_id );

		$expected_holders = [
			'Acme Team'               => [ $group_owner, $group_member ],
			'Digital Bundle - Annual' => [ $variation_reader ],
			'Digital Monthly'         => [ $product_reader ],
		];

		$plan_names = $this->dispatch_plans()->get_data()['items'];
		$this->assertSame( array_keys( $expected_holders ), $plan_names, 'The group product "Team Plan" is not offered; the group name and the individual product are.' );

		foreach ( $plan_names as $plan_name ) {
			$items   = $this->dispatch( [ 'plan' => [ $plan_name ] ] )->get_data()['items'];
			$matched = array_column( $items, 'id' );
			sort( $matched );
			$expected = $expected_holders[ $plan_name ];
			sort( $expected );
			$this->assertSame( $expected, $matched, "The '$plan_name' option returned by /plans filters to its holders." );

			foreach ( $items as $item ) {
				$displayed = array_merge( array_column( $item['groups'], 'plan' ), array_column( $item['subscriptions'], 'plan' ) );
				$this->assertContains( $plan_name, $displayed, "A row returned for '$plan_name' displays that plan, rather than some other name." );
			}
		}
	}

	/**
	 * A cancelled plan filters to the readers who still show it, and only them.
	 *
	 * The Subscription column hides a cancelled plan for anyone who also holds a live
	 * one (visiblePlanEntries in SubscriberList.jsx), so a reader who churned off one
	 * plan and onto another must not come back under the plan they left: their row
	 * would read as the plan they moved to. A fully churned reader still shows the
	 * cancelled plan, so they must. customer_ids_for_statuses() applies the same rule
	 * on the status axis; this pins it on the plan axis, where an all-active fixture
	 * cannot see it.
	 */
	public function test_a_cancelled_plan_matches_only_a_fully_churned_reader() {
		$this->login_admin();

		$monthly_id = $this->create_subscription_product( 'Digital Monthly' );
		$annual_id  = $this->create_subscription_product( 'Digital Annual' );

		$switcher = $this->create_reader( 'Switcher' );
		$this->create_individual_subscription( $switcher, 'cancelled', $monthly_id );
		$this->create_individual_subscription( $switcher, 'active', $annual_id );

		$churned = $this->create_reader( 'Churned' );
		$this->create_individual_subscription( $churned, 'cancelled', $monthly_id );

		$matched = array_column( $this->dispatch( [ 'plan' => [ 'Digital Monthly' ] ] )->get_data()['items'], 'id' );

		$this->assertSame( [ $churned ], $matched, 'Only the reader whose row still shows the cancelled plan is returned.' );
		$this->assertNotContains( $switcher, $matched, 'A reader who moved to a live plan is not returned under the plan they left.' );
	}

	/**
	 * The Complimentary filter keeps comp readers listed and finds them by the stamp,
	 * reduced like the plan filter: a reader who moved from a comp to a paid plan is
	 * not returned, one whose only, cancelled, subscription was a comp is. A comp
	 * group's members inherit its stamp. Each row marks which of its subscriptions is
	 * the comp, so the Access column agrees with the filter.
	 */
	public function test_complimentary_filter_matches_stamped_subscriptions() {
		$this->login_admin();
		$stamp = function ( WC_Subscription $subscription ) {
			$subscription->update_meta_data( \Newspack\Complimentary_Access::META_KEY, 'yes' );
			return $subscription;
		};

		$comp = $this->create_reader( 'Comp' );
		$stamp( $this->create_individual_subscription( $comp ) );
		$this->create_individual_subscription( $comp );

		$moved_on = $this->create_reader( 'Moved' );
		$stamp( $this->create_individual_subscription( $moved_on, 'cancelled' ) );
		$this->create_individual_subscription( $moved_on );

		$churned = $this->create_reader( 'Churned' );
		$stamp( $this->create_individual_subscription( $churned, 'cancelled' ) );

		$paying = $this->create_reader( 'Paying' );
		$this->create_individual_subscription( $paying );

		$group_owner  = $this->create_reader( 'CompGroupOwner' );
		$group_member = $this->create_reader( 'CompGroupMember' );
		$group        = $stamp( $this->create_group_subscription( $group_owner ) );
		add_user_meta( $group_member, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group->get_id() );

		$items = $this->dispatch( [ 'access' => [ 'complimentary' ] ] )->get_data()['items'];
		$this->assertEqualsCanonicalizing( [ $comp, $churned, $group_owner, $group_member ], array_column( $items, 'id' ) );

		$comp_row = current( wp_list_filter( $items, [ 'id' => $comp ] ) );
		$this->assertEqualsCanonicalizing( [ true, false ], array_column( $comp_row['subscriptions'], 'isComplimentary' ) );
		$member_row = current( wp_list_filter( $items, [ 'id' => $group_member ] ) );
		$this->assertTrue( $member_row['groups'][0]['isComplimentary'] );
	}

	/**
	 * A plan name that matches nothing on the site fails closed — an empty page,
	 * not the unfiltered list. A stale option in a long-open tab (or a hand-typed
	 * param) must never widen the result set. The guard predates the plan filter's
	 * options; it is here so a change to the filter cannot quietly make an unmatched
	 * name mean "no filter".
	 */
	public function test_unknown_plan_filter_returns_an_empty_page() {
		$this->login_admin();
		$this->create_individual_subscription( $this->create_reader( 'Subscribed' ), 'active' );

		$data = $this->dispatch( [ 'plan' => [ 'No Such Plan' ] ] )->get_data();
		$this->assertSame( [], $data['items'] );
		$this->assertSame( 0, $data['total'] );
		$this->assertSame( 0, $data['pages'] );
	}

	/**
	 * The plans endpoint enforces the same manage_options gate as the list endpoints.
	 */
	public function test_plans_forbidden_for_non_admin() {
		wp_set_current_user( $this->create_reader( 'NopePlans', 'subscriber' ) );
		$this->assertSame( 403, $this->dispatch_plans()->get_status() );
	}

	/**
	 * Tags come from the reader's own record, not from the connected ESP. A stored
	 * array and a JSON-encoded list both read back as a list of tags — the second
	 * is what a value written via WP-CLI or the REST meta API looks like on disk,
	 * and reading it naively yields one tag literally named `["vip","npr"]`.
	 */
	public function test_tags_hydrate_from_local_reader_meta() {
		$this->login_admin();
		$array_reader = $this->create_reader( 'ArrayTags' );
		$json_reader  = $this->create_reader( 'JsonTags' );
		$bare_reader  = $this->create_reader( 'NoTags' );
		update_user_meta( $array_reader, \Newspack\Subscribers_Wizard::READER_TAGS_META, [ 'vip', 'met-in-person', '0' ] );
		update_user_meta( $json_reader, \Newspack\Subscribers_Wizard::READER_TAGS_META, wp_json_encode( [ 'vip', 'vip', 'lapsed' ] ) );

		$tags_by_id = array_column( $this->dispatch()->get_data()['items'], 'tags', 'id' );

		$this->assertSame(
			[ 'vip', 'met-in-person', '0' ],
			$tags_by_id[ $array_reader ],
			'Empty tags are dropped, and only those — a tag named "0" is a tag.'
		);
		$this->assertSame( [ 'vip', 'lapsed' ], $tags_by_id[ $json_reader ], 'A JSON-encoded value is decoded, and duplicates collapse.' );
		$this->assertSame( [], $tags_by_id[ $bare_reader ] );
	}

	/**
	 * The Newsletters column reports the site's own record of what a reader is
	 * subscribed to (the `newsletter_subscribed_lists` reader-data item), resolved
	 * to the titles the site shows for those lists. A list the site has no
	 * definition for reports a null title and keeps its ID, rather than vanishing
	 * from the column or being flattened into prose the client cannot match on.
	 */
	public function test_newsletters_hydrate_from_local_subscription_state() {
		$this->login_admin();
		$daily_list_id  = $this->create_newsletter_list( 'Daily Brief' );
		$weekly_list_id = $this->create_newsletter_list( 'Weekend Read' );

		$subscribed_reader   = $this->create_reader( 'Subscribed' );
		$unsubscribed_reader = $this->create_reader( 'Unsubscribed' );
		// Written through the same path the newsletter data events use.
		\Newspack\Reader_Data::update_item(
			$subscribed_reader,
			'newsletter_subscribed_lists',
			wp_json_encode( [ $daily_list_id, $weekly_list_id, 'esp-only-list' ] )
		);

		$newsletters_by_id = array_column( $this->dispatch()->get_data()['items'], 'newsletters', 'id' );

		$this->assertSame(
			[
				[
					'id'    => (string) $daily_list_id,
					'title' => 'Daily Brief',
				],
				[
					'id'    => (string) $weekly_list_id,
					'title' => 'Weekend Read',
				],
				[
					'id'    => 'esp-only-list',
					'title' => null,
				],
			],
			$newsletters_by_id[ $subscribed_reader ],
			'Known lists resolve to their titles; a list the site has no record of keeps its ID with a null title, so the client can tell the two apart and a filter has something to match on.'
		);
		$this->assertSame( [], $newsletters_by_id[ $unsubscribed_reader ] );
	}

	/**
	 * Subscriptions are deduplicated by list ID, before the ID is resolved to a
	 * title. Two lists can share a title — collapsing on the title would report one
	 * subscription where the reader has two — and a list titled "0" is a list.
	 */
	public function test_newsletters_dedupe_by_list_id_not_by_resolved_title() {
		$this->login_admin();
		$morning_brief = $this->create_newsletter_list( 'Daily Brief' );
		$evening_brief = $this->create_newsletter_list( 'Daily Brief' );
		$zero_titled   = $this->create_newsletter_list( '0' );

		$reader = $this->create_reader( 'Duplicated' );
		\Newspack\Reader_Data::update_item(
			$reader,
			'newsletter_subscribed_lists',
			wp_json_encode( [ $morning_brief, $evening_brief, $morning_brief, $zero_titled ] )
		);

		$newsletters_by_id = array_column( $this->dispatch()->get_data()['items'], 'newsletters', 'id' );

		$this->assertSame(
			[
				[
					'id'    => (string) $morning_brief,
					'title' => 'Daily Brief',
				],
				[
					'id'    => (string) $evening_brief,
					'title' => 'Daily Brief',
				],
				[
					'id'    => (string) $zero_titled,
					'title' => '0',
				],
			],
			$newsletters_by_id[ $reader ],
			'The repeated list ID collapses; two same-titled lists and a list titled "0" all survive.'
		);
	}

	/**
	 * Last seen reports the reader's `last_active` record — the value reader
	 * activation stamps on every page view, and the same one the ESP sync publishes
	 * as `Last_Active`, so this column and the publisher's ESP agree about a reader.
	 *
	 * Reader-data timestamps are JavaScript **milliseconds**. Reading one as a Unix
	 * timestamp doesn't fail loudly — it silently dates every active reader to the
	 * year 57000 — so the conversion is the thing worth pinning here.
	 */
	public function test_last_seen_reads_the_readers_last_active_record() {
		$this->login_admin();
		$active_reader  = $this->create_reader( 'Active' );
		$dormant_reader = $this->create_reader( 'Dormant' );
		$garbled_reader = $this->create_reader( 'Garbled' );
		\Newspack\Reader_Data::update_item( $active_reader, 'last_active', (string) ( strtotime( '2025-11-20 17:30:00' ) * 1000 ) );
		// last_active is client-written (it is not a read-only key), so a junk value
		// has to read as unknown rather than as 1970.
		\Newspack\Reader_Data::update_item( $garbled_reader, 'last_active', 'not-a-timestamp' );

		$last_seen_by_id = array_column( $this->dispatch()->get_data()['items'], 'lastSeen', 'id' );

		$this->assertSame( '2025-11-20', $last_seen_by_id[ $active_reader ] );
		$this->assertNull( $last_seen_by_id[ $dormant_reader ], 'A reader the site holds no activity record for reads as unknown, not as a guessed date.' );
		$this->assertNull( $last_seen_by_id[ $garbled_reader ] );
	}

	/**
	 * A last-seen record ahead of the site's own clock is not taken at face value.
	 *
	 * The browser writes this value from its own clock and the client store keeps
	 * the larger of the stored and local values, so a device running fast writes a
	 * timestamp nothing can lower again: without a server-side line, one visit from
	 * a misconfigured device parks the reader in the future for good. Ordinary skew
	 * still reads as now, so a clock a minute fast doesn't blank the column.
	 */
	public function test_last_seen_does_not_trust_a_clock_ahead_of_the_site() {
		$this->login_admin();
		$skewed_reader = $this->create_reader( 'Skewed' );
		$future_reader = $this->create_reader( 'Future' );
		\Newspack\Reader_Data::update_item( $skewed_reader, 'last_active', (string) ( ( time() + MINUTE_IN_SECONDS ) * 1000 ) );
		\Newspack\Reader_Data::update_item( $future_reader, 'last_active', (string) ( strtotime( '+3 years' ) * 1000 ) );

		$last_seen_by_id = array_column( $this->dispatch()->get_data()['items'], 'lastSeen', 'id' );

		$this->assertSame( wp_date( 'Y-m-d' ), $last_seen_by_id[ $skewed_reader ], 'A slightly fast clock reads as now, not as tomorrow.' );
		$this->assertNull( $last_seen_by_id[ $future_reader ], 'A record years ahead describes the device clock, not the reader, so it reads as unknown.' );
	}

	/**
	 * Last seen is the calendar day in the PUBLISHER'S timezone, not UTC.
	 *
	 * It sits in the same row as Member since and Last payment, which are localized,
	 * so formatting this one in UTC puts an evening visit on a negative-offset site
	 * a day ahead of the others — the admin looking at the screen that evening reads
	 * tomorrow's date, and the relative subtitle the list renders from it says the
	 * reader was last seen "in 4 hours".
	 *
	 * The default-timezone test above cannot see this: with the site on UTC both
	 * formattings agree, so it passes either way.
	 */
	public function test_last_seen_is_the_publishers_calendar_day_not_utc() {
		update_option( 'timezone_string', 'America/New_York' );
		$this->login_admin();
		$reader = $this->create_reader( 'Evening' );
		// 20:00 on Nov 20 in New York is already Nov 21 in UTC.
		\Newspack\Reader_Data::update_item( $reader, 'last_active', (string) ( strtotime( '2025-11-21 01:00:00 UTC' ) * 1000 ) );

		$last_seen_by_id = array_column( $this->dispatch()->get_data()['items'], 'lastSeen', 'id' );

		$this->assertSame(
			'2025-11-20',
			$last_seen_by_id[ $reader ],
			'An 8pm visit in the site timezone is reported as that day, not as the next day in UTC.'
		);
	}

	/**
	 * Hydrating a full page costs the same number of database queries as hydrating
	 * a single row, and resolves the shared newsletter-list registry once for the
	 * whole page rather than once per reader.
	 *
	 * This is the guard that keeps the list usable on a real reader base, and it
	 * takes both assertions to hold the line. The query count catches a lookup
	 * that genuinely re-queries per row; the registry-call count catches a per-row
	 * lookup that an in-request cache happens to absorb today but that still
	 * rebuilds the whole index 50 times, and would go back to being a query per
	 * row the moment that caching changed.
	 *
	 * The fixture gives every reader newsletters, tags and a session, so all three
	 * of the lookups this slice added are exercised.
	 */
	public function test_a_full_page_costs_no_more_queries_than_a_single_row() {
		global $newsletter_lists_query_count;
		$this->login_admin();
		$list_id = $this->create_newsletter_list( 'Daily Brief' );
		for ( $i = 0; $i < 50; $i++ ) {
			$this->create_hydrated_reader( 'Crowd' . $i, $list_id );
		}

		// Patches::prevent_accidental_page_deletion() runs on every meta capability
		// check and looks its first argument up as a post, so each row's
		// get_edit_user_link() spends a post query on a user ID. That per-row cost
		// is the guard's own and predates this endpoint; #964 restores its early
		// bail. Detach it for the measurement so this test keeps measuring the
		// hydration it is about.
		remove_filter( 'map_meta_cap', [ \Newspack\Patches::class, 'prevent_accidental_page_deletion' ], 10 );

		// One fixture, two page sizes, so the only thing that differs between the
		// measurements is how many rows get hydrated.
		$single_row_cost = $this->measure_query_cost( [ 'per_page' => 1 ] );
		$full_page_cost  = $this->measure_query_cost( [ 'per_page' => 50 ] );

		$newsletter_lists_query_count = 0;
		$full_page                    = $this->dispatch( [ 'per_page' => 50 ] )->get_data();

		$this->assertCount( 50, $full_page['items'], 'The measured page really is 50 rows.' );
		$this->assertSame( [ 'Daily Brief' ], array_column( $full_page['items'][0]['newsletters'], 'title' ), 'The page really is hydrating the batched columns.' );
		$this->assertSame(
			$single_row_cost,
			$full_page_cost,
			'Hydrating 50 rows must not cost more queries than hydrating 1 for the columns this measures — a per-row lookup crept in. Subscription hydration is an in-memory mock here and contributes nothing to either side.'
		);
		$this->assertSame(
			1,
			$newsletter_lists_query_count,
			'The newsletter list registry must be resolved once per request, not once per row.'
		);
	}

	/**
	 * A reader whose only tie to the site is a completed one-time donation reads as
	 * a One-time donor: the flag the donation set, and no subscription.
	 */
	public function test_one_time_donor_is_labelled_one_time() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Giver' );
		$this->create_donation_order( $reader_id, $this->create_donation_product( 'simple' ) );
		$this->flag_donor( $reader_id );

		$item = $this->list_row( $reader_id );

		$this->assertSame( 'one-time', $item['donor']['kind'] );
		$this->assertArrayHasKey( 'lastDonation', $item['donor'] );
		$this->assertSame( [], $item['subscriptions'], 'A one-time donor holds no subscription.' );
	}

	/**
	 * A live donation subscription makes a Recurring donor. On hold counts as live,
	 * as it does everywhere else in the dashboard, and so does a subscription that
	 * is set to end but still entitles (pending-cancel maps to active).
	 *
	 * @dataProvider data_live_donation_statuses
	 *
	 * @param string $status The raw WooCommerce Subscriptions status.
	 */
	public function test_recurring_donor_is_labelled_recurring( string $status ) {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Monthly' );
		$this->create_individual_subscription( $reader_id, $status, $this->create_donation_product() );

		$this->assertSame( 'recurring', $this->list_row( $reader_id )['donor']['kind'] );
	}

	/**
	 * Raw statuses that map to a live donation.
	 *
	 * @return array[]
	 */
	public function data_live_donation_statuses() {
		return [
			'active'         => [ 'active' ],
			'on-hold'        => [ 'on-hold' ],
			'pending-cancel' => [ 'pending-cancel' ],
		];
	}

	/**
	 * An ended donation subscription makes a Former donor, whether it was cancelled
	 * or ran out.
	 *
	 * @dataProvider data_ended_donation_statuses
	 *
	 * @param string $status The raw WooCommerce Subscriptions status.
	 */
	public function test_former_donor_from_an_ended_donation_subscription( string $status ) {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Lapsed' );
		$this->create_individual_subscription( $reader_id, $status, $this->create_donation_product() );

		$this->assertSame( 'former', $this->list_row( $reader_id )['donor']['kind'] );
	}

	/**
	 * Raw statuses that map to an ended donation.
	 *
	 * @return array[]
	 */
	public function data_ended_donation_statuses() {
		return [
			'cancelled' => [ 'cancelled' ],
			'expired'   => [ 'expired' ],
		];
	}

	/**
	 * The former-donor flag alone is enough: the subscription it came from may
	 * have been deleted, or never existed on this site.
	 */
	public function test_former_donor_from_the_flag_alone() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Flagged' );
		$this->flag_former_donor( $reader_id );

		$this->assertSame( 'former', $this->list_row( $reader_id )['donor']['kind'] );
	}

	/**
	 * Giving once more after cancelling a recurring donation sets is_donor again,
	 * but the ended subscription still wins: Former beats One-time.
	 */
	public function test_cancelled_recurring_then_a_one_time_gift_reads_former() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Returned' );
		$this->create_individual_subscription( $reader_id, 'cancelled', $this->create_donation_product() );
		$this->flag_donor( $reader_id );

		$this->assertSame( 'former', $this->list_row( $reader_id )['donor']['kind'] );
	}

	/**
	 * A pending donation subscription never took a payment, so it counts toward no
	 * kind: it neither makes a donor nor stops a real one-time donor reading as
	 * One-time.
	 */
	public function test_a_pending_donation_subscription_does_not_count() {
		$this->login_admin();
		$product_id = $this->create_donation_product();
		$donor_id   = $this->create_reader( 'Donor With Pending' );
		$this->create_individual_subscription( $donor_id, 'pending', $product_id );
		$this->flag_donor( $donor_id );
		$pending_only_id = $this->create_reader( 'Pending Only' );
		$this->create_individual_subscription( $pending_only_id, 'pending', $product_id );

		$by_id = array_column( $this->dispatch()->get_data()['items'], null, 'id' );

		$this->assertSame( 'one-time', $by_id[ $donor_id ]['donor']['kind'] );
		$this->assertSame( '', $by_id[ $pending_only_id ]['donor']['kind'] );
	}

	/**
	 * A reader with only a regular plan is not a donor: the key is there, the kind
	 * is empty.
	 */
	public function test_a_non_donor_has_no_kind() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Plain' );
		$this->create_individual_subscription( $reader_id, 'active', $this->create_subscription_product( 'Digital' ) );

		$this->assertSame( '', $this->list_row( $reader_id )['donor']['kind'] );
	}

	/**
	 * A One-time donor's row links their latest completed donation order. Orders for
	 * other products, even newer ones, are skipped.
	 */
	public function test_last_donation_is_the_latest_completed_donation_order() {
		$this->login_admin();
		$donation_product_id = $this->create_donation_product( 'simple' );
		$other_product_id    = $this->create_subscription_product( 'Digital', 'simple' );
		$reader_id           = $this->create_reader( 'Giver' );
		$this->flag_donor( $reader_id );
		$this->create_donation_order( $reader_id, $donation_product_id, 'completed', '2026-03-01 12:00:00' );
		$june_order = $this->create_donation_order( $reader_id, $donation_product_id, 'completed', '2026-06-01 12:00:00' );
		$this->create_donation_order( $reader_id, $other_product_id, 'completed', '2026-07-01 12:00:00' );

		$last_donation = $this->list_row( $reader_id )['donor']['lastDonation'];

		$this->assertNotNull( $last_donation );
		$this->assertSame( '2026-06-01', $last_donation['date'] );
		$this->assertSame( $june_order->get_edit_order_url(), $last_donation['editUrl'] );
	}

	/**
	 * The flag is enough to read as One-time, but with no order behind it there is
	 * nothing to link.
	 */
	public function test_a_flag_only_one_time_donor_has_no_last_donation() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Flag Only' );
		$this->flag_donor( $reader_id );

		$donor = $this->list_row( $reader_id )['donor'];

		$this->assertSame( 'one-time', $donor['kind'] );
		$this->assertNull( $donor['lastDonation'] );
	}

	/**
	 * Only a completed order counts as a gift. A processing one may still fail.
	 */
	public function test_a_processing_donation_order_is_not_a_last_donation() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Waiting' );
		$this->flag_donor( $reader_id );
		$this->create_donation_order( $reader_id, $this->create_donation_product( 'simple' ), 'processing' );

		$this->assertNull( $this->list_row( $reader_id )['donor']['lastDonation'] );
	}

	/**
	 * The lookup reads a bounded number of the reader's latest completed orders. A
	 * donation older than all of them is not found: the row still reads One-time,
	 * with nothing to link, instead of the page paying for an unbounded scan.
	 */
	public function test_last_donation_beyond_the_scan_limit_is_null() {
		$this->login_admin();
		$reader_id        = $this->create_reader( 'Long Ago' );
		$other_product_id = $this->create_subscription_product( 'Digital', 'simple' );
		$this->flag_donor( $reader_id );
		$this->create_donation_order( $reader_id, $this->create_donation_product( 'simple' ), 'completed', '2026-01-01 12:00:00' );
		$this->create_newer_orders( $reader_id, $other_product_id, Subscribers_Donors::ORDER_SCAN_LIMIT );

		$donor = $this->list_row( $reader_id )['donor'];

		$this->assertSame( 'one-time', $donor['kind'] );
		$this->assertNull( $donor['lastDonation'] );
	}

	/**
	 * One order inside the bound is still found, so the limit is not off by one.
	 */
	public function test_last_donation_at_the_edge_of_the_scan_limit_is_found() {
		$this->login_admin();
		$reader_id        = $this->create_reader( 'Just In Range' );
		$other_product_id = $this->create_subscription_product( 'Digital', 'simple' );
		$this->flag_donor( $reader_id );
		$donation = $this->create_donation_order( $reader_id, $this->create_donation_product( 'simple' ), 'completed', '2026-01-01 12:00:00' );
		$this->create_newer_orders( $reader_id, $other_product_id, Subscribers_Donors::ORDER_SCAN_LIMIT - 1 );

		$last_donation = $this->list_row( $reader_id )['donor']['lastDonation'];

		$this->assertNotNull( $last_donation );
		$this->assertSame( $donation->get_edit_order_url(), $last_donation['editUrl'] );
	}

	/**
	 * Only a One-time donor's row looks up orders for the donor label. A non-donor,
	 * a Recurring donor and a Former donor add none, so the page costs the same as
	 * it does with donor labels off.
	 */
	public function test_other_rows_add_no_order_lookups() {
		global $wc_mocks_get_orders_calls;
		$this->login_admin();
		$donation_product_id = $this->create_donation_product();
		$this->create_reader( 'Plain' );
		$recurring_id = $this->create_reader( 'Recurring' );
		$this->create_individual_subscription( $recurring_id, 'active', $donation_product_id );
		$former_id = $this->create_reader( 'Former' );
		$this->create_individual_subscription( $former_id, 'cancelled', $donation_product_id );

		$wc_mocks_get_orders_calls = 0;
		$with_donor_labels         = $this->dispatch()->get_data();
		$lookups_with_labels       = $wc_mocks_get_orders_calls;

		update_option( Donations::NEWSPACK_READER_REVENUE_PLATFORM, 'nrh' );
		try {
			$wc_mocks_get_orders_calls = 0;
			$this->dispatch();
			$lookups_without_labels = $wc_mocks_get_orders_calls;
		} finally {
			delete_option( Donations::NEWSPACK_READER_REVENUE_PLATFORM );
		}

		$this->assertCount( 3, $with_donor_labels['items'], 'The measured page is the three readers.' );
		$this->assertGreaterThan( 0, $lookups_without_labels, 'The row already looks up its last payment, so the comparison has a baseline.' );
		$this->assertSame( $lookups_without_labels, $lookups_with_labels );
	}

	/**
	 * Each subscription entry says whether it is a donation, so the client can tell
	 * a donation row apart from a plan.
	 */
	public function test_subscription_entries_mark_donations() {
		$this->login_admin();
		$reader_id    = $this->create_reader( 'Both' );
		$donation     = $this->create_individual_subscription( $reader_id, 'active', $this->create_donation_product() );
		$regular_plan = $this->create_individual_subscription( $reader_id, 'active', $this->create_subscription_product( 'Digital' ) );

		$entries = array_column( $this->list_row( $reader_id )['subscriptions'], null, 'id' );

		$this->assertTrue( $entries[ $donation->get_id() ]['isDonation'] );
		$this->assertFalse( $entries[ $regular_plan->get_id() ]['isDonation'] );
	}

	/**
	 * Off WooCommerce, the donor flag can be set from the browser and can't tell
	 * one-time from recurring, so the row carries no donor key at all.
	 */
	public function test_no_donor_key_when_donations_are_not_woocommerce() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Elsewhere' );
		$this->flag_donor( $reader_id );
		$this->assertSame( 'one-time', $this->list_row( $reader_id )['donor']['kind'], 'The same reader is labelled while WooCommerce takes the donations.' );

		update_option( Donations::NEWSPACK_READER_REVENUE_PLATFORM, 'nrh' );
		try {
			$item = $this->list_row( $reader_id );
		} finally {
			delete_option( Donations::NEWSPACK_READER_REVENUE_PLATFORM );
		}

		$this->assertArrayNotHasKey( 'donor', $item );
	}

	/**
	 * Readers of every donor kind, including the cases where the rules compete, so
	 * the Donor filter can be checked against what each row says.
	 *
	 * Recurring donors carry the `is_donor` flag too, as they do in production
	 * (their first payment fires `donation_new`), so the One-time set is only right
	 * if it leaves them out.
	 *
	 * @return array<string,array<string,int>> Reader IDs by label, grouped by the
	 *                                         Donor filter value their row matches
	 *                                         ('none' for a non-donor).
	 */
	private function create_donor_fixture(): array {
		$donation_product_id = $this->create_donation_product();

		$one_time = $this->create_reader( 'One Time' );
		$this->create_donation_order( $one_time, $this->create_donation_product( 'simple' ) );
		$this->flag_donor( $one_time );
		$one_time_with_pending = $this->create_reader( 'One Time With Pending' );
		$this->create_individual_subscription( $one_time_with_pending, 'pending', $donation_product_id );
		$this->flag_donor( $one_time_with_pending );

		$recurring = $this->create_reader( 'Recurring' );
		$this->create_individual_subscription( $recurring, 'active', $donation_product_id );
		$this->flag_donor( $recurring );
		$recurring_on_hold = $this->create_reader( 'Recurring On Hold' );
		$this->create_individual_subscription( $recurring_on_hold, 'on-hold', $donation_product_id );
		$this->flag_donor( $recurring_on_hold );
		// Cancelled, then gave monthly again: the former flag is still set.
		$resubscribed = $this->create_reader( 'Resubscribed' );
		$this->flag_former_donor( $resubscribed );
		$this->create_individual_subscription( $resubscribed, 'active', $donation_product_id );
		$live_and_ended = $this->create_reader( 'Live And Ended' );
		$this->create_individual_subscription( $live_and_ended, 'cancelled', $donation_product_id );
		$this->create_individual_subscription( $live_and_ended, 'active', $donation_product_id );

		// Neither ended subscription carries a flag, so the subscription alone has to count.
		$cancelled = $this->create_reader( 'Cancelled' );
		$this->create_individual_subscription( $cancelled, 'cancelled', $donation_product_id );
		$expired = $this->create_reader( 'Expired' );
		$this->create_individual_subscription( $expired, 'expired', $donation_product_id );
		$flag_only = $this->create_reader( 'Former Flag Only' );
		$this->flag_former_donor( $flag_only );
		$gave_again = $this->create_reader( 'Gave Again' );
		$this->create_individual_subscription( $gave_again, 'cancelled', $donation_product_id );
		$this->flag_donor( $gave_again );

		$non_donor    = $this->create_reader( 'Non Donor' );
		$pending_only = $this->create_reader( 'Pending Only' );
		$this->create_individual_subscription( $pending_only, 'pending', $donation_product_id );

		return [
			'one-time'  => compact( 'one_time', 'one_time_with_pending' ),
			'recurring' => compact( 'recurring', 'recurring_on_hold', 'resubscribed', 'live_and_ended' ),
			'former'    => compact( 'cancelled', 'expired', 'flag_only', 'gave_again' ),
			'none'      => compact( 'non_donor', 'pending_only' ),
		];
	}

	/**
	 * The reader IDs in one or more groups of the donor fixture, sorted the way
	 * filtered_ids() returns them.
	 *
	 * @param array<string,int> ...$groups Groups of the donor fixture.
	 *
	 * @return int[]
	 */
	private static function sorted_ids( array ...$groups ): array {
		$ids = array_merge( ...array_map( 'array_values', $groups ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Each Donor filter option on its own lists exactly the readers of that kind.
	 * "Not a donor" lists everyone outside the three kinds, including a reader
	 * whose only donation subscription never took a payment.
	 *
	 * @dataProvider data_donor_kinds
	 *
	 * @param string $kind The Donor filter value.
	 */
	public function test_donor_filter_lists_each_kind( string $kind ) {
		$this->login_admin();
		$fixture = $this->create_donor_fixture();

		$this->assertSame( self::sorted_ids( $fixture[ $kind ] ), $this->filtered_ids( [ 'donor' => [ $kind ] ] ) );
	}

	/**
	 * Each Donor filter value.
	 *
	 * @return array[]
	 */
	public function data_donor_kinds() {
		return [
			'one-time'  => [ 'one-time' ],
			'recurring' => [ 'recurring' ],
			'former'    => [ 'former' ],
			'none'      => [ 'none' ],
		];
	}

	/**
	 * The filter and the badge never disagree: every reader an option returns has
	 * that kind on their row, and no reader of that kind is left out. The fixture
	 * includes the readers where the rules compete: Resubscribed and Live And
	 * Ended must read Recurring and stay out of Former, and Gave Again must read
	 * Former and stay out of One-time.
	 */
	public function test_donor_filter_matches_the_row_kind() {
		$this->login_admin();
		$fixture = $this->create_donor_fixture();

		foreach ( $fixture as $kind => $readers ) {
			$row_kind = 'none' === $kind ? '' : $kind;
			$items    = $this->dispatch( [ 'donor' => [ $kind ] ] )->get_data()['items'];
			foreach ( $items as $item ) {
				$this->assertSame( $row_kind, $item['donor']['kind'], sprintf( 'Reader %d is listed under "%s" but its row says otherwise.', $item['id'], $kind ) );
			}
			$this->assertSame( [], array_values( array_diff( $readers, array_column( $items, 'id' ) ) ), sprintf( 'A reader of kind "%s" is missing from its filter.', $kind ) );
		}
	}

	/**
	 * Several options together list the readers of each, so ticking every option
	 * narrows nothing.
	 *
	 * @dataProvider data_donor_filter_unions
	 *
	 * @param string[] $options The Donor filter values.
	 */
	public function test_donor_filter_unions_selected_options( array $options ) {
		$this->login_admin();
		$fixture = $this->create_donor_fixture();
		$groups  = array_map( fn( $option ) => $fixture[ $option ], $options );

		$this->assertSame( self::sorted_ids( ...$groups ), $this->filtered_ids( [ 'donor' => $options ] ) );
	}

	/**
	 * Sets of Donor filter values ticked together.
	 *
	 * @return array[]
	 */
	public function data_donor_filter_unions() {
		return [
			'two kinds'                => [ [ 'one-time', 'former' ] ],
			'not a donor and one kind' => [ [ 'none', 'one-time' ] ],
			'every option'             => [ [ 'one-time', 'recurring', 'former', 'none' ] ],
		];
	}

	/**
	 * The Donor filter narrows with the Status and Plan filters: each pair lists
	 * only the readers matching both, and "Not a donor" subtracts from the other
	 * filter's set instead of adding one.
	 *
	 * Beyond the donor fixture, a "Digital" plan is held by readers of several
	 * kinds, and by non-donors who are on hold or cancelled, so neither side of a
	 * pair already equals the answer on its own.
	 *
	 * @dataProvider data_donor_filter_with_another_filter
	 *
	 * @param array    $params   The request's filter params.
	 * @param string[] $expected Labels of the readers both filters match.
	 */
	public function test_donor_filter_narrows_with_another_filter( array $params, array $expected ) {
		$this->login_admin();
		$fixture = $this->create_donor_fixture();
		$readers = array_merge( ...array_values( $fixture ) );
		$plan_id = $this->create_subscription_product( 'Digital' );
		foreach ( [ 'one_time', 'non_donor', 'recurring' ] as $label ) {
			$this->create_individual_subscription( $readers[ $label ], 'active', $plan_id );
		}
		$readers['on_hold_member'] = $this->create_reader( 'On Hold Member' );
		$this->create_individual_subscription( $readers['on_hold_member'], 'on-hold', $plan_id );
		$readers['lapsed_member'] = $this->create_reader( 'Lapsed Member' );
		$this->create_individual_subscription( $readers['lapsed_member'], 'cancelled', $plan_id );

		$expected_ids = array_map( fn( $label ) => $readers[ $label ], $expected );
		sort( $expected_ids );

		$this->assertSame( $expected_ids, $this->filtered_ids( $params ) );
	}

	/**
	 * Donor filter values paired with a Status or Plan filter, and the readers
	 * matching both.
	 *
	 * @return array[]
	 */
	public function data_donor_filter_with_another_filter() {
		return [
			'one-time, active'       => [
				[
					'status' => [ 'active' ],
					'donor'  => [ 'one-time' ],
				],
				[ 'one_time' ],
			],
			'not a donor, active'    => [
				[
					'status' => [ 'active' ],
					'donor'  => [ 'none' ],
				],
				[ 'non_donor' ],
			],
			// The only case where "Not a donor" takes Former donors (`cancelled`,
			// `expired` and `gave_again`) off another filter's results.
			'not a donor, cancelled' => [
				[
					'status' => [ 'cancelled' ],
					'donor'  => [ 'none' ],
				],
				[ 'lapsed_member' ],
			],
			'recurring, on hold'     => [
				[
					'status' => [ 'on-hold' ],
					'donor'  => [ 'recurring' ],
				],
				[ 'recurring_on_hold' ],
			],
			// The flag-only former donor holds no subscription, so has no status.
			'former, cancelled'      => [
				[
					'status' => [ 'cancelled' ],
					'donor'  => [ 'former' ],
				],
				[ 'cancelled', 'expired', 'gave_again' ],
			],
			'recurring, plan'        => [
				[
					'plan'  => [ 'Digital' ],
					'donor' => [ 'recurring' ],
				],
				[ 'recurring' ],
			],
			'not a donor, plan'      => [
				[
					'plan'  => [ 'Digital' ],
					'donor' => [ 'none' ],
				],
				[ 'non_donor', 'on_hold_member', 'lapsed_member' ],
			],
		];
	}

	/**
	 * A flag stored as `'1'`, which is what Reader_Data keeps for the number 1,
	 * reads as set on the row, so the filter has to match it too.
	 */
	public function test_donor_filter_matches_a_flag_stored_as_a_number() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Numeric Flag' );
		Reader_Data::update_item( $reader_id, 'is_donor', 1 );

		$this->assertSame( 'one-time', $this->list_row( $reader_id )['donor']['kind'] );
		$this->assertSame( [ $reader_id ], $this->filtered_ids( [ 'donor' => [ 'one-time' ] ] ) );
	}

	/**
	 * A gifted recurring donation makes its recipient the Recurring donor, as the row
	 * does. The purchaser, flagged by the payment, reads One-time on both sides.
	 */
	public function test_donor_filter_follows_the_gift_holder() {
		$this->login_admin();
		$purchaser = $this->create_reader( 'Purchaser' );
		$recipient = $this->create_reader( 'Recipient' );
		$this->create_gifted_subscription( $purchaser, $recipient, 'active', $this->create_donation_product() );
		$this->flag_donor( $purchaser );

		$recurring_ids = $this->filtered_ids( [ 'donor' => [ 'recurring' ] ] );

		$this->assertContains( $recipient, $recurring_ids );
		$this->assertNotContains( $purchaser, $recurring_ids );
		$this->assertSame( 'recurring', $this->list_row( $recipient )['donor']['kind'] );
		$this->assertSame( 'one-time', $this->list_row( $purchaser )['donor']['kind'] );
		$this->assertSame( [ $purchaser ], $this->filtered_ids( [ 'donor' => [ 'one-time' ] ] ) );
	}

	/**
	 * A donation subscription run as a group is shown with the reader's groups, not
	 * as a donation, so it makes no one a donor on the row or in the filter.
	 */
	public function test_a_group_donation_subscription_counts_toward_no_kind() {
		$this->login_admin();
		$owner        = $this->create_reader( 'Group Owner' );
		$subscription = $this->create_individual_subscription( $owner, 'active', $this->create_donation_product() );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );

		$this->assertSame( '', $this->list_row( $owner )['donor']['kind'] );
		$this->assertSame( [], $this->filtered_ids( [ 'donor' => [ 'recurring' ] ] ) );
		$this->assertSame( [ $owner ], $this->filtered_ids( [ 'donor' => [ 'none' ] ] ) );
	}

	/**
	 * The donor scan's budget covers every donation product together, so a product
	 * with no subscriptions, like a one-time donation product, leaves its share to
	 * the others, whichever order the products are listed in. Split evenly, a
	 * budget of three would read one subscription per product here and drop two
	 * live donors.
	 *
	 * @dataProvider data_busy_donation_product_first
	 *
	 * @param bool $busy_product_first Whether the product holding the subscriptions is listed first.
	 */
	public function test_donor_scan_budget_is_shared_across_donation_products( bool $busy_product_first ) {
		$busy_product_id  = $this->create_donation_product();
		$empty_product_id = $this->create_donation_product( 'simple' );
		// Flagged donation products are listed newest first.
		$newer_product_id = $busy_product_first ? $busy_product_id : $empty_product_id;
		$older_product_id = $busy_product_first ? $empty_product_id : $busy_product_id;
		$product_dates    = [
			$newer_product_id => '2026-02-01 00:00:00',
			$older_product_id => '2026-01-01 00:00:00',
		];
		foreach ( $product_dates as $product_id => $date ) {
			wp_update_post(
				[
					'ID'            => $product_id,
					'post_date'     => $date,
					'post_date_gmt' => $date,
				]
			);
		}
		$holder_ids = [];
		foreach ( [ 'First', 'Second', 'Third' ] as $name ) {
			$reader_id    = $this->create_reader( $name );
			$holder_ids[] = $reader_id;
			$this->create_individual_subscription( $reader_id, 'active', $busy_product_id );
		}
		$this->assertSame( [ $newer_product_id, $older_product_id ], Subscribers_Donors::donation_product_ids(), 'The products are listed in the order this case is about.' );

		$live_ids = Subscribers_Donors::holders( count( $holder_ids ) )['live'];
		sort( $live_ids );

		$this->assertSame( $holder_ids, $live_ids );
	}

	/**
	 * Both orders for the product holding the subscriptions.
	 *
	 * @return array[]
	 */
	public function data_busy_donation_product_first() {
		return [
			'busy product first'  => [ true ],
			'empty product first' => [ false ],
		];
	}

	/**
	 * A trashed donation subscription counts toward no kind, in the filter as on
	 * the row. WooCommerce Subscriptions leaves trashed subscriptions out of a
	 * reader's list, so the row never sees one, and the filter mustn't read it as
	 * live (an unregistered status maps to on hold). The reader's earlier payment
	 * left the donor flag, so both sides read One-time.
	 */
	public function test_a_trashed_donation_subscription_counts_toward_no_kind() {
		$this->login_admin();
		$reader_id = $this->create_reader( 'Trashed Donation' );
		$this->create_individual_subscription( $reader_id, 'trash', $this->create_donation_product() );
		$this->flag_donor( $reader_id );
		// What wcs_get_users_subscriptions() does in production; the mock returns
		// every status. tear_down() detaches it with the gift filters.
		$hide_trashed = fn( $subscriptions ) => array_filter( $subscriptions, fn( $subscription ) => 'trash' !== $subscription->get_status() );
		add_filter( 'wcs_get_users_subscriptions', $hide_trashed, 10 );
		$this->gift_filters[] = $hide_trashed;

		$this->assertSame( 'one-time', $this->list_row( $reader_id )['donor']['kind'] );
		$this->assertSame( [], $this->filtered_ids( [ 'donor' => [ 'recurring' ] ] ) );
		$this->assertSame( [ $reader_id ], $this->filtered_ids( [ 'donor' => [ 'one-time' ] ] ) );
	}

	/**
	 * The donor scan reads live donation subscriptions first, so pending and ended
	 * ones, however many and however old, can't use up the budget before a live one
	 * is read. Whatever budget the live ones leave goes to the ended ones, so past
	 * the cap it is ended holders who go missing first.
	 */
	public function test_donor_scan_reads_live_subscriptions_before_the_rest() {
		$donation_product_id = $this->create_donation_product();
		$cancelled_ids       = [];
		// Created first, so a scan that reads in ID order alone meets these first.
		foreach ( [ 'pending', 'pending', 'cancelled', 'cancelled' ] as $index => $status ) {
			$reader_id = $this->create_reader( 'Older ' . $index );
			$this->create_individual_subscription( $reader_id, $status, $donation_product_id );
			if ( 'cancelled' === $status ) {
				$cancelled_ids[] = $reader_id;
			}
		}
		$live_id = $this->create_reader( 'Newest Live' );
		$this->create_individual_subscription( $live_id, 'active', $donation_product_id );

		$holders = Subscribers_Donors::holders( 2 );

		$this->assertSame( [ $live_id ], $holders['live'] );
		$this->assertSame( [ $cancelled_ids[0] ], $holders['ended'], 'The ended scan gets only the budget the live scan left.' );
		$this->assertSame( $cancelled_ids, Subscribers_Donors::holders( 0 )['ended'], 'With no limit every ended holder is read.' );
	}

	/**
	 * The per-chunk callback runs after each chunk of donation subscriptions is
	 * read, in the live and the ended scan alike, so a long caller can free memory
	 * between chunks.
	 */
	public function test_donor_scan_calls_back_after_each_chunk() {
		$donation_product_id = $this->create_donation_product();
		$this->create_individual_subscription( $this->create_reader( 'Live' ), 'active', $donation_product_id );
		$this->create_individual_subscription( $this->create_reader( 'Ended' ), 'cancelled', $donation_product_id );
		$chunks_read = 0;

		Subscribers_Donors::holders(
			0,
			function () use ( &$chunks_read ) {
				++$chunks_read;
			}
		);

		$this->assertSame( 2, $chunks_read, 'One chunk of live subscriptions and one of ended ones.' );
	}

	/**
	 * A donation product moved to the trash still marks its subscriptions as
	 * donations on the row, which reads the flag whatever the product's status, so
	 * the filter has to scan its subscriptions too.
	 */
	public function test_a_trashed_donation_product_still_counts() {
		$this->login_admin();
		$product_id = $this->create_subscription_product( 'Retired Donation', 'subscription', 0, false, 'trash' );
		update_post_meta( $product_id, WooCommerce_Products::DONATION_FLAG_META_KEY, wc_bool_to_string( true ) );
		$reader_id = $this->create_reader( 'Retired Product Donor' );
		$this->create_individual_subscription( $reader_id, 'active', $product_id );

		$this->assertContains( $product_id, Subscribers_Donors::donation_product_ids() );
		$this->assertSame( 'recurring', $this->list_row( $reader_id )['donor']['kind'] );
		$this->assertSame( [ $reader_id ], $this->filtered_ids( [ 'donor' => [ 'recurring' ] ] ) );
	}

	/**
	 * Off WooCommerce there are no donor kinds, so the param narrows nothing.
	 */
	public function test_donor_filter_is_ignored_when_donations_are_not_woocommerce() {
		$this->login_admin();
		$fixture = $this->create_donor_fixture();

		update_option( Donations::NEWSPACK_READER_REVENUE_PLATFORM, 'nrh' );
		try {
			$one_time_ids = $this->filtered_ids( [ 'donor' => [ 'one-time' ] ] );
			$none_ids     = $this->filtered_ids( [ 'donor' => [ 'none' ] ] );
		} finally {
			delete_option( Donations::NEWSPACK_READER_REVENUE_PLATFORM );
		}

		$every_reader = self::sorted_ids( ...array_values( $fixture ) );
		$this->assertSame( $every_reader, $one_time_ids );
		$this->assertSame( $every_reader, $none_ids );
	}

	/**
	 * A non-admin reader is refused.
	 */
	public function test_forbidden_for_non_admin() {
		wp_set_current_user( $this->create_reader( 'Nope', 'subscriber' ) );
		$response = $this->dispatch();
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The avatars endpoint enforces the same manage_options gate as the list
	 * endpoints.
	 */
	public function test_avatars_forbidden_for_non_admin() {
		wp_set_current_user( $this->create_reader( 'NopeAvatar', 'subscriber' ) );
		$request = new WP_REST_Request( 'POST', '/newspack/v1/wizard/newspack-subscribers/avatars' );
		$request->set_param( 'emails', [ 'reader@test.com' ] );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}
}
