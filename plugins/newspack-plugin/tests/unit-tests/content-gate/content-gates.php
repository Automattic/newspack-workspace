<?php
/**
 * Tests for the Content Gates class.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Reader_Activation;
use Newspack\Access_Rules;
use Newspack\Content_Rules;
use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;
use Newspack\Content_Gate\IP_Access_Rule;
use Newspack\Institution;

/**
 * Tests for the Content Gates class.
 */
class Test_Content_Gates extends \WP_UnitTestCase {

	/**
	 * Post ID
	 *
	 * @var int[]
	 */
	protected $post_ids = [];

	/**
	 * Gates array.
	 *
	 * @var int[]
	 */
	protected $gate_ids = [];

	/**
	 * Original $_SERVER['REMOTE_ADDR'] saved in set_up and restored in tear_down
	 * so the institutional-access scenarios can mutate it without leaking into
	 * other test classes.
	 *
	 * @var string|null
	 */
	private $original_remote_addr;

	/**
	 * Define the Content Gates feature flag for this test class only and force
	 * the REST server to re-init so audience-content-gates routes register with
	 * the flag on. Defining in bootstrap would flip the flag for every test in
	 * the suite — including any future test that asserts feature-off behavior.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		$GLOBALS['wp_rest_server'] = null;
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Test set up.
	 */
	public function set_up() {
		parent::set_up();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		$this->original_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
		$this->gate_ids[] = Content_Gate::create_gate( [ 'title' => 'Draft Gate' ] );
		Content_Gate::update_gate_settings(
			$this->gate_ids[0],
			[
				'title'         => 'Draft Gate',
				'status'        => 'draft',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => false,
					'gate_id'              => 0,
				],
			]
		);
		$this->gate_ids[] = Content_Gate::create_gate( [ 'title' => 'Trash Gate' ] );
		Content_Gate::update_gate_settings(
			$this->gate_ids[1],
			[
				'title'         => 'Trash Gate',
				'status'        => 'trash',
				'priority'      => 1,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => false,
					'gate_id'              => 0,
				],
			]
		);
		$this->gate_ids[] = Content_Gate::create_gate( [ 'title' => 'Published Gate' ] );
		Content_Gate::update_gate_settings(
			$this->gate_ids[2],
			[
				'title'         => 'Published Gate',
				'status'        => 'publish',
				'priority'      => 2,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => false,
					'gate_id'              => 0,
				],
			]
		);
		$this->gate_ids[] = Content_Gate::create_gate( [ 'title' => 'Published Gate w/ missing config' ] );
		Content_Gate::update_gate_settings(
			$this->gate_ids[3],
			[
				'title'         => 'Published Gate',
				'status'        => 'publish',
				'priority'      => 3,
				'content_rules' => [],
				'registration'  => [
					'active'               => false,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => false,
					'gate_id'              => 0,
				],
				'custom_access' => [
					'active'       => false,
					'metering'     => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'gate_id'      => 0,
					'access_rules' => [],
				],
			]
		);
		$this->post_ids[] = $this->factory->post->create();
	}

	/**
	 * Teardown after tests.
	 */
	public function tear_down() {
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->reset_restriction_cache();
		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}
		unset( $_COOKIE[ IP_Access_Rule::COOKIE_NAME ] );
		// phpcs:enable
		delete_transient( Institution::TRANSIENT_KEY );
		parent::tear_down();
	}

	/**
	 * Test get_gates().
	 */
	public function test_get_gates() {
		$gates = Content_Gate::get_gates();
		$this->assertCount( 4, $gates, 'Default params get gates with all statuses' );
		$this->assertEquals( $this->gate_ids[0], $gates[0]['id'] );
		$this->assertEquals( $this->gate_ids[1], $gates[1]['id'] );
		$this->assertEquals( $this->gate_ids[2], $gates[2]['id'] );
		$this->assertEquals( $this->gate_ids[3], $gates[3]['id'] );

		$gates = Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' );
		$this->assertCount( 2, $gates, 'If passing a post status, only get gates with that status' );
		$this->assertEquals( $this->gate_ids[2], $gates[0]['id'] );
		$this->assertEquals( $this->gate_ids[3], $gates[1]['id'] );
	}

	/**
	 * Test get_post_gates() (for front-end display).
	 */
	public function test_get_post_gates() {
		$gates = Content_Restriction_Control::get_post_gates( $this->post_ids[0] );
		$this->assertCount( 1, $gates, 'One gate for the post' );
		$this->assertEquals( $this->gate_ids[2], $gates[0]['id'], 'Gate with publish status and matching rules configuration is included' );
		$this->assertNotContains( $this->gate_ids[3], $gates, 'Gate with publish status but no rules configuration is not included' );
	}

	/**
	 * Test content rules.
	 */
	public function test_content_rules() {
		// Create test categories.
		$cat1 = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Test Category 1',
			]
		);
		$cat2 = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Test Category 2',
			]
		);

		// Create test posts.
		$post1 = $this->factory->post->create( [ 'post_category' => [ $cat1 ] ] );
		$post2 = $this->factory->post->create( [ 'post_category' => [ $cat2 ] ] );
		$post3 = $this->factory->post->create( [ 'post_category' => [] ] );
		$this->post_ids[] = $post1;
		$this->post_ids[] = $post2;
		$this->post_ids[] = $post3;

		// Update content rules to match posts in category 1.
		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'category',
					'value' => [ $cat1 ],
				],
			]
		);
		$this->reset_restriction_cache();

		$gates = Content_Restriction_Control::get_post_gates( $post1 );
		$this->assertCount( 1, $gates, 'One gate for the post in category 1' );
		$this->assertEquals( $this->gate_ids[2], $gates[0]['id'], 'Gate with publish status and matching rules configuration is included' );
		$this->assertNotContains( $this->gate_ids[3], $gates, 'Gate with publish status but no rules configuration is not included' );

		$gates = Content_Restriction_Control::get_post_gates( $post2 );
		$this->assertCount( 0, $gates, 'No gates for the post in category 2' );

		$gates = Content_Restriction_Control::get_post_gates( $post3 );
		$this->assertCount( 0, $gates, 'No gate for the post with no categories' );

		// Update content rules to add an empty post_type value.
		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'post_types',
					'value' => [],
				],
				[
					'slug'  => 'category',
					'value' => [ $cat1 ],
				],
			]
		);
		$this->reset_restriction_cache();
		$gates = Content_Restriction_Control::get_post_gates( $post1 );
		$this->assertCount( 1, $gates, 'One gate for the post in category 1' );
		$this->assertEquals( $this->gate_ids[2], $gates[0]['id'], 'Rule with an empty array-like value is ignored; category rule still matches' );

		// Make the content rule an exclusion rule.
		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'      => 'category',
					'value'     => [ $cat1 ],
					'exclusion' => true,
				],
			]
		);
		$this->reset_restriction_cache();

		$gates = Content_Restriction_Control::get_post_gates( $post1 );
		$this->assertCount( 0, $gates, 'No gates for the post in category 1' );

		$gates = Content_Restriction_Control::get_post_gates( $post2 );
		$this->assertCount( 1, $gates, 'One gate for the post in category 2' );
		$this->assertEquals( $this->gate_ids[2], $gates[0]['id'], 'Gate with publish status and matching rules configuration is included' );

		$gates = Content_Restriction_Control::get_post_gates( $post3 );
		$this->assertCount( 1, $gates, 'One gate for the post with no categories' );
		$this->assertEquals( $this->gate_ids[2], $gates[0]['id'], 'Gate with publish status and matching rules configuration is included' );
	}

	/**
	 * Test that a content rule targeting a parent term in a hierarchical
	 * taxonomy cascades to descendant terms, matching WooCommerce Memberships.
	 */
	public function test_content_rules_hierarchical_child_terms() {
		// Build a category tree: parent > child > grandchild.
		$parent_cat = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Parent Category',
			]
		);
		$child_cat = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Child Category',
				'parent'   => $parent_cat,
			]
		);
		$grandchild_cat = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Grandchild Category',
				'parent'   => $child_cat,
			]
		);
		// An unrelated category outside the parent's subtree.
		$other_cat = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Other Category',
			]
		);

		// Posts assigned only to a descendant term, never directly to the parent.
		$parent_post     = $this->factory->post->create( [ 'post_category' => [ $parent_cat ] ] );
		$child_post      = $this->factory->post->create( [ 'post_category' => [ $child_cat ] ] );
		$grandchild_post = $this->factory->post->create( [ 'post_category' => [ $grandchild_cat ] ] );
		$other_post      = $this->factory->post->create( [ 'post_category' => [ $other_cat ] ] );
		$this->post_ids  = array_merge( $this->post_ids, [ $parent_post, $child_post, $grandchild_post, $other_post ] );

		// Inclusion rule targeting only the parent term.
		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'category',
					'value' => [ $parent_cat ],
				],
			]
		);
		$this->reset_restriction_cache();

		$gates = Content_Restriction_Control::get_post_gates( $child_post );
		$this->assertCount( 1, $gates, 'Post in a child of the targeted parent category is gated' );

		$gates = Content_Restriction_Control::get_post_gates( $grandchild_post );
		$this->assertCount( 1, $gates, 'Post in a grandchild of the targeted parent category is gated' );

		$gates = Content_Restriction_Control::get_post_gates( $other_post );
		$this->assertCount( 0, $gates, 'Post outside the targeted subtree is not gated' );

		// Exclusion rule targeting the parent term: descendants are excluded too.
		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'      => 'category',
					'value'     => [ $parent_cat ],
					'exclusion' => true,
				],
			]
		);
		$this->reset_restriction_cache();

		$gates = Content_Restriction_Control::get_post_gates( $child_post );
		$this->assertCount( 0, $gates, 'Post in a child of an excluded parent category is not gated' );

		$gates = Content_Restriction_Control::get_post_gates( $grandchild_post );
		$this->assertCount( 0, $gates, 'Post in a grandchild of an excluded parent category is not gated' );

		$gates = Content_Restriction_Control::get_post_gates( $other_post );
		$this->assertCount( 1, $gates, 'Post outside the excluded subtree is still gated' );

		// The cascade is one-directional: a rule targeting a child term does NOT
		// pull in posts that only carry the parent term.
		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'category',
					'value' => [ $child_cat ],
				],
			]
		);
		$this->reset_restriction_cache();

		$gates = Content_Restriction_Control::get_post_gates( $parent_post );
		$this->assertCount( 0, $gates, 'Post in the parent term is not gated by a rule targeting a child term' );

		$gates = Content_Restriction_Control::get_post_gates( $child_post );
		$this->assertCount( 1, $gates, 'Post in the targeted child term is gated' );

		// Stored rule values may be strings; the cascade must still match because the
		// helper normalizes term IDs to integers before intersecting.
		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'category',
					'value' => [ (string) $parent_cat ],
				],
			]
		);
		$this->reset_restriction_cache();

		$gates = Content_Restriction_Control::get_post_gates( $child_post );
		$this->assertCount( 1, $gates, 'Stringified parent term ID still cascades to gate a child-category post' );
	}

	/**
	 * Test that a content rule on a non-hierarchical taxonomy (tags) matches
	 * only the targeted term, with no descendant expansion.
	 */
	public function test_content_rules_non_hierarchical_terms() {
		$tag         = $this->factory->term->create( [ 'taxonomy' => 'post_tag' ] );
		$other_tag   = $this->factory->term->create( [ 'taxonomy' => 'post_tag' ] );
		$tagged_post = $this->factory->post->create();
		$other_post  = $this->factory->post->create();
		wp_set_post_terms( $tagged_post, [ $tag ], 'post_tag' );
		wp_set_post_terms( $other_post, [ $other_tag ], 'post_tag' );
		$this->post_ids = array_merge( $this->post_ids, [ $tagged_post, $other_post ] );

		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'post_tag',
					'value' => [ $tag ],
				],
			]
		);

		$gates = Content_Restriction_Control::get_post_gates( $tagged_post );
		$this->assertCount( 1, $gates, 'Post with the targeted tag is gated' );

		$gates = Content_Restriction_Control::get_post_gates( $other_post );
		$this->assertCount( 0, $gates, 'Post with a different tag is not gated' );
	}

	/**
	 * Test that gate layouts are created when a gate is created.
	 */
	public function test_create_gate_creates_layouts() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Gate' ] );
		$this->gate_ids[] = $gate_id;

		$gate = Content_Gate::get_gate( $gate_id );
		$this->assertNotEmpty( $gate['registration']['gate_layout_id'], 'Registration layout ID should be set' );
		$this->assertNotEmpty( $gate['custom_access']['gate_layout_id'], 'Custom access layout ID should be set' );

		// Verify the layout posts exist.
		$registration_layout = get_post( $gate['registration']['gate_layout_id'] );
		$custom_access_layout = get_post( $gate['custom_access']['gate_layout_id'] );

		$this->assertNotNull( $registration_layout, 'Registration layout post should exist' );
		$this->assertNotNull( $custom_access_layout, 'Custom access layout post should exist' );
		$this->assertEquals( Content_Gate::GATE_LAYOUT_CPT, $registration_layout->post_type, 'Registration layout should be correct post type' );
		$this->assertEquals( Content_Gate::GATE_LAYOUT_CPT, $custom_access_layout->post_type, 'Custom access layout should be correct post type' );
		$this->assertEquals( 'publish', $registration_layout->post_status, 'Registration layout should be published' );
		$this->assertEquals( 'publish', $custom_access_layout->post_status, 'Custom access layout should be published' );
	}

	/**
	 * Test that layouts are deleted when a gate is permanently deleted.
	 */
	public function test_delete_gate_deletes_layouts() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Gate for Deletion' ] );
		$gate = Content_Gate::get_gate( $gate_id );

		$registration_layout_id = $gate['registration']['gate_layout_id'];
		$custom_access_layout_id = $gate['custom_access']['gate_layout_id'];

		// Verify layouts exist before deletion.
		$this->assertNotNull( get_post( $registration_layout_id ), 'Registration layout should exist before deletion' );
		$this->assertNotNull( get_post( $custom_access_layout_id ), 'Custom access layout should exist before deletion' );

		// Permanently delete the gate.
		wp_delete_post( $gate_id, true );

		// Verify layouts are deleted.
		$this->assertNull( get_post( $registration_layout_id ), 'Registration layout should be deleted' );
		$this->assertNull( get_post( $custom_access_layout_id ), 'Custom access layout should be deleted' );
	}

	/**
	 * Test that only layouts associated with the deleted gate are removed.
	 */
	public function test_delete_gate_only_deletes_own_layouts() {
		$gate1_id = Content_Gate::create_gate( [ 'title' => 'Gate 1' ] );
		$gate2_id = Content_Gate::create_gate( [ 'title' => 'Gate 2' ] );
		$this->gate_ids[] = $gate2_id;

		$gate1 = Content_Gate::get_gate( $gate1_id );
		$gate2 = Content_Gate::get_gate( $gate2_id );

		$gate1_registration_layout_id = $gate1['registration']['gate_layout_id'];
		$gate2_registration_layout_id = $gate2['registration']['gate_layout_id'];
		$gate2_custom_access_layout_id = $gate2['custom_access']['gate_layout_id'];

		// Delete gate 1.
		wp_delete_post( $gate1_id, true );

		// Gate 1's layout should be deleted.
		$this->assertNull( get_post( $gate1_registration_layout_id ), 'Gate 1 registration layout should be deleted' );

		// Gate 2's layouts should still exist.
		$this->assertNotNull( get_post( $gate2_registration_layout_id ), 'Gate 2 registration layout should still exist' );
		$this->assertNotNull( get_post( $gate2_custom_access_layout_id ), 'Gate 2 custom access layout should still exist' );
	}

	/**
	 * Test that deleting a gate handles missing layouts gracefully.
	 */
	public function test_delete_gate_handles_missing_layouts() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Gate' ] );
		$gate = Content_Gate::get_gate( $gate_id );

		$registration_layout_id = $gate['registration']['gate_layout_id'];

		// Manually delete one layout first.
		wp_delete_post( $registration_layout_id, true );
		$this->assertNull( get_post( $registration_layout_id ), 'Registration layout should be deleted' );

		// Deleting the gate should not cause errors even with missing layout.
		wp_delete_post( $gate_id, true );

		// Verify the gate is deleted.
		$this->assertNull( get_post( $gate_id ), 'Gate should be deleted' );
	}

	/**
	 * Test that deleting a gate handles gates without layouts (e.g., legacy gates).
	 */
	public function test_delete_gate_handles_gates_without_layouts() {
		// Create a gate and manually remove layout IDs to simulate a legacy gate.
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Legacy Gate' ] );
		$gate = Content_Gate::get_gate( $gate_id );

		// Delete the auto-created layouts and clear the settings.
		wp_delete_post( $gate['registration']['gate_layout_id'], true );
		wp_delete_post( $gate['custom_access']['gate_layout_id'], true );

		Content_Gate::update_registration_settings( $gate_id, [ 'gate_layout_id' => 0 ] );
		Content_Gate::update_custom_access_settings( $gate_id, [ 'gate_layout_id' => 0 ] );

		// Deleting the gate should not cause errors.
		wp_delete_post( $gate_id, true );

		// Verify the gate is deleted.
		$this->assertNull( get_post( $gate_id ), 'Gate should be deleted' );
	}

	/**
	 * Test that get_inline_gate_content_for_post returns default content when layout post doesn't exist.
	 */
	public function test_inline_gate_content_with_missing_layout() {
		$non_existent_id = 999999;

		$content = Content_Gate::get_inline_gate_content_for_post( $non_existent_id );

		// Should contain the clearfix div.
		$this->assertStringContainsString( 'clear:both', $content, 'Clearfix div should be present' );

		// Should contain the default gate content.
		$this->assertStringContainsString( 'This post is only available to members', $content, 'Default content should be present' );

		// Should be wrapped in gate container.
		$this->assertStringContainsString( 'newspack-content-gate__inline-gate', $content, 'Gate container should be present' );
	}

	/**
	 * Test that get_inline_gate_content_for_post returns actual content when layout post exists.
	 */
	public function test_inline_gate_content_with_existing_layout() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Gate' ] );
		$this->gate_ids[] = $gate_id;

		$gate = Content_Gate::get_gate( $gate_id );
		$layout_id = $gate['registration']['gate_layout_id'];

		// Update the layout with custom content.
		$custom_content = '<!-- wp:paragraph --><p>Custom gate message for testing.</p><!-- /wp:paragraph -->';
		wp_update_post(
			[
				'ID'           => $layout_id,
				'post_content' => $custom_content,
			]
		);

		// Set style to inline.
		update_post_meta( $layout_id, 'style', 'inline' );

		$content = Content_Gate::get_inline_gate_content_for_post( $layout_id );

		// Should contain the clearfix div.
		$this->assertStringContainsString( 'clear:both', $content, 'Clearfix div should be present' );

		// Should contain the custom content.
		$this->assertStringContainsString( 'Custom gate message for testing', $content, 'Custom content should be present' );

		// Should NOT contain the default content.
		$this->assertStringNotContainsString( 'This post is only available to members', $content, 'Default content should not be present' );

		// Should be wrapped in gate container.
		$this->assertStringContainsString( 'newspack-content-gate__inline-gate', $content, 'Gate container should be present' );
	}

	/**
	 * Test that get_inline_gate_content_for_post returns empty string for overlay style.
	 */
	public function test_inline_gate_content_returns_empty_for_overlay_style() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Gate' ] );
		$this->gate_ids[] = $gate_id;

		$gate = Content_Gate::get_gate( $gate_id );
		$layout_id = $gate['registration']['gate_layout_id'];

		// Set style to overlay.
		update_post_meta( $layout_id, 'style', 'overlay' );

		$content = Content_Gate::get_inline_gate_content_for_post( $layout_id );

		$this->assertEmpty( $content, 'Should return empty string for overlay style' );
	}

	/**
	 * Test that get_restricted_post_excerpt_for_gate uses defaults when layout doesn't exist.
	 */
	public function test_restricted_excerpt_with_missing_layout() {
		$post_id = $this->factory->post->create(
			[
				'post_content' => '<p>First paragraph.</p><p>Second paragraph.</p><p>Third paragraph.</p><p>Fourth paragraph.</p>',
			]
		);
		$this->post_ids[] = $post_id;

		$post = get_post( $post_id );
		$non_existent_id = 999999;

		$excerpt = Content_Gate::get_restricted_post_excerpt_for_gate( $post, $non_existent_id );

		// Default visible_paragraphs is 2, so should have first two paragraphs.
		$this->assertStringContainsString( 'First paragraph', $excerpt, 'First paragraph should be present' );
		$this->assertStringContainsString( 'Second paragraph', $excerpt, 'Second paragraph should be present' );
		$this->assertStringNotContainsString( 'Third paragraph', $excerpt, 'Third paragraph should not be present' );
	}

	/**
	 * Test that get_restricted_post_excerpt_for_gate respects layout settings.
	 */
	public function test_restricted_excerpt_with_existing_layout() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Gate' ] );
		$this->gate_ids[] = $gate_id;

		$gate = Content_Gate::get_gate( $gate_id );
		$layout_id = $gate['registration']['gate_layout_id'];

		// Set visible paragraphs to 3.
		update_post_meta( $layout_id, 'visible_paragraphs', 3 );
		update_post_meta( $layout_id, 'style', 'inline' );
		update_post_meta( $layout_id, 'use_more_tag', false );

		$post_id = $this->factory->post->create(
			[
				'post_content' => '<p>First paragraph.</p><p>Second paragraph.</p><p>Third paragraph.</p><p>Fourth paragraph.</p>',
			]
		);
		$this->post_ids[] = $post_id;

		$post = get_post( $post_id );
		$excerpt = Content_Gate::get_restricted_post_excerpt_for_gate( $post, $layout_id );

		// Should have first three paragraphs.
		$this->assertStringContainsString( 'First paragraph', $excerpt, 'First paragraph should be present' );
		$this->assertStringContainsString( 'Second paragraph', $excerpt, 'Second paragraph should be present' );
		$this->assertStringContainsString( 'Third paragraph', $excerpt, 'Third paragraph should be present' );
		$this->assertStringNotContainsString( 'Fourth paragraph', $excerpt, 'Fourth paragraph should not be present' );
	}

	/**
	 * Test access rules normalization from flat to grouped format.
	 */
	public function test_normalize_access_rules() {
		// Empty rules should return empty array.
		$result = Access_Rules::normalize_rules( [] );
		$this->assertEmpty( $result, 'Empty rules should return empty array' );

		// Flat rules should each become their own group (OR logic).
		$flat_rules = [
			[
				'slug'  => 'subscription',
				'value' => [ 1, 2 ],
			],
			[
				'slug'  => 'email_domain',
				'value' => 'example.com',
			],
		];
		$result = Access_Rules::normalize_rules( $flat_rules );
		$this->assertCount( 2, $result, 'Each flat rule should become its own group' );
		$this->assertEquals( [ $flat_rules[0] ], $result[0], 'First group should contain first rule' );
		$this->assertEquals( [ $flat_rules[1] ], $result[1], 'Second group should contain second rule' );

		// Already grouped rules should remain unchanged.
		$grouped_rules = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ 1 ],
				],
			],
			[
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
		$result = Access_Rules::normalize_rules( $grouped_rules );
		$this->assertCount( 2, $result, 'Grouped rules should have 2 groups' );
		$this->assertEquals( $grouped_rules, $result, 'Grouped rules should remain unchanged' );
	}

	/**
	 * Test access rules evaluation with grouped OR logic.
	 */
	public function test_evaluate_access_rules_grouped() {
		// Empty rules should grant access.
		$result = Access_Rules::evaluate_rules( [] );
		$this->assertTrue( $result, 'Empty rules should grant access' );

		// Single empty group should grant access.
		$result = Access_Rules::evaluate_rules( [ [] ] );
		$this->assertTrue( $result, 'Single empty group should grant access' );
	}

	/**
	 * Test access rules evaluation with real pass/fail combinations.
	 */
	public function test_evaluate_access_rules_pass_fail_combinations() {
		// Create a test user with a specific email domain.
		$user_id = $this->factory->user->create(
			[
				'user_email' => 'test@allowed-domain.com',
			]
		);
		wp_set_current_user( $user_id );

		// Test 1: Flat legacy rules with passing rule.
		$flat_rules_pass = [
			[
				'slug'  => 'email_domain',
				'value' => 'allowed-domain.com',
			],
		];
		$result = Access_Rules::evaluate_rules( $flat_rules_pass );
		$this->assertFalse( $result, 'Flat rules with passing email_domain should deny access for unverified reader' );

		// Test 2: Flat legacy rules with passing rule for verified reader.
		Reader_Activation::set_reader_verified( $user_id );
		$result = Access_Rules::evaluate_rules( $flat_rules_pass );
		$this->assertTrue( $result, 'Flat rules with passing email_domain should grant access for verified reader' );

		// Test 3: Flat legacy rules with failing rule.
		$flat_rules_fail = [
			[
				'slug'  => 'email_domain',
				'value' => 'other-domain.com',
			],
		];
		$result = Access_Rules::evaluate_rules( $flat_rules_fail );
		$this->assertFalse( $result, 'Flat rules with non-matching email_domain should deny access' );

		// Test 4: Flat rules with mixed pass/fail (OR logic - should pass).
		$flat_rules_mixed = [
			[
				'slug'  => 'email_domain',
				'value' => 'allowed-domain.com', // Passes.
			],
			[
				'slug'  => 'email_domain',
				'value' => 'other-domain.com', // Fails.
			],
		];
		$result = Access_Rules::evaluate_rules( $flat_rules_mixed );
		$this->assertTrue( $result, 'Flat rules with mixed results should grant access (OR logic)' );

		// Test 5: Multiple groups - first group fails, second passes (OR logic - should pass).
		$grouped_rules_or_pass = [
			// Group 1: Fails (non-matching domain).
			[
				[
					'slug'  => 'email_domain',
					'value' => 'other-domain.com',
				],
			],
			// Group 2: Passes (matching domain).
			[
				[
					'slug'  => 'email_domain',
					'value' => 'allowed-domain.com',
				],
			],
		];
		$result = Access_Rules::evaluate_rules( $grouped_rules_or_pass );
		$this->assertTrue( $result, 'Multiple groups with at least one passing should grant access (OR logic)' );

		// Test 6: Multiple groups - all groups fail (OR logic - should fail).
		$grouped_rules_all_fail = [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'domain-a.com',
				],
			],
			[
				[
					'slug'  => 'email_domain',
					'value' => 'domain-b.com',
				],
			],
		];
		$result = Access_Rules::evaluate_rules( $grouped_rules_all_fail );
		$this->assertFalse( $result, 'Multiple groups with all failing should deny access' );

		// Test 7: Group with AND logic - both rules must pass.
		$grouped_and_logic = [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'allowed-domain.com', // Passes.
				],
				[
					'slug'  => 'email_domain',
					'value' => 'other-domain.com', // Fails.
				],
			],
		];
		$result = Access_Rules::evaluate_rules( $grouped_and_logic );
		$this->assertFalse( $result, 'Single group with mixed AND rules should deny access' );

		// Clean up.
		wp_delete_user( $user_id );
	}

	/**
	 * Test access rules evaluation with invalid or missing slug entries.
	 */
	public function test_evaluate_access_rules_invalid_entries() {
		// Create a test user.
		$user_id = $this->factory->user->create(
			[
				'user_email' => 'test@example.com',
			]
		);
		wp_set_current_user( $user_id );

		// Test 1: Rule with missing slug should be skipped (not block access).
		$rules_missing_slug = [
			[
				[
					'value' => 'some-value', // Missing 'slug' key.
				],
			],
		];
		$result = Access_Rules::evaluate_rules( $rules_missing_slug );
		$this->assertTrue( $result, 'Rules with missing slug should be skipped and grant access' );

		// Test 2: Rule with non-existent slug should not block access.
		$rules_nonexistent_slug = [
			[
				[
					'slug'  => 'nonexistent_rule',
					'value' => 'some-value',
				],
			],
		];
		$result = Access_Rules::evaluate_rules( $rules_nonexistent_slug );
		$this->assertTrue( $result, 'Rules with non-existent slug should not block access' );

		// Test 3: Mixed valid failing rule and invalid rule in same group.
		$rules_mixed_valid_invalid = [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'other-domain.com', // Valid rule, fails.
				],
				[
					'slug'  => 'nonexistent_rule', // Invalid, passes.
					'value' => 'some-value',
				],
			],
		];
		$result = Access_Rules::evaluate_rules( $rules_mixed_valid_invalid );
		$this->assertFalse( $result, 'Group with valid failing rule should deny access even with invalid rules' );

		// Test 4: Group with only invalid rules should pass.
		$rules_all_invalid = [
			[
				[
					'slug'  => 'nonexistent_rule_1',
					'value' => 'value1',
				],
				[
					'value' => 'no-slug', // Missing slug.
				],
			],
		];
		$result = Access_Rules::evaluate_rules( $rules_all_invalid );
		$this->assertTrue( $result, 'Group with only invalid/skipped rules should grant access' );

		// Clean up.
		wp_delete_user( $user_id );
	}

	/**
	 * Test access rules evaluation requires logged-in user.
	 */
	public function test_evaluate_access_rules_requires_login() {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		// Any valid rule should fail when user is not logged in.
		$rules = [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
		$result = Access_Rules::evaluate_rules( $rules );
		$this->assertFalse( $result, 'Rules should deny access when user is not logged in' );
	}

	/**
	 * Test that a post marked as exempt bypasses the content gate restriction.
	 */
	public function test_exempt_post_is_not_restricted() {
		$post_id = $this->post_ids[0];

		// Without the exemption flag, the post should be restricted by the published gate.
		$is_restricted = apply_filters( 'newspack_is_post_restricted', false, $post_id );
		$this->assertTrue( $is_restricted, 'Post matched by a published gate should be restricted' );

		// Set the exemption meta key on the post.
		update_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true );

		// With the exemption flag set, the post should not be restricted even though it matches a gate.
		$is_restricted = apply_filters( 'newspack_is_post_restricted', false, $post_id );
		$this->assertFalse( $is_restricted, 'Post with exemption flag should not be restricted' );
	}

	/**
	 * Test that custom_access settings return grouped access_rules format.
	 */
	public function test_custom_access_returns_grouped_rules() {
		// Create a gate with flat access rules (legacy format).
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Grouped Rules Gate' ] );
		$this->gate_ids[] = $gate_id;

		// Save flat rules directly to post meta (simulating legacy data).
		$custom_access = [
			'active'       => true,
			'metering'     => [
				'enabled' => false,
				'count'   => 0,
				'period'  => 'month',
			],
			'access_rules' => [
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
		\update_post_meta( $gate_id, 'custom_access', $custom_access );

		// Retrieve settings - should be normalized to grouped format.
		$settings = Content_Gate::get_custom_access_settings( $gate_id );
		$this->assertTrue( $settings['active'], 'Active should be true' );
		$this->assertIsArray( $settings['access_rules'], 'access_rules should be an array' );

		// Check that flat rules were normalized to grouped format.
		$this->assertCount( 1, $settings['access_rules'], 'Should have one group' );
		$this->assertIsArray( $settings['access_rules'][0], 'First element should be an array (group)' );
		$this->assertCount( 1, $settings['access_rules'][0], 'Group should have one rule' );
		$this->assertEquals( 'email_domain', $settings['access_rules'][0][0]['slug'], 'Rule slug should be preserved' );
	}

	/**
	 * Helper to set a private static property on Content_Gate via reflection.
	 *
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 */
	private function set_content_gate_property( $property, $value ) {
		$reflection = new \ReflectionProperty( Content_Gate::class, $property );
		$reflection->setAccessible( true );
		$reflection->setValue( null, $value );
	}

	/**
	 * Reset the static per-post restriction cache on Content_Restriction_Control.
	 * This cache is populated by is_post_restricted() and must be cleared between
	 * tests to prevent cross-test contamination.
	 */
	private function reset_restriction_cache() {
		foreach ( [ 'post_gate_id_map', 'post_gate_layout_id_map', 'post_gates_map', 'term_descendants_map' ] as $prop ) {
			$reflection = new \ReflectionProperty( Content_Restriction_Control::class, $prop );
			$reflection->setAccessible( true );
			$reflection->setValue( null, [] );
		}
	}

	/**
	 * Test comment filters on fully gated posts.
	 */
	public function test_comments_closed_on_gated_post() {
		$post_id = $this->post_ids[0];

		$this->set_content_gate_property( 'is_gated', true );
		$this->set_content_gate_property( 'is_metered', false );

		// Simulate queried object.
		$this->go_to( get_permalink( $post_id ) );

		$this->assertFalse( Content_Gate::filter_comments_open( true, $post_id ), 'Comments should be closed on gated post' );
		$this->assertEmpty( Content_Gate::filter_comments_array( [ 'comment1', 'comment2' ], $post_id ), 'Comments array should be empty on gated post' );
		$this->assertSame( 0, Content_Gate::filter_comments_number( 5, $post_id ), 'Comment count should be 0 on gated post' );

		// Reset.
		$this->set_content_gate_property( 'is_gated', false );
	}

	/**
	 * Test comment filters on metered posts.
	 */
	public function test_comments_closed_but_visible_on_metered_post() {
		$post_id = $this->post_ids[0];

		$this->set_content_gate_property( 'is_gated', false );
		$this->set_content_gate_property( 'is_metered', true );

		// Simulate queried object.
		$this->go_to( get_permalink( $post_id ) );

		$this->assertFalse( Content_Gate::filter_comments_open( true, $post_id ), 'Comments should be closed on metered post' );

		$comments = [ 'comment1', 'comment2' ];
		$this->assertSame( $comments, Content_Gate::filter_comments_array( $comments, $post_id ), 'Existing comments should remain visible on metered post' );
		$this->assertSame( 5, Content_Gate::filter_comments_number( 5, $post_id ), 'Comment count should be unchanged on metered post' );

		// Reset.
		$this->set_content_gate_property( 'is_metered', false );
	}

	/**
	 * Test comment filters do not affect unrelated posts.
	 */
	public function test_comments_unaffected_on_other_posts() {
		$post_id = $this->post_ids[0];
		$other_post_id = $this->factory->post->create();
		$this->post_ids[] = $other_post_id;

		$this->set_content_gate_property( 'is_gated', true );
		$this->set_content_gate_property( 'is_metered', false );

		// Simulate queried object as the gated post.
		$this->go_to( get_permalink( $post_id ) );

		// Filters should not affect the other post.
		$this->assertTrue( Content_Gate::filter_comments_open( true, $other_post_id ), 'Comments should remain open on non-gated post' );
		$comments = [ 'comment1' ];
		$this->assertSame( $comments, Content_Gate::filter_comments_array( $comments, $other_post_id ), 'Comments array should be unchanged on non-gated post' );
		$this->assertSame( 3, Content_Gate::filter_comments_number( 3, $other_post_id ), 'Comment count should be unchanged on non-gated post' );

		// Reset.
		$this->set_content_gate_property( 'is_gated', false );
	}

	/**
	 * Test comment filters pass through on unrestricted posts.
	 */
	public function test_comments_unaffected_on_unrestricted_post() {
		$post_id = $this->post_ids[0];

		$this->set_content_gate_property( 'is_gated', false );
		$this->set_content_gate_property( 'is_metered', false );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( Content_Gate::filter_comments_open( true, $post_id ), 'Comments should remain open on unrestricted post' );
		$comments = [ 'comment1', 'comment2' ];
		$this->assertSame( $comments, Content_Gate::filter_comments_array( $comments, $post_id ), 'Comments array should be unchanged on unrestricted post' );
		$this->assertSame( 5, Content_Gate::filter_comments_number( 5, $post_id ), 'Comment count should be unchanged on unrestricted post' );
	}

	/**
	 * Test that already grouped access_rules remain unchanged.
	 */
	public function test_custom_access_preserves_grouped_rules() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Preserve Grouped Rules Gate' ] );
		$this->gate_ids[] = $gate_id;

		// Save already grouped rules.
		$grouped_rules = [
			[
				[
					'slug'  => 'subscription',
					'value' => [ 1 ],
				],
			],
			[
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
		$custom_access = [
			'active'       => true,
			'metering'     => [
				'enabled' => false,
				'count'   => 0,
				'period'  => 'month',
			],
			'access_rules' => $grouped_rules,
		];
		\update_post_meta( $gate_id, 'custom_access', $custom_access );

		// Retrieve settings - should remain grouped.
		$settings = Content_Gate::get_custom_access_settings( $gate_id );
		$this->assertCount( 2, $settings['access_rules'], 'Should have two groups' );
		$this->assertEquals( $grouped_rules, $settings['access_rules'], 'Grouped rules should be preserved' );
	}

	// =========================================================================
	// Newsletter content rule (added in feat/access-control-premium-newsletters)
	// =========================================================================

	/**
	 * A gate with a `newsletters` content rule must NOT apply to a post whose
	 * ID is not in the rule's value array.
	 */
	public function test_newsletter_content_rule_does_not_match_other_posts() {
		$list_post_id     = $this->factory->post->create();
		$other_post_id    = $this->factory->post->create();
		$this->post_ids[] = $list_post_id;
		$this->post_ids[] = $other_post_id;

		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2], // Published gate from set_up().
			[
				[
					'slug'  => 'newsletters',
					'value' => [ $list_post_id ],
				],
			]
		);

		// $other_post_id is NOT in the newsletters rule value.
		$gates = Content_Restriction_Control::get_post_gates( $other_post_id );
		$this->assertEmpty( $gates, 'Newsletter content rule must not match posts not in its value array.' );
	}

	/**
	 * A gate with a `newsletters` content rule MUST apply to a post whose
	 * ID is in the rule's value array.
	 */
	public function test_newsletter_content_rule_matches_listed_post() {
		$list_post_id     = $this->factory->post->create();
		$this->post_ids[] = $list_post_id;

		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2], // Published gate from set_up().
			[
				[
					'slug'  => 'newsletters',
					'value' => [ $list_post_id ],
				],
			]
		);

		$gates = Content_Restriction_Control::get_post_gates( $list_post_id );
		$this->assertCount( 1, $gates, 'Newsletter content rule must match a post whose ID is in the value array.' );
		$this->assertEquals( $this->gate_ids[2], $gates[0]['id'] );
	}

	/**
	 * Test that the specific_posts content rule is registered.
	 */
	public function test_specific_posts_rule_is_registered() {
		$rules = Content_Rules::get_content_rules();
		$this->assertArrayHasKey( 'specific_posts', $rules, 'specific_posts rule is registered' );

		$rule = $rules['specific_posts'];
		$this->assertSame( __( 'Specific posts', 'newspack-plugin' ), $rule['name'] );
		$this->assertSame( [], $rule['default'] );
		$this->assertTrue( $rule['include_only'], 'specific_posts is include-only (no exclusion mode)' );
		$this->assertSame( '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search', $rule['endpoint'], 'endpoint matches the registered REST route' );
		$this->assertStringContainsString( 'restrict specific posts', $rule['description'], 'description signals override behavior' );
	}

	/**
	 * Test the posts-search REST endpoint returns published posts of supported post types.
	 */
	public function test_posts_search_endpoint_returns_published_posts() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$published_post = $this->factory->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Searchable Post',
			]
		);
		$draft_post     = $this->factory->post->create(
			[
				'post_status' => 'draft',
				'post_title'  => 'Searchable Draft',
			]
		);
		$published_page = $this->factory->post->create(
			[
				'post_status' => 'publish',
				'post_type'   => 'page',
				'post_title'  => 'Searchable Page',
			]
		);
		$this->post_ids[] = $published_post;
		$this->post_ids[] = $draft_post;
		$this->post_ids[] = $published_page;

		$request = new \WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search' );
		$request->set_param( 'search', 'Searchable' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertContains( $published_post, $ids, 'Includes published post' );
		$this->assertContains( $published_page, $ids, 'Includes published page (other supported post type)' );
		$this->assertNotContains( $draft_post, $ids, 'Excludes non-published post' );

		foreach ( $data as $item ) {
			$this->assertArrayHasKey( 'id', $item );
			$this->assertArrayHasKey( 'name', $item );
			$this->assertArrayHasKey( 'type_label', $item );
		}
	}

	/**
	 * Test the posts-search endpoint can hydrate saved tokens via include.
	 */
	public function test_posts_search_endpoint_supports_include() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$post_a = $this->factory->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'A',
			]
		);
		$post_b = $this->factory->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'B',
			]
		);
		$this->post_ids[] = $post_a;
		$this->post_ids[] = $post_b;

		$request = new \WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search' );
		$request->set_param( 'include', $post_a . ',' . $post_b );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertEqualsCanonicalizing( [ $post_a, $post_b ], $ids );
	}

	/**
	 * Test the posts-search endpoint requires admin permissions.
	 *
	 * The shared `api_permissions_check` helper used by all wizard routes returns a
	 * WP_Error with status 403 when `current_user_can( $this->capability )` fails.
	 * That error code is preserved by WP_REST_Server (it only re-maps to 401 when the
	 * permission callback returns boolean false / null).
	 */
	public function test_posts_search_endpoint_requires_permissions() {
		wp_set_current_user( 0 );

		$request  = new \WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test specific_posts overrides post_types: a page in specific_posts is restricted
	 * even when the gate's post_types rule only allows posts.
	 */
	public function test_specific_posts_overrides_post_types_rule() {
		$page_id = $this->factory->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		$this->post_ids[] = $page_id;

		// Reuse the published gate (gate_ids[2]) — currently restricts post_types=['post'].
		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
				[
					'slug'  => 'specific_posts',
					'value' => [ (string) $page_id ],
				],
			]
		);

		$gates = Content_Restriction_Control::get_post_gates( $page_id );
		$this->assertCount( 1, $gates, 'Page is gated because it is listed in specific_posts, despite post_types=post' );
		$this->assertSame( $this->gate_ids[2], $gates[0]['id'] );
	}

	/**
	 * Test specific_posts with no match: gate falls back to AND evaluation of other rules.
	 */
	public function test_specific_posts_no_match_falls_through_to_other_rules() {
		$post_id          = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$other_id         = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->post_ids[] = $post_id;
		$this->post_ids[] = $other_id;

		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
				[
					'slug'  => 'specific_posts',
					'value' => [ (string) $other_id ],
				],
			]
		);

		$gates = Content_Restriction_Control::get_post_gates( $post_id );
		$this->assertCount( 1, $gates, 'Post is gated by post_types AND-chain (specific_posts did not match it)' );
	}

	/**
	 * Test specific_posts alone (no post_types rule) restricts only the listed posts.
	 */
	public function test_specific_posts_alone_restricts_only_listed_posts() {
		$gated_id         = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$ungated_id       = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->post_ids[] = $gated_id;
		$this->post_ids[] = $ungated_id;

		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'specific_posts',
					'value' => [ (string) $gated_id ],
				],
			]
		);

		$this->assertCount( 1, Content_Restriction_Control::get_post_gates( $gated_id ) );
		$this->assertCount( 0, Content_Restriction_Control::get_post_gates( $ungated_id ) );
	}

	/**
	 * Test specific_posts override wins against a category rule, too.
	 */
	public function test_specific_posts_overrides_taxonomy_rule() {
		$cat_id = $this->factory->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Restricted Only',
			]
		);

		// Post with NO category — would fail the taxonomy rule normally.
		$post_id          = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->post_ids[] = $post_id;

		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'category',
					'value' => [ $cat_id ],
				],
				[
					'slug'  => 'specific_posts',
					'value' => [ (string) $post_id ],
				],
			]
		);

		$gates = Content_Restriction_Control::get_post_gates( $post_id );
		$this->assertCount( 1, $gates, 'Post is gated via specific_posts override despite not matching the category rule' );
	}

	/**
	 * Test empty specific_posts value does NOT trigger the override and — when it's
	 * the gate's only rule — does NOT accidentally include the gate.
	 */
	public function test_specific_posts_empty_value_does_not_match() {
		$post_id          = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->post_ids[] = $post_id;

		Content_Rules::update_gate_content_rules(
			$this->gate_ids[2],
			[
				[
					'slug'  => 'specific_posts',
					'value' => [],
				],
			]
		);

		$this->assertCount( 0, Content_Restriction_Control::get_post_gates( $post_id ), 'Empty specific_posts does not include any gate' );
	}

	/**
	 * Test the posts-search endpoint treats a numeric search as a post ID lookup.
	 */
	public function test_posts_search_endpoint_numeric_search_is_id_lookup() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$target           = $this->factory->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Findable',
			]
		);
		$other            = $this->factory->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Decoy',
			]
		);
		$this->post_ids[] = $target;
		$this->post_ids[] = $other;

		$request = new \WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search' );
		$request->set_param( 'search', (string) $target );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertSame( [ $target ], $ids, 'Numeric search returns only the post with that ID' );
	}

	/**
	 * Test that include hydrates non-published tokens so the editor keeps
	 * showing items whose status changed after the gate was saved.
	 */
	public function test_posts_search_endpoint_include_hydrates_non_published() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$draft = $this->factory->post->create(
			[
				'post_status' => 'draft',
				'post_title'  => 'Was Published, Now Draft',
			]
		);
		$this->post_ids[] = $draft;

		// `include` should return the draft.
		$request = new \WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search' );
		$request->set_param( 'include', (string) $draft );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertContains( $draft, $ids, 'include hydrates non-published tokens' );

		// `search` should NOT return the draft (search-mode stays publish-only).
		$request2 = new \WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search' );
		$request2->set_param( 'search', 'Was Published' );
		$response2 = rest_get_server()->dispatch( $request2 );
		$this->assertSame( 200, $response2->get_status() );
		$ids2 = wp_list_pluck( $response2->get_data(), 'id' );
		$this->assertNotContains( $draft, $ids2, 'search-mode does not surface non-published posts' );
	}

	/**
	 * Test that per_page=0 or per_page=500 are rejected at the schema boundary with a 400 status.
	 */
	public function test_posts_search_endpoint_per_page_below_minimum() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$request = new \WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search' );
		$request->set_param( 'per_page', 0 );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status(), 'per_page=0 fails schema validation' );

		$request->set_param( 'per_page', 500 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status(), 'per_page=500 fails schema validation' );
	}

	/**
	 * Test that include with many IDs is capped at 100 results.
	 */
	public function test_posts_search_endpoint_caps_include_results() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$ids = [];
		for ( $i = 0; $i < 105; $i++ ) {
			$ids[] = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		}
		$this->post_ids = array_merge( $this->post_ids, $ids );

		$request = new \WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . '/wizard/newspack-audience-access-control/posts-search' );
		$request->set_param( 'include', implode( ',', $ids ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertLessThanOrEqual( 100, count( $response->get_data() ), 'Include result set is capped at 100' );
	}

	// =========================================================================
	// Institutional access bypassing registration (NPPD-1494)
	//
	// When a gate has both registration mode and custom_access mode active,
	// anonymous visitors must be able to pass via custom_access rules that
	// support anonymous evaluation (currently `institution`). The institution
	// rule re-checks the visitor's live IP, so a stale IP-access cookie alone
	// must not be enough to grant access.
	// =========================================================================

	/**
	 * Configure the gate at $this->gate_ids[2] (the published gate from set_up)
	 * with the given registration and custom_access blocks. Returns the
	 * gate's registration and custom_access layout IDs for layout assertions.
	 *
	 * @param array $registration  Registration block.
	 * @param array $custom_access Custom access block.
	 *
	 * @return array{registration_layout_id:int, custom_access_layout_id:int}
	 */
	private function configure_published_gate( $registration, $custom_access ) {
		$gate_id = $this->gate_ids[2];
		$gate    = Content_Gate::get_gate( $gate_id );

		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Published Gate',
				'status'        => 'publish',
				'priority'      => 2,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => array_merge(
					[
						'gate_layout_id'       => $gate['registration']['gate_layout_id'],
						'metering'             => [
							'enabled' => false,
							'count'   => 0,
							'period'  => 'month',
						],
						'require_verification' => false,
						'gate_id'              => 0,
					],
					$registration
				),
				'custom_access' => array_merge(
					[
						'gate_layout_id' => $gate['custom_access']['gate_layout_id'],
						'metering'       => [
							'enabled' => false,
							'count'   => 0,
							'period'  => 'month',
						],
						'gate_id'        => 0,
						'access_rules'   => [],
					],
					$custom_access
				),
			]
		);

		return [
			'registration_layout_id'  => (int) $gate['registration']['gate_layout_id'],
			'custom_access_layout_id' => (int) $gate['custom_access']['gate_layout_id'],
		];
	}

	/**
	 * Set the visitor's IP and the cache-bypass cookie for anonymous IP rule
	 * evaluation. Pair with reset_visitor_state() in tear-down or between
	 * scenarios.
	 *
	 * @param string $ip          Visitor IP.
	 * @param bool   $with_cookie Whether to also set the IP_Access_Rule cookie.
	 */
	private function set_visitor_ip( $ip, $with_cookie = true ) {
		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$_SERVER['REMOTE_ADDR'] = $ip;
		if ( $with_cookie ) {
			$_COOKIE[ IP_Access_Rule::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		} else {
			unset( $_COOKIE[ IP_Access_Rule::COOKIE_NAME ] );
		}
		// phpcs:enable
	}

	/**
	 * Clear visitor IP, cookie, current user, institution cache, and the
	 * per-request restriction cache. Call at the end of each scenario to
	 * avoid leaking state into other tests in the suite.
	 */
	private function reset_visitor_state() {
		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		unset( $_SERVER['REMOTE_ADDR'] );
		unset( $_COOKIE[ IP_Access_Rule::COOKIE_NAME ] );
		// phpcs:enable
		wp_set_current_user( 0 );
		delete_transient( Institution::TRANSIENT_KEY );
		$this->reset_restriction_cache();
	}

	/**
	 * Anonymous visitor whose IP matches an institution allowed by the gate's
	 * custom_access rules must be granted access even when registration mode
	 * is also active.
	 */
	public function test_anonymous_with_matching_ip_bypasses_registration() {
		$inst_id = Institution::create( 'University', '', [ 'ip_range' => '10.0.0.0/8' ] );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );

		$this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [ $inst_id ],
						],
					],
				],
			]
		);

		wp_set_current_user( 0 );
		$this->set_visitor_ip( '10.1.2.3' );
		$this->reset_restriction_cache();

		$this->assertFalse(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Anonymous visitor with matching institutional IP must not be restricted.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * Anonymous visitor on a matching IP but without the IP-access bypass
	 * cookie must still be restricted. The cookie is the page-cache-safety
	 * signal that lets Institution::user_matches_institution evaluate the
	 * IP server-side. First-time on-campus visitors have to complete the
	 * institutional-access check (which sets the cookie) before subsequent
	 * gated requests can grant access via IP — landing directly on a gated
	 * post does not.
	 */
	public function test_anonymous_with_matching_ip_without_cookie_is_restricted() {
		$inst_id = Institution::create( 'University', '', [ 'ip_range' => '10.0.0.0/8' ] );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );

		$this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [ $inst_id ],
						],
					],
				],
			]
		);

		wp_set_current_user( 0 );
		// Matching IP, but no cookie — institution rule won't run server-side.
		$this->set_visitor_ip( '10.1.2.3', false );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Anonymous visitor on matching IP without the IP-access cookie must be restricted.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * Anonymous visitor without a matching IP must be restricted, and the
	 * gate layout shown must be the registration layout (not the
	 * custom_access one), since registration is the relevant prompt for an
	 * anonymous visitor.
	 */
	public function test_anonymous_without_matching_ip_is_restricted_with_registration_layout() {
		$inst_id = Institution::create( 'University', '', [ 'ip_range' => '10.0.0.0/8' ] );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );

		$layouts = $this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [ $inst_id ],
						],
					],
				],
			]
		);

		wp_set_current_user( 0 );
		$this->set_visitor_ip( '192.168.1.1' );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Anonymous visitor with non-matching IP must be restricted.'
		);
		$this->assertSame(
			$layouts['registration_layout_id'],
			Content_Restriction_Control::get_gate_layout_id( $this->post_ids[0] ),
			'Anonymous visitor must see the registration layout, not the custom_access one.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * A stale IP-access cookie (e.g., set on campus, visitor now at home)
	 * must not grant access on its own — the institution rule re-checks the
	 * live IP, so a non-matching current IP results in restriction.
	 */
	public function test_anonymous_with_stale_cookie_but_changed_ip_is_restricted() {
		$inst_id = Institution::create( 'University', '', [ 'ip_range' => '10.0.0.0/8' ] );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );

		$this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [ $inst_id ],
						],
					],
				],
			]
		);

		wp_set_current_user( 0 );
		// Cookie present (stale from a previous on-campus session) but IP no longer matches.
		$this->set_visitor_ip( '192.168.1.1', true );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Stale IP cookie alone must not grant access — the live IP must match.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * Anonymous visitor on a registration-only gate (no custom_access) must
	 * be restricted regardless of cookie or IP.
	 */
	public function test_anonymous_on_registration_only_gate_is_restricted() {
		$this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => false,
				'access_rules' => [],
			]
		);

		wp_set_current_user( 0 );
		$this->set_visitor_ip( '10.1.2.3' );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Anonymous visitor must be restricted by a registration-only gate.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * When custom_access is active but its access_rules array is empty,
	 * anonymous visitors must still be restricted — empty rules cannot
	 * grant access (even though Access_Rules::evaluate_rules returns true
	 * for empty inputs).
	 */
	public function test_anonymous_with_empty_access_rules_is_restricted() {
		$this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => true,
				'access_rules' => [],
			]
		);

		wp_set_current_user( 0 );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Anonymous visitor must be restricted when custom_access has no rules to evaluate.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * When custom_access only contains rules that don't support anonymous
	 * evaluation (subscription, email_domain, reader_data), anonymous
	 * visitors must remain restricted.
	 */
	public function test_anonymous_cannot_bypass_via_non_anonymous_rules() {
		$this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'email_domain',
							'value' => 'example.com',
						],
					],
				],
			]
		);

		wp_set_current_user( 0 );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Anonymous visitor must not bypass registration via rules that require login.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * Blocker: an institution rule saved with no institutions selected
	 * (`value => []`) must not silently grant anonymous access. Without a
	 * value, the rule is "not configured" — Institution::evaluate(0, [])
	 * returns true as the rule's own no-constraint semantics, but for the
	 * registration bypass we require a populated rule that actually matches.
	 */
	public function test_anonymous_with_unpopulated_institution_rule_is_restricted() {
		$this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [],
						],
					],
				],
			]
		);

		wp_set_current_user( 0 );
		// Even with a matching IP and the cookie set, an unpopulated rule must not bypass.
		$this->set_visitor_ip( '10.1.2.3' );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'An institution rule with no institutions selected must not grant anonymous access.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * Anonymous visitor with a matching IP must remain restricted when the
	 * institution rule is AND-grouped with a non-anonymous-capable rule
	 * (e.g. email_domain). AND-within-group means the group can only pass
	 * if every rule passes; email_domain returns false for `user_id = 0`,
	 * so the group fails even with a matching institutional IP.
	 */
	public function test_anonymous_with_matching_ip_and_grouped_with_email_domain_is_restricted() {
		$inst_id = Institution::create( 'University', '', [ 'ip_range' => '10.0.0.0/8' ] );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );

		$this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [ $inst_id ],
						],
						[
							'slug'  => 'email_domain',
							'value' => 'example.com',
						],
					],
				],
			]
		);

		wp_set_current_user( 0 );
		$this->set_visitor_ip( '10.1.2.3' );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Anonymous visitor must be restricted when institution is AND-grouped with email_domain (which requires login).'
		);

		$this->reset_visitor_state();
	}

	/**
	 * On a custom_access-only gate (no registration), an anonymous visitor
	 * with a non-matching IP must be restricted, and the gate layout shown
	 * must be the custom_access layout.
	 */
	public function test_anonymous_on_custom_access_only_gate_is_restricted_with_custom_layout() {
		$inst_id = Institution::create( 'University', '', [ 'ip_range' => '10.0.0.0/8' ] );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );

		$layouts = $this->configure_published_gate(
			[ 'active' => false ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [ $inst_id ],
						],
					],
				],
			]
		);

		wp_set_current_user( 0 );
		$this->set_visitor_ip( '192.168.1.1' );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Anonymous visitor must be restricted by a custom_access-only gate when IP does not match.'
		);
		$this->assertSame(
			$layouts['custom_access_layout_id'],
			Content_Restriction_Control::get_gate_layout_id( $this->post_ids[0] ),
			'Custom-access-only gate must surface the custom_access layout.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * On a custom_access-only gate, an anonymous visitor with a matching IP
	 * must pass.
	 */
	public function test_anonymous_on_custom_access_only_gate_passes_with_matching_ip() {
		$inst_id = Institution::create( 'University', '', [ 'ip_range' => '10.0.0.0/8' ] );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );

		$this->configure_published_gate(
			[ 'active' => false ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [ $inst_id ],
						],
					],
				],
			]
		);

		wp_set_current_user( 0 );
		$this->set_visitor_ip( '10.1.2.3' );
		$this->reset_restriction_cache();

		$this->assertFalse(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Anonymous visitor with matching IP must not be restricted by a custom_access-only gate.'
		);

		$this->reset_visitor_state();
	}

	/**
	 * A logged-in unverified user with `require_verification` must remain
	 * restricted — the IP cookie must not bypass email verification.
	 */
	public function test_unverified_user_with_require_verification_is_restricted_despite_cookie() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		// Intentionally not setting EMAIL_VERIFIED meta.

		$this->configure_published_gate(
			[
				'active'               => true,
				'require_verification' => true,
			],
			[
				'active'       => false,
				'access_rules' => [],
			]
		);

		wp_set_current_user( $user_id );
		$this->set_visitor_ip( '10.1.2.3' );
		$this->reset_restriction_cache();

		$this->assertTrue(
			apply_filters( 'newspack_is_post_restricted', false, $this->post_ids[0] ),
			'Unverified user must remain restricted even with the IP-access cookie set.'
		);

		wp_delete_user( $user_id );
		$this->reset_visitor_state();
	}

	/**
	 * Each is_post_restricted() call must evaluate restrictions for its own
	 * $user_id, both for the bool return and for the cache slot it writes.
	 * Regression coverage for Newspack_Premium_Newsletters::process_queue,
	 * which loops over multiple user IDs in a single request.
	 */
	public function test_is_post_restricted_evaluates_each_user_independently() {
		$inst_id = Institution::create( 'University', '', [ 'email_domain' => 'university.edu' ] );
		$this->post_ids[] = $inst_id;
		delete_transient( Institution::TRANSIENT_KEY );

		$this->configure_published_gate(
			[ 'active' => true ],
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'institution',
							'value' => [ $inst_id ],
						],
					],
				],
			]
		);

		$matching_user = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'a@university.edu',
			]
		);
		update_user_meta( $matching_user, Reader_Activation::EMAIL_VERIFIED, true );
		$other_user = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'b@other.com',
			]
		);
		update_user_meta( $other_user, Reader_Activation::EMAIL_VERIFIED, true );

		$this->reset_restriction_cache();

		// Call order must not affect outcome: the matching user passes regardless of who was checked first.
		$this->assertTrue(
			Content_Restriction_Control::is_post_restricted( false, $this->post_ids[0], $other_user ),
			'Non-matching user must be restricted.'
		);
		$this->assertFalse(
			Content_Restriction_Control::is_post_restricted( false, $this->post_ids[0], $matching_user ),
			'Matching user must not be restricted, even when called after a different user.'
		);

		wp_delete_user( $matching_user );
		wp_delete_user( $other_user );
		$this->reset_visitor_state();
	}

	/**
	 * Pin the gate-layout cache contract: get_gate_layout_id() must read for
	 * the *current* user (via get_current_user_id()), not for whichever user
	 * happened to populate the cache via an earlier is_post_restricted()
	 * call. This protects the page-render viewer from seeing a queue
	 * worker's or REST callback's cached layout.
	 */
	public function test_get_gate_layout_id_does_not_return_other_users_cached_layout() {
		$queue_user = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		// $queue_user is intentionally unverified — gate's require_verification will restrict it.
		$page_user = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $page_user, Reader_Activation::EMAIL_VERIFIED, true );

		$this->configure_published_gate(
			[
				'active'               => true,
				'require_verification' => true,
			],
			[
				'active'       => false,
				'access_rules' => [],
			]
		);

		$this->reset_restriction_cache();

		// Queue-worker pattern: is_post_restricted called with an explicit, non-current user.
		// This must populate the cache under $queue_user only.
		$this->assertTrue(
			Content_Restriction_Control::is_post_restricted( false, $this->post_ids[0], $queue_user ),
			'Unverified queue user must be restricted by the require_verification gate.'
		);

		// Switch to the page-render viewer.
		wp_set_current_user( $page_user );

		$this->assertFalse(
			Content_Restriction_Control::get_gate_layout_id( $this->post_ids[0] ),
			'get_gate_layout_id must not surface a cache entry written for a different user.'
		);
		$this->assertFalse(
			Content_Restriction_Control::get_gate_post_id( $this->post_ids[0] ),
			'get_gate_post_id must not surface a cache entry written for a different user.'
		);

		wp_delete_user( $queue_user );
		wp_delete_user( $page_user );
		$this->reset_visitor_state();
	}
}
