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
	 * Render the republish widget's modal for the test post, as a single post view.
	 *
	 * @return string The rendered widget markup.
	 */
	private function render_modal() {
		global $post, $wp_query;

		$post                        = $this->test_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query->is_single         = true;
		$wp_query->queried_object    = $this->test_post;
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
		return ob_get_clean();
	}

	/**
	 * The republish modal should attribute the post to the active Custom
	 * Byline value, not the WP post author.
	 */
	public function test_republish_modal_uses_custom_byline_over_post_author() {
		$output = $this->render_modal();

		$this->assertStringContainsString( 'Jane Smith', $output, 'Modal should attribute the post to the Custom Byline value.' );
		$this->assertStringNotContainsString( 'John Doe', $output, 'Modal should not fall back to the WP post author when a Custom Byline is active.' );
		$this->assertStringNotContainsString( 'by By', $output, 'Modal should not double up its own "by" prefix with a Custom Byline that already includes one.' );
		$this->assertStringNotContainsString( 'author vcard', $output, 'Custom Byline author links should be stripped, matching the plain-text WP author and CAP bylines.' );
	}

	/**
	 * A post with Custom Byline meta present but inactive should fall back
	 * to the WP post author, with the plugin's own "by" prefix intact.
	 */
	public function test_republish_modal_falls_back_to_post_author_when_custom_byline_inactive() {
		update_post_meta( $this->test_post->ID, '_newspack_byline_active', false );

		$output = $this->render_modal();

		$this->assertStringContainsString( 'John Doe', $output, 'Modal should fall back to the WP post author when the Custom Byline is inactive.' );
		$this->assertStringNotContainsString( 'Jane Smith', $output, 'Modal should not use an inactive Custom Byline.' );
		$this->assertStringContainsString( 'by John Doe', $output, 'The plugin\'s own "by" prefix should still apply for the WP post author.' );
	}

	/**
	 * A malformed byline format (e.g. from a misbehaving third-party
	 * filter) should degrade gracefully, not fatal.
	 */
	public function test_republish_modal_survives_malformed_byline_format() {
		update_post_meta( $this->test_post->ID, '_newspack_byline_active', false );

		$malformed_format = function () {
			return 'by %s %s';
		};
		add_filter( 'republication_tracker_tool_byline_format', $malformed_format );

		$output = $this->render_modal();

		remove_filter( 'republication_tracker_tool_byline_format', $malformed_format );

		$this->assertStringContainsString( 'John Doe', $output, 'Modal should still render the byline when the format is malformed.' );
	}

	/**
	 * An active Custom Byline should take precedence over Co-Authors Plus
	 * guest authors when both are present on the same post.
	 */
	public function test_republish_modal_prefers_custom_byline_over_cap_guest_author() {
		$GLOBALS['_test_cap_coauthors'] = 'CAP Guest Author';

		$output = $this->render_modal();

		unset( $GLOBALS['_test_cap_coauthors'] );

		$this->assertStringContainsString( 'Jane Smith', $output, 'Modal should attribute the post to the Custom Byline value when both CAP and Custom Byline are active.' );
		$this->assertStringNotContainsString( 'CAP Guest Author', $output, 'Modal should not use the CAP guest author when a Custom Byline is also active.' );
	}

	/**
	 * When the Plain Text tab is enabled, it should also attribute the post
	 * to the active Custom Byline value, not the WP post author.
	 */
	public function test_republish_modal_plain_text_tab_uses_custom_byline() {
		update_option( 'republication_tracker_tool_enable_plain_text', 'on' );

		$output = $this->render_modal();

		delete_option( 'republication_tracker_tool_enable_plain_text' );

		$this->assertStringContainsString( 'id="republication-tracker-tool-plain-text-content"', $output, 'The Plain Text tab should be rendered when enabled.' );
		$this->assertStringContainsString( 'By Jane Smith, Test Blog', $output, 'The Plain Text tab should attribute the post to the Custom Byline value.' );
		$this->assertStringNotContainsString( 'By John Doe', $output, 'The Plain Text tab should not fall back to the WP post author when a Custom Byline is active.' );
	}

	/**
	 * A byline format using a positional placeholder (e.g. from a
	 * translation) should still include the byline.
	 */
	public function test_byline_text_supports_positional_placeholder() {
		$positional_format = function () {
			return 'por %1$s';
		};
		add_filter( 'republication_tracker_tool_byline_format', $positional_format );
		// The CAP mock (loaded in bootstrap) replaces the byline argument with this global.
		$GLOBALS['_test_cap_coauthors'] = 'John Doe';

		$byline_text = Republication_Tracker_Tool::get_byline_text( 'John Doe' );

		unset( $GLOBALS['_test_cap_coauthors'] );
		remove_filter( 'republication_tracker_tool_byline_format', $positional_format );

		$this->assertSame( 'por John Doe, Test Blog', $byline_text );
	}

	/**
	 * A null byline (e.g. get_the_author() outside the loop, or a filter
	 * returning null) should not trigger PHP deprecation notices.
	 */
	public function test_byline_text_handles_null_byline() {
		$null_byline = function () {
			return null;
		};
		add_filter( 'republication_tracker_tool_byline', $null_byline, 99 );

		$deprecations = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		set_error_handler(
			function ( $errno, $errstr ) use ( &$deprecations ) {
				$deprecations[] = $errstr;
				return true;
			},
			E_DEPRECATED
		);

		$byline_text = Republication_Tracker_Tool::get_byline_text( null );

		restore_error_handler();
		remove_filter( 'republication_tracker_tool_byline', $null_byline, 99 );

		$this->assertSame( array(), $deprecations, 'A null byline should not trigger deprecation notices.' );
		$this->assertStringContainsString( 'Test Blog', $byline_text, 'A null byline should still include the site name.' );
	}

	/**
	 * The HTML and Plain Text tabs should share the same byline format, so a
	 * CAP byline keeps the plugin's "by" prefix in both.
	 */
	public function test_republish_modal_html_and_plain_text_tabs_share_byline_format() {
		update_post_meta( $this->test_post->ID, '_newspack_byline_active', false );
		update_option( 'republication_tracker_tool_enable_plain_text', 'on' );
		$GLOBALS['_test_cap_coauthors'] = 'CAP Guest Author';

		$output = $this->render_modal();

		unset( $GLOBALS['_test_cap_coauthors'] );
		delete_option( 'republication_tracker_tool_enable_plain_text' );

		// Once each in the modal's visible article info, the HTML tab and the Plain Text tab.
		$this->assertSame( 3, substr_count( $output, 'by CAP Guest Author, Test Blog' ), 'Both the HTML and Plain Text tabs should prefix the CAP byline with "by".' );
	}

	/**
	 * On the standalone /republish/ page the global post is the main query's
	 * post, not the one being republished. The Plain Text byline should still
	 * come from the republished post.
	 */
	public function test_plain_text_byline_uses_republished_post_not_global_post() {
		global $post;

		update_post_meta( $this->test_post->ID, '_newspack_byline_active', false );

		$global_post = $this->factory->post->create_and_get( array( 'post_status' => 'publish' ) );
		update_post_meta( $global_post->ID, '_newspack_byline_active', true );
		update_post_meta( $global_post->ID, '_newspack_byline', 'By Someone Else' );

		$post = $global_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $global_post );

		$plain_text = Republication_Tracker_Tool_Content::get_republishable_plain_text_content( $this->test_post );

		$this->assertStringContainsString( 'by John Doe, Test Blog', $plain_text, 'The Plain Text byline should credit the republished post\'s author.' );
		$this->assertStringNotContainsString( 'Someone Else', $plain_text, 'The Plain Text byline should not use the global post\'s Custom Byline.' );
		$this->assertSame( $global_post->ID, $post->ID, 'The global post should be restored afterwards.' );
	}
}
