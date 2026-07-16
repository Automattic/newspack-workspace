<?php
/**
 * Characterization tests for the migrate-membership-gates CLI (NPPD-2059).
 *
 * These pin the behavior of the pure mapping/fingerprint/layout-extraction
 * helpers exactly as ported from the standalone drop-in, INCLUDING the three
 * known bugs that land as separate stacked PRs. Where a test asserts a buggy
 * result on purpose it is flagged with the follow-up issue ID; those stacked
 * fixes will flip the corresponding assertion:
 *
 * - NPPD-2058: extract_gate_layouts() only inspects top-level wrapper blocks, so
 *   nested / reusable-block gate layouts migrate as empty.
 * - NPPD-2063: map_rules_to_ac_format() emits the raw WooCommerce content-type
 *   name as the AC rule slug instead of remapping to 'post_types' / 'specific_posts'.
 * - NPPD-2064: compute_rules_fingerprint() is the grouping key that decides how
 *   plans are split across gates.
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
	 * de-duplicated, stringified value list.
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
	 * Create a published np_memberships_gate-shaped post with the given block content.
	 *
	 * The post type is registered on the fly since WooCommerce Memberships is not
	 * loaded in the unit-test harness; extract_gate_layouts() only reads
	 * post_content, so a plain post carrying the markup is sufficient.
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
