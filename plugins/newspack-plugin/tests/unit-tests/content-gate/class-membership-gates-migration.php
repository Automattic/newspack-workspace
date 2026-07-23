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

require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mock.php';
require_once dirname( __DIR__, 3 ) . '/includes/cli/class-membership-gates-migration.php';

/**
 * Characterization tests for the migrate-membership-gates helpers.
 */
class Test_Membership_Gates_Migration extends \WP_UnitTestCase {

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

	/**
	 * NPPD-2106: WCM access lengths map onto the one_time_purchase duration schema —
	 * days stay days, weeks convert to days, months stay months, years convert to
	 * months (the rule's only units are 'days', 'months', and 'forever').
	 */
	public function test_map_access_length_to_duration_maps_wcm_periods_to_rule_units() {
		$expected_durations_by_length = [
			'30 days'  => [
				'duration_value' => 30,
				'duration_unit'  => 'days',
			],
			'2 weeks'  => [
				'duration_value' => 14,
				'duration_unit'  => 'days',
			],
			'6 months' => [
				'duration_value' => 6,
				'duration_unit'  => 'months',
			],
			'1 years'  => [
				'duration_value' => 12,
				'duration_unit'  => 'months',
			],
		];
		foreach ( $expected_durations_by_length as $wcm_length => $expected_duration ) {
			[ $amount, $period ] = explode( ' ', $wcm_length );
			$this->assertSame(
				$expected_duration,
				$this->invoke_private_static( 'map_access_length_to_duration', [ (int) $amount, $period ] ),
				sprintf( 'WCM access length "%s" should map to %s.', $wcm_length, wp_json_encode( $expected_duration ) )
			);
		}
	}

	/**
	 * NPPD-2106: a plan with no access length is unlimited in WCM
	 * (get_access_length_amount()/period() return '') and maps to 'forever'.
	 */
	public function test_map_access_length_to_duration_treats_missing_length_as_forever() {
		$forever_duration = [
			'duration_value' => 0,
			'duration_unit'  => 'forever',
		];

		$this->assertSame( $forever_duration, $this->invoke_private_static( 'map_access_length_to_duration', [ '', '' ] ) );
		$this->assertSame( $forever_duration, $this->invoke_private_static( 'map_access_length_to_duration', [ 0, 'days' ] ) );
	}

	/**
	 * NPPD-2106: an unrecognized period (a filtered custom WCM period) must not be
	 * guessed at — especially not widened to 'forever'. The mapper returns null so
	 * the caller can fail closed and warn the operator.
	 */
	public function test_map_access_length_to_duration_returns_null_for_unrecognized_period() {
		$this->assertNull( $this->invoke_private_static( 'map_access_length_to_duration', [ 3, 'fortnights' ] ) );
	}

	/**
	 * NPPD-2106: a group with only subscription products keeps the pre-existing
	 * mapping — a single OR group holding one 'subscription' rule with the merged
	 * product IDs.
	 */
	public function test_build_paid_access_rules_maps_subscription_products_to_the_subscription_rule() {
		$access_rules = $this->invoke_private_static(
			'build_paid_access_rules',
			[
				[
					$this->make_paid_descriptor( [ 101, 102 ], [], null ),
					$this->make_paid_descriptor( [ 102, 103 ], [], null ),
				],
			]
		);

		$this->assertSame(
			[
				[
					[
						'slug'  => 'subscription',
						'value' => [ 101, 102, 103 ],
					],
				],
			],
			$access_rules,
			'Subscription products keep the existing subscription-rule mapping, de-duplicated across the group.'
		);
	}

	/**
	 * NPPD-2106: simple (non-subscription) products with a membership length map to a
	 * one_time_purchase rule carrying the #693 value schema — product_ids +
	 * duration_value + duration_unit.
	 */
	public function test_build_paid_access_rules_maps_simple_products_to_a_one_time_purchase_rule() {
		$access_rules = $this->invoke_private_static(
			'build_paid_access_rules',
			[
				[
					$this->make_paid_descriptor(
						[],
						[ 201 ],
						[
							'duration_value' => 12,
							'duration_unit'  => 'months',
						]
					),
				],
			]
		);

		$this->assertSame(
			[
				[
					[
						'slug'  => 'one_time_purchase',
						'value' => [
							'product_ids'    => [ 201 ],
							'duration_value' => 12,
							'duration_unit'  => 'months',
						],
					],
				],
			],
			$access_rules
		);
	}

	/**
	 * NPPD-2106: a mixed plan (subscription + simple products) yields both rules as
	 * separate OR groups on the same gate — an active subscription OR a qualifying
	 * one-time purchase grants access.
	 */
	public function test_build_paid_access_rules_puts_subscription_and_one_time_rules_in_separate_or_groups() {
		$access_rules = $this->invoke_private_static(
			'build_paid_access_rules',
			[
				[
					$this->make_paid_descriptor(
						[ 101 ],
						[ 201 ],
						[
							'duration_value' => 0,
							'duration_unit'  => 'forever',
						]
					),
				],
			]
		);

		$this->assertCount( 2, $access_rules, 'Subscription and one-time rules are separate OR groups.' );
		$this->assertCount( 1, $access_rules[0], 'Each OR group holds a single rule (no AND-ing of the two).' );
		$this->assertSame( 'subscription', $access_rules[0][0]['slug'] );
		$this->assertSame( 'one_time_purchase', $access_rules[1][0]['slug'] );
		$this->assertSame( [ 101 ], $access_rules[0][0]['value'], 'Simple products must not leak into the subscription rule.' );
		$this->assertSame( [ 201 ], $access_rules[1][0]['value']['product_ids'] );
	}

	/**
	 * NPPD-2106: plans sharing a duration merge their simple products into one
	 * one_time_purchase rule; a plan with a different duration gets its own rule, so
	 * one gate can carry e.g. an annual day-pass product and a lifetime product.
	 */
	public function test_build_paid_access_rules_buckets_one_time_products_by_duration() {
		$twelve_months = [
			'duration_value' => 12,
			'duration_unit'  => 'months',
		];
		$forever       = [
			'duration_value' => 0,
			'duration_unit'  => 'forever',
		];

		$access_rules = $this->invoke_private_static(
			'build_paid_access_rules',
			[
				[
					$this->make_paid_descriptor( [], [ 201, 202 ], $twelve_months ),
					$this->make_paid_descriptor( [], [ 202, 203 ], $twelve_months ),
					$this->make_paid_descriptor( [], [ 301 ], $forever ),
				],
			]
		);

		$this->assertCount( 2, $access_rules );
		$this->assertSame(
			[
				'product_ids'    => [ 201, 202, 203 ],
				'duration_value' => 12,
				'duration_unit'  => 'months',
			],
			$access_rules[0][0]['value'],
			'Same-duration plans share one rule with merged, de-duplicated product IDs.'
		);
		$this->assertSame(
			[
				'product_ids'    => [ 301 ],
				'duration_value' => 0,
				'duration_unit'  => 'forever',
			],
			$access_rules[1][0]['value'],
			'A different duration gets its own OR group.'
		);
	}

	/**
	 * NPPD-2106: no products at all (or descriptors for signup plans only) produce no
	 * paid access rules — output unchanged from the pre-2106 behavior.
	 */
	public function test_build_paid_access_rules_returns_empty_for_no_products() {
		$this->assertSame( [], $this->invoke_private_static( 'build_paid_access_rules', [ [] ] ) );
		$this->assertSame(
			[],
			$this->invoke_private_static(
				'build_paid_access_rules',
				[ [ $this->make_paid_descriptor( [], [], null ) ] ]
			)
		);
	}

	/**
	 * NPPD-2106: a null duration (unmappable WCM access length) fails closed — the
	 * plan's simple products are excluded from the one_time_purchase rule rather than
	 * silently granted forever. The caller is responsible for warning the operator.
	 */
	public function test_build_paid_access_rules_skips_one_time_products_with_a_null_duration() {
		$access_rules = $this->invoke_private_static(
			'build_paid_access_rules',
			[
				[
					$this->make_paid_descriptor( [ 101 ], [ 201 ], null ),
				],
			]
		);

		$this->assertCount( 1, $access_rules, 'Only the subscription rule is emitted.' );
		$this->assertSame( 'subscription', $access_rules[0][0]['slug'] );
	}

	/**
	 * NPPD-2106: the summary-table description of the paid access rules, so dry-run
	 * reporting shows the mapping before anything is written.
	 */
	public function test_describe_paid_access_rules_summarizes_rules_for_the_summary_table() {
		$this->assertSame( '—', $this->invoke_private_static( 'describe_paid_access_rules', [ [] ] ) );

		$description = $this->invoke_private_static(
			'describe_paid_access_rules',
			[
				[
					[
						[
							'slug'  => 'subscription',
							'value' => [ 101, 102 ],
						],
					],
					[
						[
							'slug'  => 'one_time_purchase',
							'value' => [
								'product_ids'    => [ 201 ],
								'duration_value' => 12,
								'duration_unit'  => 'months',
							],
						],
					],
					[
						[
							'slug'  => 'one_time_purchase',
							'value' => [
								'product_ids'    => [ 301 ],
								'duration_value' => 0,
								'duration_unit'  => 'forever',
							],
						],
					],
				],
			]
		);

		$this->assertSame(
			'subscription(101, 102) OR one_time_purchase(201; 12 months) OR one_time_purchase(301; forever)',
			$description
		);
	}

	/**
	 * NPPD-2106 review: both gate evaluators treat empty access rules as
	 * unrestricted, so a paid access mode that is active with no rules leaves the
	 * gate open to every registered reader — verify_migrated_gate() must flag it.
	 */
	public function test_verify_migrated_gate_flags_an_active_paid_access_mode_with_no_access_rules() {
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
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Paid fixture layout', '' ),
				'access_rules'   => [],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertContains(
			'the paid access mode is active with no access rules — registered readers are not restricted by it',
			$issues
		);
	}

	/**
	 * NPPD-2106 review: a run that maps NO paid rules must leave the paid access
	 * mode exactly as it is — never activate it with an empty rule set, and never
	 * clobber rules a previous (correctly equipped) run already wrote. This is the
	 * re-run-from-a-pre-one_time_purchase-build scenario.
	 */
	public function test_apply_paid_access_skips_and_preserves_existing_rules_when_no_rules_are_mapped() {
		$gate_id                 = \Newspack\Content_Gate::create_gate( [ 'title' => 'Paid preservation fixture' ] );
		$existing_one_time_rules = [
			[
				[
					'slug'  => 'one_time_purchase',
					'value' => [
						'product_ids'    => [ 12 ],
						'duration_value' => 0,
						'duration_unit'  => 'forever',
					],
				],
			],
		];
		\Newspack\Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Existing paid layout', '<!-- wp:paragraph --><p>Existing.</p><!-- /wp:paragraph -->' ),
				'access_rules'   => $existing_one_time_rules,
			]
		);

		$result = $this->invoke_private_static(
			'apply_paid_access',
			[ $gate_id, 'Paid preservation fixture', '<!-- wp:paragraph --><p>New.</p><!-- /wp:paragraph -->', [] ]
		);

		$settings_after = \Newspack\Content_Gate::get_custom_access_settings( $gate_id );
		$this->assertSame( 'skipped_no_rules', $result );
		$this->assertSame(
			$existing_one_time_rules,
			$settings_after['access_rules'],
			'A run with no mappable paid rules must not overwrite previously written rules.'
		);
		$this->assertNotEmpty( $settings_after['active'], 'The previously active mode stays active.' );
	}

	/**
	 * NPPD-2106 review: with mapped rules the paid access mode is configured (and a
	 * missing source layout still skips, matching the pre-existing behavior).
	 */
	public function test_apply_paid_access_configures_the_mode_when_rules_are_mapped() {
		$gate_id      = \Newspack\Content_Gate::create_gate( [ 'title' => 'Paid configure fixture' ] );
		$mapped_rules = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ 101 ],
				],
			],
		];

		$this->assertSame(
			'skipped_no_layout',
			$this->invoke_private_static( 'apply_paid_access', [ $gate_id, 'Paid configure fixture', null, $mapped_rules ] ),
			'No member-content layout in the source gate skips the mode (pre-existing behavior).'
		);
		$this->assertEmpty(
			\Newspack\Content_Gate::get_custom_access_settings( $gate_id )['active'],
			'A skip leaves the mode untouched.'
		);

		$result = $this->invoke_private_static(
			'apply_paid_access',
			[ $gate_id, 'Paid configure fixture', '<!-- wp:paragraph --><p>Members.</p><!-- /wp:paragraph -->', $mapped_rules ]
		);

		$settings_after = \Newspack\Content_Gate::get_custom_access_settings( $gate_id );
		$this->assertSame( 'configured', $result );
		$this->assertSame( $mapped_rules, $settings_after['access_rules'] );
		$this->assertNotEmpty( $settings_after['active'] );
		$this->assertNotEmpty( $settings_after['gate_layout_id'] );
	}

	/**
	 * NPPD-2106 guard path: on a build that has not registered the
	 * one_time_purchase access rule, a plan's one-time products are dropped from
	 * the mapping (with a warning), and the group yields no paid rules at all — so
	 * paid access configuration is skipped rather than written empty.
	 */
	public function test_get_group_paid_descriptors_drops_one_time_products_when_the_rule_is_unregistered() {
		$rules_property = new \ReflectionProperty( \Newspack\Access_Rules::class, 'rules' );
		$rules_property->setAccessible( true );
		$original_rules         = (array) $rules_property->getValue();
		$rules_without_one_time = $original_rules;
		unset( $rules_without_one_time['one_time_purchase'] );
		$rules_property->setValue( null, $rules_without_one_time );
		\WP_CLI::$warnings = [];

		try {
			$descriptors = $this->invoke_private_static(
				'get_group_paid_descriptors',
				[ [ $this->make_group_plan( [ 201 ] ) ] ]
			);
		} finally {
			$rules_property->setValue( null, $original_rules );
		}

		$this->assertSame( [], $descriptors[0]['one_time_product_ids'], 'One-time products are dropped when the rule is not registered (fail closed).' );
		$this->assertSame(
			[],
			$this->invoke_private_static( 'build_paid_access_rules', [ $descriptors ] ),
			'The group yields no paid rules, so the migration skips paid access configuration.'
		);
		$this->assertCount( 1, \WP_CLI::$warnings );
		$this->assertStringContainsString( 'does not register the one_time_purchase access rule', \WP_CLI::$warnings[0] );
	}

	/**
	 * NPPD-2106 review: dropping a linked product variation is no longer silent —
	 * the operator is told which variation IDs were dropped and that the parent
	 * product can be added to the gate manually.
	 */
	public function test_get_group_paid_descriptors_warns_when_dropping_product_variations() {
		$variation_id = self::factory()->post->create( [ 'post_type' => 'product_variation' ] );

		// Register a stub one_time_purchase rule so the unregistered-rule guard does
		// not also fire and muddy the warning assertions.
		$rules_property = new \ReflectionProperty( \Newspack\Access_Rules::class, 'rules' );
		$rules_property->setAccessible( true );
		$original_rules = (array) $rules_property->getValue();
		$rules_property->setValue( null, array_merge( $original_rules, [ 'one_time_purchase' => [ 'name' => 'One-time purchase' ] ] ) );
		\WP_CLI::$warnings = [];

		try {
			$descriptors = $this->invoke_private_static(
				'get_group_paid_descriptors',
				[ [ $this->make_group_plan( [ $variation_id, 201 ] ) ] ]
			);
		} finally {
			$rules_property->setValue( null, $original_rules );
		}

		$this->assertSame( [ 201 ], $descriptors[0]['one_time_product_ids'], 'The non-variation product stays mapped.' );
		$this->assertCount( 1, \WP_CLI::$warnings );
		$this->assertStringContainsString( (string) $variation_id, \WP_CLI::$warnings[0] );
		$this->assertStringContainsString( 'parent product', \WP_CLI::$warnings[0] );
	}

	/**
	 * Build a plan descriptor shaped like group_plans_by_fingerprint() output, for
	 * exercising get_group_paid_descriptors() without WooCommerce Memberships.
	 *
	 * @param int[]      $product_ids          Linked product IDs.
	 * @param int|string $access_length_amount WCM access length amount ('' = unlimited).
	 * @param string     $access_length_period WCM access length period ('' = unlimited).
	 * @param string     $access_length_type   WCM access length type.
	 *
	 * @return array The plan descriptor.
	 */
	private function make_group_plan( array $product_ids, $access_length_amount = '', string $access_length_period = '', string $access_length_type = 'unlimited' ): array {
		return [
			'pid'                  => 0,
			'name'                 => 'Guard fixture plan',
			'access_method'        => 'purchase',
			'ac_rules'             => [],
			'product_ids'          => $product_ids,
			'access_length_amount' => $access_length_amount,
			'access_length_period' => $access_length_period,
			'access_length_type'   => $access_length_type,
		];
	}

	/**
	 * Build a per-plan paid-access descriptor as the migration loop hands it to
	 * build_paid_access_rules().
	 *
	 * @param int[]      $subscription_product_ids Subscription product IDs.
	 * @param int[]      $one_time_product_ids     Simple (one-time) product IDs.
	 * @param array|null $duration                 Mapped duration, or null when unmappable.
	 *
	 * @return array The descriptor.
	 */
	private function make_paid_descriptor( array $subscription_product_ids, array $one_time_product_ids, ?array $duration ): array {
		return [
			'subscription_product_ids' => $subscription_product_ids,
			'one_time_product_ids'     => $one_time_product_ids,
			'duration'                 => $duration,
		];
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
}
