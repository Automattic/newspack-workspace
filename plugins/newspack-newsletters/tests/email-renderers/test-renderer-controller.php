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
}
