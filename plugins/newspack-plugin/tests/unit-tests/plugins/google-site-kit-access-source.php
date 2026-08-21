<?php
/**
 * Tests the access_source GA4 parameter.
 *
 * @package Newspack\Tests
 */

use Newspack\GoogleSiteKit;

/**
 * Covers one branch of the resolver per test, plus the guarantee that building
 * the parameters never spends a reader's metering allowance.
 *
 * @group GoogleSiteKit_Access_Source
 */
class Newspack_Test_GoogleSiteKit_Access_Source extends WP_UnitTestCase {

	/**
	 * Slug of the test-only access rule standing in for a rule registered
	 * outside this vocabulary (see Promoted_Fields::register_access_rules()).
	 *
	 * @var string
	 */
	const UNMAPPED_RULE_SLUG = 'newspack_test_unmapped_source';

	/**
	 * Gate post ID created by the most recent create_gated_post() call.
	 *
	 * @var int
	 */
	private $gate_id;

	/**
	 * Institution post IDs to delete in tear_down(). Institution posts are
	 * inserted directly rather than through $this->factory, so they are not
	 * rolled back for us.
	 *
	 * @var int[]
	 */
	private $institution_ids = [];

	/**
	 * Enable the content gating feature flag.
	 *
	 * Without it `get_custom_event_parameters()` never reaches the resolver, so
	 * the metering test below would pass without exercising anything.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Start each test from an empty WooCommerce store.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		Newspack\Group_Subscription::reset_cache();
	}

	/**
	 * Clear every request-scoped memo this code path populates, so no test
	 * inherits another's answer.
	 */
	public function tear_down() {
		foreach ( $this->institution_ids as $institution_id ) {
			wp_delete_post( $institution_id, true );
		}
		$this->institution_ids = [];
		delete_transient( Newspack\Institution::TRANSIENT_KEY );
		Newspack\Institution::reset_matching_cache();
		Newspack\Group_Subscription::reset_cache();
		Newspack\Access_Attribution::reset_memo();
		Newspack\Access_Rules::flush_one_time_purchase_memo();
		GoogleSiteKit::reset_access_source_memo();
		parent::tear_down();
	}

	/**
	 * Off a singular view there is no gate to evaluate.
	 */
	public function test_non_singular_reports_no_custom_access_gate() {
		$this->go_to( home_url( '/' ) );
		$this->assertSame( 'no_custom_access_gate', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A post carrying no gates at all.
	 */
	public function test_ungated_post_reports_no_custom_access_gate() {
		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'no_custom_access_gate', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A registration-only gate is out of scope: the ESP scopes the whole
	 * Content Access family to custom-access gates, and access_source mirrors
	 * that. Regwall state is read from is_reader and logged_in instead.
	 */
	public function test_registration_only_gate_reports_no_custom_access_gate() {
		$post_id = $this->create_gated_post( [ 'registration' => [ 'active' => true ] ] );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'no_custom_access_gate', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A custom-access gate nobody passes and which does not meter.
	 */
	public function test_blocked_reader_reports_gated() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
				],
			]
		);
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'gated', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * On its own, a custom-access gate with an empty rule set restricts nobody,
	 * so there is no gating to report.
	 */
	public function test_only_unrestricting_gate_reports_no_custom_access_gate() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [],
				],
			]
		);
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'no_custom_access_gate', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A gate whose custom access is on but whose rule set is empty restricts
	 * nobody — but it must not speak for the whole post. Content_Restriction_Control
	 * stops at the first gate that restricts, so a reader blocked by a second
	 * gate is genuinely blocked, and reporting "no gate applies" would describe
	 * the opposite of what the reader sees.
	 */
	public function test_unrestricting_gate_does_not_mask_a_restricting_one() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [],
				],
			]
		);
		$this->attach_gate(
			$post_id,
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
				],
			]
		);
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'gated', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A passing rule reports its source rather than collapsing to "no gate".
	 * This is the failure a reader-dependent restriction check would produce,
	 * and it is silent: every value still looks plausible.
	 */
	public function test_passing_rule_reports_its_source() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.org',
							],
						],
					],
				],
			]
		);
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader@example.org' ] );
		Newspack\Reader_Activation::set_reader_verified( $user_id );
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'domain', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A reader recognised by an institution reports `institution`, not the
	 * institution's name: the name identifies the organisation to anyone with
	 * the GA4 report open, and the whole vocabulary is deliberately generic
	 * except for products the publisher sells.
	 */
	public function test_institutional_reader_reports_institution() {
		$institution_id = $this->factory->post->create(
			[
				'post_type'   => 'np_institution',
				'post_status' => 'publish',
				'post_title'  => 'Test University',
			]
		);
		$this->institution_ids[] = $institution_id;
		update_post_meta( $institution_id, 'np_institution_email_domain', 'university.example' );
		Newspack\Institution::invalidate_cache();

		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'institution',
								'value' => [ $institution_id ],
							],
						],
					],
				],
			]
		);
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader@university.example' ] );
		Newspack\Reader_Activation::set_reader_verified( $user_id );
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'institution', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A seat holder on somebody else's group subscription reports `group`, not
	 * the product name. The name belongs to the buyer; reporting it here would
	 * credit a purchase the reader never made.
	 */
	public function test_group_seat_holder_reports_group() {
		$product_id = 701;
		\wc_create_mock_product(
			[
				'id'   => $product_id,
				'name' => 'Campus Plan',
			]
		);
		$owner_id  = $this->factory->user->create( [ 'user_email' => 'owner@example.org' ] );
		$member_id = $this->factory->user->create( [ 'user_email' => 'member@example.org' ] );

		$subscription = \wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'products'       => [ $product_id ],
			]
		);
		$subscription->update_meta_data( Newspack\Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$subscription->update_meta_data( Newspack\Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', 'ACME Corp' );
		add_user_meta( $member_id, Newspack\Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		Newspack\Group_Subscription::reset_cache();

		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'subscription',
								'value' => [ $product_id ],
							],
						],
					],
				],
			]
		);
		Newspack\Reader_Activation::set_reader_verified( $member_id );
		wp_set_current_user( $member_id );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'group', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * The owner of the same subscription is credited with the product, which is
	 * the contrast that makes the `group` label above mean something.
	 */
	public function test_subscription_owner_reports_the_product_name() {
		$product_id = 702;
		\wc_create_mock_product(
			[
				'id'   => $product_id,
				'name' => 'Campus Plan',
			]
		);
		$owner_id = $this->factory->user->create( [ 'user_email' => 'owner@example.org' ] );
		\wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
				'products'       => [ $product_id ],
			]
		);

		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'subscription',
								'value' => [ $product_id ],
							],
						],
					],
				],
			]
		);
		Newspack\Reader_Activation::set_reader_verified( $owner_id );
		wp_set_current_user( $owner_id );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Campus Plan', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * Metering is the one part of this vocabulary anonymous visitors reach, and
	 * they are the majority of the traffic it describes — a gate that meters
	 * before it blocks is a different reader experience from one that blocks
	 * outright, and GA4 is where that difference gets measured.
	 */
	public function test_anonymous_reader_on_a_metering_gate_reports_metering_eligible() {
		wp_set_current_user( 0 );
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
					'metering'     => [
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
					],
				],
			]
		);
		$this->assertTrue(
			Newspack\Metering::offers_metering( $this->gate_id, false ),
			'The fixture gate must meter anonymous readers, or this asserts nothing.'
		);
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'metering_eligible', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * The resolution is memoized per post and reader for the life of the request.
	 *
	 * Reader verification is the probe because it is the one input here that
	 * changes the answer without being cached anywhere else: the gate list is
	 * memoized by Content_Restriction_Control::get_post_gates(), so mutating
	 * the gates would prove nothing about this memo.
	 *
	 * The reader starts verified and is then un-verified, rather than the other
	 * way round. Content_Restriction_Control caches the gate that restricted a
	 * post+reader pair in $post_gate_id_map and short-circuits to "restricted"
	 * on every later call, with no public reset — so a reader who starts blocked
	 * stays blocked for the rest of the process regardless of this memo. Running
	 * the probe from unrestricted to restricted leaves that cache untouched.
	 */
	public function test_resolution_is_memoized_per_post_and_reader() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.org',
							],
						],
					],
				],
			]
		);
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader@example.org' ] );
		update_user_meta( $user_id, Newspack\Reader_Activation::READER, true );
		Newspack\Reader_Activation::set_reader_verified( $user_id );
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $post_id ) );

		// Verified: the email_domain rule grants access and names the source.
		$this->assertSame( 'domain', GoogleSiteKit::get_request_access_source() );

		delete_user_meta( $user_id, Newspack\Reader_Activation::EMAIL_VERIFIED );

		$this->assertSame(
			'domain',
			GoogleSiteKit::get_request_access_source(),
			'The second call in the same request must come from the memo, not a re-evaluation.'
		);

		GoogleSiteKit::reset_access_source_memo();

		$this->assertSame(
			'gated',
			GoogleSiteKit::get_request_access_source(),
			'After the memo is cleared the reader is re-evaluated, proving the stale answer above was the memo.'
		);
	}

	/**
	 * Building the parameters must not record a metered view. An analytics
	 * parameter that spends a reader's allowance is worse than a missing one.
	 */
	public function test_building_params_does_not_write_metering_meta() {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
					'metering'     => [
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
					],
				],
			]
		);
		$this->assertTrue(
			Newspack\Metering::offers_metering( $this->gate_id ),
			'The fixture gate must actually offer metering, or the no-write guarantee is untested.'
		);
		$this->go_to( get_permalink( $post_id ) );

		$before = get_user_meta( $user_id );
		$params = GoogleSiteKit::get_custom_event_parameters();
		$after  = get_user_meta( $user_id );

		$this->assertSame( $before, $after, 'Building GA4 params must not consume a metered view.' );
		$this->assertSame(
			'metering_eligible',
			$params['access_source'] ?? null,
			'A blocked reader on a metering gate is metering_eligible.'
		);
	}

	/**
	 * A gate the reader passes cannot speak for a post another gate still
	 * blocks. Restriction is AND across every gate on the post — the reader
	 * sees the gate overlay — so attributing their access to the gate they
	 * happened to pass reports the opposite of what they experienced.
	 */
	public function test_passing_gate_does_not_mask_a_blocking_gate() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.org',
							],
						],
					],
				],
			]
		);
		$this->attach_gate(
			$post_id,
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
				],
			]
		);
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader@example.org' ] );
		Newspack\Reader_Activation::set_reader_verified( $user_id );
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'gated', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * The metering half of the case above: a blocked reader on a post whose
	 * gates meter is metering_eligible, not the label of the gate they passed.
	 * Blocked is blocked; only the flavour of the block changes.
	 */
	public function test_blocked_reader_on_a_metering_post_is_metering_eligible_not_attributed() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.org',
							],
						],
					],
				],
			]
		);
		$metering_gate_id = $this->attach_gate(
			$post_id,
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
					'metering'     => [
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
					],
				],
			]
		);
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader@example.org' ] );
		Newspack\Reader_Activation::set_reader_verified( $user_id );
		wp_set_current_user( $user_id );
		$this->assertTrue(
			Newspack\Metering::offers_metering( $metering_gate_id, true ),
			'The blocking gate must meter this reader, or the assertion below proves nothing.'
		);
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'metering_eligible', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * Metering is a property of the gate that blocked, not of the post. A
	 * reader who sails through a metering gate and is then stopped by a hard
	 * one gets no free views at all — reporting them as metering_eligible would
	 * count a soft block that never happened.
	 */
	public function test_metering_on_a_gate_the_reader_passed_does_not_soften_a_hard_block() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.org',
							],
						],
					],
					'metering'     => [
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
					],
				],
			]
		);
		$passed_gate_id = $this->gate_id;
		$this->attach_gate(
			$post_id,
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
				],
			]
		);
		$blocking_gate_id = $this->gate_id;
		$user_id          = $this->factory->user->create( [ 'user_email' => 'reader@example.org' ] );
		Newspack\Reader_Activation::set_reader_verified( $user_id );
		wp_set_current_user( $user_id );
		$this->assertTrue(
			Newspack\Metering::offers_metering( $passed_gate_id, true ),
			'The passed gate must meter, or this asserts nothing about which gate is consulted.'
		);
		$this->assertFalse(
			Newspack\Metering::offers_metering( $blocking_gate_id, true ),
			'The blocking gate must not meter, or both gates would give the same answer.'
		);
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'gated', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A post the publisher marked exempt is not restricted no matter what its
	 * gates say, so there is no gating to report on it.
	 */
	public function test_exempt_post_reports_no_custom_access_gate() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
				],
			]
		);
		update_post_meta( $post_id, Newspack\Content_Restriction_Control::IS_EXEMPT_META_KEY, true );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'no_custom_access_gate', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * Rules registered by other code — Promoted_Fields turns every access-rule
	 * ESP field into one — carry slugs this vocabulary has no mapping for. A
	 * reader admitted by one of those is not gated, and naming their source
	 * would be a guess, so the parameter is omitted and GA4 reports (not set).
	 */
	public function test_unmapped_rule_slug_omits_the_parameter() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->register_unmapped_rule();

		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => self::UNMAPPED_RULE_SLUG,
								'value' => $user_id,
							],
						],
					],
				],
			]
		);
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( '', GoogleSiteKit::get_request_access_source() );
		$this->assertArrayNotHasKey(
			'access_source',
			GoogleSiteKit::get_custom_event_parameters(),
			'An omitted source must not reach GA4 as an empty string.'
		);
	}

	/**
	 * Register an access rule whose slug Access_Attribution has no label for.
	 *
	 * Access_Rules::$rules is static with no unregister counterpart, so
	 * registration is guarded and the slug is namespaced to this test file.
	 * The rule admits exactly the user named in its value.
	 */
	private function register_unmapped_rule() {
		if ( isset( Newspack\Access_Rules::get_registered_rules()[ self::UNMAPPED_RULE_SLUG ] ) ) {
			return;
		}
		Newspack\Access_Rules::register_rule(
			[
				'id'       => self::UNMAPPED_RULE_SLUG,
				'name'     => 'Unmapped test source',
				'callback' => function ( $user_id, $value ) {
					return (int) $user_id === (int) $value;
				},
			]
		);
	}

	/**
	 * Build a post with a published gate bound to it.
	 *
	 * Binding uses a `specific_posts` content rule, which
	 * Content_Restriction_Control::get_post_gates() treats as an inclusion
	 * override — the most deterministic association available, and immune to
	 * category or post-type matching changing underneath the test.
	 *
	 * @param array $gate_meta Post meta to set on the gate, keyed by meta key
	 *                         (`custom_access`, `registration`).
	 * @return int Post ID of the gated post.
	 */
	private function create_gated_post( $gate_meta ) {
		$post_id = $this->factory->post->create();
		$this->attach_gate( $post_id, $gate_meta );
		return $post_id;
	}

	/**
	 * Publish a gate and bind it to an existing post.
	 *
	 * @param int   $post_id   Post the gate applies to.
	 * @param array $gate_meta Post meta to set on the gate, keyed by meta key.
	 * @return int Post ID of the gate.
	 */
	private function attach_gate( $post_id, $gate_meta ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Newspack\Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Access source test gate',
			]
		);
		$layout_id = $this->factory->post->create(
			[
				'post_type'   => Newspack\Content_Gate::GATE_LAYOUT_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Access source test layout',
			]
		);
		foreach ( $gate_meta as $meta_key => $meta_value ) {
			// Content_Restriction_Control only restricts once it has a layout to
			// render, so a gate left on the stored default of 0 restricts nobody
			// however its rules evaluate. Every gate here gets one, or the
			// fixtures would describe gates that cannot gate.
			if ( in_array( $meta_key, [ 'custom_access', 'registration' ], true ) && empty( $meta_value['gate_layout_id'] ) ) {
				$meta_value['gate_layout_id'] = $layout_id;
			}
			update_post_meta( $gate_id, $meta_key, $meta_value );
		}
		Newspack\Content_Rules::update_gate_content_rules(
			$gate_id,
			[
				[
					'slug'  => 'specific_posts',
					'value' => [ $post_id ],
				],
			]
		);
		$this->gate_id = $gate_id;
		return $gate_id;
	}
}
