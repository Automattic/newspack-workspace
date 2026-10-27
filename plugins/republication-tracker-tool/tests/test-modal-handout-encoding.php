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
	 * The site icon keeps a small size through an ordinary paste into the block editor.
	 *
	 * That paste keeps only these attributes on an image (the paste schema in the
	 * `@wordpress/dom` package) and drops the rest, `style` included, so an icon sized
	 * only by `style` shows at the size of the file in the republished story. Core
	 * also deletes a pasted image that is 1px wide or high.
	 */
	public function test_site_icon_keeps_a_small_size_through_a_block_editor_paste() {
		$paste_keeps = [ 'alt', 'src', 'srcset', 'usemap', 'ismap', 'width', 'height' ];
		$processor   = new WP_HTML_Tag_Processor( $this->textarea_value() );
		$icon        = [];
		while ( $processor->next_tag( 'img' ) ) {
			if ( 'https://example.test/icon.png' === $processor->get_attribute( 'src' ) ) {
				foreach ( array_intersect( $processor->get_attribute_names_with_prefix( '' ), $paste_keeps ) as $name ) {
					$icon[ $name ] = $processor->get_attribute( $name );
				}
				break;
			}
		}

		$this->assertArrayHasKey( 'src', $icon, 'The site icon is missing.' );
		foreach ( [ 'width', 'height' ] as $dimension ) {
			$this->assertArrayHasKey( $dimension, $icon, "Nothing the paste keeps sets the icon's $dimension." );
			$this->assertMatchesRegularExpression( '/^\d+$/', (string) $icon[ $dimension ] );
			$this->assertGreaterThan( 1, (int) $icon[ $dimension ] );
			$this->assertLessThanOrEqual( 32, (int) $icon[ $dimension ] );
		}
	}

	/**
	 * A non-breaking space sets the site icon off from the attribution text.
	 *
	 * The paste drops the icon's margin along with its style; the space survives it and
	 * keeps the icon on the same line as the sentence's last word.
	 */
	public function test_site_icon_is_set_off_by_a_non_breaking_space() {
		$this->assertStringContainsString( '&nbsp;<img src="https://example.test/icon.png"', $this->textarea_value() );
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
