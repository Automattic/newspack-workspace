<?php
/**
 * Class ModalHandoutEncodingTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * What the Republish modal serves in its copy textarea.
 *
 * A browser decodes a textarea's source once. After that decode the story has to
 * still show code it displays as text, and the footer (tracking and attribution)
 * has to be working markup, because the modal's caption-strip round trip writes
 * back through `.value` and decodes nothing more. The block's round trip is pinned
 * by `src/blocks/republish-button/view.test.js`.
 *
 * @group modal-encoding
 */
class ModalHandoutEncodingTest extends WP_UnitTestCase {

	/**
	 * Story under test.
	 *
	 * @var WP_Post
	 */
	private $story;

	/**
	 * Set up a story that shows code as text, and every footer part.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'parsely', [ 'apikey' => 'example.test' ] );
		update_option( 'republication_tracker_tool_analytics_ga4_id', 'G-TEST000000' );
		update_option(
			'republication_tracker_additional_tracking_code',
			'<noscript><img src="https://example.test/p.gif?u={{post-url}}&amp;x=1" alt="café"></noscript><!-- </textarea> --><span id="extra-tracking-end"></span>'
		);
		add_filter( 'get_site_icon_url', [ $this, 'site_icon_url' ] );
		$this->story = self::factory()->post->create_and_get(
			[
				'post_content' => '<p>Code: &lt;script&gt;window.rttMarker=1&lt;/script&gt;</p>',
				'post_status'  => 'publish',
			]
		);
	}

	/**
	 * Reset the shared modal guard.
	 */
	public function tear_down() {
		Republication_Tracker_Tool::$modal_rendered = false;
		parent::tear_down();
	}

	/**
	 * Synthetic site icon, so the attribution carries one.
	 *
	 * @return string
	 */
	public function site_icon_url() {
		return 'https://example.test/icon.png';
	}

	/**
	 * Render the widget modal and return the copy textarea's value as a browser builds it.
	 *
	 * @return string
	 */
	private function textarea_value() {
		global $post, $wp_query;
		$post                        = $this->story; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query->is_single         = true;
		$wp_query->queried_object    = $this->story;
		$wp_query->queried_object_id = $this->story->ID;

		ob_start();
		( new Republication_Tracker_Tool_Widget() )->widget(
			[
				'before_widget' => '',
				'after_widget'  => '',
			],
			[ 'text' => '' ]
		);
		$output = ob_get_clean();

		$found = preg_match( '#<textarea[^>]*id="republication-tracker-tool-shareable-content"[^>]*>(.*?)</textarea>#s', $output, $matches );
		$this->assertSame( 1, $found, 'The modal copy textarea is missing.' );

		// The one decode the browser does when it builds the textarea.
		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Code the story shows as text stays text.
	 */
	public function test_story_code_shown_as_text_stays_text() {
		$value = $this->textarea_value();
		$this->assertStringContainsString( 'Code: &lt;script&gt;window.rttMarker=1&lt;/script&gt;', $value );
		$this->assertStringNotContainsString( '<script>window.rttMarker', $value );
	}

	/**
	 * Tracking, attribution and site icon arrive as markup, not as encoded text.
	 */
	public function test_footer_is_working_markup() {
		$value     = $this->textarea_value();
		$permalink = get_permalink( $this->story );
		$this->assertStringContainsString( '<img id="republication-tracker-tool-source" src="', $value );
		$this->assertStringNotContainsString( '&lt;img id=', $value );
		$this->assertStringContainsString( '<script id="parsely-cfg" src="//cdn.parsely.com/keys/example.test/p.js"></script>', $value );
		$this->assertStringContainsString( '<a target="_blank" href="' . $permalink . '">article</a>', $value );
		$this->assertStringContainsString( '<img src="https://example.test/icon.png"', $value );
		$this->assertStringContainsString( '<noscript><img src="https://example.test/p.gif?u=' . $permalink . '&amp;x=1" alt="café"></noscript>', $value );
	}

	/**
	 * A literal closing textarea tag in tracking code stays inside the textarea.
	 */
	public function test_footer_cannot_close_the_textarea() {
		$this->assertStringContainsString( '<span id="extra-tracking-end"></span>', $this->textarea_value() );
	}

	/**
	 * With Parse.ly, GA4, additional code and attribution off, the pixel is still markup.
	 */
	public function test_footer_with_only_the_pixel() {
		delete_option( 'parsely' );
		delete_option( 'republication_tracker_tool_analytics_ga4_id' );
		delete_option( 'republication_tracker_additional_tracking_code' );
		update_option( 'republication_tracker_tool_display_attribution', 'off' );
		$value = $this->textarea_value();
		$this->assertStringContainsString( '<img id="republication-tracker-tool-source" src="', $value );
		$this->assertStringNotContainsString( '&lt;', str_replace( 'Code: &lt;script&gt;window.rttMarker=1&lt;/script&gt;', '', $value ) );
	}

	/**
	 * Raw markup a site's filter adds to the tracking code arrives as markup, inside the textarea.
	 *
	 * Passing the encoded footer straight through kses would strip the script and keep the
	 * closing tag, which would end the textarea early.
	 */
	public function test_raw_markup_from_a_tracking_filter_stays_inside_the_textarea() {
		add_filter(
			'republication_tracker_tool_tracking_code',
			function ( $html ) {
				return $html . '</textarea><script id="raw-from-filter"></script>';
			}
		);
		$this->assertStringContainsString( '</textarea><script id="raw-from-filter"></script>', $this->textarea_value() );
	}
}
