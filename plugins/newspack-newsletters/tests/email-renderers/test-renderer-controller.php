<?php
/**
 * Class Renderer Controller Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Renderer Controller Test.
 */
class Test_Renderer_Controller extends WP_UnitTestCase {
	/**
	 * Unstamped posts resolve to the legacy MJML engine.
	 */
	public function test_unstamped_post_resolves_to_mjml() {
		$post_id = self::factory()->post->create();
		$this->assertSame( 'mjml', Renderer_Controller::get_post_renderer( $post_id ) );
	}

	/**
	 * A stamped post resolves to its stored engine value.
	 */
	public function test_stamped_post_resolves_to_stored_value() {
		$post_id = self::factory()->post->create();
		Renderer_Controller::stamp_renderer( $post_id, 'wc' );
		$this->assertSame( 'wc', Renderer_Controller::get_post_renderer( $post_id ) );
	}

	/**
	 * An unknown engine value is normalized to the legacy MJML engine.
	 */
	public function test_unknown_engine_normalizes_to_mjml() {
		$post_id = self::factory()->post->create();
		Renderer_Controller::stamp_renderer( $post_id, 'something-else' );
		$this->assertSame( 'mjml', Renderer_Controller::get_post_renderer( $post_id ) );
	}

	/**
	 * Create a newsletter CPT post carrying a single core paragraph block.
	 *
	 * @param string $body Paragraph body text.
	 * @return int Created post ID.
	 */
	private function create_newsletter_with_paragraph( $body ) {
		return self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Test newsletter',
				'post_content' => '<!-- wp:paragraph --><p>' . $body . '</p><!-- /wp:paragraph -->',
			]
		);
	}

	/**
	 * Produces email-safe HTML (at least one table) containing the post body text.
	 */
	public function test_render_wc_returns_html_with_content() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$post_id = $this->create_newsletter_with_paragraph( 'Hello from the WC engine' );
		$html    = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( 'Hello from the WC engine', $html );
		$this->assertStringContainsString( '<table', $html );
	}

	/**
	 * Applies the per-newsletter background color via get_rendering_post() rather than
	 * global $post, simulating the REST round-trip path where $post is never set.
	 */
	public function test_render_wc_applies_per_newsletter_background() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$post_id = $this->create_newsletter_with_paragraph( 'Colored newsletter' );
		update_post_meta( $post_id, 'background_color', '#123456' );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( '123456', $html );
	}

	/**
	 * The email render keeps `email`-only blocks and hides `web`-only blocks. The
	 * prebuilt layouts' "Support our newsroom" section is email-only and was being
	 * dropped because render_wc followed the web visibility path (NEWS-1901).
	 */
	public function test_render_wc_respects_newsletter_visibility() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:paragraph --><p>ALWAYS VISIBLE</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph {"newsletterVisibility":"email"} --><p>EMAIL ONLY BLOCK</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph {"newsletterVisibility":"web"} --><p>WEB ONLY BLOCK</p><!-- /wp:paragraph -->';
		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Visibility newsletter',
				'post_content' => $content,
				// Set an explicit preheader so the preview-text fallback (which trims a
				// text summary of ALL content, web-only blocks included, matching the MJML
				// path) doesn't put "WEB ONLY BLOCK" into the hidden preheader and muddy
				// the body-visibility assertion below.
				'meta_input'   => [ 'preview_text' => 'Deterministic preheader' ],
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( 'ALWAYS VISIBLE', $html, 'Expected an unmarked block to render in the email.' );
		$this->assertStringContainsString( 'EMAIL ONLY BLOCK', $html, 'Expected an email-only block to render in the email.' );
		$this->assertStringNotContainsString( 'WEB ONLY BLOCK', $html, 'Expected a web-only block to be hidden in the email.' );
	}

	/**
	 * The active engine follows the WC renderer feature flag.
	 */
	public function test_active_engine_follows_flag() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		$this->assertSame( 'mjml', Renderer_Controller::active_engine() );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );

		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$this->assertSame( 'wc', Renderer_Controller::active_engine() );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
	}

	/**
	 * Create a newsletter CPT post whose body contains a single external link.
	 *
	 * @param string $href Link URL.
	 * @return int Created post ID.
	 */
	private function create_newsletter_with_link( $href ) {
		return self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Link newsletter',
				'post_content' => '<!-- wp:paragraph --><p><a href="' . $href . '">click here</a></p><!-- /wp:paragraph -->',
			]
		);
	}

	/**
	 * Body links are rewritten through Newspack_Newsletters_Renderer::process_links(), so they
	 * carry UTM params in the sent HTML — parity with the MJML path (NEWS: link tracking gap).
	 */
	public function test_render_wc_appends_utm_to_body_links() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$post_id = $this->create_newsletter_with_link( 'https://example.com/story' );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( 'utm_medium=email', $html, 'Body links must be routed through process_links for UTM parity.' );
	}

	/**
	 * The newsletter's custom_css meta is injected into the rendered <head>, matching the
	 * MJML template which appends custom_css to its <style> block.
	 */
	public function test_render_wc_injects_custom_css() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$post_id = $this->create_newsletter_with_paragraph( 'Custom CSS newsletter' );
		update_post_meta( $post_id, 'custom_css', '.np-custom-marker{color:#abcabc}' );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( 'np-custom-marker', $html, 'custom_css meta must be injected into the email head.' );
	}

	/**
	 * With the tracking pixel enabled (the default), render_wc() appends the 1x1 open pixel —
	 * parity with the MJML path, which adds it via the newspack_newsletters_editor_mjml_body hook.
	 */
	public function test_render_wc_includes_tracking_pixel_when_enabled() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		update_option( 'newspack_newsletters_use_tracking_pixel', '1' );
		$post_id = $this->create_newsletter_with_paragraph( 'Pixel newsletter' );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( 'width="1" height="1"', $html, 'The open tracking pixel must be present when enabled.' );
	}

	/**
	 * With the tracking pixel disabled, render_wc() emits no open pixel.
	 */
	public function test_render_wc_omits_tracking_pixel_when_disabled() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		update_option( 'newspack_newsletters_use_tracking_pixel', '0' );
		$post_id = $this->create_newsletter_with_paragraph( 'No pixel newsletter' );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		delete_option( 'newspack_newsletters_use_tracking_pixel' );
		$this->assertStringNotContainsString( 'width="1" height="1"', $html, 'The open tracking pixel must be absent when disabled.' );
	}

	/**
	 * A block carrying conditionalBefore/conditionalAfter (ESP merge-tag guards for
	 * per-segment show/hide) is wrapped in those guards in the rendered email, matching
	 * the MJML path's <mj-raw> conditional wrapping. Without this, a segment-only block
	 * ships unguarded to the whole list (BLOCKER — irreversible sends).
	 */
	public function test_render_wc_wraps_conditional_content_blocks() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:group {"conditionalBefore":"*|IF_COND_BEFORE|*","conditionalAfter":"*|IF_COND_AFTER|*"} -->'
			. '<div class="wp-block-group"><!-- wp:paragraph --><p>GUARDED CONTENT</p><!-- /wp:paragraph --></div>'
			. '<!-- /wp:group -->';
		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Conditional newsletter',
				'post_content' => $content,
				// Explicit preheader so the preview-text fallback doesn't also place
				// "GUARDED CONTENT" in the hidden preheader and skew the position checks.
				'meta_input'   => [ 'preview_text' => 'Deterministic preheader' ],
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$before_pos  = strpos( $html, '*|IF_COND_BEFORE|*' );
		$content_pos = strpos( $html, 'GUARDED CONTENT' );
		$after_pos   = strpos( $html, '*|IF_COND_AFTER|*' );

		$this->assertNotFalse( $before_pos, 'The opening ESP conditional guard must be present.' );
		$this->assertNotFalse( $after_pos, 'The closing ESP conditional guard must be present.' );
		$this->assertLessThan( $content_pos, $before_pos, 'The opening guard must precede the guarded content.' );
		$this->assertGreaterThan( $content_pos, $after_pos, 'The closing guard must follow the guarded content.' );
	}

	/**
	 * A block with no conditional attributes is not wrapped — the guard only fires for
	 * blocks that actually carry conditionalBefore AND conditionalAfter.
	 */
	public function test_render_wc_does_not_wrap_unconditional_blocks() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$post_id = $this->create_newsletter_with_paragraph( 'Plain content' );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringNotContainsString( '*|IF', $html, 'Unconditional blocks must not gain merge-tag guards.' );
	}

	/**
	 * Returns an empty string for a non-WP_Post argument rather than fataling.
	 */
	public function test_render_wc_returns_empty_string_for_invalid_post() {
		$this->assertSame( '', Renderer_Controller::render_wc( null ) );
	}

	/**
	 * A failure inside the package renderer is swallowed: render_wc() returns '' and clears the
	 * render post in finally, even when the renderer throws.
	 */
	public function test_render_wc_returns_empty_string_when_renderer_throws() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$post_id = $this->create_newsletter_with_paragraph( 'Boom' );

		$thrower = function () {
			throw new \RuntimeException( 'forced render failure' );
		};
		add_filter( 'woocommerce_email_editor_theme_json', $thrower, 99 );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		remove_filter( 'woocommerce_email_editor_theme_json', $thrower, 99 );

		$this->assertSame( '', $html );
		$this->assertNull( Renderer_Controller::get_rendering_post(), 'render post is cleared even when rendering throws' );
	}

	/**
	 * Create a newsletter CPT post with arbitrary block markup.
	 *
	 * @param string $content Serialized block markup.
	 * @return int Created post ID.
	 */
	private function create_newsletter( $content ) {
		return self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Full bleed newsletter',
				'post_content' => wp_slash( $content ),
			]
		);
	}

	/**
	 * Count full-width band tables (bgcolor + width:100%) that sit at body level —
	 * i.e. outside the content-width `email_layout_wrapper`.
	 *
	 * @param string $html Email HTML.
	 * @return int Number of body-level full-width band tables.
	 */
	private function count_body_level_bands( $html ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$bands = $xpath->query( "//table[@bgcolor and contains(@style, 'width:100%')]" );
		$count = 0;
		foreach ( $bands as $band ) {
			if ( 0 === $xpath->query( "ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' email_layout_wrapper ')]", $band )->length ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Count `email-block-cover` tables that sit at body level — i.e. outside the
	 * content-width `email_layout_wrapper` — the signature of a bled full-width cover.
	 *
	 * @param string $html Email HTML.
	 * @return int Number of body-level cover tables.
	 */
	private function count_body_level_covers( $html ) {
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xpath  = new DOMXPath( $dom );
		$covers = $xpath->query( "//table[contains(concat(' ', normalize-space(@class), ' '), ' email-block-cover ')]" );
		$count  = 0;
		foreach ( $covers as $cover ) {
			if ( 0 === $xpath->query( "ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' email_layout_wrapper ')]", $cover )->length ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Read the inline style of the inner container inside a body-level (bled) cover,
	 * so tests can assert its content is capped to the content width.
	 *
	 * @param string $html Email HTML.
	 * @return string The inner-container style, or '' if no body-level cover.
	 */
	private function bled_cover_inner_style( $html ) {
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xpath = new DOMXPath( $dom );
		$inner = $xpath->query(
			"//table[contains(concat(' ', normalize-space(@class), ' '), ' email-block-cover ')]"
			. "[not(ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' email_layout_wrapper ')])]"
			. "//div[contains(concat(' ', normalize-space(@class), ' '), ' wp-block-cover__inner-container ')]"
		)->item( 0 );
		return $inner instanceof DOMElement ? $inner->getAttribute( 'style' ) : '';
	}

	/**
	 * A top-level alignfull group with a background bleeds to a body-level full-width
	 * band, while its content survives (NEWS-1901 default-layout full-bleed).
	 */
	public function test_render_wc_bleeds_alignfull_background_section() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
			. '<!-- wp:group {"align":"full","style":{"color":{"background":"#ffcc00","text":"#000000"}}} -->'
			. '<div class="wp-block-group alignfull has-text-color has-background" style="background-color:#ffcc00;color:#000000">'
			. '<!-- wp:paragraph --><p>Banded content</p><!-- /wp:paragraph --></div>'
			. '<!-- /wp:group -->'
			. '<!-- wp:paragraph --><p>Outro</p><!-- /wp:paragraph -->';

		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter( $content ) ) );

		$this->assertSame( 1, $this->count_body_level_bands( $html ), 'Expected one body-level full-width band for the alignfull background group.' );
		$this->assertStringContainsString( 'Banded content', $html, 'Expected the band content to survive the transform.' );
		$this->assertStringContainsString( 'Intro', $html, 'Expected surrounding content to survive.' );
		$this->assertStringContainsString( 'Outro', $html );
	}

	/**
	 * A conditional guard on an alignfull background group survives the full-bleed
	 * transform — the DOM surgery that hoists the band must not drop the ESP merge tags,
	 * or a segment-only full-bleed section would ship unguarded (the blocker scenario).
	 */
	public function test_render_wc_wraps_conditional_alignfull_background_section() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:group {"align":"full","conditionalBefore":"*|IF_BAND|*","conditionalAfter":"*|END_BAND|*","style":{"color":{"background":"#ffcc00","text":"#000000"}}} -->'
			. '<div class="wp-block-group alignfull has-text-color has-background" style="background-color:#ffcc00;color:#000000">'
			. '<!-- wp:paragraph --><p>Banded guarded content</p><!-- /wp:paragraph --></div>'
			. '<!-- /wp:group -->';
		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Conditional band newsletter',
				'post_content' => $content,
				'meta_input'   => [ 'preview_text' => 'Deterministic preheader' ],
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$before_pos  = strpos( $html, '*|IF_BAND|*' );
		$content_pos = strpos( $html, 'Banded guarded content' );
		$after_pos   = strpos( $html, '*|END_BAND|*' );
		$this->assertNotFalse( $before_pos, 'The opening guard must survive the full-bleed transform.' );
		$this->assertNotFalse( $after_pos, 'The closing guard must survive the full-bleed transform.' );
		$this->assertLessThan( $content_pos, $before_pos, 'The opening guard must precede the banded content.' );
		$this->assertGreaterThan( $content_pos, $after_pos, 'The closing guard must follow the banded content.' );
	}

	/**
	 * An alignfull group with NO background is left in the content-width wrapper — only
	 * background sections bleed.
	 */
	public function test_render_wc_does_not_bleed_alignfull_without_background() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:group {"align":"full"} -->'
			. '<div class="wp-block-group alignfull"><!-- wp:paragraph --><p>No background here</p><!-- /wp:paragraph --></div>'
			. '<!-- /wp:group -->';

		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter( $content ) ) );

		$this->assertSame( 0, $this->count_body_level_bands( $html ), 'A backgroundless alignfull group must not be hoisted to a full-width band.' );
		$this->assertStringContainsString( 'No background here', $html );
	}

	/**
	 * A newsletter without any alignfull background section renders unchanged: no
	 * body-level band, and the single content-width wrapper is preserved.
	 */
	public function test_render_wc_normal_newsletter_has_no_bands() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter_with_paragraph( 'Just a paragraph' ) ) );

		$this->assertSame( 0, $this->count_body_level_bands( $html ) );
		$this->assertStringContainsString( 'Just a paragraph', $html );
	}

	/**
	 * A top-level alignfull cover bleeds to a body-level full-width section (its
	 * background image spans the email), while its content survives.
	 */
	public function test_render_wc_bleeds_alignfull_cover() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
			. '<!-- wp:cover {"url":"https://example.com/bg.jpg","align":"full","minHeight":240} -->'
			. '<div class="wp-block-cover alignfull" style="min-height:240px">'
			. '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
			. '<img class="wp-block-cover__image-background" src="https://example.com/bg.jpg" data-object-fit="cover"/>'
			. '<div class="wp-block-cover__inner-container">'
			. '<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Hero heading</h2><!-- /wp:heading -->'
			. '</div></div>'
			. '<!-- /wp:cover -->'
			. '<!-- wp:paragraph --><p>Outro</p><!-- /wp:paragraph -->';

		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter( $content ) ) );

		$this->assertSame( 1, $this->count_body_level_covers( $html ), 'An alignfull cover must bleed to a body-level full-width section.' );
		$this->assertStringContainsString( 'Hero heading', $html, 'Cover content must survive the transform.' );
		$this->assertStringContainsString( 'Intro', $html, 'Surrounding content must survive.' );
		$this->assertStringContainsString( 'Outro', $html );

		// The bled cover's content must be capped to the content width with the same
		// border-box + gutter as normal blocks, so the overlay text lines up with them.
		$inner_style = $this->bled_cover_inner_style( $html );
		$this->assertStringContainsString( 'box-sizing:border-box', $inner_style, 'Bled cover content must use border-box so its width matches normal blocks.' );
		$this->assertStringContainsString( 'max-width:' . \Newspack\Newsletters\Email_Renderers\Full_Bleed_Sections::CONTENT_WIDTH . 'px', $inner_style, 'Bled cover content must cap at the content width.' );
		$this->assertMatchesRegularExpression( '/padding-left:\s*\d+px/', $inner_style, 'Bled cover content must carry the horizontal gutter.' );
		// Outlook ignores max-width, so the inner content is pinned via an MSO ghost table.
		$this->assertStringContainsString( 'width="' . \Newspack\Newsletters\Email_Renderers\Full_Bleed_Sections::CONTENT_WIDTH . '"', $html, 'Bled cover must pin its inner content for Outlook via an MSO ghost table.' );
	}

	/**
	 * A cover nested inside another block (Group/Columns) must NOT be hoisted — only
	 * top-level covers bleed. Mirrors the nested-background-group guard.
	 */
	public function test_render_wc_does_not_bleed_nested_cover() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:cover {"url":"https://example.com/bg.jpg","align":"full","minHeight":200} -->'
			. '<div class="wp-block-cover alignfull" style="min-height:200px">'
			. '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
			. '<img class="wp-block-cover__image-background" src="https://example.com/bg.jpg"/>'
			. '<div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Nested cover</p><!-- /wp:paragraph --></div></div>'
			. '<!-- /wp:cover -->'
			. '</div><!-- /wp:group -->';

		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter( $content ) ) );

		$this->assertSame( 0, $this->count_body_level_covers( $html ), 'A cover nested inside a group must not be hoisted wholesale.' );
		$this->assertStringContainsString( 'Nested cover', $html );
	}

	/**
	 * A cover without full alignment stays inside the content-width wrapper — only
	 * alignfull covers bleed.
	 */
	public function test_render_wc_does_not_bleed_default_cover() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:cover {"url":"https://example.com/bg.jpg","minHeight":240} -->'
			. '<div class="wp-block-cover" style="min-height:240px">'
			. '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
			. '<img class="wp-block-cover__image-background" src="https://example.com/bg.jpg"/>'
			. '<div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Constrained cover</p><!-- /wp:paragraph --></div></div>'
			. '<!-- /wp:cover -->';

		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter( $content ) ) );

		$this->assertSame( 0, $this->count_body_level_covers( $html ), 'A non-aligned cover must stay inside the content-width wrapper.' );
		$this->assertStringContainsString( 'Constrained cover', $html );
	}

	/**
	 * An alignfull background group nested inside a Columns block is NOT hoisted
	 * wholesale — only a top-level group's own background bleeds.
	 */
	public function test_render_wc_does_not_hoist_alignfull_nested_in_columns() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:group {"align":"full","style":{"color":{"background":"#ffcc00"}}} -->'
			. '<div class="wp-block-group alignfull has-background" style="background-color:#ffcc00">'
			. '<!-- wp:paragraph --><p>Nested banded</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
			. '</div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Side</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter( $content ) ) );

		$this->assertSame( 0, $this->count_body_level_bands( $html ), 'A nested alignfull group must not hoist its containing columns block.' );
		$this->assertStringContainsString( 'Nested banded', $html );
		$this->assertStringContainsString( 'Side', $html );
	}

	/**
	 * Normal blocks in a banded email keep their per-block MSO ghost-table scaffolding —
	 * the Outlook side gutter / spacing lives in those conditional comments, not CSS, so
	 * the rebuild must carry the comment siblings across, not just the block elements.
	 */
	public function test_render_wc_banded_email_preserves_normal_block_mso_scaffolding() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
			. '<!-- wp:group {"align":"full","style":{"color":{"background":"#ffcc00"}}} -->'
			. '<div class="wp-block-group alignfull has-background" style="background-color:#ffcc00">'
			. '<!-- wp:paragraph --><p>Banded</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
			. '<!-- wp:paragraph --><p>Outro</p><!-- /wp:paragraph -->';

		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter( $content ) ) );

		$this->assertSame( 1, $this->count_body_level_bands( $html ), 'Sanity: the band should still bleed.' );
		$this->assertMatchesRegularExpression(
			'/<!--\[if mso \| IE\]>(?:(?!<!\[endif\]).)*?padding-left:\s*24px/is',
			$html,
			'Normal blocks must retain their MSO ghost-table side gutter in a banded email.'
		);
	}

	/**
	 * When the first top-level block is a band (e.g. a full-bleed site-title header), the
	 * hidden preheader is still emitted before it so it wins the inbox preview slot.
	 */
	public function test_render_wc_preheader_precedes_a_leading_band() {
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		$content = '<!-- wp:group {"align":"full","style":{"color":{"background":"#ffcc00"}}} -->'
			. '<div class="wp-block-group alignfull has-background" style="background-color:#ffcc00">'
			. '<!-- wp:paragraph --><p>Header</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';

		$html      = Renderer_Controller::render_wc( get_post( $this->create_newsletter( $content ) ) );
		$preheader = strpos( $html, 'email_preheader' );
		$band      = strpos( $html, 'bgcolor=' );

		$this->assertNotFalse( $preheader );
		$this->assertNotFalse( $band );
		$this->assertLessThan( $band, $preheader, 'The preheader must appear before a leading full-bleed band.' );
	}

	/**
	 * The transform fast-bails (returns input untouched) when the markup has no
	 * alignfull background section.
	 */
	public function test_full_bleed_transform_is_noop_without_markers() {
		$html = '<html><body><table><tr><td><p>nothing to bleed</p></td></tr></table></body></html>';
		$this->assertSame(
			$html,
			\Newspack\Newsletters\Email_Renderers\Full_Bleed_Sections::transform( $html )
		);
	}
}
