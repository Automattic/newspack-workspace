<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class OverlayQueueing Test
 *
 * Verifies that overlay popups are portaled to wp_footer rather than emitted
 * inside post content, so the rendered DOM node is a direct child of <body>
 * and escapes any ancestor stacking context (transformed wrapper, sticky ad
 * container, isolation:isolate, etc.) that would otherwise trap the popup's
 * z-index below sibling content.
 *
 * @package Newspack_Popups
 */

/**
 * OverlayQueueing test case.
 */
class OverlayQueueingTest extends WP_UnitTestCase {

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		// Drain any queued overlays carried over from prior tests.
		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		ob_end_clean();
	}

	/**
	 * Build an overlay popup object via the real model so it carries every
	 * default option the markup generator expects.
	 *
	 * @param string $title        Optional title.
	 * @param string $trigger_type 'time' or 'scroll'. Scroll-triggered overlays
	 *                             ship with a page-position marker that must stay
	 *                             inline in the content.
	 * @return array Popup object as consumed by the inserter.
	 */
	private static function create_overlay_popup_object( $title = 'Overlay prompt', $trigger_type = 'time' ) {
		$popup_id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_title'   => $title,
				'post_content' => 'Overlay body for ' . $title,
			]
		);
		Newspack_Popups_Model::set_popup_options(
			$popup_id,
			[
				'placement'    => 'center',
				'trigger_type' => $trigger_type,
			]
		);
		return Newspack_Popups_Model::create_popup_object( get_post( $popup_id ) );
	}

	/**
	 * The returned content from insert_popups_in_post_content must NOT include
	 * the overlay markup — it should be queued for wp_footer instead.
	 */
	public function test_overlay_not_inlined_into_returned_content() {
		$overlay_popup = self::create_overlay_popup_object();
		$post_content  = "<!-- wp:paragraph -->\n<p>Body paragraph.</p>\n<!-- /wp:paragraph -->\n";

		$returned_content = Newspack_Popups_Inserter::insert_popups_in_post_content(
			$post_content,
			[ $overlay_popup ]
		);

		self::assertStringNotContainsString(
			'newspack-lightbox',
			$returned_content,
			'Overlay markup must not be inlined into post content; it is queued for wp_footer.'
		);
		self::assertStringNotContainsString(
			'<!-- wp:html -->',
			$returned_content,
			'The legacy wp:html wrapper around inlined overlay markup must no longer appear in returned content.'
		);
		self::assertStringContainsString(
			'Body paragraph.',
			$returned_content,
			'Original post content must be preserved.'
		);
	}

	/**
	 * After insert_popups_in_post_content queues an overlay, print_queued_overlays
	 * emits the overlay markup once.
	 */
	public function test_queued_overlay_is_emitted_at_footer() {
		$overlay_popup = self::create_overlay_popup_object();
		Newspack_Popups_Inserter::insert_popups_in_post_content( '<p>Body.</p>', [ $overlay_popup ] );

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$footer_output = ob_get_clean();

		self::assertStringContainsString(
			'newspack-lightbox',
			$footer_output,
			'print_queued_overlays must emit the queued overlay markup.'
		);
	}

	/**
	 * Queueing the same overlay popup from multiple call paths (e.g. singular
	 * content + above-header) must result in a single emission, deduped by ID.
	 *
	 * Asserted by counting the number of outer lightbox containers (`id="id_<n>"`)
	 * for the popup in question, not by total output length – more direct, and
	 * stable against any future per-emission variability inside the markup.
	 */
	public function test_dedupe_by_id_across_multiple_queue_calls() {
		$overlay_popup = self::create_overlay_popup_object();
		$expected_id   = 'id="' . Newspack_Popups_Model::canonize_popup_id( $overlay_popup['id'] ) . '"';

		// Queue the SAME popup multiple times via repeated calls.
		Newspack_Popups_Inserter::insert_popups_in_post_content( '<p>Body.</p>', [ $overlay_popup ] );
		Newspack_Popups_Inserter::insert_popups_in_post_content( '<p>Body.</p>', [ $overlay_popup ] );
		Newspack_Popups_Inserter::insert_popups_in_post_content( '<p>Body.</p>', [ $overlay_popup ] );

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$footer_output = ob_get_clean();

		self::assertSame(
			1,
			substr_count( $footer_output, $expected_id ),
			'Queueing the same popup multiple times must still produce exactly one lightbox container.'
		);
	}

	/**
	 * The dedupe map is shared across injection points: a popup reached from
	 * `insert_popups_in_post_content` (singular content path) AND the helper
	 * that backs the classic / block-theme above-header path must still result
	 * in a single emission. The factory default trigger (`time`) is what would
	 * normally route a popup through the above-header path in production; the
	 * test here asserts the dedupe contract on `queue_overlay()` itself, not
	 * the per-callsite eligibility filtering.
	 */
	public function test_dedupe_across_injection_points() {
		$overlay_popup = self::create_overlay_popup_object( 'Cross-path overlay', 'time' );
		$expected_id   = 'id="' . Newspack_Popups_Model::canonize_popup_id( $overlay_popup['id'] ) . '"';

		Newspack_Popups_Inserter::insert_popups_in_post_content( '<p>Body.</p>', [ $overlay_popup ] );

		// Reach the queue from a second path by invoking the private queue
		// helper directly.
		$reflection_method = new ReflectionMethod( 'Newspack_Popups_Inserter', 'queue_overlay' );
		$reflection_method->setAccessible( true );
		$reflection_method->invoke( null, $overlay_popup );

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$footer_output = ob_get_clean();

		self::assertSame(
			1,
			substr_count( $footer_output, $expected_id ),
			'A popup queued from two different injection points must emit exactly once.'
		);
	}

	/**
	 * The `insert_popups_after_header` archive path (classic Newspack theme)
	 * must still emit the scroll-trigger page-position marker inline – the
	 * IntersectionObserver reveal mechanism needs it to find a marker DOM node
	 * to observe. The lightbox itself is still queued for the footer flush.
	 */
	public function test_classic_archive_path_emits_marker_inline_for_scroll_triggered() {
		$overlay_popup = self::create_overlay_popup_object( 'Archive scroll overlay', 'scroll' );

		// Pretend we're rendering an archive page.
		global $wp_query;
		$prior_singular        = $wp_query ? $wp_query->is_singular : false;
		$wp_query->is_singular = false;

		// Force `popups_for_post()` to return our overlay rather than running
		// the eligibility query, which depends on a fully bootstrapped request.
		$reflection_class = new ReflectionClass( 'Newspack_Popups_Inserter' );
		$popups_property  = $reflection_class->getProperty( 'popups' );
		$popups_property->setAccessible( true );
		$prior_popups = $popups_property->getValue();
		$popups_property->setValue( null, [ $overlay_popup ] );

		ob_start();
		Newspack_Popups_Inserter::insert_popups_after_header();
		$inline_output = ob_get_clean();

		// Restore globals.
		$popups_property->setValue( null, $prior_popups );
		$wp_query->is_singular = $prior_singular;

		self::assertStringContainsString(
			'page-position-marker_',
			$inline_output,
			'Classic-theme archive scroll-triggered overlays must emit the page-position marker inline.'
		);
		self::assertStringNotContainsString(
			'newspack-lightbox',
			$inline_output,
			'The lightbox markup must NOT be emitted inline from the archive path; it should be queued for the footer.'
		);

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$footer_output = ob_get_clean();

		self::assertStringContainsString(
			'newspack-lightbox',
			$footer_output,
			'The lightbox must be emitted at wp_footer for archive overlays.'
		);
	}

	/**
	 * Inline placements must still be inlined into post content — only
	 * overlay-typed placements are portaled.
	 */
	public function test_inline_placement_remains_in_returned_content() {
		$inline_popup = [
			'id'      => wp_rand(),
			'content' => 'Inline content.',
			'options' => [
				'placement'               => 'inline',
				'trigger_type'            => 'scroll',
				'trigger_scroll_progress' => '0',
				'trigger_blocks_count'    => '0',
			],
		];
		$returned_content = Newspack_Popups_Inserter::insert_popups_in_post_content(
			"<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n",
			[ $inline_popup ]
		);

		self::assertStringContainsString(
			'[newspack-popup id="' . $inline_popup['id'] . '"]',
			$returned_content,
			'Inline popups must still be emitted into post content as the shortcode block.'
		);

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$footer_output = ob_get_clean();

		self::assertSame(
			'',
			$footer_output,
			'Inline popups must never reach the overlay footer queue.'
		);
	}

	/**
	 * Mixed batch: one inline + one overlay. Inline goes inline; overlay goes
	 * to the footer queue.
	 */
	public function test_inline_and_overlay_route_to_their_respective_paths() {
		$overlay_popup = self::create_overlay_popup_object();
		$inline_popup  = [
			'id'      => wp_rand(),
			'content' => 'Inline content.',
			'options' => [
				'placement'               => 'inline',
				'trigger_type'            => 'scroll',
				'trigger_scroll_progress' => '0',
				'trigger_blocks_count'    => '0',
			],
		];

		$returned_content = Newspack_Popups_Inserter::insert_popups_in_post_content(
			"<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n",
			[ $inline_popup, $overlay_popup ]
		);

		self::assertStringContainsString(
			'[newspack-popup id="' . $inline_popup['id'] . '"]',
			$returned_content,
			'Inline popup must still be inlined.'
		);
		self::assertStringNotContainsString(
			'newspack-lightbox',
			$returned_content,
			'Overlay popup must not leak into post content when batched with an inline popup.'
		);

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$footer_output = ob_get_clean();

		self::assertStringContainsString(
			'newspack-lightbox',
			$footer_output,
			'Overlay popup must be emitted at the footer.'
		);
		self::assertStringNotContainsString(
			'[newspack-popup id="' . $inline_popup['id'] . '"]',
			$footer_output,
			'Inline popup must never leak into the footer queue output.'
		);
	}

	/**
	 * Scroll-triggered overlays must keep their page-position marker inline in
	 * the post content (the IntersectionObserver mechanism for scroll-trigger
	 * needs the marker positioned against `.entry-content`). The lightbox itself
	 * is still portaled to the footer queue.
	 */
	public function test_scroll_triggered_overlay_marker_stays_inline() {
		$overlay_popup = self::create_overlay_popup_object( 'Scroll overlay', 'scroll' );

		$returned_content = Newspack_Popups_Inserter::insert_popups_in_post_content(
			"<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n",
			[ $overlay_popup ]
		);

		self::assertStringContainsString(
			'page-position-marker_',
			$returned_content,
			'Scroll-triggered overlays must emit their page-position marker inline in the post content.'
		);
		self::assertStringNotContainsString(
			'newspack-lightbox',
			$returned_content,
			'The lightbox itself must still be queued for footer rendering, not emitted inline.'
		);

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$footer_output = ob_get_clean();

		self::assertStringContainsString(
			'newspack-lightbox',
			$footer_output,
			'The lightbox should be emitted at wp_footer.'
		);
		self::assertStringNotContainsString(
			'page-position-marker_',
			$footer_output,
			'The footer-emitted lightbox must NOT carry a duplicate page-position marker; the inline one is the one that drives scroll trigger.'
		);
	}

	/**
	 * Time-triggered overlays have no page-position marker. None should be
	 * emitted inline, and the lightbox is queued for footer.
	 */
	public function test_time_triggered_overlay_has_no_inline_marker() {
		$overlay_popup = self::create_overlay_popup_object( 'Time overlay', 'time' );

		$returned_content = Newspack_Popups_Inserter::insert_popups_in_post_content(
			"<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n",
			[ $overlay_popup ]
		);

		self::assertStringNotContainsString(
			'page-position-marker_',
			$returned_content,
			'Time-triggered overlays must not produce a page-position marker.'
		);
	}

	/**
	 * Flushing the queue must clear it: a second flush emits nothing.
	 */
	public function test_flush_clears_the_queue() {
		$overlay_popup = self::create_overlay_popup_object();
		Newspack_Popups_Inserter::insert_popups_in_post_content( '<p>Body.</p>', [ $overlay_popup ] );

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		ob_end_clean();

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$second_flush = ob_get_clean();

		self::assertSame(
			'',
			$second_flush,
			'Flushing the queue must drain it; a subsequent flush should be a no-op.'
		);
	}

	/**
	 * Regression: after a flush, re-queueing the same scroll-triggered overlay
	 * in the same request (e.g. a downstream `apply_filters( 'the_content', ... )`
	 * call) must re-emit both the lightbox AND the inline page-position marker.
	 * The overlay-queue + emitted-markers maps drain together; otherwise the
	 * second pass silently produces a lightbox without a marker and the
	 * scroll-trigger observer in segmentation.js finds nothing to observe.
	 */
	public function test_marker_re_emits_after_flush_and_requeue() {
		$overlay_popup = self::create_overlay_popup_object( 'Scroll overlay', 'scroll' );

		$first_content = Newspack_Popups_Inserter::insert_popups_in_post_content(
			"<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n",
			[ $overlay_popup ]
		);
		self::assertStringContainsString( 'page-position-marker_', $first_content, 'First pass must emit the inline scroll-trigger marker.' );

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		ob_end_clean();

		$second_content = Newspack_Popups_Inserter::insert_popups_in_post_content(
			"<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->\n",
			[ $overlay_popup ]
		);
		self::assertStringContainsString(
			'page-position-marker_',
			$second_content,
			'After a flush, re-queueing the same scroll-triggered overlay must re-emit the inline marker (else a scroll overlay silently fails to reveal).'
		);

		ob_start();
		Newspack_Popups_Inserter::print_queued_overlays();
		$second_footer = ob_get_clean();
		self::assertStringContainsString( 'newspack-lightbox', $second_footer, 'After a flush, the re-queued overlay must also re-emit its lightbox at the footer.' );
	}

	/**
	 * NPPM-2897: an overlay's blocks are rendered inside print_queued_overlays
	 * via render_block(), which *stores* the block's layout/spacing CSS in the
	 * style engine's `block-supports` store. WordPress flushes that store with
	 * wp_enqueue_stored_styles() at wp_footer priority 1. If overlays render
	 * after that priority, their inline block-support styles (e.g. a Row block's
	 * `justify-content: space-between`) are stored too late and never printed.
	 * Guard the ordering: the overlay flush must run before the style flush.
	 */
	public function test_overlays_render_before_block_support_style_flush() {
		$overlay_priority = has_action( 'wp_footer', [ 'Newspack_Popups_Inserter', 'print_queued_overlays' ] );
		$flush_priority   = has_action( 'wp_footer', 'wp_enqueue_stored_styles' );

		self::assertNotFalse( $overlay_priority, 'print_queued_overlays must be hooked to wp_footer.' );
		self::assertNotFalse( $flush_priority, 'WordPress core must flush stored block-support styles on wp_footer.' );
		// A lower wp_footer priority runs earlier. Overlays must render before the
		// style flush, so the overlay priority must be the smaller number.
		self::assertTrue(
			$overlay_priority < $flush_priority,
			sprintf(
				'Overlays must render before wp_enqueue_stored_styles flushes block-support styles, or their inline block styles are dropped (NPPM-2897). print_queued_overlays is at wp_footer priority %d; the style flush is at priority %d — the overlay priority must be lower.',
				$overlay_priority,
				$flush_priority
			)
		);
	}

	/**
	 * #194 stacking-context invariant: overlay lightboxes must be portaled out
	 * to wp_footer (where they land as a direct child of <body> and escape any
	 * ancestor stacking context), never emitted back inside `the_content`. The
	 * NPPM-2897 fix only changed the wp_footer *priority*; this guards that it
	 * stays a wp_footer hook and never regresses to a the_content hook (which
	 * would re-trap the lightbox's z-index in an ancestor stacking context). The
	 * complementary behavioral check that the markup is absent from the_content
	 * output lives in test_overlay_not_inlined_into_returned_content().
	 */
	public function test_overlays_portaled_to_footer_not_content() {
		self::assertNotFalse(
			has_action( 'wp_footer', [ 'Newspack_Popups_Inserter', 'print_queued_overlays' ] ),
			'Overlay lightboxes must be flushed from wp_footer so they render as a direct <body> child (#194).'
		);
		self::assertFalse(
			has_action( 'the_content', [ 'Newspack_Popups_Inserter', 'print_queued_overlays' ] ),
			'print_queued_overlays must never be hooked to the_content — inlining re-traps the lightbox in an ancestor stacking context (#194).'
		);
	}
}
