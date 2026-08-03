<?php
/**
 * Class WidgetTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Test widget functionality.
 */
class WidgetTest extends WP_UnitTestCase {

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

		$this->widget = new Republication_Tracker_Tool_Widget();

		$this->test_post = $this->factory->post->create_and_get(
			array(
				'post_title'   => 'Test Post for Widget',
				'post_content' => '<p>Test content for widget display.</p>',
				'post_status'  => 'publish',
			)
		);
	}

	/**
	 * Clean up after tests.
	 */
	public function tear_down() {
		wp_delete_post( $this->test_post->ID, true );
		parent::tear_down();
	}

	/**
	 * Test widget displays on single posts.
	 */
	public function test_widget_displays_on_single_post() {
		global $post, $wp_query;

		$post = $this->test_post;
		$wp_query->is_single = true;
		$wp_query->queried_object = $this->test_post;
		$wp_query->queried_object_id = $this->test_post->ID;

		$args = array(
			'before_widget' => '<div class="widget">',
			'after_widget'  => '</div>',
			'before_title'  => '<h2>',
			'after_title'   => '</h2>',
		);

		$instance = array(
			'title' => 'Republish This Story',
			'text'  => 'Test widget text',
		);

		ob_start();
		$this->widget->widget( $args, $instance );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Republish This Story', $output );
		$this->assertStringContainsString( 'Test widget text', $output );

		$this->assertStringStartsWith( $args['before_widget'], $output );
		$this->assertStringContainsString( $args['after_widget'], $output );
		$this->assertStringContainsString( $args['before_title'], $output );
		$this->assertStringContainsString( $args['after_title'], $output );
		$this->assertStringContainsString( 'republication-tracker-tool-button', $output );
		$this->assertStringContainsString( 'republication-tracker-tool-modal', $output );
	}

	/**
	 * Test widget respects hide widget meta.
	 */
	public function test_widget_respects_hide_meta() {
		global $post, $wp_query;

		$post = $this->test_post;
		$wp_query->is_single = true;
		$wp_query->queried_object = $this->test_post;
		$wp_query->queried_object_id = $this->test_post->ID;

		update_post_meta( $this->test_post->ID, 'republication-tracker-tool-hide-widget', true );

		ob_start();
		$this->widget->widget( array(), array() );
		$output = ob_get_clean();

		$this->assertEmpty( $output );

		delete_post_meta( $this->test_post->ID, 'republication-tracker-tool-hide-widget' );
	}

	/**
	 * Test widget does not display on hide_republication_widget filter set to true.
	 */
	public function test_widget_does_not_display_on_hide_filter() {
		global $post, $wp_query;
		$post = $this->test_post;
		$wp_query->is_single = true;
		$wp_query->queried_object = $this->test_post;
		$wp_query->queried_object_id = $this->test_post->ID;

		add_filter( 'hide_republication_widget', '__return_true' );

		ob_start();
		$this->widget->widget( array(), array() );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
		remove_filter( 'hide_republication_widget', '__return_true' );
	}

	/**
	 * Test even if multiple instances of the widget are created, it should only contain one modal.
	 */
	public function test_multiple_widget_instances() {
		global $post, $wp_query;
		$post = $this->test_post;
		$wp_query->is_single = true;
		$wp_query->queried_object = $this->test_post;
		$wp_query->queried_object_id = $this->test_post->ID;

		$instance = array(
			'title' => 'Republish This Story',
			'text'  => 'Test widget text',
		);

		$args = array(
			'before_widget' => '<div class="widget">',
			'after_widget'  => '</div>',
			'before_title'  => '<h2>',
			'after_title'   => '</h2>',
		);

		ob_start();
		$this->widget->widget( $args, $instance );
		$this->widget->widget( $args, $instance );
		$this->widget->widget( $args, $instance );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'republication-tracker-tool-modal', $output );
		$this->assertStringContainsString( 'republication-tracker-tool-button', $output );

		// Check only one modal is present.
		$this->assertEquals( substr_count( $output, 'republication-tracker-tool-modal' ), 1, 'Single modal found in output.' );
		$this->assertEquals( substr_count( $output, 'republication-tracker-tool-button' ), 3, 'Multiple buttons found in output.' );
	}
}
