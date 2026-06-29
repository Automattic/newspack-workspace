<?php
/**
 * Class Ad Block Renderer Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;
use Newspack_Newsletters\Ads;

/**
 * Ad block renderer tests.
 *
 * The `newspack-newsletters/ad` block has no save output (`html: false` in
 * block.json), so without a custom renderer the WC email-editor package would
 * silently drop the ad from the rendered email. The Newspack override
 * (`Email_Renderers\Blocks\Ad`) resolves the ad post and renders its block
 * content through the active WC email pipeline, mirroring what the legacy MJML
 * renderer does via `post_to_mjml_components()`.
 *
 * These tests use `render_wc()` end-to-end and assert that ad block content
 * appears in the output. They also cover auto-insertion: `render_wc()` now
 * applies the `newspack_newsletters_newsletter_content` filter (which runs
 * Ads::filter_newsletter_content()) before passing content to the WC renderer,
 * so ads scheduled for auto-injection also render correctly.
 */
class Test_Ad_Block extends WP_UnitTestCase {
	/**
	 * Boot the WC editor package so render_wc() can render newsletters.
	 */
	public function set_up() {
		parent::set_up();
		Editor_Bootstrap::init();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Create a minimal ad CPT post with simple block content.
	 *
	 * No start/expiry date meta is set, so Ads::is_ad_active() returns true.
	 * The ad content is a single paragraph so the rendered output is predictable.
	 *
	 * @param string $ad_text The text to include in the ad paragraph.
	 * @return \WP_Post The ad post.
	 */
	private function create_ad_post( string $ad_text ): \WP_Post {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => Ads::CPT,
				'post_status'  => 'publish',
				'post_title'   => 'Test Ad',
				'post_content' => '<!-- wp:paragraph --><p>' . esc_html( $ad_text ) . '</p><!-- /wp:paragraph -->',
			]
		);
		return get_post( $post_id );
	}

	/**
	 * Render newsletter content through the WC engine.
	 *
	 * @param string $content Block markup for the newsletter body.
	 * @return string Rendered email HTML.
	 */
	private function render_newsletter( string $content ): string {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Ad block test newsletter',
				'post_content' => $content,
			]
		);
		return Renderer_Controller::render_wc( get_post( $post_id ) );
	}

	// ------------------------------------------------------------------
	// Manual ad blocks (adId set to a specific post ID)
	// ------------------------------------------------------------------

	/**
	 * A manually-placed ad block renders the ad post's content.
	 *
	 * Without the override the WC package receives an empty $block_content
	 * (the block has no save output) and its fallback renderer emits nothing —
	 * the ad is silently dropped. The override must resolve the ad post and
	 * render its block content so the recipient sees the ad.
	 */
	public function test_manual_ad_block_renders_ad_content() {
		$ad_post    = $this->create_ad_post( 'BUY OUR PRODUCT' );
		$newsletter = '<!-- wp:newspack-newsletters/ad {"adId":"' . $ad_post->ID . '"} /-->';

		$html = $this->render_newsletter( $newsletter );

		$this->assertStringContainsString(
			'BUY OUR PRODUCT',
			$html,
			'Expected the ad post paragraph text to appear in the rendered newsletter HTML.'
		);
	}

	/**
	 * An ad block with an unresolvable post ID renders nothing (not fatal).
	 *
	 * When the referenced post doesn't exist the override must return an empty
	 * string (not throw, not render garbage) — the rest of the newsletter still
	 * renders correctly.
	 */
	public function test_ad_block_with_unknown_id_renders_empty() {
		$newsletter = '<!-- wp:paragraph --><p>Before ad.</p><!-- /wp:paragraph -->'
			. '<!-- wp:newspack-newsletters/ad {"adId":"9999999"} /-->'
			. '<!-- wp:paragraph --><p>After ad.</p><!-- /wp:paragraph -->';

		$html = $this->render_newsletter( $newsletter );

		// The surrounding paragraphs must render; the ad renders empty, not fatal.
		$this->assertStringContainsString( 'Before ad.', $html, 'Expected pre-ad paragraph to render.' );
		$this->assertStringContainsString( 'After ad.', $html, 'Expected post-ad paragraph to render.' );
		$this->assertStringNotContainsString( '9999999', $html, 'Expected the bad ad ID not to appear in the output.' );
	}

	/**
	 * The ad block marks the ad as inserted after rendering.
	 *
	 * The MJML renderer calls Ads::mark_ad_inserted() after rendering the ad.
	 * The WC override must do the same so that impression tracking and
	 * de-duplication work correctly when the same newsletter is rendered twice
	 * in the same request (e.g., preview + send).
	 */
	public function test_manual_ad_block_marks_ad_as_inserted() {
		$ad_post = $this->create_ad_post( 'Marked Ad' );
		$newsletter_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Mark test newsletter',
				'post_content' => '<!-- wp:newspack-newsletters/ad {"adId":"' . $ad_post->ID . '"} /-->',
			]
		);

		Renderer_Controller::render_wc( get_post( $newsletter_id ) );

		$this->assertTrue(
			Ads::is_ad_inserted( $newsletter_id, $ad_post->ID ),
			'Expected the ad to be marked as inserted after rendering.'
		);
	}

	// ------------------------------------------------------------------
	// Auto-insertion via newspack_newsletters_newsletter_content filter
	// ------------------------------------------------------------------

	/**
	 * Auto-inserted ads appear in the WC-rendered email.
	 *
	 * The legacy MJML renderer applies `newspack_newsletters_newsletter_content`
	 * to the newsletter's content before parsing, which lets Ads::filter_newsletter_content()
	 * inject ad blocks at the right positions. render_wc() must apply the same
	 * filter so auto-scheduled ads also reach the WC renderer as real block
	 * comments that the Ad block renderer then expands.
	 */
	public function test_auto_inserted_ad_renders_in_wc_output() {
		$ad_post = $this->create_ad_post( 'AUTO INSERTED AD TEXT' );

		// Newsletter with no manual ad block — auto-insertion should fire.
		$newsletter_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Auto-insert test newsletter',
				'post_content' => '<!-- wp:paragraph --><p>Article content.</p><!-- /wp:paragraph -->',
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $newsletter_id ) );

		$this->assertStringContainsString(
			'AUTO INSERTED AD TEXT',
			$html,
			'Expected auto-inserted ad content to appear in the WC-rendered newsletter.'
		);
	}

	/**
	 * Auto-inserted ads still render when the post cache is cold.
	 *
	 * The renderer feeds the filtered (ad-injected) content to the package via a
	 * render-scoped `the_content` filter rather than swapping the shared `posts`
	 * cache entry. This regression test busts the cache for the newsletter before
	 * rendering — the old cache-swap approach silently dropped the ad here because
	 * `wp_cache_replace()` is a no-op on a missing key.
	 */
	public function test_auto_inserted_ad_renders_on_cold_post_cache() {
		$this->create_ad_post( 'COLD CACHE AD TEXT' );

		$newsletter_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Cold cache newsletter',
				'post_content' => '<!-- wp:paragraph --><p>Article content.</p><!-- /wp:paragraph -->',
			]
		);

		$newsletter = get_post( $newsletter_id );
		// Force the package's internal get_post() to miss the cache and read the
		// canonical (un-injected) content from the database.
		clean_post_cache( $newsletter_id );
		wp_cache_delete( $newsletter_id, 'posts' );

		$html = Renderer_Controller::render_wc( $newsletter );

		$this->assertStringContainsString(
			'COLD CACHE AD TEXT',
			$html,
			'Expected the auto-inserted ad to render even with a cold post cache.'
		);
	}

	// ------------------------------------------------------------------
	// Direct-ID guards: only an active ad of the ads CPT may render
	// ------------------------------------------------------------------

	/**
	 * An ad block pointing at a non-ad post renders nothing.
	 *
	 * The direct-ID path must confirm the resolved post is of the ads CPT, so a
	 * stale or wrong id can't leak an arbitrary post's content into the email.
	 */
	public function test_direct_id_non_ad_post_renders_empty() {
		$plain_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Regular article',
				'post_content' => '<!-- wp:paragraph --><p>SECRET ARTICLE BODY</p><!-- /wp:paragraph -->',
			]
		);

		$html = $this->render_newsletter( '<!-- wp:newspack-newsletters/ad {"adId":"' . $plain_id . '"} /-->' );

		$this->assertStringNotContainsString(
			'SECRET ARTICLE BODY',
			$html,
			'Expected a non-ad post pointed at by adId to render nothing.'
		);
	}

	/**
	 * An ad block pointing at an expired ad renders nothing.
	 *
	 * The direct-ID path runs the same `is_ad_active()` check as auto-select, so an
	 * ad past its expiry date is skipped instead of rendered.
	 */
	public function test_direct_id_inactive_ad_renders_empty() {
		$ad_post = $this->create_ad_post( 'EXPIRED AD TEXT' );
		update_post_meta( $ad_post->ID, 'expiry_date', '2000-01-01' );

		$html = $this->render_newsletter( '<!-- wp:newspack-newsletters/ad {"adId":"' . $ad_post->ID . '"} /-->' );

		$this->assertStringNotContainsString(
			'EXPIRED AD TEXT',
			$html,
			'Expected an expired ad pointed at by adId to render nothing.'
		);
	}

	/**
	 * A self-referencing ad renders once and does not blank the newsletter.
	 *
	 * An ad whose content embeds an ad block for itself re-enters the renderer;
	 * the render-stack cycle guard must stop the recursion so the ad's own content
	 * still renders (once) rather than recursing until the whole render is empty.
	 */
	public function test_cyclic_ad_reference_renders_without_blanking() {
		// Create the ad, then point its embedded ad block at its own ID.
		$ad_id = self::factory()->post->create(
			[
				'post_type'   => Ads::CPT,
				'post_status' => 'publish',
				'post_title'  => 'Self-referencing ad',
			]
		);
		wp_update_post(
			[
				'ID'           => $ad_id,
				'post_content' => '<!-- wp:paragraph --><p>CYCLE AD MARKER</p><!-- /wp:paragraph -->'
					. '<!-- wp:newspack-newsletters/ad {"adId":"' . $ad_id . '"} /-->',
			]
		);

		$html = $this->render_newsletter(
			'<!-- wp:paragraph --><p>Intro.</p><!-- /wp:paragraph -->'
			. '<!-- wp:newspack-newsletters/ad {"adId":"' . $ad_id . '"} /-->'
			. '<!-- wp:paragraph --><p>Outro.</p><!-- /wp:paragraph -->'
		);

		// The surrounding content renders, and the ad marker appears exactly once
		// (the nested self-reference is stopped by the cycle guard).
		$this->assertStringContainsString( 'Intro.', $html, 'Expected the intro paragraph to render.' );
		$this->assertStringContainsString( 'Outro.', $html, 'Expected the outro paragraph to render.' );
		$this->assertSame(
			1,
			substr_count( $html, 'CYCLE AD MARKER' ),
			'Expected the self-referencing ad to render its content exactly once.'
		);
	}
}
