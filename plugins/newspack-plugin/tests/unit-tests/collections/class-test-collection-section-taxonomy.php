<?php
/**
 * Unit tests for the Collection Section Taxonomy handler.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Collections\Collection_Section_Taxonomy
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use WP_REST_Request;
use Newspack\Collections\Collection_Section_Taxonomy;
use Newspack\Collections\Post_Type;
use Newspack\Collections\Settings;

/**
 * Test the Collection Section Taxonomy functionality.
 */
class Test_Collection_Section_Taxonomy extends WP_UnitTestCase {

	use Traits\Trait_Collections_Test;
	use Traits\Trait_Meta_Handler_Test;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();
		Collection_Section_Taxonomy::register_taxonomy();
		Collection_Section_Taxonomy::register_meta();
		Collection_Section_Taxonomy::init();
	}

	/**
	 * Test that the taxonomy is registered.
	 *
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::register_taxonomy
	 */
	public function test_taxonomy_registration() {
		$taxonomy = get_taxonomy( Collection_Section_Taxonomy::get_taxonomy() );
		$this->assertNotNull( $taxonomy, 'Taxonomy should be registered.' );
		$this->assertEquals( 'Collection Sections', $taxonomy->labels->name, 'Taxonomy label should be "Collection Sections".' );
		$this->assertTrue( $taxonomy->public, 'Taxonomy should be public.' );
		$this->assertContains( 'post', $taxonomy->object_type, 'Taxonomy should be associated with posts.' );
	}

	/**
	 * Test that the meta is registered.
	 *
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::register_meta
	 */
	public function test_register_meta() {
		$this->set_current_user_role( 'administrator' );
		$this->assertMetaFieldsRegistered( Collection_Section_Taxonomy::class, 'term', Collection_Section_Taxonomy::get_taxonomy() );
		$this->assertFrontendMetaDefinitionsValid( Collection_Section_Taxonomy::class );
		$term_id = wp_insert_term( 'Test Section', Collection_Section_Taxonomy::get_taxonomy() );
		$this->assertMetaValueCanBeSetAndRetrieved( Collection_Section_Taxonomy::class, $term_id['term_id'], 'section_order', 5 );
	}

	/**
	 * Test set_parent_menu returns the collections menu slug when taxonomy matches.
	 */
	public function test_set_parent_menu_returns_collections_menu_when_taxonomy_matches() {
		global $current_screen;
		$current_screen = (object) [ 'taxonomy' => Collection_Section_Taxonomy::get_taxonomy() ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$original = 'edit.php';
		$result   = Collection_Section_Taxonomy::set_parent_menu( $original );
		$this->assertEquals(
			'edit.php?post_type=' . Post_Type::get_post_type(),
			$result,
			'Should return the collections menu slug when taxonomy matches.'
		);
	}

	/**
	 * Test set_parent_menu returns the original parent file when taxonomy does not match.
	 */
	public function test_set_parent_menu_returns_original_when_taxonomy_does_not_match() {
		global $current_screen;
		$current_screen = (object) [ 'taxonomy' => 'other_taxonomy' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$original = 'edit.php';
		$result   = Collection_Section_Taxonomy::set_parent_menu( $original );
		$this->assertEquals(
			$original,
			$result,
			'Should return the original parent file when taxonomy does not match.'
		);
	}

	/**
	 * Test that set_taxonomy_column_name_in_post_list changes the taxonomy column label to 'Sections'.
	 *
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::set_taxonomy_column_name_in_post_list
	 */
	public function test_set_taxonomy_column_name_in_post_list() {
		$columns = [
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'taxonomy-' . Collection_Section_Taxonomy::get_taxonomy() => 'Collection Sections',
			'date'  => 'Date',
		];

		$result = Collection_Section_Taxonomy::set_taxonomy_column_name_in_post_list( $columns );

		$this->assertEquals(
			'Sections',
			$result[ 'taxonomy-' . Collection_Section_Taxonomy::get_taxonomy() ],
			'The taxonomy column label should be changed to "Sections".'
		);
	}

	/**
	 * Test that the order column is added to the taxonomy admin list.
	 *
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::add_order_column
	 */
	public function test_add_order_column() {
		$columns = [
			'cb'          => '<input type="checkbox" />',
			'name'        => 'Name',
			'description' => 'Description',
			'slug'        => 'Slug',
		];

		$result = Collection_Section_Taxonomy::add_order_column( $columns );

		$this->assertArrayHasKey( Collection_Section_Taxonomy::ORDER_COLUMN_NAME, $result, 'Order column should be added.' );
		$this->assertEquals( Collection_Section_Taxonomy::get_order_column_heading(), $result[ Collection_Section_Taxonomy::ORDER_COLUMN_NAME ], 'Order column should have the correct heading.' );
		$this->assertEquals( 'Name', $result['name'], 'Name column should be preserved.' );
		$this->assertEquals( 'Description', $result['description'], 'Description column should be preserved.' );
		$this->assertEquals( 'Slug', $result['slug'], 'Slug column should be preserved.' );
	}

	/**
	 * Test that the order column displays the correct value.
	 *
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::display_order_column
	 */
	public function test_display_order_column() {
		$this->set_current_user_role( 'administrator' );

		// Create a term with order meta.
		$term = wp_insert_term( 'Test Section', Collection_Section_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $term );
		Collection_Section_Taxonomy::set( $term['term_id'], 'section_order', 5 );

		// Test displaying the order.
		$content = Collection_Section_Taxonomy::display_order_column( '', Collection_Section_Taxonomy::ORDER_COLUMN_NAME, $term['term_id'] );
		$this->assertEquals( '5', $content, 'Order column should display the correct value.' );

		// Test with a term that has no order meta.
		$term2 = wp_insert_term( 'Test Section 2', Collection_Section_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $term2 );
		$content = Collection_Section_Taxonomy::display_order_column( '', Collection_Section_Taxonomy::ORDER_COLUMN_NAME, $term2['term_id'] );
		$this->assertEquals( '0', $content, 'Order column should display 0 for terms with no order meta.' );

		// Test with a different column.
		$content = Collection_Section_Taxonomy::display_order_column( 'Original', 'other_column', $term['term_id'] );
		$this->assertEquals( 'Original', $content, 'Other columns should not be modified.' );
	}

	/**
	 * Test that the order column is made sortable.
	 *
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::make_order_column_sortable
	 */
	public function test_make_order_column_sortable() {
		$sortable_columns = [];
		$result           = Collection_Section_Taxonomy::make_order_column_sortable( $sortable_columns );

		$this->assertArrayHasKey( Collection_Section_Taxonomy::ORDER_COLUMN_NAME, $result, 'Order column should be made sortable.' );
		$this->assertEquals( 'meta_value_num', $result[ Collection_Section_Taxonomy::ORDER_COLUMN_NAME ], 'Order column should be sorted by meta value numerically.' );
	}

	/**
	 * Test that the order meta is saved when creating a term.
	 *
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::save_order_meta
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::ensure_order_meta_on_create
	 */
	public function test_save_order_meta() {
		$this->set_current_user_role( 'administrator' );

		// Test saving order meta when creating a term.
		$_POST[ Collection_Section_Taxonomy::$prefix . 'section_order' ] = '10';
		$term = wp_insert_term( 'Test Section', Collection_Section_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $term );

		$order = Collection_Section_Taxonomy::get( $term['term_id'], 'section_order' );
		$this->assertEquals( '10', $order, 'Order meta should be saved when creating a term.' );

		// Test saving order meta when updating a term.
		$_POST[ Collection_Section_Taxonomy::$prefix . 'section_order' ] = '20';
		wp_update_term( $term['term_id'], Collection_Section_Taxonomy::get_taxonomy(), [ 'name' => 'Updated Section' ] );

		$order = Collection_Section_Taxonomy::get( $term['term_id'], 'section_order' );
		$this->assertEquals( '20', $order, 'Order meta should be saved when updating a term.' );

		// Test that order meta is set to 0 when creating a term without order.
		unset( $_POST[ Collection_Section_Taxonomy::$prefix . 'section_order' ] );
		$term2 = wp_insert_term( 'Test Section 2', Collection_Section_Taxonomy::get_taxonomy() );
		$this->assertNotWPError( $term2 );

		$order = Collection_Section_Taxonomy::get( $term2['term_id'], 'section_order' );
		$this->assertEquals( '0', $order, 'Order meta should default to 0 when creating a term without order.' );
	}

	/**
	 * Test that the order field is added to the quick edit form.
	 *
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::add_quick_edit_field
	 */
	public function test_add_quick_edit_field() {
		// Start output buffering to capture the output.
		ob_start();
		Collection_Section_Taxonomy::add_quick_edit_field( Collection_Section_Taxonomy::ORDER_COLUMN_NAME, 'edit-tags', Collection_Section_Taxonomy::get_taxonomy() );
		$output = ob_get_clean();

		$this->assertStringContainsString( Collection_Section_Taxonomy::$prefix . 'section_order', $output, 'Quick edit field should include the order meta key.' );
		$this->assertStringContainsString( Collection_Section_Taxonomy::get_order_column_heading(), $output, 'Quick edit field should include the order column heading.' );
		$this->assertStringContainsString( 'type="number"', $output, 'Quick edit field should be a number input.' );
		$this->assertStringContainsString( 'min="0"', $output, 'Quick edit field should have a minimum value of 0.' );
		$this->assertStringContainsString( 'step="1"', $output, 'Quick edit field should have a step value of 1.' );

		// Test that the field is not added for other columns.
		ob_start();
		Collection_Section_Taxonomy::add_quick_edit_field( 'other_column', 'edit-tags', Collection_Section_Taxonomy::get_taxonomy() );
		$output = ob_get_clean();
		$this->assertEmpty( $output, 'Quick edit field should not be added for other columns.' );
	}

	/**
	 * Test that section taxonomy slug updates when settings change via REST API.
	 *
	 * @covers \Newspack\Collections\Settings::update_from_request
	 * @covers \Newspack\Collections\Collection_Section_Taxonomy::register_taxonomy
	 */
	public function test_section_taxonomy_slug_updates() {
		Collection_Section_Taxonomy::init();
		$this->assertEquals( 'collection-section', get_taxonomy( Collection_Section_Taxonomy::get_taxonomy() )->rewrite['slug'] );

		// Update settings via REST API.
		$custom_slug = 'magazine';
		$request     = new WP_REST_Request();
		$request->set_param( 'custom_naming_enabled', true );
		$request->set_param( 'custom_slug', $custom_slug );
		Settings::update_from_request( $request );
		$this->assertEquals( $custom_slug . '-section', get_taxonomy( Collection_Section_Taxonomy::get_taxonomy() )->rewrite['slug'] );
	}
}
