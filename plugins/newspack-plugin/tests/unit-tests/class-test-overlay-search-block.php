<?php
/**
 * Tests for Overlay Search block.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\Overlay_Search\Overlay_Search_Block
 */

use Newspack\Blocks\Overlay_Search\Overlay_Search_Block;

require_once NEWSPACK_ABSPATH . 'tests/mocks/jetpack-mock.php';

/**
 * Test class for the Overlay Search Block.
 *
 * @group overlay-search-block
 */
class Newspack_Test_Overlay_Search_Block extends WP_UnitTestCase {

	const BLOCK_NAME = 'newspack/overlay-search';

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once NEWSPACK_ABSPATH . 'src/blocks/overlay-search/class-overlay-search-block.php';

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			Overlay_Search_Block::register_block();
		}

		// Default: Jetpack handoff disabled. Individual tests opt in.
		Jetpack::$test_active_modules = [];
		delete_option( 'instant_search_enabled' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		Jetpack::$test_active_modules = [];
		delete_option( 'instant_search_enabled' );

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			unregister_block_type( self::BLOCK_NAME );
		}

		parent::tear_down();
	}

	/**
	 * Render the block via `do_blocks` so the full registration path is exercised.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render( array $attributes = [] ): string {
		$json = empty( $attributes ) ? '' : ' ' . wp_json_encode( $attributes );
		return do_blocks( '<!-- wp:' . self::BLOCK_NAME . $json . ' /-->' );
	}

	/**
	 * Enable the Jetpack Instant Search handoff for the next render.
	 */
	private function enable_jetpack_instant_search(): void {
		Jetpack::$test_active_modules = [ 'search' ];
		update_option( 'instant_search_enabled', 1 );
	}

	/**
	 * Default render emits a button trigger and a panel with an embedded search form.
	 */
	public function test_default_render() {
		$output = $this->render();

		$this->assertStringContainsString( '<button', $output );
		$this->assertStringContainsString( 'newspack-overlay-search__trigger', $output );
		$this->assertStringContainsString( 'newspack-overlay-search__panel', $output );
		$this->assertStringContainsString( 'role="dialog"', $output );
		$this->assertStringContainsString( 'aria-modal="true"', $output );
		$this->assertStringContainsString( 'aria-hidden="true"', $output );
		// Panel starts inert and unfocusable; JS toggles it open.
		$this->assertMatchesRegularExpression( '/<div[^>]*\sinert(\s|>)/', $output );
		// core/search form is rendered inside the panel.
		$this->assertStringContainsString( 'wp-block-search', $output );
		// Visible trigger label defaults to "Search".
		$this->assertMatchesRegularExpression( '/newspack-overlay-search__label[^"]*">\s*Search\s*</', $output );
	}

	/**
	 * Trigger and panel reference each other via matching aria-controls / id.
	 */
	public function test_trigger_aria_controls_matches_panel_id() {
		$output = $this->render();

		preg_match( '/aria-controls="([^"]+)"/', $output, $controls );
		preg_match( '/id="(newspack-overlay-search-panel-[^"]+)"/', $output, $panel_id );

		$this->assertNotEmpty( $controls[1] ?? '' );
		$this->assertNotEmpty( $panel_id[1] ?? '' );
		$this->assertSame( $controls[1], $panel_id[1] );
	}

	/**
	 * `wp_unique_id()` produces a fresh id for each render so multiple blocks on
	 * a page don't collide.
	 */
	public function test_panel_ids_are_unique_across_renders() {
		$first  = $this->render();
		$second = $this->render();

		preg_match( '/id="(newspack-overlay-search-panel-[^"]+)"/', $first, $first_id );
		preg_match( '/id="(newspack-overlay-search-panel-[^"]+)"/', $second, $second_id );

		$this->assertNotEmpty( $first_id[1] ?? '' );
		$this->assertNotEmpty( $second_id[1] ?? '' );
		$this->assertNotSame( $first_id[1], $second_id[1] );
	}

	/**
	 * Custom `triggerText` reaches the visible label.
	 */
	public function test_custom_trigger_text() {
		$output = $this->render( [ 'triggerText' => 'Find articles' ] );

		$this->assertMatchesRegularExpression( '/newspack-overlay-search__label[^"]*">\s*Find articles\s*</', $output );
	}

	/**
	 * Whitespace-only `triggerText` falls back to the default label.
	 */
	public function test_whitespace_trigger_text_falls_back_to_default() {
		$output = $this->render( [ 'triggerText' => '   ' ] );

		$this->assertMatchesRegularExpression( '/newspack-overlay-search__label[^"]*">\s*Search\s*</', $output );
	}

	/**
	 * `is-style-icon-only` keeps the icon visible and hides the label via
	 * `screen-reader-text`.
	 */
	public function test_icon_only_style() {
		$output = $this->render( [ 'className' => 'is-style-icon-only' ] );

		$this->assertStringContainsString( 'newspack-overlay-search__icon', $output );
		$this->assertStringContainsString( 'newspack-overlay-search__label screen-reader-text', $output );
	}

	/**
	 * `is-style-text-only` omits the icon and shows just the label.
	 */
	public function test_text_only_style() {
		$output = $this->render( [ 'className' => 'is-style-text-only' ] );

		// The trigger button has no icon span. The panel's close button still
		// uses `newspack-overlay-search__icon`, so a global "not contains" check
		// would be wrong.
		$this->assertDoesNotMatchRegularExpression(
			'/<button[^>]*newspack-overlay-search__trigger[^>]*>\s*<span[^>]*newspack-overlay-search__icon/',
			$output
		);
		$this->assertStringContainsString( 'newspack-overlay-search__label', $output );
	}

	/**
	 * The trigger has no `aria-label` — the visible label (or screen-reader
	 * text) already names the button. A duplicated `aria-label` would cause
	 * screen readers to double-announce.
	 */
	public function test_trigger_has_no_aria_label() {
		// Scope to the trigger button — `core/search`'s rendered submit button
		// carries its own `aria-label`, which is fine and not what we're checking.
		$pattern = '/<button[^>]*newspack-overlay-search__trigger[^>]*\saria-label=/';

		$this->assertDoesNotMatchRegularExpression( $pattern, $this->render() );
		$this->assertDoesNotMatchRegularExpression( $pattern, $this->render( [ 'className' => 'is-style-icon-only' ] ) );
	}

	/**
	 * A valid `overlayColor` reaches the panel as an inline `background` style.
	 */
	public function test_overlay_color_renders_inline_style() {
		$output = $this->render( [ 'overlayColor' => '#123456' ] );

		$this->assertMatchesRegularExpression( '/<div[^>]*class="newspack-overlay-search__panel"[^>]*style="background:\s*#123456;?\s*"/', $output );
	}

	/**
	 * An empty `overlayColor` produces no inline style attribute on the panel.
	 */
	public function test_empty_overlay_color_omits_style_attribute() {
		$output = $this->render( [ 'overlayColor' => '' ] );

		$this->assertDoesNotMatchRegularExpression( '/<div[^>]*class="newspack-overlay-search__panel"[^>]*\sstyle=/', $output );
	}

	/**
	 * `safecss_filter_attr` strips a CSS-injection attempt smuggled in
	 * alongside the color value.
	 */
	public function test_overlay_color_strips_css_injection() {
		$output = $this->render(
			[
				'overlayColor' => 'red; background-image: url(javascript:alert(1))',
			]
		);

		$this->assertStringNotContainsString( 'javascript:', $output );
		$this->assertStringNotContainsString( 'background-image', $output );
	}

	/**
	 * Jetpack-enabled render emits the `jetpack-search-filter__link` anchor
	 * instead of the button + panel.
	 */
	public function test_jetpack_branch_emits_anchor_without_panel() {
		$this->enable_jetpack_instant_search();

		$output = $this->render();

		$this->assertStringContainsString( 'jetpack-search-filter__link', $output );
		$this->assertStringContainsString( '<a ', $output );
		$this->assertStringContainsString( 'href=', $output );
		// No panel, no button trigger.
		$this->assertStringNotContainsString( 'newspack-overlay-search__panel', $output );
		$this->assertStringNotContainsString( '<button', $output );
	}

	/**
	 * The Jetpack anchor links to the site's search query URL so Jetpack's
	 * frontend script has a meaningful fallback if it fails to hijack the click.
	 */
	public function test_jetpack_anchor_links_to_search_query() {
		$this->enable_jetpack_instant_search();

		$output = $this->render();

		preg_match( '/<a[^>]*href="([^"]+)"/', $output, $href );

		$this->assertNotEmpty( $href[1] ?? '' );
		// `add_query_arg( 's', '', ... )` produces `?s` (no trailing `=`) on some
		// WP builds; either form proves the search query is on the URL.
		$this->assertMatchesRegularExpression( '/[?&]s(=|$)/', $href[1] );
	}

	/**
	 * If the option is set but the Jetpack search module is inactive, the
	 * default (non-Jetpack) renderer runs.
	 */
	public function test_jetpack_option_without_active_module_falls_back() {
		Jetpack::$test_active_modules = [];
		update_option( 'instant_search_enabled', 1 );

		$output = $this->render();

		$this->assertStringNotContainsString( 'jetpack-search-filter__link', $output );
		$this->assertStringContainsString( 'newspack-overlay-search__panel', $output );
	}

	/**
	 * If Jetpack's module is active but the instant-search option is off, the
	 * default renderer runs (Jetpack module alone doesn't imply instant search).
	 */
	public function test_jetpack_module_without_option_falls_back() {
		Jetpack::$test_active_modules = [ 'search' ];
		delete_option( 'instant_search_enabled' );

		$output = $this->render();

		$this->assertStringNotContainsString( 'jetpack-search-filter__link', $output );
		$this->assertStringContainsString( 'newspack-overlay-search__panel', $output );
	}
}
