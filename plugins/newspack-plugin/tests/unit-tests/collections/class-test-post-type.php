<?php
/**
 * Unit tests for the Collection Post Type.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use WP_REST_Request;
use Newspack\Collections\Post_Type;
use Newspack\Collections\Enqueuer;
use Newspack\Collections\Settings;

/**
 * Test the Collections Post Type functionality.
 */
class Test_Post_Type extends WP_UnitTestCase {
	use Traits\Trait_Collections_Test;
	use Traits\Trait_Enqueuer_Test;

	/**
	 * The order column name accessible via reflection.
	 *
	 * @var string
	 */
	protected static $order_column_name;

	/**
	 * Access private constants via reflection once.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Use reflection to access the private constants.
		$reflection              = new \ReflectionClass( Post_Type::class );
		self::$order_column_name = $reflection->getConstant( 'ORDER_COLUMN_NAME' );
	}

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Register post type directly as the WP environment is already initialized.
		Post_Type::register_post_type();
	}

	/**
	 * Test that the post type is registered.
	 *
	 * @covers \Newspack\Collections\Post_Type::register_post_type
	 */
	public function test_post_type_registration() {
		$post_type = get_post_type_object( Post_Type::get_post_type() );
		$this->assertNotNull( $post_type, 'Post type should be registered.' );
		$this->assertEquals( 'Collections', $post_type->labels->name, 'Post type label should be "Collections".' );
		$this->assertTrue( $post_type->public, 'Post type should be public.' );
		$this->assertTrue( $post_type->show_in_rest, 'Post type should be available in REST API.' );
		$this->assertNotFalse( $post_type->has_archive, 'Post type should have archive.' );
	}

	/**
	 * Test that hooks are registered and unregistered correctly.
	 *
	 * @covers \Newspack\Collections\Post_Type::register_hooks
	 * @covers \Newspack\Collections\Post_Type::unregister_hooks
	 * @covers \Newspack\Collections\Post_Type::manage_hooks
	 */
	public function test_hooks_management() {
		$reflection = new \ReflectionMethod( Post_Type::class, 'get_hooks' );
		$reflection->setAccessible( true );
		$hooks = $reflection->invoke( null );

		// Test hook registration.
		Post_Type::register_hooks();
		foreach ( $hooks as $hook ) {
			$this->assertEquals(
				$hook[2] ?? 10, // If not priority is set, WP defaults to 10.
				has_action( $hook[0], $hook[1] ),
				sprintf( 'Hook "%s" should be registered.', $hook[0] )
			);
		}

		// Test hook unregistration.
		Post_Type::unregister_hooks();
		foreach ( $hooks as $hook ) {
			$this->assertFalse(
				has_action( $hook[0], $hook[1] ),
				sprintf( 'Hook "%s" should be unregistered.', $hook[0] )
			);
		}
	}

	/**
	 * Test that a collection post can be created.
	 *
	 * @covers \Newspack\Collections\Post_Type::register_post_type
	 */
	public function test_create_collection_post() {
		$args = [
			'post_title' => 'My Collection',
			'post_name'  => 'my-collection',
		];

		// Create a collection post.
		$post_id = $this->create_test_collection( $args );
		$post    = get_post( $post_id );

		$this->assertEquals( Post_Type::get_post_type(), $post->post_type, 'Post should be a collection.' );
		$this->assertEquals( $args['post_title'], $post->post_title, 'Post title should be set correctly.' );
		$this->assertEquals( $args['post_name'], $post->post_name, 'Post slug should be set correctly.' );
	}

	/**
	 * Test that collection meta data is output for admin scripts.
	 *
	 * @covers \Newspack\Collections\Post_Type::output_collection_meta_data_for_admin_scripts
	 */
	public function test_output_collection_meta_data_for_admin_scripts() {
		global $current_screen;

		// Set up the current screen.
		$current_screen = (object) [ // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			'post_type' => Post_Type::get_post_type(),
			'base'      => 'post',
		];

		// Call the method with the current screen object.
		Post_Type::output_collection_meta_data_for_admin_scripts( $current_screen );

		// Check that data was added correctly.
		$data = Enqueuer::get_data();
		$this->assertArrayHasKey( 'collectionPostType', $data, 'Collection post type data should be added.' );
		$this->assertEquals( Post_Type::get_post_type(), $data['collectionPostType']['postType'], 'Post type should be correct.' );
		$this->assertArrayHasKey( 'metaDefinitions', $data['collectionPostType'], 'Post meta definitions should be included.' );

		// Clean up.
		$current_screen = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->cleanup_enqueued_assets_for_script( Enqueuer::SCRIPT_NAME_ADMIN );
		$this->reset_enqueuer_data();
	}

	/**
	 * Test that the order column is added to the admin list view.
	 *
	 * @covers \Newspack\Collections\Post_Type::add_order_column
	 */
	public function test_add_order_column() {
		$columns = [
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'date'  => 'Date',
		];

		$result = Post_Type::add_order_column( $columns );

		$this->assertArrayHasKey( self::$order_column_name, $result, 'Order column should be added.' );
		$this->assertEquals( Post_Type::get_order_column_heading(), $result[ self::$order_column_name ], 'Order column should have the correct heading.' );
		$this->assertEquals( 'Title', $result['title'], 'Title column should be preserved.' );
		$this->assertEquals( 'Date', $result['date'], 'Date column should be preserved.' );
	}

	/**
	 * Test that the order column displays the correct value.
	 *
	 * @covers \Newspack\Collections\Post_Type::display_order_column
	 */
	public function test_display_order_column() {
		$post_id = $this->create_test_collection( [ self::$order_column_name => 42 ] );

		// Capture the output.
		ob_start();
		Post_Type::display_order_column( self::$order_column_name, $post_id );
		$output = ob_get_clean();

		$this->assertEquals( '42', $output, 'Order column should display the correct menu order value.' );

		// Test with a different column name.
		ob_start();
		Post_Type::display_order_column( 'title', $post_id );
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Non-order column should not output anything.' );
	}

	/**
	 * Test that the order column is sortable.
	 *
	 * @covers \Newspack\Collections\Post_Type::make_order_column_sortable
	 */
	public function test_make_order_column_sortable() {
		$columns = [
			'title' => 'Title',
			'date'  => 'Date',
		];

		$result = Post_Type::make_order_column_sortable( $columns );

		$this->assertArrayHasKey( self::$order_column_name, $result, 'Order column should be added to sortable columns.' );
		$this->assertEquals( self::$order_column_name, $result[ self::$order_column_name ], 'Order column should be sortable by menu_order.' );
		$this->assertEquals( 'Title', $result['title'], 'Title column should be preserved.' );
		$this->assertEquals( 'Date', $result['date'], 'Date column should be preserved.' );
	}

	/**
	 * Test that collections can be ordered by menu_order.
	 *
	 * @covers \Newspack\Collections\Post_Type::register_post_type
	 */
	public function test_collections_menu_order() {
		// Create collections with different menu orders.
		$post1 = $this->create_test_collection(
			[
				'post_title'             => 'First Collection',
				self::$order_column_name => 10,
			]
		);
		$post2 = $this->create_test_collection(
			[
				'post_title'             => 'Second Collection',
				self::$order_column_name => 5,
			]
		);
		$post3 = $this->create_test_collection(
			[
				'post_title'             => 'Third Collection',
				self::$order_column_name => 15,
			]
		);

		// Query posts ordered by menu_order.
		$query = new \WP_Query(
			[
				'post_type'      => Post_Type::get_post_type(),
				'orderby'        => self::$order_column_name,
				'order'          => 'ASC',
				'posts_per_page' => -1,
			]
		);

		$this->assertCount( 3, $query->posts, 'Should find all three collections.' );
		$this->assertEquals( $post2, $query->posts[0]->ID, 'Second collection should be first (menu_order: 5).' );
		$this->assertEquals( $post1, $query->posts[1]->ID, 'First collection should be second (menu_order: 10).' );
		$this->assertEquals( $post3, $query->posts[2]->ID, 'Third collection should be last (menu_order: 15).' );
	}

	/**
	 * Test that post type slug updates when settings change via REST API.
	 *
	 * @covers \Newspack\Collections\Settings::update_from_request
	 * @covers \Newspack\Collections\Post_Type::register_post_type
	 */
	public function test_post_type_slug_updates() {
		Post_Type::init();
		$this->assertEquals( 'collections', get_post_type_object( Post_Type::get_post_type() )->rewrite['slug'] );

		// Update settings via REST API.
		$custom_slug = 'magazine';
		$request     = new WP_REST_Request();
		$request->set_param( 'custom_naming_enabled', true );
		$request->set_param( 'custom_slug', $custom_slug );
		Settings::update_from_request( $request );
		$this->assertEquals( $custom_slug, get_post_type_object( Post_Type::get_post_type() )->rewrite['slug'] );
	}
}
