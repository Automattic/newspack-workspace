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

		$custom_byline_author_id = $this->factory->user->create(
			array(
				'display_name' => 'Jane Smith',
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
		update_post_meta(
			$this->test_post->ID,
			'_newspack_byline',
			sprintf( 'By [Author id=%d]Jane Smith[/Author]', $custom_byline_author_id )
		);
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
		$this->assertStringNotContainsString( 'by By', $output, 'Modal should not double up its own "by" prefix with a Custom Byline that already includes one.' );
	}

	/**
	 * A post with Custom Byline meta present but inactive should fall back
	 * to the WP post author, with the plugin's own "by" prefix intact.
	 */
	public function test_republish_modal_falls_back_to_post_author_when_custom_byline_inactive() {
		global $post, $wp_query;

		update_post_meta( $this->test_post->ID, '_newspack_byline_active', false );

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

		$this->assertStringContainsString( 'John Doe', $output, 'Modal should fall back to the WP post author when the Custom Byline is inactive.' );
		$this->assertStringNotContainsString( 'Jane Smith', $output, 'Modal should not use an inactive Custom Byline.' );
		$this->assertStringContainsString( 'by John Doe', $output, 'The plugin\'s own "by" prefix should still apply for the WP post author.' );
	}

	/**
	 * A malformed byline format (e.g. from a misbehaving third-party
	 * filter) should degrade gracefully, not fatal.
	 */
	public function test_republish_modal_survives_malformed_byline_format() {
		global $post, $wp_query;

		update_post_meta( $this->test_post->ID, '_newspack_byline_active', false );

		$malformed_format = function () {
			return 'by %s %s';
		};
		add_filter( 'republication_tracker_tool_byline_format', $malformed_format );

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

		remove_filter( 'republication_tracker_tool_byline_format', $malformed_format );

		$this->assertStringContainsString( 'John Doe', $output, 'Modal should still render the byline when the format is malformed.' );
	}
}
