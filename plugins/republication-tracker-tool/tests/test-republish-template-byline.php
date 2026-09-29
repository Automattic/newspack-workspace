<?php
/**
 * Class RepublishTemplateBylineTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Test that the /republish/ page attributes the republished post, not
 * whatever post the page's main query left as the global post.
 *
 * These tests rely on the CAP and Newspack\Bylines mocks falling back to the
 * global post, like the real ones; that's what makes them fail if the
 * template stops setting it.
 */
class RepublishTemplateBylineTest extends WP_UnitTestCase {

	/**
	 * The post being republished.
	 *
	 * @var WP_Post
	 */
	private $republished_post;

	/**
	 * A newer post by a different author, standing in for the global post
	 * the /republish/ page's home-style main query sets up.
	 *
	 * @var WP_Post
	 */
	private $newest_post;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();

		require_once __DIR__ . '/mocks/class-newspack-bylines-mock.php';

		update_option( 'republication_tracker_tool_enable_plain_text', 'on' );

		$this->republished_post = $this->factory->post->create_and_get(
			array(
				'post_title'  => 'Republished Post',
				'post_status' => 'publish',
				'post_author' => $this->factory->user->create( array( 'display_name' => 'John Doe' ) ),
				'post_date'   => '2026-01-01 00:00:00',
			)
		);

		$this->newest_post = $this->factory->post->create_and_get(
			array(
				'post_title'  => 'Newest Post',
				'post_status' => 'publish',
				'post_author' => $this->factory->user->create( array( 'display_name' => 'Newest Author' ) ),
				'post_date'   => '2026-02-01 00:00:00',
			)
		);
	}

	/**
	 * Clean up after tests.
	 */
	public function tear_down() {
		wp_reset_postdata();
		delete_option( 'republication_tracker_tool_enable_plain_text' );
		parent::tear_down();
	}

	/**
	 * Render the republish page template for the republished post, with the
	 * newest post as the global post.
	 *
	 * @return string The template output.
	 */
	private function render_template() {
		global $post, $wp_query;

		// Mirror the page's home-style main query, where the newest post is
		// both the query's post and the global post.
		$wp_query->post = $this->newest_post;
		$post           = $this->newest_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		set_query_var( 'republish_post_id', $this->republished_post->ID );

		ob_start();
		include REPUBLICATION_TRACKER_TOOL_PATH . 'templates/republish-template.php';
		return ob_get_clean();
	}

	/**
	 * Both tabs should use the republished post's author, not the global
	 * post's (the CAP mock reads the global post, like real CAP).
	 */
	public function test_both_tabs_use_republished_post_author() {
		$output = $this->render_template();

		// Once in the HTML tab and once in the Plain Text tab.
		$this->assertSame( 2, substr_count( $output, 'by John Doe, Test Blog' ), 'Both tabs should attribute the republished post\'s author.' );
		$this->assertStringNotContainsString( 'Newest Author', $output, 'Neither tab should use the global post\'s author.' );
	}

	/**
	 * Both tabs should use the republished post's Custom Byline.
	 */
	public function test_both_tabs_use_republished_post_custom_byline() {
		update_post_meta( $this->republished_post->ID, '_newspack_byline_active', true );
		update_post_meta( $this->republished_post->ID, '_newspack_byline', 'By Jane Smith' );

		$output = $this->render_template();

		$this->assertSame( 2, substr_count( $output, 'By Jane Smith, Test Blog' ), 'Both tabs should use the republished post\'s Custom Byline.' );
		$this->assertStringNotContainsString( 'John Doe', $output, 'Neither tab should fall back to the WP author when a Custom Byline is active.' );
	}

	/**
	 * The global post's Custom Byline should not be used for another post.
	 */
	public function test_global_post_custom_byline_is_not_used() {
		update_post_meta( $this->newest_post->ID, '_newspack_byline_active', true );
		update_post_meta( $this->newest_post->ID, '_newspack_byline', 'By Someone Else' );

		$output = $this->render_template();

		$this->assertStringNotContainsString( 'Someone Else', $output, 'Neither tab should use the global post\'s Custom Byline.' );
		$this->assertSame( 2, substr_count( $output, 'by John Doe, Test Blog' ) );
	}

	/**
	 * The existing republication_tracker_tool_author_byline filter should
	 * still be able to change the HTML tab's byline.
	 */
	public function test_author_byline_filter_still_applies() {
		$custom_author_byline = function () {
			return 'Filtered Byline';
		};
		add_filter( 'republication_tracker_tool_author_byline', $custom_author_byline );

		$output = $this->render_template();

		remove_filter( 'republication_tracker_tool_author_byline', $custom_author_byline );

		$this->assertStringContainsString( 'Filtered Byline', $output );
	}

	/**
	 * The global post should be restored after the template renders.
	 */
	public function test_global_post_is_restored() {
		global $post;

		$this->render_template();

		$this->assertSame( $this->newest_post->ID, $post->ID );
	}
}
