<?php
/**
 * Class CustomBylineAttributionTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Test that the republish modal attributes posts using an active Custom
 * Byline, rather than falling back to the WP post author.
 */
class CustomBylineAttributionTest extends WP_UnitTestCase {

	/**
	 * Test widget.
	 *
	 * @var Republication_Tracker_Tool_Widget
	 */
	private $widget;

	/**
	 * Test post.
	 *
	 * @var WP_Post
	 */
	private $test_post;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();

		require_once __DIR__ . '/mocks/class-newspack-bylines-mock.php';

		$this->widget = new Republication_Tracker_Tool_Widget();

		$author_id = $this->factory->user->create(
			array(
				'display_name' => 'John Doe',
			)
		);

		$this->test_post = $this->factory->post->create_and_get(
			array(
				'post_title'   => 'Test Post with Custom Byline',
				'post_content' => '<p>Test content.</p>',
				'post_status'  => 'publish',
				'post_author'  => $author_id,
			)
		);

		update_post_meta( $this->test_post->ID, '_newspack_byline_active', true );
		update_post_meta( $this->test_post->ID, '_newspack_byline', 'Jane Smith' );
	}

	/**
	 * Clean up after tests.
	 */
	public function tear_down() {
		wp_reset_postdata();
		wp_delete_post( $this->test_post->ID, true );
		Republication_Tracker_Tool::$modal_rendered = false;
		parent::tear_down();
	}

	/**
	 * The republish modal should attribute the post to the active Custom
	 * Byline value, not the WP post author.
	 */
	public function test_republish_modal_uses_custom_byline_over_post_author() {
		global $post, $wp_query;

		$post                     = $this->test_post;
		$wp_query->is_single      = true;
		$wp_query->queried_object = $this->test_post;
		$wp_query->queried_object_id = $this->test_post->ID;
		setup_postdata( $this->test_post );

		$args = array(
			'before_widget' => '<div class="widget">',
			'after_widget'  => '</div>',
			'before_title'  => '<h2>',
			'after_title'   => '</h2>',
		);

		$instance = array(
			'title' => 'Republish This Story',
			'text'  => 'Republish this story',
		);

		ob_start();
		$this->widget->widget( $args, $instance );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Jane Smith', $output, 'Modal should attribute the post to the Custom Byline value.' );
		$this->assertStringNotContainsString( 'John Doe', $output, 'Modal should not fall back to the WP post author when a Custom Byline is active.' );
	}
}
