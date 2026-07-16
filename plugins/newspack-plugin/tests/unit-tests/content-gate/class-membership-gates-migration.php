<?php
/**
 * Characterization tests for the migrate-membership-gates CLI (NPPD-2059).
 *
 * These pin the behavior of the pure mapping/fingerprint/layout-extraction
 * helpers. The map_rules_to_ac_format tests assert the NPPD-2063 translation table
 * (WC content rules → valid AC 'post_types' / 'specific_posts' / taxonomy slugs).
 * Where a test asserts a buggy result on purpose it is flagged with the follow-up
 * issue ID; that stacked fix will flip the corresponding assertion:
 *
 * - NPPD-2058: extract_gate_layouts() only inspects top-level wrapper blocks, so
 *   nested / reusable-block gate layouts migrate as empty. Pinned by the
 *   extract_gate_layouts / serialize_gate_inner_blocks tests below (they flip red).
 *
 * NPPD-2064 (content-overlap consolidation, preserving WCM's OR access semantics):
 * the consolidation *decision* logic is extracted into WC-free pure helpers
 * (rules_cover, rule_sets_overlap, plan_rule_set_consolidation, group_product_ids,
 * format_overlap_warning) and pinned by the net-new tests below. The group→gate wiring
 * that drives them lives in group_plans_by_fingerprint() / consolidate_plan_groups(),
 * which depend on WC_Memberships_Membership_Plan and so are exercised end-to-end against
 * real WooCommerce Memberships. The compute_rules_fingerprint() tests only pin the
 * fingerprint's *canonicality* (order-independence), which the 2064 fix preserves.
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
	 * The rule mapping only calls get_content_type(), get_content_type_name() and
	 * get_object_ids(), so the WC Memberships plugin is not needed to exercise it.
	 *
	 * @param string $content_type      The WC content type kind ('post_type' or 'taxonomy').
	 * @param string $content_type_name The WC content type name (e.g. 'post', 'category').
	 * @param int[]  $object_ids        The restricted object IDs.
	 *
	 * @return object A rule-shaped object.
	 */
	private function make_rule( string $content_type, string $content_type_name, array $object_ids ) {
		return new class( $content_type, $content_type_name, $object_ids ) {

			/**
			 * The WC content type kind.
			 *
			 * @var string
			 */
			private $content_type;

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
			 * @param string $content_type      The WC content type kind.
			 * @param string $content_type_name The WC content type name.
			 * @param int[]  $object_ids        The restricted object IDs.
			 */
			public function __construct( string $content_type, string $content_type_name, array $object_ids ) {
				$this->content_type      = $content_type;
				$this->content_type_name = $content_type_name;
				$this->object_ids        = $object_ids;
			}

			/**
			 * Return the WC content type kind ('post_type' or 'taxonomy').
			 *
			 * @return string
			 */
			public function get_content_type() {
				return $this->content_type;
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
	 * A post-type rule targeting specific objects maps to a 'specific_posts' rule
	 * whose value is the stringified object IDs — the slug AC enforcement honours for
	 * individual posts (a raw 'post'/'page' slug would never match any post).
	 */
	public function test_map_rules_to_ac_format_maps_specific_post_type_rule_to_specific_posts() {
		$post_rule = $this->make_rule( 'post_type', 'post', [ 12, 34 ] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $post_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'specific_posts',
					'value' => [ '12', '34' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * A post-type rule with no object IDs restricts the whole post type, so it maps
	 * to a 'post_types' rule whose value is the post-type slug.
	 */
	public function test_map_rules_to_ac_format_maps_all_posts_rule_to_post_types() {
		$all_posts_rule = $this->make_rule( 'post_type', 'post', [] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $all_posts_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * The post_type vs. taxonomy split relies on the rule's own get_content_type()
	 * discriminator, so a whole-post-type rule for a custom post type (here
	 * 'guest-author') maps to a 'post_types' rule carrying that custom post-type slug
	 * as its value — no hardcoded post-type name list is consulted.
	 */
	public function test_map_rules_to_ac_format_maps_custom_post_type_to_post_types() {
		$guest_author_rule = $this->make_rule( 'post_type', 'guest-author', [] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $guest_author_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'guest-author' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * Taxonomy rules already use the taxonomy slug as their AC slug (which AC
	 * enforcement resolves via get_taxonomy()), so they pass through unchanged with a
	 * term-ID value list.
	 */
	public function test_map_rules_to_ac_format_keeps_taxonomy_slug_unchanged() {
		$category_rule = $this->make_rule( 'taxonomy', 'category', [ 5, 6 ] );
		$tag_rule      = $this->make_rule( 'taxonomy', 'post_tag', [ 7 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $category_rule, $tag_rule ] ]
		);

		$this->assertSame(
			[
				[
					'slug'  => 'category',
					'value' => [ '5', '6' ],
				],
				[
					'slug'  => 'post_tag',
					'value' => [ '7' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * Two taxonomy rules for the same taxonomy collapse into a single AC rule with a
	 * de-duplicated, stringified term-ID list.
	 */
	public function test_map_rules_to_ac_format_merges_and_dedupes_object_ids_for_the_same_slug() {
		$first_category_rule  = $this->make_rule( 'taxonomy', 'category', [ 1, 2 ] );
		$second_category_rule = $this->make_rule( 'taxonomy', 'category', [ 2, 3 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $first_category_rule, $second_category_rule ] ]
		);

		$this->assertCount( 1, $mapped_rules, 'Same-slug rules collapse into a single AC rule.' );
		$this->assertSame( 'category', $mapped_rules[0]['slug'] );
		$this->assertSame( [ '1', '2', '3' ], $mapped_rules[0]['value'], 'Object IDs are merged, de-duplicated, and stringified.' );
	}

	/**
	 * A mixed rule set exercises all three mappings and their merge semantics at
	 * once: whole-post-type rules merge their post-type slugs under 'post_types',
	 * specific-object rules (across different post types) merge their IDs under
	 * 'specific_posts', and a taxonomy rule keeps its own slug. The 'post_types'
	 * value is sorted (see the canonicalization test below).
	 */
	public function test_map_rules_to_ac_format_merges_mixed_rule_set_by_target_slug() {
		$all_posts_rule    = $this->make_rule( 'post_type', 'post', [] );
		$all_pages_rule    = $this->make_rule( 'post_type', 'page', [] );
		$specific_page     = $this->make_rule( 'post_type', 'page', [ 5 ] );
		$specific_articles = $this->make_rule( 'post_type', 'post', [ 12, 34 ] );
		$category_rule     = $this->make_rule( 'taxonomy', 'category', [ 8 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $all_posts_rule, $all_pages_rule, $specific_page, $specific_articles, $category_rule ] ]
		);

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'page', 'post' ],
				],
				[
					'slug'  => 'specific_posts',
					'value' => [ '5', '12', '34' ],
				],
				[
					'slug'  => 'category',
					'value' => [ '8' ],
				],
			],
			$mapped_rules,
			'Only post_types is canonicalized; specific_posts and taxonomy values keep insertion order (the fingerprint orders those numeric IDs via SORT_NUMERIC).'
		);
	}

	/**
	 * The 'post_types' value is sorted, so two plans restricting the same post types
	 * in a different rule order produce identical mapped output — and therefore the
	 * same grouping fingerprint, so they share one gate instead of splitting into
	 * duplicates. (Post-type slugs are non-numeric, and compute_rules_fingerprint()'s
	 * SORT_NUMERIC pass would otherwise leave their order untouched.)
	 */
	public function test_map_rules_to_ac_format_canonicalizes_post_types_value_order() {
		$posts_then_pages = [
			$this->make_rule( 'post_type', 'post', [] ),
			$this->make_rule( 'post_type', 'page', [] ),
		];
		$pages_then_posts = [
			$this->make_rule( 'post_type', 'page', [] ),
			$this->make_rule( 'post_type', 'post', [] ),
		];

		$mapped_posts_first = $this->invoke_private_static( 'map_rules_to_ac_format', [ $posts_then_pages ] );
		$mapped_pages_first = $this->invoke_private_static( 'map_rules_to_ac_format', [ $pages_then_posts ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'page', 'post' ],
				],
			],
			$mapped_posts_first,
			'post_types values are sorted, so rule order does not change the output.'
		);
		$this->assertSame( $mapped_posts_first, $mapped_pages_first, 'Rule order does not change the mapped output.' );

		$this->assertSame(
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $mapped_posts_first ] ),
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $mapped_pages_first ] ),
			'Identical output yields identical fingerprints, so the plans group into one gate.'
		);
	}

	/**
	 * Rules with an empty content-type name are dropped entirely.
	 */
	public function test_map_rules_to_ac_format_skips_rules_with_empty_content_type() {
		$empty_rule = $this->make_rule( 'post_type', '', [ 7 ] );

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
	 * NPPD-2064 subset detection: a category-only rule set is covered by a rule set
	 * that adds an all-posts rule (its content is a subset), but not the reverse. This
	 * is the decidable case the consolidation merges into a single gate.
	 */
	public function test_rules_cover_detects_subset_rule_set() {
		$category_only = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$category_plus_all_posts = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];

		$this->assertTrue(
			$this->invoke_private_static( 'rules_cover', [ $category_plus_all_posts, $category_only ] ),
			'The category+all-posts rule set covers the category-only rule set.'
		);
		$this->assertFalse(
			$this->invoke_private_static( 'rules_cover', [ $category_only, $category_plus_all_posts ] ),
			'The narrower category-only rule set does not cover the broader one.'
		);
		$this->assertFalse(
			$this->invoke_private_static( 'rules_cover', [ $category_plus_all_posts, [] ] ),
			'An empty (site-wide) rule set is never treated as a subset to consolidate.'
		);
	}

	/**
	 * A rule value covers another only when it contains every element — term-ID or
	 * post-type-slug containment, on the stringified values map_rules_to_ac_format
	 * emits.
	 */
	public function test_rules_cover_requires_value_containment_for_the_same_slug() {
		$category_five = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$category_five_six = [
			[
				'slug'  => 'category',
				'value' => [ '5', '6' ],
			],
		];

		$this->assertTrue(
			$this->invoke_private_static( 'rules_cover', [ $category_five_six, $category_five ] ),
			'category {5,6} covers category {5}.'
		);
		$this->assertFalse(
			$this->invoke_private_static( 'rules_cover', [ $category_five, $category_five_six ] ),
			'category {5} does not cover category {5,6}.'
		);
	}

	/**
	 * NPPD-2064 field shape: four plans gating one category, two of them carrying an
	 * extra all-posts rule, split into two fingerprint groups. The category-only group
	 * is a subset of the category+all-posts group, so it is absorbed into it — one
	 * gate, no unresolved overlaps.
	 */
	public function test_plan_rule_set_consolidation_absorbs_subset_into_superset() {
		$category_only = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$category_plus_all_posts = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ [ $category_only, $category_plus_all_posts ], [ true, true ] ]
		);

		$this->assertSame(
			[ 0 => 1 ],
			$plan['absorbed_by'],
			'The category-only group (index 0) is absorbed into the category+all-posts group (index 1).'
		);
		$this->assertSame( [], $plan['overlaps'], 'A clean subset produces no unresolved-overlap warnings.' );
	}

	/**
	 * NPPD-2064 non-subset overlap: two rule sets share a category but each carries a
	 * distinct extra tag, so neither covers the other. They stay separate and the
	 * denial risk is flagged rather than silently merged.
	 */
	public function test_plan_rule_set_consolidation_flags_non_subset_overlap() {
		$category_and_tag_seven = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'post_tag',
				'value' => [ '7' ],
			],
		];
		$category_and_tag_eight = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'post_tag',
				'value' => [ '8' ],
			],
		];

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ [ $category_and_tag_seven, $category_and_tag_eight ], [ true, true ] ]
		);

		$this->assertSame( [], $plan['absorbed_by'], 'Neither rule set is a subset, so nothing is absorbed.' );
		$this->assertSame( [ [ 0, 1 ] ], $plan['overlaps'], 'The shared category is flagged as an unresolved overlap.' );
	}

	/**
	 * Disjoint rule sets neither consolidate nor warn.
	 */
	public function test_plan_rule_set_consolidation_leaves_disjoint_groups_alone() {
		$category_five = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$tag_seven = [
			[
				'slug'  => 'post_tag',
				'value' => [ '7' ],
			],
		];

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ [ $category_five, $tag_seven ], [ true, true ] ]
		);

		$this->assertSame( [], $plan['absorbed_by'] );
		$this->assertSame( [], $plan['overlaps'] );
	}

	/**
	 * NPPD-2064 purchase boundary: a signup group whose content is a subset of a
	 * purchase group is NOT absorbed — a single gate would attach the purchase group's
	 * subscription requirement to the signup content, denying registered readers who
	 * reach it for free today. They stay separate and the overlap is flagged.
	 */
	public function test_plan_rule_set_consolidation_does_not_merge_across_the_purchase_boundary() {
		$signup_category_only = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$purchase_category_plus_all_posts = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ [ $signup_category_only, $purchase_category_plus_all_posts ], [ false, true ] ]
		);

		$this->assertSame( [], $plan['absorbed_by'], 'A signup subset is not folded into a purchase superset.' );
		$this->assertSame( [ [ 0, 1 ] ], $plan['overlaps'], 'The cross-boundary overlap is flagged instead.' );
	}

	/**
	 * NPPD-2064 nested tiers: three plans gating a widening set of the same category
	 * (a Basic/Plus/Premium shape) cover each other in a chain. Every absorbed group
	 * must resolve to the terminal (widest) root, never an intermediate one — otherwise
	 * consolidate_plan_groups() folds a group into a root it never seeded and fatals.
	 */
	public function test_plan_rule_set_consolidation_resolves_transitive_chains_to_terminal_root() {
		$tier_basic = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$tier_plus = [
			[
				'slug'  => 'category',
				'value' => [ '5', '6' ],
			],
		];
		$tier_top = [
			[
				'slug'  => 'category',
				'value' => [ '5', '6', '7' ],
			],
		];

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ [ $tier_basic, $tier_plus, $tier_top ], [ true, true, true ] ]
		);

		$this->assertSame(
			[
				0 => 2,
				1 => 2,
			],
			$plan['absorbed_by'],
			'Both narrower tiers resolve to the widest (terminal) root, never the intermediate one.'
		);
		$this->assertSame( [], $plan['overlaps'], 'A single terminal root leaves no unresolved overlap.' );
	}

	/**
	 * NPPD-2066 hierarchy expansion: a rule restricting a parent category term expands
	 * to include its descendant terms, because both WooCommerce Memberships and Access
	 * Control cascade a parent-term restriction to posts assigned only to a child term.
	 * The expanded value is what the consolidation decision compares, so parent ⊃ child
	 * becomes a visible coverage relation instead of two flat, non-overlapping values.
	 */
	public function test_expand_rule_set_hierarchy_expands_parent_category_to_descendants() {
		$parent_term_id = self::factory()->category->create();
		$child_term_id  = self::factory()->category->create( [ 'parent' => $parent_term_id ] );

		$parent_rule_set = [
			[
				'slug'  => 'category',
				'value' => [ (string) $parent_term_id ],
			],
		];

		$expanded = $this->invoke_private_static( 'expand_rule_set_hierarchy', [ $parent_rule_set ] );

		$this->assertSame( 'category', $expanded[0]['slug'] );
		$this->assertEqualSets(
			[ (string) $parent_term_id, (string) $child_term_id ],
			$expanded[0]['value'],
			'The parent-category rule value gains its child term (values stay stringified).'
		);
	}

	/**
	 * NPPD-2066: expansion only touches hierarchical taxonomies. Tags (non-hierarchical)
	 * and post_types / specific_posts rules have no term tree and pass through untouched,
	 * so the expansion never fabricates coverage between unrelated rule sets.
	 */
	public function test_expand_rule_set_hierarchy_leaves_non_hierarchical_and_non_taxonomy_rules_unchanged() {
		$tag_term_id = self::factory()->tag->create();

		$rule_set = [
			[
				'slug'  => 'post_tag',
				'value' => [ (string) $tag_term_id ],
			],
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
			[
				'slug'  => 'specific_posts',
				'value' => [ '42' ],
			],
		];

		$this->assertSame(
			$rule_set,
			$this->invoke_private_static( 'expand_rule_set_hierarchy', [ $rule_set ] ),
			'Non-hierarchical taxonomy, post-type, and specific-post rules are returned verbatim.'
		);
	}

	/**
	 * NPPD-2066 end-to-end decision: a plan restricting a parent category and a plan
	 * restricting its child are split by fingerprint grouping (distinct flat values). Once
	 * their rule values are hierarchy-expanded — exactly what consolidate_plan_groups()
	 * does before delegating — the parent set covers the child set, so the child group is
	 * absorbed into the parent and no unresolved overlap is left. Without expansion the
	 * split is silent: neither absorbed nor flagged.
	 */
	public function test_hierarchy_nested_plans_consolidate_after_expansion() {
		$parent_term_id = self::factory()->category->create();
		$child_term_id  = self::factory()->category->create( [ 'parent' => $parent_term_id ] );

		$child_group_rules  = [
			[
				'slug'  => 'category',
				'value' => [ (string) $child_term_id ],
			],
		];
		$parent_group_rules = [
			[
				'slug'  => 'category',
				'value' => [ (string) $parent_term_id ],
			],
		];

		$expanded_rule_sets = array_map(
			fn( $rules ) => $this->invoke_private_static( 'expand_rule_set_hierarchy', [ $rules ] ),
			[ $child_group_rules, $parent_group_rules ]
		);

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ $expanded_rule_sets, [ true, true ] ]
		);

		$this->assertSame(
			[ 0 => 1 ],
			$plan['absorbed_by'],
			'The child-category group (index 0) is absorbed into the parent-category group (index 1).'
		);
		$this->assertSame( [], $plan['overlaps'], 'A clean hierarchy subset leaves no unresolved-overlap warning.' );
	}

	/**
	 * NPPD-2066 equal-coverage regression guard: hierarchy expansion can canonicalise two
	 * *distinct* fingerprints to the *same* term closure — a plan restricting a parent
	 * category, and a plan restricting that parent plus a redundant child. Once expanded
	 * they mutually cover, which is not a strict subset; a planner that only absorbs strict
	 * subsets would leave both as roots and fire a spurious overlap warning, splitting a
	 * pair the pre-expansion flat containment consolidated cleanly. Equal-coverage groups
	 * must collapse into their lowest-index representative so they still share one gate.
	 */
	public function test_plan_rule_set_consolidation_collapses_equal_coverage_groups() {
		$parent_term_id = self::factory()->category->create();
		$child_term_id  = self::factory()->category->create( [ 'parent' => $parent_term_id ] );

		$parent_only_rules       = [
			[
				'slug'  => 'category',
				'value' => [ (string) $parent_term_id ],
			],
		];
		$parent_plus_child_rules = [
			[
				'slug'  => 'category',
				'value' => [ (string) $parent_term_id, (string) $child_term_id ],
			],
		];

		$expanded_rule_sets = array_map(
			fn( $rules ) => $this->invoke_private_static( 'expand_rule_set_hierarchy', [ $rules ] ),
			[ $parent_only_rules, $parent_plus_child_rules ]
		);

		$this->assertSame(
			$expanded_rule_sets[0][0]['value'],
			$expanded_rule_sets[1][0]['value'],
			'Both rule sets expand to the identical term closure (precondition for the regression).'
		);

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ $expanded_rule_sets, [ true, true ] ]
		);

		$this->assertSame(
			[ 1 => 0 ],
			$plan['absorbed_by'],
			'The equal-coverage group (index 1) collapses into the lowest-index representative (index 0).'
		);
		$this->assertSame( [], $plan['overlaps'], 'Equal-coverage groups consolidate, so no spurious overlap warning fires.' );
	}

	/**
	 * NPPD-2066 equal-coverage determinism: three distinct fingerprints (parent P; P plus
	 * one child; P plus both children) all expand to the same term closure, forming an
	 * equivalence class of size three. Every member must fold to the single lowest-index
	 * representative regardless of iteration order — the asymmetric $j < $i tie-break plus
	 * the chain resolution converge the whole class on one root, never leaving an
	 * intermediate representative that would fatal in consolidate_plan_groups().
	 */
	public function test_plan_rule_set_consolidation_collapses_equal_coverage_class_of_three() {
		$parent_term_id      = self::factory()->category->create();
		$first_child_term_id = self::factory()->category->create( [ 'parent' => $parent_term_id ] );
		$last_child_term_id  = self::factory()->category->create( [ 'parent' => $parent_term_id ] );

		$rule_sets = array_map(
			fn( $value ) => $this->invoke_private_static(
				'expand_rule_set_hierarchy',
				[
					[
						[
							'slug'  => 'category',
							'value' => $value,
						],
					],
				]
			),
			[
				[ (string) $parent_term_id ],
				[ (string) $parent_term_id, (string) $first_child_term_id ],
				[ (string) $parent_term_id, (string) $first_child_term_id, (string) $last_child_term_id ],
			]
		);

		$this->assertEqualSets( $rule_sets[0][0]['value'], $rule_sets[1][0]['value'], 'All three expand to the same closure.' );
		$this->assertEqualSets( $rule_sets[0][0]['value'], $rule_sets[2][0]['value'], 'All three expand to the same closure.' );

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ $rule_sets, [ true, true, true ] ]
		);

		$this->assertSame(
			[
				1 => 0,
				2 => 0,
			],
			$plan['absorbed_by'],
			'Both later members of the equivalence class fold to the lowest-index representative.'
		);
		$this->assertSame( [], $plan['overlaps'], 'A fully-collapsed equivalence class leaves no unresolved overlap.' );
	}

	/**
	 * The product-ID union across a group's plan descriptors is de-duplicated, so a
	 * consolidated gate's paid-access list carries each merged plan's products once.
	 */
	public function test_group_product_ids_unions_and_dedupes_descriptor_products() {
		$group = [
			[ 'product_ids' => [ '103' ] ],
			[ 'product_ids' => [ '101', '102' ] ],
			[ 'product_ids' => [ '102' ] ],
		];

		$this->assertSame(
			[ '103', '101', '102' ],
			$this->invoke_private_static( 'group_product_ids', [ $group ] )
		);
	}

	/**
	 * A whole-post-type rule overlaps taxonomy-scoped content of that type, since the
	 * taxonomy rule gates posts the post-type rule also gates.
	 */
	public function test_rule_sets_overlap_treats_all_posts_as_overlapping_a_taxonomy() {
		$all_posts = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$category_five = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];

		$specific_posts = [
			[
				'slug'  => 'specific_posts',
				'value' => [ '42' ],
			],
		];

		$this->assertTrue(
			$this->invoke_private_static( 'rule_sets_overlap', [ $all_posts, $category_five ] )
		);
		$this->assertTrue(
			$this->invoke_private_static( 'rule_sets_overlap', [ $all_posts, $specific_posts ] ),
			'A whole-post-type rule also overlaps specific-post rules of that type.'
		);
		$this->assertFalse(
			$this->invoke_private_static(
				'rule_sets_overlap',
				[
					[
						[
							'slug'  => 'category',
							'value' => [ '5' ],
						],
					],
					[
						[
							'slug'  => 'post_tag',
							'value' => [ '7' ],
						],
					],
				]
			),
			'Different taxonomies with no post-type rule do not register as overlapping.'
		);
	}

	/**
	 * The overlap warning is built from the consolidated (merged) group, so a plan
	 * folded into a root — the entitlement most likely to sit inside the overlap — is
	 * named alongside the root's own plans and products. (Guards against building the
	 * warning from the pre-consolidation group, which drops the absorbed plan.)
	 */
	public function test_format_overlap_warning_names_folded_in_plan_products() {
		$merged_group_with_folded_plan = [
			[
				'name'        => 'Root Plan',
				'product_ids' => [ 200 ],
			],
			[
				'name'        => 'Folded Plan',
				'product_ids' => [ 201 ],
			],
		];
		$other_group = [
			[
				'name'        => 'Other Plan',
				'product_ids' => [ 300 ],
			],
		];

		$warning = $this->invoke_private_static(
			'format_overlap_warning',
			[ $merged_group_with_folded_plan, $other_group ]
		);

		$this->assertStringContainsString( 'Folded Plan', $warning, 'The folded-in plan name appears in the warning.' );
		$this->assertStringContainsString( '201', $warning, 'The folded-in plan\'s product appears in the warning.' );
		$this->assertStringContainsString( 'Root Plan', $warning );
		$this->assertStringContainsString( 'Other Plan', $warning );
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
