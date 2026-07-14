<?php
/**
 * Class for test
 *
 * @package Newspack_Popups
 */

/**
 * WP_UnitTestCase which renders a page with popups.
 */
class WP_UnitTestCase_PageWithPopups extends WP_UnitTestCase {
	protected static $popup_content       = 'The popup content.'; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	protected static $popup_id            = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	protected static $raw_post_content    = 'The post content.'; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	protected static $post_content        = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	protected static $post_head           = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	protected static $dom_xpath           = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	protected static $post_head_dom_xpath = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	protected static $segments            = []; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		// Reset segments.
		Newspack_Segments_Model::delete_all_segments();
		self::$segments = Newspack_Popups_Segmentation::create_segment(
			[
				'name'          => 'segment1',
				'configuration' => [],
			]
		);

		// Remove any popups (from previous tests).
		self::remove_all_popups();

		self::$popup_id = self::createPopup();

		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[ 'frequency' => 'daily' ]
		);
	}

	/**
	 * Remove all popups on the test instance.
	 */
	protected static function remove_all_popups() {
		foreach ( Newspack_Popups_Model::retrieve_popups( true ) as $popup ) {
			wp_delete_post( $popup['id'] );
		}
	}

	/**
	 * Create a popup in the database.
	 *
	 * @param string $popup_content String to render as popup content.
	 * @param object $options Options for the popup.
	 * @param object $post_options Options for the post.
	 * @return int Popup ID.
	 */
	protected function createPopup( $popup_content = null, $options = null, $post_options = [] ) {
		if ( null === $popup_content ) {
			$popup_content = self::$popup_content;
		}
		$popup_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
					'post_title'   => 'Popup title',
					'post_content' => $popup_content,
				],
				$post_options
			)
		);
		if ( null !== $options ) {
			Newspack_Popups_Model::set_popup_options( $popup_id, $options );
		}
		return $popup_id;
	}

	/**
	 * Get number of popups rendered on the page.
	 */
	protected function getRenderedPopupsAmount() {
		return self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container")]' )->length;
	}

	/**
	 * Trigger post rendering with popups in it.
	 *
	 * @param string $url_query Query to append to URL.
	 * @param string $post_content_override String to render as post content.
	 * @param array  $category_ids Ids of categories of the post.
	 * @param array  $tag_ids Ids of tags of the post.
	 * @param strine $post_type Type of the post.
	 */
	protected function renderPost( $url_query = '', $post_content_override = null, $category_ids = [], $tag_ids = [], $post_type = 'post' ) {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => $post_type,
				'post_content' => $post_content_override ? $post_content_override : self::$raw_post_content,
			]
		);

		if ( ! empty( $category_ids ) ) {
			wp_set_post_terms( $post_id, $category_ids, 'category' );
		}
		if ( ! empty( $tag_ids ) ) {
			wp_set_post_terms( $post_id, $tag_ids, 'post_tag' );
		}

		// Navigate to post.
		self::go_to( get_permalink( $post_id ) . '&' . $url_query );
		global $wp_query, $post;
		$wp_query->in_the_loop = true;
		setup_postdata( $post );

		// Reset internal duplicate-prevention.
		Newspack_Popups_Inserter::$the_content_has_rendered = false;

		// Drain any overlays queued from previous renders in this test class.
		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		ob_end_clean();

		$content = get_post( $post_id )->post_content;

		$filtered_content = apply_filters( 'the_content', $content );

		// Overlay prompts are now portaled to wp_footer rather than emitted
		// inside post content. Concatenate the flushed footer output so the
		// existing DOM-based assertions can still find overlay markup via the
		// `newspack-popup-container` class, mirroring how the rendered page
		// actually looks in the browser (post body + overlays before </body>).
		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$footer_overlays = ob_get_clean();

		self::$post_content = $filtered_content . $footer_overlays;
		$dom                = new DomDocument();
		@$dom->loadHTML( self::$post_content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		self::$dom_xpath = new DOMXpath( $dom );

		// Save page head.
		ob_start();
		wp_head();
		self::$post_head = ob_get_clean();
		$post_head_dom   = new DomDocument();
		@$post_head_dom->loadHTML( self::$post_head ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		self::$post_head_dom_xpath = new DOMXpath( $post_head_dom );
	}
}
