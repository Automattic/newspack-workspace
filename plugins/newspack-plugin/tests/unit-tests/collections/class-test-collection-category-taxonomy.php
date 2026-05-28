<?php
/**
 * Unit tests for the Collection Category Taxonomy handler.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Collections\Collection_Category_Taxonomy
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use WP_REST_Request;
use Newspack\Collections\Collection_Category_Taxonomy;
use Newspack\Collections\Post_Type;
use Newspack\Collections\Settings;

/**
 * Test the Collection Category Taxonomy functionality.
 */
class Test_Collection_Category_Taxonomy extends WP_UnitTestCase {
	use Traits\Trait_Collections_Test;
	use Traits\Trait_Meta_Handler_Test;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Register post type and taxonomy directly as the WP environment is already initialized.
		Post_Type::register_post_type();
		Collection_Category_Taxonomy::register_taxonomy();
	}

	/**
	 * Test that the taxonomy is registered.
	 *
	 * @covers \Newspack\Collections\Collection_Category_Taxonomy::register_taxonomy
	 */
	public function test_taxonomy_registration() {
		$taxonomy = get_taxonomy( Collection_Category_Taxonomy::get_taxonomy() );
		$this->assertNotNull( $taxonomy, 'Taxonomy should be registered.' );
		$this->assertEquals( 'Collection Categories', $taxonomy->labels->name, 'Taxonomy label should be "Collection Categories".' );
		$this->assertTrue( $taxonomy->public, 'Taxonomy should be public.' );
		$this->assertContains( Post_Type::get_post_type(), $taxonomy->object_type, 'Taxonomy should be associated with collection post type.' );
	}

	/**
	 * Test that set_taxonomy_column_name changes the taxonomy column label to 'Categories'.
	 *
	 * @covers \Newspack\Collections\Collection_Category_Taxonomy::set_taxonomy_column_name
	 */
	public function test_set_taxonomy_column_name() {
		$columns = [
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'taxonomy-' . Collection_Category_Taxonomy::get_taxonomy() => 'Collection Categories',
			'date'  => 'Date',
		];

		$result = Collection_Category_Taxonomy::set_taxonomy_column_name( $columns );

		$this->assertEquals(
			'Categories',
			$result[ 'taxonomy-' . Collection_Category_Taxonomy::get_taxonomy() ],
			'The taxonomy column label should be changed to "Categories".'
		);
	}

	/**
	 * Test that category taxonomy slug updates when settings change via REST API.
	 *
	 * @covers \Newspack\Collections\Settings::update_from_request
	 * @covers \Newspack\Collections\Collection_Category_Taxonomy::register_taxonomy
	 */
	public function test_category_taxonomy_slug_updates() {
		Collection_Category_Taxonomy::init();
		$this->assertEquals( 'collection-category', get_taxonomy( Collection_Category_Taxonomy::get_taxonomy() )->rewrite['slug'] );

		// Update settings via REST API.
		$custom_slug = 'magazine';
		$request     = new WP_REST_Request();
		$request->set_param( 'custom_naming_enabled', true );
		$request->set_param( 'custom_slug', $custom_slug );
		Settings::update_from_request( $request );
		$this->assertEquals( $custom_slug . '-category', get_taxonomy( Collection_Category_Taxonomy::get_taxonomy() )->rewrite['slug'] );
	}

	/**
	 * Test that term meta fields are registered correctly.
	 *
	 * @covers \Newspack\Collections\Collection_Category_Taxonomy::register_meta
	 * @covers \Newspack\Collections\Collection_Category_Taxonomy::register_meta_for_object
	 * @covers \Newspack\Collections\Collection_Category_Taxonomy::get_meta_definitions
	 */
	public function test_register_meta() {
		Collection_Category_Taxonomy::register_meta();
		$this->assertMetaFieldsRegistered( Collection_Category_Taxonomy::class, 'term', Collection_Category_Taxonomy::get_taxonomy() );
		$this->assertFrontendMetaDefinitionsValid( Collection_Category_Taxonomy::class );
		$term_id = wp_insert_term( 'Test Category', Collection_Category_Taxonomy::get_taxonomy() );
		$this->assertMetaValueCanBeSetAndRetrieved( Collection_Category_Taxonomy::class, $term_id['term_id'], 'subscribe_link', 'https://example.com/subscribe' );
	}

	/**
	 * Test saving and retrieving term meta values.
	 *
	 * @covers \Newspack\Collections\Collection_Category_Taxonomy::save_term_meta
	 */
	public function test_term_meta_save_and_retrieval() {
		$term_id = wp_insert_term( 'Test Category', Collection_Category_Taxonomy::get_taxonomy() );
		$this->assertIsArray( $term_id, 'Term should be created successfully.' );

		$term_id        = $term_id['term_id'];
		$subscribe_link = 'https://example.com/subscribe';
		$order_link     = 'https://example.com/order';

		// Test saving subscribe link.
		$_POST[ Collection_Category_Taxonomy::$prefix . 'subscribe_link' ] = $subscribe_link;
		$this->set_current_user_role( 'administrator' );
		Collection_Category_Taxonomy::save_term_meta( $term_id );

		$subscribe_link = get_term_meta( $term_id, 'newspack_collection_subscribe_link', true );
		$this->assertEquals( $subscribe_link, $subscribe_link, 'Subscribe link should be saved correctly.' );

		// Test saving order link.
		$_POST[ Collection_Category_Taxonomy::$prefix . 'order_link' ] = $order_link;
		Collection_Category_Taxonomy::save_term_meta( $term_id );

		$order_link = get_term_meta( $term_id, 'newspack_collection_order_link', true );
		$this->assertEquals( $order_link, $order_link, 'Order link should be saved correctly.' );

		// Test deleting meta when empty value is provided.
		$_POST[ Collection_Category_Taxonomy::$prefix . 'subscribe_link' ] = '';
		Collection_Category_Taxonomy::save_term_meta( $term_id );

		$subscribe_link = get_term_meta( $term_id, 'newspack_collection_subscribe_link', true );
		$this->assertEmpty( $subscribe_link, 'Subscribe link should be deleted when empty value is provided.' );

		// Clean up $_POST.
		unset( $_POST[ Collection_Category_Taxonomy::$prefix . 'subscribe_link' ], $_POST[ Collection_Category_Taxonomy::$prefix . 'order_link' ] );
	}

	/**
	 * Test auth callback for term meta fields.
	 *
	 * @covers \Newspack\Collections\Collection_Category_Taxonomy::auth_callback
	 */
	public function test_auth_callback() {
		$this->set_current_user_role( 'administrator' );
		$this->assertTrue( Collection_Category_Taxonomy::auth_callback(), 'Editor should have permission to manage categories.' );

		$this->set_current_user_role( 'subscriber' );
		$this->assertFalse( Collection_Category_Taxonomy::auth_callback(), 'Subscriber should not have permission to manage categories.' );
	}
}
