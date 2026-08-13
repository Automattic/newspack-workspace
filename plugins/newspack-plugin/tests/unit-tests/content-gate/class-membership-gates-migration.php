<?php
/**
 * Characterization tests for the migrate-membership-gates CLI (NPPD-2059).
 *
 * These pin the behavior of the pure mapping/fingerprint/layout-extraction
 * helpers exactly as ported from the standalone drop-in. Where a test asserts a
 * buggy result on purpose it is flagged with the follow-up issue ID; those
 * stacked fixes will flip the corresponding assertion:
 *
 * - NPPD-2058: extract_gate_layouts() only inspects top-level wrapper blocks, so
 *   nested / reusable-block gate layouts migrate as empty. Pinned by the
 *   extract_gate_layouts / serialize_gate_inner_blocks tests below (they flip red).
 * - NPPD-2063: map_rules_to_ac_format() emits the raw WooCommerce content-type
 *   name as the AC rule slug instead of remapping to 'post_types' / 'specific_posts'.
 *   Pinned by the map_rules_to_ac_format tests below (they flip red).
 *
 * NOT pinned here: NPPD-2064 (fingerprint-based gate splitting/grouping). That fix
 * lands in group_plans_by_fingerprint() and the merged-product consolidation, which
 * depend on WC_Memberships_Membership_Plan and so are not unit-testable in this
 * harness — they are exercised end-to-end against real WooCommerce Memberships. The
 * compute_rules_fingerprint() tests below only pin the fingerprint's *canonicality*
 * (order-independence), which the 2064 fix preserves; they will NOT flip red, so the
 * 2064 author must add net-new grouping/split tests rather than rely on these.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\CLI\Membership_Gates_Migration;
use Newspack\Newsletters\Subscription_Lists;

require_once dirname( __DIR__, 3 ) . '/includes/cli/class-membership-gates-migration.php';

/**
 * Characterization tests for the migrate-membership-gates helpers.
 */
class Test_Membership_Gates_Migration extends \WP_UnitTestCase {

	/**
	 * Load the newsletters mocks once for the class. Deferred to set_up_before_class()
	 * rather than a file-scope require because PHPUnit loads every test file before the
	 * run starts: a file-scope require would define Subscription_List and
	 * Subscription_Lists for the whole suite, and three production guards branch on
	 * class_exists() for those, so unrelated tests would silently take the
	 * "Newsletters active" path.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-namespaced-mocks.php';
	}

	/**
	 * Invoke a private static method on the CLI class via reflection.
	 *
	 * @param string $method_name The method name.
	 * @param array  $arguments   Positional arguments.
	 *
	 * @return mixed The method return value.
	 */
	private function invoke_private_static( string $method_name, array $arguments ) {
		$reflected_method = new \ReflectionMethod( Membership_Gates_Migration::class, $method_name );
		$reflected_method->setAccessible( true );
		return $reflected_method->invoke( null, ...$arguments );
	}

	/**
	 * Build a minimal stand-in for a WC_Memberships_Membership_Plan_Rule.
	 *
	 * The drop-in's rule mapping only calls get_content_type_name() and
	 * get_object_ids(), so the WC Memberships plugin is not needed to exercise it.
	 *
	 * @param string $content_type_name The WC content type name (e.g. 'post', 'category').
	 * @param int[]  $object_ids        The restricted object IDs.
	 *
	 * @return object A rule-shaped object.
	 */
	private function make_rule( string $content_type_name, array $object_ids ) {
		return new class( $content_type_name, $object_ids ) {

			/**
			 * The WC content type name.
			 *
			 * @var string
			 */
			private $content_type_name;

			/**
			 * The restricted object IDs.
			 *
			 * @var int[]
			 */
			private $object_ids;

			/**
			 * Constructor.
			 *
			 * @param string $content_type_name The WC content type name.
			 * @param int[]  $object_ids        The restricted object IDs.
			 */
			public function __construct( string $content_type_name, array $object_ids ) {
				$this->content_type_name = $content_type_name;
				$this->object_ids        = $object_ids;
			}

			/**
			 * Return the WC content type name.
			 *
			 * @return string
			 */
			public function get_content_type_name() {
				return $this->content_type_name;
			}

			/**
			 * Return the restricted object IDs.
			 *
			 * @return int[]
			 */
			public function get_object_ids() {
				return $this->object_ids;
			}
		};
	}

	/**
	 * Build a plan-group descriptor as group_plans_by_fingerprint() would, carrying
	 * just the access method group_requires_purchase() inspects.
	 *
	 * @param string $access_method The WCM plan access method ('purchase' or 'signup').
	 *
	 * @return array
	 */
	private function make_group_plan( string $access_method ): array {
		return [
			'pid'           => 0,
			'name'          => 'Plan',
			'access_method' => $access_method,
			'ac_rules'      => [],
			'product_ids'   => [],
		];
	}

	/**
	 * A garbage `_product_ids` entry normalizes to 0, and a rule value of 0 grants the
	 * gate to every paying reader — WC_Subscription::has_product() matches a line item
	 * whose variation_id is 0, which every simple-product line item's is. A negative ID
	 * is dropped for the same reason: absint() would have turned it into a different,
	 * real product ID. A deleted product writes a rule nothing can satisfy, which fails
	 * safe but over-restricts, so it is dropped and reported too. Variations keep this
	 * command's inherited behavior and stay dropped.
	 */
	public function test_resolve_product_ids_drops_ids_a_subscription_rule_must_not_carry() {
		$product   = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$variation = self::factory()->post->create( [ 'post_type' => 'product_variation' ] );
		$deleted   = self::factory()->post->create( [ 'post_type' => 'product' ] );
		wp_delete_post( $deleted, true );

		$group = [
			[
				'pid'           => 0,
				'name'          => 'Plan',
				'access_method' => 'purchase',
				'ac_rules'      => [],
				'product_ids'   => [ $product, 0, -7, $deleted, $variation ],
			],
		];

		$resolved = $this->invoke_private_static( 'resolve_product_ids', [ $group ] );

		$this->assertSame( [ $product ], $resolved['product_ids'] );
		$this->assertSame( [ 0, -7 ], $resolved['dropped']['invalid'] );
		$this->assertSame( [ $deleted ], $resolved['dropped']['unresolvable'] );
		$this->assertSame( [ $variation ], $resolved['dropped']['variations'] );
	}

	/**
	 * A group is purchase-gated only when EVERY plan requires a purchase — the two
	 * gate modes AND for a logged-in reader, so a mixed group would demand the
	 * subscription from members the signup plan granted for free. A group holding one
	 * signup plan and one purchase plan is therefore registration-gated, not
	 * purchase-gated.
	 */
	public function test_group_requires_purchase_only_when_every_plan_is_purchase() {
		$all_purchase = [ $this->make_group_plan( 'purchase' ), $this->make_group_plan( 'purchase' ) ];
		$mixed        = [ $this->make_group_plan( 'signup' ), $this->make_group_plan( 'purchase' ) ];
		$all_signup   = [ $this->make_group_plan( 'signup' ) ];

		$this->assertTrue(
			$this->invoke_private_static( 'group_requires_purchase', [ $all_purchase ] ),
			'A group where every plan requires a purchase is purchase-gated.'
		);
		$this->assertFalse(
			$this->invoke_private_static( 'group_requires_purchase', [ $mixed ] ),
			'A mixed signup+purchase group is registration-gated — the signup plan grants the more permissive access.'
		);
		$this->assertFalse(
			$this->invoke_private_static( 'group_requires_purchase', [ $all_signup ] ),
			'A signup-only group is registration-gated.'
		);
	}

	/**
	 * NPPD-2063: the AC rule slug is the raw WooCommerce content-type name, not the
	 * AC content-rules key ('post_types' for post types, 'specific_posts' for
	 * individual posts). Object IDs are stringified. The stacked NPPD-2063 fix will
	 * change the expected slug here.
	 */
	public function test_map_rules_to_ac_format_uses_raw_wc_content_type_name_as_slug() {
		$post_rule = $this->make_rule( 'post', [ 12, 34 ] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $post_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post',
					'value' => [ '12', '34' ],
				],
			],
			$mapped_rules,
			'Slug should be the verbatim WC content-type name and values stringified (NPPD-2063 seam).'
		);
	}

	/**
	 * Two rules with the same content type are merged into one AC rule with a
	 * de-duplicated, stringified value list. (The 'category' slug assertion is also
	 * touched by NPPD-2063, which will remap the slug — expect this to flip red too.)
	 */
	public function test_map_rules_to_ac_format_merges_and_dedupes_object_ids_for_the_same_slug() {
		$first_category_rule  = $this->make_rule( 'category', [ 1, 2 ] );
		$second_category_rule = $this->make_rule( 'category', [ 2, 3 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $first_category_rule, $second_category_rule ] ]
		);

		$this->assertCount( 1, $mapped_rules, 'Same-slug rules collapse into a single AC rule.' );
		$this->assertSame( 'category', $mapped_rules[0]['slug'] );
		$this->assertSame( [ '1', '2', '3' ], $mapped_rules[0]['value'], 'Object IDs are merged, de-duplicated, and stringified.' );
	}

	/**
	 * Rules with an empty content-type name are dropped entirely.
	 */
	public function test_map_rules_to_ac_format_skips_rules_with_empty_content_type() {
		$empty_rule = $this->make_rule( '', [ 7 ] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $empty_rule ] ] );

		$this->assertSame( [], $mapped_rules );
	}

	/**
	 * NPPD-2064 grouping key: the fingerprint is canonical, so rule sets that are
	 * equivalent up to rule order and object-ID order collapse to the same string
	 * (and therefore into a single gate).
	 */
	public function test_compute_rules_fingerprint_is_independent_of_rule_and_value_order() {
		$rules_in_one_order = [
			[
				'slug'  => 'category',
				'value' => [ '2', '1' ],
			],
			[
				'slug'  => 'post',
				'value' => [ '5' ],
			],
		];
		$rules_in_other_order = [
			[
				'slug'  => 'post',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'category',
				'value' => [ '1', '2' ],
			],
		];

		$fingerprint_one   = $this->invoke_private_static( 'compute_rules_fingerprint', [ $rules_in_one_order ] );
		$fingerprint_other = $this->invoke_private_static( 'compute_rules_fingerprint', [ $rules_in_other_order ] );

		$this->assertSame( $fingerprint_one, $fingerprint_other, 'Equivalent rule sets must share a fingerprint so they merge into one gate (NPPD-2064 seam).' );
	}

	/**
	 * Differing content rules produce different fingerprints, so the plans land in
	 * separate gates.
	 */
	public function test_compute_rules_fingerprint_differs_for_different_rules() {
		$category_rules = [
			[
				'slug'  => 'category',
				'value' => [ '1' ],
			],
		];
		$post_rules = [
			[
				'slug'  => 'post',
				'value' => [ '1' ],
			],
		];

		$this->assertNotSame(
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $category_rules ] ),
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $post_rules ] )
		);
	}

	/**
	 * Happy path: top-level non-member-content maps to the registration layout and
	 * top-level member-content maps to the paid-access (custom_access) layout, with
	 * the WooCommerce wrapper blocks themselves stripped from the output.
	 */
	public function test_extract_gate_layouts_reads_top_level_wrapper_blocks() {
		$gate_content = <<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Become a member to read this.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>Thanks for being a member.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'Become a member to read this.', $layouts['registration'] );
		$this->assertStringContainsString( 'Thanks for being a member.', $layouts['custom_access'] );
		$this->assertStringNotContainsString( 'woocommerce-memberships/non-member-content', $layouts['registration'], 'The wrapper block markup is not carried into the layout.' );
	}

	/**
	 * A gate post may interleave several top-level wrappers of the same type (a post
	 * mixing public and members-only sections). Every wrapper's content is kept, in
	 * document order, for both wrapper types — no wrapper silently wins over another.
	 */
	public function test_extract_gate_layouts_concatenates_repeated_top_level_wrappers() {
		$gate_content = <<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>First upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>First members-only section.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Second upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>Second members-only section.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'First upsell.', $layouts['registration'] );
		$this->assertStringContainsString( 'Second upsell.', $layouts['registration'] );
		$this->assertStringContainsString( 'First members-only section.', $layouts['custom_access'] );
		$this->assertStringContainsString( 'Second members-only section.', $layouts['custom_access'] );
		$this->assertLessThan(
			strpos( $layouts['registration'], 'Second upsell.' ),
			strpos( $layouts['registration'], 'First upsell.' ),
			'Wrappers are concatenated in document order.'
		);
	}

	/**
	 * NPPD-2058: only top-level wrapper blocks are inspected, so a gate whose
	 * non-member-content wrapper is nested inside another block (here a group)
	 * migrates as an EMPTY registration layout. The stacked NPPD-2058 fix walks
	 * nested/reusable blocks and will make these assertions non-empty.
	 */
	public function test_extract_gate_layouts_returns_empty_for_nested_wrapper_blocks() {
		$gate_content = <<<'HTML'
<!-- wp:group --><div class="wp-block-group">
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Nested upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
</div><!-- /wp:group -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertSame( '', $layouts['registration'], 'A nested non-member-content wrapper yields an empty registration layout (NPPD-2058 bug).' );
		$this->assertNull( $layouts['custom_access'], 'No top-level member-content wrapper means a null custom-access layout.' );
	}

	/**
	 * The inner-block serializer drops WooCommerce Memberships wrapper blocks while
	 * keeping ordinary content blocks.
	 */
	public function test_serialize_gate_inner_blocks_strips_membership_wrapper_blocks() {
		$inner_blocks = parse_blocks(
			'<!-- wp:paragraph --><p>Keep me.</p><!-- /wp:paragraph -->'
			. '<!-- wp:woocommerce-memberships/member-content --><!-- wp:paragraph --><p>Drop me.</p><!-- /wp:paragraph --><!-- /wp:woocommerce-memberships/member-content -->'
		);

		$serialized = $this->invoke_private_static( 'serialize_gate_inner_blocks', [ $inner_blocks ] );

		$this->assertStringContainsString( 'Keep me.', $serialized );
		$this->assertStringNotContainsString( 'woocommerce-memberships/member-content', $serialized );
		$this->assertStringNotContainsString( 'Drop me.', $serialized );
	}

	/**
	 * A gate whose every content rule carries a slug the evaluator cannot resolve is
	 * reported as unenforceable.
	 *
	 * This is the NPPD-2063 slug mistranslation seen from the operator's side: the
	 * migration writes rules with the raw WooCommerce content-type name ('post'), and
	 * Content_Restriction_Control::rule_matches_post() falls through to
	 * get_taxonomy( 'post' ) — which is null — so the gate matches no post at all.
	 */
	public function test_verify_migrated_gate_flags_content_rules_the_evaluator_cannot_resolve() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post',
					'value' => [ '1' ],
				],
			] 
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'none of its content rules resolve', $issues[0] );
		$this->assertStringContainsString( 'post', $issues[0] );
	}

	/**
	 * A gate whose rules are only partly resolvable under-gates rather than failing
	 * outright: the rules combine with 'any', so the content behind the dead slugs is
	 * left readable while the rest is gated. That partial leak is reported too — a
	 * plan restricting all posts plus a category (a common WCM configuration) maps to
	 * exactly this shape, and reporting it clean would hide the NPPD-2063 blast radius
	 * until cutover.
	 */
	public function test_verify_migrated_gate_flags_content_rules_only_some_of_which_resolve() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post',
					'value' => [ '1' ],
				],
				[
					'slug'  => 'category',
					'value' => [ '2' ],
				],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '1 of its 2 content rules do not resolve', $issues[0] );
		$this->assertStringContainsString( 'post', $issues[0], 'The dead slug is named so the operator knows what is left ungated.' );
	}

	/**
	 * A gate migrated from a plan that required a purchase, but whose paid access mode
	 * was never activated, lets any registered reader through: registration mode alone
	 * stops nobody with an account, since the migration never writes
	 * require_verification. That is a worse outcome than an inert gate — the content
	 * silently loses its paywall at cutover — so it must not pass verification.
	 */
	public function test_verify_migrated_gate_flags_a_purchase_gate_with_no_paid_access_mode() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'its paid access mode is not active', $issues[0] );
		$this->assertSame(
			[],
			$this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, false ] ),
			'The same gate is fine for a signup-only plan — only the purchase requirement makes it a leak.'
		);
	}

	/**
	 * An active paid access mode with no access rules asks for no purchase:
	 * is_post_restricted() skips an empty rule set, so a registered reader passes.
	 * Reachable when every one of a plan's products is a variation (dropped as gates
	 * reference parent products only) or when the plan has no products at all.
	 */
	public function test_verify_migrated_gate_flags_a_purchase_gate_whose_paid_access_has_no_rules() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);
		\Newspack\Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Paid access fixture layout', '' ),
				'access_rules'   => [],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no access rules', $issues[0] );
	}

	/**
	 * The paid path migrated fully — an active paid access mode constrained by the
	 * plan's products — so nothing is reported.
	 */
	public function test_verify_migrated_gate_passes_a_purchase_gate_with_product_access_rules() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);
		\Newspack\Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Paid access fixture layout', '' ),
				'access_rules'   => [
					[
						[
							'slug'  => 'subscription',
							'value' => [ 123 ],
						],
					],
				],
			]
		);

		$this->assertSame( [], $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] ) );
	}

	/**
	 * The evaluator only checks that a mode's layout ID is truthy, so a blank layout
	 * post counts as "gated" and the reader gets a truncated article with nothing
	 * under it — no form, no upsell, no explanation.
	 */
	public function test_verify_migrated_gate_flags_an_active_mode_with_an_empty_layout() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);
		$layout_id = \Newspack\Content_Gate::get_registration_settings( $gate_id )['gate_layout_id'];
		wp_update_post(
			[
				'ID'           => $layout_id,
				'post_content' => '',
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'points at an empty layout', $issues[0] );
	}

	/**
	 * A gate written with rule slugs the evaluator handles by name, an active mode and
	 * a layout post passes verification with no issues.
	 */
	public function test_verify_migrated_gate_passes_for_an_enforceable_gate() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			] 
		);

		$this->assertSame( [], $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] ) );
	}

	/**
	 * An active mode pointing at no layout post restricts nothing —
	 * Content_Restriction_Control requires a truthy gate_layout_id — so it is flagged.
	 */
	public function test_verify_migrated_gate_flags_an_active_mode_with_no_layout() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			] 
		);
		\Newspack\Content_Gate::update_registration_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => 0,
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertContains( 'the registration mode is active with no layout', $issues );
	}

	/**
	 * A gate whose modes are all inactive is skipped outright by the evaluator.
	 */
	public function test_verify_migrated_gate_flags_a_gate_with_no_active_mode() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			] 
		);
		\Newspack\Content_Gate::update_registration_settings( $gate_id, [ 'active' => false ] );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertContains( 'neither the registration nor the paid access mode is active', $issues );
	}

	/**
	 * Slug resolvability mirrors Content_Restriction_Control::rule_matches_post():
	 * three slugs are handled by name and everything else must be a registered
	 * taxonomy.
	 */
	public function test_is_content_rule_slug_resolvable_matches_the_evaluator() {
		foreach ( [ 'post_types', 'specific_posts', 'newsletters', 'category', 'post_tag' ] as $resolvable_slug ) {
			$this->assertTrue(
				$this->invoke_private_static( 'is_content_rule_slug_resolvable', [ $resolvable_slug ] ),
				sprintf( 'Expected "%s" to be resolvable.', $resolvable_slug )
			);
		}
		foreach ( [ 'post', 'page', 'not_a_taxonomy' ] as $unresolvable_slug ) {
			$this->assertFalse(
				$this->invoke_private_static( 'is_content_rule_slug_resolvable', [ $unresolvable_slug ] ),
				sprintf( 'Expected "%s" to be unresolvable.', $unresolvable_slug )
			);
		}
	}

	// -------------------------------------------------------------------------
	// compute_pre_write_issues() — dry-run predictive verification
	// -------------------------------------------------------------------------

	/**
	 * A plan with all-unresolvable content-rule slugs is flagged in dry-run, mirroring
	 * the "none of its content rules resolve" check in verify_migrated_gate().
	 */
	public function test_compute_pre_write_issues_flags_all_unresolvable_slugs() {
		$ac_rules = [
			[
				'slug'  => 'post',
				'value' => [ '1' ],
			],
		];
		$layouts  = [
			'registration'  => '',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts, [] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'none of its content rules resolve', $issues[0] );
		$this->assertStringContainsString( 'post', $issues[0] );
	}

	/**
	 * When only some slugs are unresolvable, the partial-leak variant of the message
	 * is produced — the content behind the dead rules stays ungated while the rest is
	 * covered.
	 */
	public function test_compute_pre_write_issues_flags_partially_unresolvable_slugs() {
		$ac_rules = [
			[
				'slug'  => 'post',
				'value' => [ '1' ],
			],
			[
				'slug'  => 'category',
				'value' => [ '2' ],
			],
		];
		$layouts = [
			'registration'  => '',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts, [] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '1 of its 2 content rules do not resolve', $issues[0] );
	}

	/**
	 * A purchase plan with no custom_access layout extracted is flagged — apply_layout()
	 * will be skipped for the paid access mode, so any registered reader gets through.
	 * Mirrors verify_migrated_gate()'s "paid access mode is not active" check.
	 */
	public function test_compute_pre_write_issues_flags_purchase_plan_with_no_custom_access_layout() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Upsell.</p>',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, true, $layouts, [ 123 ] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'paid access mode will not be activated', $issues[0] );
	}

	/**
	 * A purchase plan whose merged product IDs are all empty is flagged — access_rules
	 * will be an empty array, so the paid access mode asks for no purchase and any
	 * registered reader passes. Mirrors verify_migrated_gate()'s "active but has no
	 * access rules" check.
	 */
	public function test_compute_pre_write_issues_flags_purchase_plan_with_empty_product_ids() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Upsell.</p>',
			'custom_access' => '<p>Member content.</p>',
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, true, $layouts, [] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no access rules', $issues[0] );
	}

	/**
	 * A signup-only plan with resolvable slugs produces no pre-write issues.
	 */
	public function test_compute_pre_write_issues_returns_empty_for_a_clean_signup_plan() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Register.</p>',
			'custom_access' => null,
		];

		$this->assertSame(
			[],
			$this->invoke_private_static(
				'compute_pre_write_issues',
				[ $ac_rules, false, $layouts, [] ]
			)
		);
	}

	/**
	 * A purchase plan with a custom_access layout and at least one product ID is clean.
	 */
	public function test_compute_pre_write_issues_returns_empty_for_a_clean_purchase_plan() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Upsell.</p>',
			'custom_access' => '<p>Welcome.</p>',
		];

		$this->assertSame(
			[],
			$this->invoke_private_static(
				'compute_pre_write_issues',
				[ $ac_rules, true, $layouts, [ 99 ] ]
			)
		);
	}

	/**
	 * Create a published gate with an active registration mode pointing at a real
	 * layout post — i.e. enforceable except for the content rules under test.
	 *
	 * @param array[] $content_rules AC-format content rules.
	 *
	 * @return int The gate post ID.
	 */
	private function create_enforceable_gate( array $content_rules ): int {
		$gate_id = \Newspack\Content_Gate::create_gate( [ 'title' => 'Verification fixture' ] );
		\Newspack\Content_Rules::update_gate_content_rules( $gate_id, $content_rules );
		\Newspack\Content_Gate::update_registration_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Verification fixture layout', '' ),
			]
		);
		return $gate_id;
	}

	/**
	 * Create a published post carrying the given block content, standing in for an
	 * np_memberships_gate.
	 *
	 * A plain post (the default 'post' type) is used because extract_gate_layouts()
	 * only reads post_content — it never checks the post type — so the real
	 * np_memberships_gate CPT (registered by WooCommerce Memberships, which is not
	 * loaded in the unit-test harness) is not needed here.
	 *
	 * @param string $content The block markup.
	 *
	 * @return \WP_Post
	 */
	private function create_gate_post( string $content ): \WP_Post {
		$post_id = self::factory()->post->create(
			[
				'post_content' => $content,
				'post_status'  => 'publish',
			]
		);
		return get_post( $post_id );
	}

	/**
	 * Newsletter-list rules belong to the premium newsletter gate bucket, which
	 * migrate-premium-newsletters writes. Mapped here they would be inert — the
	 * evaluator judges a list post against the newsletter bucket — while still
	 * entering the fingerprint, splitting two plans that restrict identical content
	 * into two gates.
	 */
	public function test_map_rules_to_ac_format_skips_newsletter_list_rules() {
		$rules = [
			$this->make_rule( 'post', [] ),
			$this->make_rule( Subscription_Lists::CPT, [ 21, 22 ] ),
		];

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ $rules ] );

		$this->assertCount( 1, $mapped_rules );
		$this->assertSame( 'post', $mapped_rules[0]['slug'] );
	}

	/**
	 * A plan restricting only newsletter lists maps to no content rules at all, which
	 * is correct — but it is not the same as a plan that restricts nothing, and the
	 * operator needs to know where it went.
	 */
	public function test_plan_has_newsletter_rules_distinguishes_the_skip_reason() {
		$this->assertTrue(
			$this->invoke_private_static( 'plan_has_newsletter_rules', [ [ $this->make_rule( Subscription_Lists::CPT, [ 21 ] ) ] ] )
		);
		$this->assertFalse(
			$this->invoke_private_static( 'plan_has_newsletter_rules', [ [ $this->make_rule( 'post', [] ) ] ] )
		);
	}

	/**
	 * Build a plan-group descriptor carrying just the name find_superseded_gates()
	 * inspects.
	 *
	 * @param string $name The plan name.
	 *
	 * @return array
	 */
	private function make_named_plan( string $name ): array {
		return [
			'pid'           => 0,
			'name'          => $name,
			'access_method' => 'purchase',
			'ac_rules'      => [],
			'product_ids'   => [],
		];
	}

	/**
	 * When regrouping merges plans a previous run migrated separately, the gates
	 * those plans were written to are named so the operator can retire them.
	 */
	public function test_find_superseded_gates_names_gates_the_merged_plans_already_have() {
		$group          = [ $this->make_named_plan( 'Plan A' ), $this->make_named_plan( 'Plan B' ) ];
		$existing_gates = [
			'plan a' => 11,
			'plan b' => 22,
		];

		$superseded = $this->invoke_private_static( 'find_superseded_gates', [ $group, 'plan a | plan b', $existing_gates ] );

		$this->assertSame(
			[
				'plan a' => 11,
				'plan b' => 22,
			],
			$superseded
		);
	}

	/**
	 * A single-plan group's title IS its plan name, so the gate it is about to update
	 * must never be reported as superseded by itself.
	 */
	public function test_find_superseded_gates_excludes_the_groups_own_title() {
		$group = [ $this->make_named_plan( 'Plan A' ) ];

		$superseded = $this->invoke_private_static( 'find_superseded_gates', [ $group, 'plan a', [ 'plan a' => 11 ] ] );

		$this->assertSame( [], $superseded );
	}

	/**
	 * A null entry marks a title claimed by this run rather than a gate found on the
	 * site, so there is no prior gate to retire.
	 */
	public function test_find_superseded_gates_ignores_titles_claimed_by_this_run() {
		$group = [ $this->make_named_plan( 'Plan A' ), $this->make_named_plan( 'Plan B' ) ];

		$superseded = $this->invoke_private_static( 'find_superseded_gates', [ $group, 'plan a | plan b', [ 'plan a' => null ] ] );

		$this->assertSame( [], $superseded );
	}

	/**
	 * A genuinely new group supersedes nothing.
	 */
	public function test_find_superseded_gates_returns_empty_when_no_plan_has_a_gate() {
		$group = [ $this->make_named_plan( 'Plan A' ), $this->make_named_plan( 'Plan B' ) ];

		$superseded = $this->invoke_private_static( 'find_superseded_gates', [ $group, 'plan a | plan b', [ 'other' => 11 ] ] );

		$this->assertSame( [], $superseded );
	}
}
