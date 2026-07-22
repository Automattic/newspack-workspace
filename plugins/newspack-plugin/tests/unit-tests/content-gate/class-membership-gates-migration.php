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
