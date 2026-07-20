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
 * The `newspack-newsletters/ad` block has no save output, so without a custom renderer the WC
 * package silently drops ads. Covers manual ad blocks, auto-insertion via the
 * `newspack_newsletters_newsletter_content` filter, and guards (type check, expiry, cycle detection).
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
	 * Create a minimal ad CPT post with no date meta (so is_ad_active() returns true).
	 *
	 * @param string $ad_text Text in the ad paragraph.
	 * @return \WP_Post
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
	 * A manually-placed ad block renders the ad post's content — without the override the WC package
	 * receives empty block content and silently drops the ad.
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
	 * An ad block with an unresolvable post ID renders nothing (not fatal) — surrounding content is unaffected.
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
	 * Rendering an ad block marks it as inserted for impression tracking and de-duplication,
	 * mirroring what the MJML renderer does via Ads::mark_ad_inserted().
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
	 * Auto-inserted ads appear in the WC-rendered email via the `newspack_newsletters_newsletter_content`
	 * filter — render_wc() must apply this filter before passing content to the WC renderer.
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
	 * Auto-inserted ads render even with a cold post cache — guards the regression where the old
	 * cache-swap approach used wp_cache_replace(), which is a no-op on a missing key and silently dropped ads.
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
	// Link tracking
	// ------------------------------------------------------------------

	/**
	 * Ad links are routed through click tracking — the WC ad renderer runs the ad's HTML
	 * through process_links() with the ad's own post context, so ad URLs are proxied through
	 * the click endpoint, mirroring the MJML ad path. Click tracking only proxies ad links.
	 */
	public function test_ad_links_are_click_tracked() {
		update_option( 'newspack_newsletters_use_click_tracking', '1' );
		$ad_id = self::factory()->post->create(
			[
				'post_type'    => Ads::CPT,
				'post_status'  => 'publish',
				'post_title'   => 'Tracked Ad',
				'post_content' => '<!-- wp:paragraph --><p><a href="https://example.com/deal">Advertiser</a></p><!-- /wp:paragraph -->',
			]
		);

		$html = $this->render_newsletter( '<!-- wp:newspack-newsletters/ad {"adId":"' . $ad_id . '"} /-->' );

		delete_option( 'newspack_newsletters_use_click_tracking' );
		$this->assertStringContainsString(
			'np_newsletters_click',
			$html,
			'Ad links must be proxied through the click endpoint for tracking.'
		);
	}

	// ------------------------------------------------------------------
	// Direct-ID guards: only an active ad of the ads CPT may render
	// ------------------------------------------------------------------

	/**
	 * An ad block pointing at a non-ad post renders nothing — prevents arbitrary post content leaking into email.
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
	 * An ad block pointing at an expired ad renders nothing — the direct-ID path runs the same is_ad_active() check.
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
	 * A self-referencing ad renders once — the render-stack cycle guard stops recursion without blanking the newsletter.
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
