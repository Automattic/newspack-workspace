<?php
/**
 * Tests for the Subscribers wizard read endpoints (subscribers, avatars, plans).
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 * @group subscribers-wizard
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * GET /wizard/newspack-subscribers/subscribers.
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
	 * Include the WC mocks before the class boots.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/mocks/wc-mocks.php';
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
		$this->scope_token      = 'scope' . wp_generate_password( 8, false );
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
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
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
		$product_id = $this->create_subscription_product( $product_name, 'subscription', 0, true );
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
	 * plus the name of every published, non-group subscription product (variations
	 * included, since a subscription on a variation displays the variation's name).
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
			[ 'Acme Team', 'Beta Team', 'Digital Bundle', 'Digital Bundle - Annual', 'Digital Monthly' ],
			$plans['items'],
			'Group names and non-group subscription product/variation names, deduplicated and alphabetised.'
		);
		$this->assertNotContains( 'Tote Bag', $plans['items'], 'A non-subscription product is not a plan.' );
		$this->assertNotContains(
			'Team Plan',
			$plans['items'],
			'The product a group is sold on is not an option: members display the group name, so filtering on the product would return only the owners and no row would read as the filtered name.'
		);
		$this->assertSame( 5, $plans['total'] );
	}

	/**
	 * A group product is excluded whether it is sold as a whole product or as a
	 * variation of one, because group enablement is per product/variation and a
	 * variation does not inherit its parent's setting. The parent of a group
	 * variation is still an option: it is not itself a group product.
	 */
	public function test_plans_endpoint_excludes_group_variations() {
		$this->login_admin();

		$bundle_id = $this->create_subscription_product( 'Campus Bundle', 'variable-subscription' );
		$this->create_subscription_product( 'Campus Bundle - Individual', 'subscription_variation', $bundle_id );
		$this->create_subscription_product( 'Campus Bundle - Site Licence', 'subscription_variation', $bundle_id, true );

		$items = $this->dispatch_plans()->get_data()['items'];

		$this->assertContains( 'Campus Bundle', $items );
		$this->assertContains( 'Campus Bundle - Individual', $items );
		$this->assertNotContains( 'Campus Bundle - Site Licence', $items, 'A group-enabled variation is not an option.' );
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

		$expected_holders = [
			'Acme Team'       => [ $group_owner, $group_member ],
			'Digital Monthly' => [ $product_reader ],
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
	 * A plan name that matches nothing on the site fails closed — an empty page,
	 * not the unfiltered list. A stale option in a long-open tab (or a hand-typed
	 * param) must never widen the result set.
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
