<?php
/**
 * Tests the User Gate Access class.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Attribution;
use Newspack\Access_Rules;
use Newspack\Content_Gate;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\Institution;
use Newspack\Reader_Activation;
use Newspack\User_Gate_Access;

/**
 * Test User Gate Access functionality.
 *
 * @group User_Gate_Access
 */
class Newspack_Test_User_Gate_Access extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private static $user_id;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		self::$user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
		Reader_Activation::set_reader_verified( self::$user_id );
		self::$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		Access_Attribution::reset_memo();
		Access_Rules::flush_one_time_purchase_memo();
		User_Gate_Access::reset_memo();
	}

	/**
	 * Helper to create a gate with custom access rules.
	 *
	 * @param string $title        Gate title.
	 * @param array  $access_rules Access rules array.
	 *
	 * @return int Gate post ID.
	 */
	private function create_gate_with_rules( $title, $access_rules ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		update_post_meta(
			$gate_id,
			'custom_access',
			[
				'active'       => true,
				'access_rules' => $access_rules,
			]
		);
		return $gate_id;
	}

	/**
	 * Test get_custom_access_gates returns only active custom access gates.
	 */
	public function test_get_custom_access_gates_filters_correctly() {
		// Create a gate with custom access active.
		$this->create_gate_with_rules( 'Active Gate', [] );

		// Create a gate without custom access.
		$inactive_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Inactive Gate',
			]
		);
		update_post_meta( $inactive_id, 'custom_access', [ 'active' => false ] );

		// Use reflection to test private method.
		$method = new ReflectionMethod( User_Gate_Access::class, 'get_custom_access_gates' );
		$method->setAccessible( true );
		$gates = $method->invoke( null );

		$this->assertCount( 1, $gates, 'Should only return gates with active custom access.' );
		$this->assertEquals( 'Active Gate', reset( $gates )['title'] );
	}

	/**
	 * Test evaluate_gate_for_user with empty rules returns can_bypass true.
	 */
	public function test_evaluate_gate_empty_rules_means_bypass() {
		$gate_id = $this->create_gate_with_rules( 'Empty Gate', [] );
		$gate    = Content_Gate::get_gate( $gate_id );

		$method = new ReflectionMethod( User_Gate_Access::class, 'evaluate_gate_for_user' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $gate, self::$user_id );

		$this->assertTrue( $result['can_bypass'], 'Empty access rules should mean the user can bypass (gate does not restrict).' );
		$this->assertEmpty( $result['groups'] );
	}

	/**
	 * Test evaluate_gate_for_user with email domain rule.
	 */
	public function test_evaluate_gate_email_domain_pass() {
		$rules = [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
		$gate_id = $this->create_gate_with_rules( 'Domain Gate', $rules );
		$gate    = Content_Gate::get_gate( $gate_id );

		$method = new ReflectionMethod( User_Gate_Access::class, 'evaluate_gate_for_user' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $gate, self::$user_id );

		$this->assertTrue( $result['can_bypass'], 'User with example.com email should pass email_domain rule.' );
		$this->assertTrue( $result['groups'][0]['passes'] );
		$this->assertTrue( $result['groups'][0]['rules'][0]['passes'] );
	}

	/**
	 * Test evaluate_gate_for_user with email domain rule - fail case.
	 */
	public function test_evaluate_gate_email_domain_fail() {
		$rules = [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'other.com',
				],
			],
		];
		$gate_id = $this->create_gate_with_rules( 'Domain Gate', $rules );
		$gate    = Content_Gate::get_gate( $gate_id );

		$method = new ReflectionMethod( User_Gate_Access::class, 'evaluate_gate_for_user' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $gate, self::$user_id );

		$this->assertFalse( $result['can_bypass'], 'User with example.com email should fail other.com domain rule.' );
		$this->assertFalse( $result['groups'][0]['passes'] );
	}

	/**
	 * Test metabox only renders for users with manage_options capability.
	 */
	public function test_render_requires_manage_options() {
		wp_set_current_user( self::$user_id ); // Subscriber, no manage_options.
		$user = get_user_by( 'id', self::$user_id );

		ob_start();
		User_Gate_Access::render_user_gate_access( $user );
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Should not render for users without manage_options.' );
	}

	/**
	 * Test metabox renders for admin users when gates exist.
	 */
	public function test_render_for_admin() {
		wp_set_current_user( self::$admin_id );
		$this->create_gate_with_rules( 'Test Gate', [] );
		$user = get_user_by( 'id', self::$user_id );

		ob_start();
		User_Gate_Access::render_user_gate_access( $user );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Access Control', $output, 'Should render heading for admin users.' );
	}

	/**
	 * Test metabox does not render when no custom-access gates exist.
	 */
	public function test_render_empty_when_no_gates() {
		wp_set_current_user( self::$admin_id );
		$user = get_user_by( 'id', self::$user_id );

		ob_start();
		User_Gate_Access::render_user_gate_access( $user );
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Should not render when no custom-access gates exist.' );
	}

	/**
	 * Test OR logic between groups - user passes one group but not another.
	 */
	public function test_evaluate_gate_or_logic_between_groups() {
		$rules = [
			// Group 1: email domain that won't match.
			[
				[
					'slug'  => 'email_domain',
					'value' => 'other.com',
				],
			],
			// Group 2: email domain that will match.
			[
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
		$gate_id = $this->create_gate_with_rules( 'OR Gate', $rules );
		$gate    = Content_Gate::get_gate( $gate_id );

		$method = new ReflectionMethod( User_Gate_Access::class, 'evaluate_gate_for_user' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $gate, self::$user_id );

		$this->assertTrue( $result['can_bypass'], 'User should pass when at least one group matches (OR logic).' );
		$this->assertFalse( $result['groups'][0]['passes'], 'First group should fail.' );
		$this->assertTrue( $result['groups'][1]['passes'], 'Second group should pass.' );
	}

	/**
	 * An unconfigured one_time_purchase rule denies access, so the panel must
	 * describe it as such rather than falling into the generic "(any)" branch
	 * that suits rules whose evaluator reads an empty value as no constraint.
	 */
	public function test_format_rule_value_describes_unconfigured_one_time_purchase() {
		$format_rule_value_method = new ReflectionMethod( User_Gate_Access::class, 'format_rule_value' );
		$format_rule_value_method->setAccessible( true );

		$this->assertSame(
			'(no products selected) (invalid duration, grants no access)',
			$format_rule_value_method->invoke( null, 'one_time_purchase', [] ),
			'An empty one_time_purchase value should read as granting no access, matching how the rule evaluates.'
		);
		$this->assertSame(
			'(any)',
			$format_rule_value_method->invoke( null, 'email_domain', '' ),
			'An empty value for a rule that reads it as no constraint should still read "(any)".'
		);
	}

	/**
	 * A passing subscription rule names every subscription that satisfies it —
	 * owned or via group membership — and only those: a cancelled subscription
	 * and one for an unrelated product grant nothing and must not be listed.
	 */
	public function test_subscription_rule_lists_the_subscriptions_that_grant_access() {
		\wc_create_mock_product(
			[
				'id'   => 101,
				'name' => 'All Access',
			]
		);
		$owned     = \wcs_create_subscription(
			[
				'customer_id'    => self::$user_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'products'       => [ 101 ],
			]
		);
		$cancelled = \wcs_create_subscription(
			[
				'customer_id'    => self::$user_id,
				'status'         => 'cancelled',
				'billing_period' => 'month',
				'products'       => [ 101 ],
			]
		);
		$unrelated = \wcs_create_subscription(
			[
				'customer_id'    => self::$user_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'products'       => [ 999 ],
			]
		);
		// Group subscriptions owned by someone else, where the reader is a member:
		// an active one grants access, a cancelled one does not.
		$manager_id      = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$group           = $this->create_group_subscription( $manager_id, 'active', [ 101 ] );
		$cancelled_group = $this->create_group_subscription( $manager_id, 'cancelled', [ 101 ] );
		add_user_meta( self::$user_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group->get_id() );
		add_user_meta( self::$user_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $cancelled_group->get_id() );

		$links = User_Gate_Access::get_granting_entity_links( 'subscription', [ 101 ], self::$user_id, [ 'payment_recovery_grace' => true ] );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( '#' . $owned->get_id() . '</a>', $links[0] );
		$this->assertStringContainsString( 'href="' . esc_url( $owned->get_edit_order_url() ) . '"', $links[0], 'The label must link to the subscription edit screen.' );
		$this->assertStringContainsString( '#' . $group->get_id() . '</a>', $links[1], 'A group subscription the reader is a member of grants access and is listed.' );
		$joined = implode( '', $links );
		$this->assertStringNotContainsString( '#' . $cancelled->get_id() . '<', $joined );
		$this->assertStringNotContainsString( '#' . $unrelated->get_id() . '<', $joined );
		$this->assertStringNotContainsString( '#' . $cancelled_group->get_id() . '<', $joined );
	}

	/**
	 * Create a subscription with group membership enabled.
	 *
	 * @param int    $customer_id Owner user ID.
	 * @param string $status      Subscription status.
	 * @param int[]  $products    Product IDs on the subscription.
	 *
	 * @return \WC_Subscription
	 */
	private function create_group_subscription( $customer_id, $status, $products ) {
		$subscription = \wcs_create_subscription(
			[
				'customer_id'    => $customer_id,
				'status'         => $status,
				'billing_period' => 'month',
				'products'       => $products,
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		return $subscription;
	}

	/**
	 * An "any subscription" rule (empty value) lists every active subscription.
	 */
	public function test_subscription_rule_with_no_products_lists_all_active_subscriptions() {
		$first  = \wcs_create_subscription(
			[
				'customer_id'    => self::$user_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'products'       => [ 101 ],
			]
		);
		$second = \wcs_create_subscription(
			[
				'customer_id'    => self::$user_id,
				'status'         => 'active',
				'billing_period' => 'year',
				'products'       => [ 102 ],
			]
		);

		$links = User_Gate_Access::get_granting_entity_links( 'subscription', '', self::$user_id );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( '#' . $first->get_id() . '</a>', $links[0] );
		$this->assertStringContainsString( '#' . $second->get_id() . '</a>', $links[1] );
	}

	/**
	 * A passing one-time purchase rule names the paid orders inside the access
	 * window and only those: an order older than the window and a refunded
	 * order grant nothing.
	 */
	public function test_one_time_purchase_rule_lists_the_orders_that_grant_access() {
		\wc_create_mock_product(
			[
				'id'   => 201,
				'name' => 'Day Pass',
			]
		);
		$recent = \wc_create_order(
			[
				'customer_id'  => self::$user_id,
				'status'       => 'completed',
				'total'        => 10,
				'date_created' => gmdate( 'Y-m-d H:i:s' ),
				'items'        => [ new \WC_Order_Item_Product( [ 'product_id' => 201 ] ) ],
			]
		);
		$stale  = \wc_create_order(
			[
				'customer_id'  => self::$user_id,
				'status'       => 'completed',
				'total'        => 10,
				'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( '-60 days' ) ),
				'items'        => [ new \WC_Order_Item_Product( [ 'product_id' => 201 ] ) ],
			]
		);
		$refund = \wc_create_order(
			[
				'customer_id'  => self::$user_id,
				'status'       => 'refunded',
				'total'        => 10,
				'date_created' => gmdate( 'Y-m-d H:i:s' ),
				'items'        => [ new \WC_Order_Item_Product( [ 'product_id' => 201 ] ) ],
			]
		);
		$value  = [
			'product_ids'    => [ 201 ],
			'duration_value' => 30,
			'duration_unit'  => 'days',
		];

		// A guest checkout under the reader's email counts as theirs, as it does
		// for the rule itself.
		$guest = \wc_create_order(
			[
				'customer_id'   => 0,
				'billing_email' => 'reader@example.com',
				'status'        => 'processing',
				'total'         => 10,
				'date_created'  => gmdate( 'Y-m-d H:i:s' ),
				'items'         => [ new \WC_Order_Item_Product( [ 'product_id' => 201 ] ) ],
			]
		);

		$links  = User_Gate_Access::get_granting_entity_links( 'one_time_purchase', $value, self::$user_id );
		$joined = implode( '', $links );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( '#' . $recent->get_id() . '</a>', $joined );
		$this->assertStringContainsString( '#' . $guest->get_id() . '</a>', $joined, 'A guest order under the reader\'s billing email is listed.' );
		$this->assertStringContainsString( 'href="' . esc_url( $recent->get_edit_order_url() ) . '"', $joined, 'The label must link to the order edit screen.' );
		$this->assertStringNotContainsString( '#' . $stale->get_id() . '<', $joined );
		$this->assertStringNotContainsString( '#' . $refund->get_id() . '<', $joined );

		// Lifetime access has no window, so the older order counts too.
		$value['duration_unit'] = 'forever';
		$links                  = User_Gate_Access::get_granting_entity_links( 'one_time_purchase', $value, self::$user_id );
		$this->assertCount( 3, $links, 'A forever rule lists every paid order for the product.' );
	}

	/**
	 * A lifetime rule can match every order a long-standing customer placed;
	 * the report lists a handful and trails off rather than the whole ledger.
	 */
	public function test_one_time_purchase_listing_is_capped() {
		\wc_create_mock_product(
			[
				'id'   => 201,
				'name' => 'Day Pass',
			]
		);
		for ( $i = 0; $i < User_Gate_Access::GRANTING_ORDERS_LIMIT + 2; $i++ ) {
			\wc_create_order(
				[
					'customer_id'  => self::$user_id,
					'status'       => 'completed',
					'total'        => 10,
					'date_created' => gmdate( 'Y-m-d H:i:s', strtotime( "-$i days" ) ),
					'items'        => [ new \WC_Order_Item_Product( [ 'product_id' => 201 ] ) ],
				]
			);
		}
		$value = [
			'product_ids'    => [ 201 ],
			'duration_value' => 0,
			'duration_unit'  => 'forever',
		];

		$links = User_Gate_Access::get_granting_entity_links( 'one_time_purchase', $value, self::$user_id );

		$this->assertCount( User_Gate_Access::GRANTING_ORDERS_LIMIT + 1, $links );
		$this->assertSame( '…', end( $links ), 'The list ends with an ellipsis when more orders qualify.' );
		$this->assertStringNotContainsString( '<', end( $links ) );
	}

	/**
	 * Rules other than the two ownership rules have no records to point at, and
	 * neither does access granted by a filter with no local record behind it.
	 */
	public function test_non_ownership_rules_and_filter_granted_access_list_nothing() {
		$this->assertSame( [], User_Gate_Access::get_granting_entity_links( 'email_domain', 'example.com', self::$user_id ) );

		// A cancelled subscription is the only local record; the filter forcing the
		// rule to pass must not turn it into a granting one.
		\wcs_create_subscription(
			[
				'customer_id'    => self::$user_id,
				'status'         => 'cancelled',
				'billing_period' => 'month',
				'products'       => [ 101 ],
			]
		);
		add_filter( 'newspack_access_rules_has_active_subscription', '__return_true' );
		$this->assertTrue( Access_Rules::has_active_subscription( self::$user_id, [ 101 ] ), 'The filter grants access.' );
		$links = User_Gate_Access::get_granting_entity_links( 'subscription', [ 101 ], self::$user_id );
		remove_filter( 'newspack_access_rules_has_active_subscription', '__return_true' );

		$this->assertSame( [], $links );
	}

	/**
	 * The report links each granting subscription next to the rule it satisfies,
	 * and never lists records for a failing rule.
	 */
	public function test_render_links_granting_subscriptions_next_to_a_passing_rule() {
		wp_set_current_user( self::$admin_id );
		\wc_create_mock_product(
			[
				'id'   => 101,
				'name' => 'All Access',
			]
		);
		$owned = \wcs_create_subscription(
			[
				'customer_id'    => self::$user_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'products'       => [ 101 ],
			]
		);
		$this->create_gate_with_rules(
			'Members Gate',
			[
				[
					[
						'slug'  => 'subscription',
						'value' => [ 101 ],
					],
				],
				[
					[
						'slug'  => 'subscription',
						'value' => [ 999 ],
					],
				],
			]
		);

		ob_start();
		User_Gate_Access::render_user_gate_access( get_user_by( 'id', self::$user_id ) );
		$output = ob_get_clean();

		$this->assertSame( 1, substr_count( $output, '#' . $owned->get_id() . '</a>' ), 'The subscription is listed once, for the rule it satisfies, and not for the failing rule.' );
	}

	/**
	 * Titles that reach the report from the database are printed as text, not
	 * markup: a product named with a tag must not inject it into the page.
	 */
	public function test_render_escapes_titles_inside_links() {
		wp_set_current_user( self::$admin_id );
		\wc_create_mock_product(
			[
				'id'   => 101,
				'name' => 'Plan <b>Bold</b> & Co',
			]
		);
		$this->create_gate_with_rules(
			'Gate <i>Italic</i>',
			[
				[
					[
						'slug'  => 'subscription',
						'value' => [ 101 ],
					],
				],
			]
		);

		ob_start();
		User_Gate_Access::render_user_gate_access( get_user_by( 'id', self::$user_id ) );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<b>', $output );
		$this->assertStringNotContainsString( '<i>', $output );
		$this->assertStringContainsString( 'Plan &lt;b&gt;Bold&lt;/b&gt; &amp; Co', $output );
		$this->assertStringContainsString( 'Gate &lt;i&gt;Italic&lt;/i&gt;', $output );
	}

	/**
	 * Free-text rule values (email domains, reader data) are stored as typed, so
	 * the report prints them as text: markup in a value must not become markup
	 * on the page.
	 */
	public function test_render_escapes_free_text_rule_values() {
		wp_set_current_user( self::$admin_id );
		$this->create_gate_with_rules(
			'Domain Gate',
			[
				[
					[
						'slug'  => 'email_domain',
						'value' => 'example.com<a href="https://evil.example">x</a>',
					],
				],
			]
		);

		ob_start();
		User_Gate_Access::render_user_gate_access( get_user_by( 'id', self::$user_id ) );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'href="https://evil.example"', $output );
		$this->assertStringContainsString( '<code>example.com&lt;a href=&quot;https://evil.example&quot;&gt;x&lt;/a&gt;</code>', $output );
	}

	/**
	 * A variation has no edit screen of its own, so its link goes to the parent
	 * product's editor.
	 */
	public function test_variation_links_to_its_parent_product() {
		wp_set_current_user( self::$admin_id );
		$parent_id = $this->factory->post->create( [ 'post_title' => 'Membership' ] );
		\wc_create_mock_product(
			[
				'id'        => 102,
				'name'      => 'Membership - Annual',
				'parent_id' => $parent_id,
			]
		);

		$format_rule_value_method = new ReflectionMethod( User_Gate_Access::class, 'format_rule_value' );
		$format_rule_value_method->setAccessible( true );
		$html = $format_rule_value_method->invoke( null, 'subscription', [ 102 ] );

		$this->assertStringContainsString( 'href="' . esc_url( get_edit_post_link( $parent_id ) ) . '"', $html );
		$this->assertStringContainsString( '>Membership - Annual</a>', $html );
	}

	/**
	 * Only a published institution links to its screen; a draft or trashed one
	 * is named without a link, and a missing one shows its ID.
	 */
	public function test_institution_links_only_when_published() {
		$published = $this->factory->post->create(
			[
				'post_type'   => Institution::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'State University',
			]
		);
		$trashed   = $this->factory->post->create(
			[
				'post_type'   => Institution::POST_TYPE,
				'post_status' => 'trash',
				'post_title'  => 'Old College',
			]
		);

		$format_rule_value_method = new ReflectionMethod( User_Gate_Access::class, 'format_rule_value' );
		$format_rule_value_method->setAccessible( true );
		$html = $format_rule_value_method->invoke( null, 'institution', [ $published, $trashed, 999999 ] );

		$this->assertStringContainsString( '#/institutions/' . $published . '">State University</a>', $html );
		$this->assertStringContainsString( ', Old College, #999999', $html );
		$this->assertStringNotContainsString( '#/institutions/' . $trashed, $html );
	}
}
