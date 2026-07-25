<?php
/**
 * Tests for the Subscribers wizard subscribers read endpoint.
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
	 * Create a group subscription owned by $owner_id.
	 *
	 * @param int    $owner_id The owner user ID.
	 * @param int    $limit    Seat limit.
	 * @param string $status   Subscription status.
	 *
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $owner_id, int $limit = 5, string $status = 'active' ): WC_Subscription {
		$sub = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => $status,
				'billing_period' => 'month',
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
		}
		return wcs_create_subscription( $data );
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
		update_user_meta( $array_reader, \Newspack\Subscribers_Wizard::READER_TAGS_META, [ 'vip', 'met-in-person' ] );
		update_user_meta( $json_reader, \Newspack\Subscribers_Wizard::READER_TAGS_META, wp_json_encode( [ 'vip', 'vip', 'lapsed' ] ) );

		$tags_by_id = array_column( $this->dispatch()->get_data()['items'], 'tags', 'id' );

		$this->assertSame( [ 'vip', 'met-in-person' ], $tags_by_id[ $array_reader ] );
		$this->assertSame( [ 'vip', 'lapsed' ], $tags_by_id[ $json_reader ], 'A JSON-encoded value is decoded, and duplicates collapse.' );
		$this->assertSame( [], $tags_by_id[ $bare_reader ] );
	}

	/**
	 * The Newsletters column reports the site's own record of what a reader is
	 * subscribed to (the `newsletter_subscribed_lists` reader-data item), resolved
	 * to the titles the site shows for those lists. A list the site has no
	 * definition for falls back to its ID rather than vanishing from the column.
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
			[ 'Daily Brief', 'Weekend Read', 'esp-only-list' ],
			$newsletters_by_id[ $subscribed_reader ],
			'Known lists resolve to their titles; an unknown list ID is kept as-is.'
		);
		$this->assertSame( [], $newsletters_by_id[ $unsubscribed_reader ] );
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

		// One fixture, two page sizes, so the only thing that differs between the
		// measurements is how many rows get hydrated.
		$single_row_cost = $this->measure_query_cost( [ 'per_page' => 1 ] );
		$full_page_cost  = $this->measure_query_cost( [ 'per_page' => 50 ] );

		$newsletter_lists_query_count = 0;
		$full_page                    = $this->dispatch( [ 'per_page' => 50 ] )->get_data();

		$this->assertCount( 50, $full_page['items'], 'The measured page really is 50 rows.' );
		$this->assertSame( [ 'Daily Brief' ], $full_page['items'][0]['newsletters'], 'The page really is hydrating the batched columns.' );
		$this->assertSame(
			$single_row_cost,
			$full_page_cost,
			'Hydrating 50 rows must not cost more queries than hydrating 1 — a per-row lookup crept in.'
		);
		$this->assertSame(
			1,
			$newsletter_lists_query_count,
			'The newsletter list registry must be resolved once per request, not once per row.'
		);
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
