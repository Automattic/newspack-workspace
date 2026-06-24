<?php
/**
 * Class Block Renderer Overrides Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry;
use Newspack\Newsletters\Email_Renderers\Blocks\Column;
use Newspack\Newsletters\Email_Renderers\Blocks\Quote;
use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Column as Package_Column;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Quote as Package_Quote;

/**
 * Block Renderer Overrides Test.
 *
 * Covers the override harness that swaps the package's per-block
 * `render_email_callback` for Newspack's renderers, plus the columns
 * percentage-width fix that the override restores.
 */
class Test_Block_Renderer_Overrides extends WP_UnitTestCase {
	/**
	 * Run override discovery so the self-registering renderers are mapped.
	 */
	public function set_up() {
		parent::set_up();
		Block_Renderer_Registry::init();
	}

	/**
	 * The width helper restores a percentage width the package stripped to px.
	 *
	 * The package's Column renderer runs `Styles_Helper::parse_value( '70%' )`,
	 * which strips the `%` and emits `width="70"` (= 70px). The helper restores
	 * the percent so the wrapper cell reads `width="70%"` again.
	 */
	public function test_width_helper_restores_percent() {
		$html   = '<td class="x" width="70"><table width="100%"></table></td>';
		$result = Column::preserve_percentage_width( $html, '70%' );
		$this->assertStringContainsString( 'width="70%"', $result, 'Expected the percentage width to be restored on the wrapper cell.' );
		$this->assertStringNotContainsString( 'width="70"', str_replace( 'width="70%"', '', $result ), 'Expected no bare width="70" to remain once the percent is restored.' );
	}

	/**
	 * The width helper leaves non-percentage widths untouched.
	 *
	 * A pixel width never lost information to `parse_value`, so the helper must
	 * be a no-op and return the HTML byte-for-byte.
	 */
	public function test_width_helper_ignores_non_percent() {
		$html = '<td class="x" width="200"><table width="100%"></table></td>';
		$this->assertSame( $html, Column::preserve_percentage_width( $html, '200px' ), 'Expected a non-percentage width to return the HTML unchanged.' );
	}

	/**
	 * The width helper leaves an empty width untouched.
	 *
	 * With no width attribute the package falls back to the layout width, so
	 * there is nothing to restore and the HTML must pass through unchanged.
	 */
	public function test_width_helper_ignores_empty_width() {
		$html = '<td class="x" width="600"><table width="100%"></table></td>';
		$this->assertSame( $html, Column::preserve_percentage_width( $html, '' ), 'Expected an empty width to return the HTML unchanged.' );
	}

	/**
	 * The width helper restores a decimal percentage on the wrapper cell.
	 *
	 * The package emits `parse_value( '33.33%' )` = `33.33`, so the wrapper cell
	 * reads `width="33.33"`. The helper must target that canonical numeric and
	 * restore the percent to `width="33.33%"`.
	 */
	public function test_width_helper_restores_decimal_percent() {
		$html   = '<td class="x" width="33.33"><table width="100%"></table></td>';
		$result = Column::preserve_percentage_width( $html, '33.33%' );
		$this->assertStringContainsString( 'width="33.33%"', $result, 'Expected the decimal percentage width to be restored.' );
		$this->assertStringNotContainsString( 'width="33.33"', str_replace( 'width="33.33%"', '', $result ), 'Expected no bare width="33.33" to remain.' );
	}

	/**
	 * The width helper normalizes a trailing-zero percentage to the package value.
	 *
	 * A `30.0%` attribute is emitted by the package as `parse_value( '30.0%' )` =
	 * `30`, i.e. `width="30"`. The helper must compute the same canonical numeric
	 * and restore the percent on `width="30"` (the value the package actually
	 * emitted), not look for a literal `width="30.0"` that never exists.
	 */
	public function test_width_helper_normalizes_trailing_zero_percent() {
		$html   = '<td class="x" width="30"><table width="100%"></table></td>';
		$result = Column::preserve_percentage_width( $html, '30.0%' );
		$this->assertStringContainsString( 'width="30%"', $result, 'Expected the normalized percentage width to be restored on width="30".' );
		$this->assertStringNotContainsString( 'width="30"', str_replace( 'width="30%"', '', $result ), 'Expected no bare width="30" to remain.' );
	}

	/**
	 * The width helper only rewrites the first (wrapper cell) width occurrence.
	 *
	 * The wrapper `<td>` is the only cell carrying the column's numeric width and
	 * it is the first cell in the column output; any later identical numeric width
	 * must be left untouched so unrelated cells are never rewritten.
	 */
	public function test_width_helper_restores_first_occurrence_only() {
		$html   = '<td width="70"></td><td width="70"></td>';
		$result = Column::preserve_percentage_width( $html, '70%' );
		$this->assertSame( '<td width="70%"></td><td width="70"></td>', $result, 'Expected only the first width="70" to be rewritten.' );
	}

	/**
	 * The registry swaps the render callback for a mapped block.
	 *
	 * For `core/column` the registry must set a callable `render_email_callback`
	 * bound to the Newspack Column renderer instance.
	 */
	public function test_registry_overrides_mapped_block() {
		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'core/column' ] );
		$this->assertArrayHasKey( 'render_email_callback', $settings, 'Expected a render_email_callback to be set for the mapped block.' );
		$this->assertIsCallable( $settings['render_email_callback'], 'The render_email_callback should be callable.' );
		$this->assertInstanceOf( Column::class, $settings['render_email_callback'][0], 'The callback should be bound to the Newspack Column renderer.' );
	}

	/**
	 * Registering via add() maps any block name (no hardcoded list).
	 *
	 * Overrides self-register by calling add() at the bottom of their file, so the
	 * registry must map whatever block name is passed to a lazily-instantiated
	 * renderer of the given class — proving registration is data-driven, not a
	 * hardcoded list.
	 */
	public function test_add_registers_an_arbitrary_block_override() {
		Block_Renderer_Registry::add( 'test/dummy', Column::class );

		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'test/dummy' ] );

		$this->assertInstanceOf( Column::class, $settings['render_email_callback'][0], 'add() should register an override for any block name.' );
	}

	/**
	 * Glob discovery loads override files so they self-register.
	 *
	 * This is the only test that exercises the headline glob discovery in
	 * isolation. The fixture renderer lives in a non-autoloaded `fixtures/` dir
	 * and is never referenced by name, so the sole path to mapping
	 * `test/fixture-block` is discover() globbing the directory and requiring the
	 * file (which self-registers at its bottom). Delete the glob loop and this
	 * fails — unlike the other registry tests, where classmap autoloading of
	 * Blocks\Column would mask a broken glob.
	 */
	public function test_discover_registers_overrides_via_glob() {
		Block_Renderer_Registry::discover( __DIR__ . '/fixtures/block-renderers' );

		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'test/fixture-block' ] );

		$this->assertArrayHasKey( 'render_email_callback', $settings, 'Expected discover() to have loaded the fixture file and registered its block.' );
		$this->assertIsCallable( $settings['render_email_callback'], 'The discovered override should map to a callable render callback.' );
	}

	/**
	 * A non-renderer override class fails closed (no callback, no fatal).
	 *
	 * The instantiation guard requires the class to be a package block-renderer
	 * subclass. A class that exists but isn't one (here stdClass) must be skipped,
	 * leaving the package callback in place rather than binding a bad renderer.
	 */
	public function test_non_renderer_override_fails_closed() {
		Block_Renderer_Registry::add( 'test/not-a-renderer', \stdClass::class );

		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'test/not-a-renderer' ] );

		$this->assertArrayNotHasKey( 'render_email_callback', $settings, 'A non-renderer class must not be bound as a render callback.' );
	}

	/**
	 * An override whose constructor throws fails closed (no callback, no fatal).
	 *
	 * The is_subclass_of() guard can't catch an instantiable subclass that throws
	 * (or needs constructor args), so the registry wraps `new` in a try/catch. The throwing
	 * fixture is a valid subclass, so it clears the type guard and exercises that
	 * catch — registration must survive without a render callback.
	 */
	public function test_uninstantiable_override_fails_closed() {
		require_once __DIR__ . '/fixtures/class-throwing-block-renderer.php';
		Block_Renderer_Registry::add( 'test/throws', \Newspack\Newsletters\Email_Renderers\Blocks\Throwing_Block_Renderer::class );

		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'test/throws' ] );

		$this->assertArrayNotHasKey( 'render_email_callback', $settings, 'A renderer that throws on construction must not be bound, and must not fatal.' );
	}

	/**
	 * The registry leaves an unmapped block untouched.
	 *
	 * A block with no override (e.g. core/paragraph) must pass through with no
	 * `render_email_callback` injected.
	 */
	public function test_registry_leaves_unmapped_block_untouched() {
		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'core/paragraph' ] );
		$this->assertArrayNotHasKey( 'render_email_callback', $settings, 'Expected no render_email_callback to be added for an unmapped block.' );
	}

	/**
	 * The Newspack Column renderer extends the package Column renderer.
	 *
	 * This is the structural lock for the double-wrapper regression: the package
	 * Column overrides `add_spacer()` to a no-op because columns render side by
	 * side. The override MUST inherit that behavior — extending the abstract base
	 * instead re-applies the abstract `add_spacer()` (an extra
	 * `email-block-layout` wrapper) around each already-wrapped column.
	 */
	public function test_column_renderer_extends_package_column() {
		$this->assertTrue(
			is_subclass_of( Column::class, Package_Column::class ),
			'The Newspack Column renderer must extend the package Column so it inherits the no-op add_spacer().'
		);
	}

	/**
	 * A real two-column render restores both percentages with no double-wrapper.
	 *
	 * Renders a real `core/columns` with 70% / 30% columns through the WC
	 * pipeline (Renderer_Controller::render_wc) and asserts both that the
	 * percentage widths survive (no bare px) and that no column `<td>` is wrapped
	 * in an extra `<div class="email-block-layout">` — the f1 double-wrapper.
	 *
	 * Before the f1 fix each column cell is spacer-wrapped (one
	 * div.email-block-layout immediately wrapping the column td per column); after
	 * the fix the package's no-op add_spacer() is inherited and the wrappers are
	 * gone.
	 */
	public function test_two_column_render_preserves_percentages_without_double_wrapper() {
		Editor_Bootstrap::init();

		$content = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column {"width":"70%"} --><div class="wp-block-column"><!-- wp:paragraph --><p>Left at 70</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '<!-- wp:column {"width":"30%"} --><div class="wp-block-column"><!-- wp:paragraph --><p>Right at 30</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Two column newsletter',
				'post_content' => $content,
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		// Percentages survive; no bare-pixel widths leak through.
		$this->assertStringContainsString( 'width="70%"', $html, 'Expected the 70% column width to be preserved.' );
		$this->assertStringContainsString( 'width="30%"', $html, 'Expected the 30% column width to be preserved.' );
		$this->assertStringNotContainsString( 'width="70"', str_replace( 'width="70%"', '', $html ), 'Expected no bare width="70" pixel width to remain.' );
		$this->assertStringNotContainsString( 'width="30"', str_replace( 'width="30%"', '', $html ), 'Expected no bare width="30" pixel width to remain.' );

		// No per-column double-wrapper: a div.email-block-layout must never wrap a column td.
		$double_wrappers = preg_match_all( '/<div class="email-block-layout"[^>]*>\s*<td class="block wp-block-column/', $html );
		$this->assertSame( 0, $double_wrappers, 'Expected no column <td> to be wrapped in an extra div.email-block-layout (the f1 double-wrapper).' );
	}

	/**
	 * The Newspack Quote renderer extends the package Quote renderer.
	 *
	 * The override must extend the package class (not just the abstract base) so
	 * all the package's quote layout, border, and wrapper logic is inherited
	 * unchanged. The cite parity fix is applied via theme.json filter, not
	 * via render_content(), so the class is a structural shim that satisfies
	 * the registry type-guard.
	 */
	public function test_quote_renderer_extends_package_quote() {
		$this->assertTrue(
			is_subclass_of( Quote::class, Package_Quote::class ),
			'The Newspack Quote renderer must extend the package Quote so all layout logic is inherited.'
		);
	}

	/**
	 * A rendered quote cite does NOT carry font-style: italic.
	 *
	 * The package vendor theme.json declares `fontStyle: italic` for the cite
	 * element inside core/quote. The editor canvas renders the cite upright
	 * (font-style: normal), so the email must match. This test renders a
	 * quote-with-cite through the real WC pipeline and confirms the cite's
	 * inline style no longer contains font-style: italic while still carrying the
	 * other citation styles (font-size, font-weight) that the package provides.
	 */
	public function test_quote_cite_is_not_italic() {
		Editor_Bootstrap::init();

		$content = '<!-- wp:quote --><blockquote class="wp-block-quote">'
			. '<!-- wp:paragraph --><p>Quoted text here.</p><!-- /wp:paragraph -->'
			. '<cite>A. Reporter</cite></blockquote><!-- /wp:quote -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Quote cite parity test',
				'post_content' => $content,
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		// The cite must be present with the citation class.
		$this->assertStringContainsString( 'email-block-quote-citation', $html, 'Expected the citation wrapper class to be present.' );
		$this->assertStringContainsString( 'A. Reporter', $html, 'Expected the citation text to survive.' );

		// Extract the cite element's style and assert it does NOT contain italic.
		preg_match( '/<cite class="email-block-quote-citation"[^>]*style="([^"]*)"/', $html, $matches );
		$cite_style = $matches[1] ?? '';
		$this->assertNotEmpty( $cite_style, 'Expected the cite to have an inline style attribute.' );
		$this->assertStringNotContainsString( 'font-style: italic', $cite_style, 'Expected the cite NOT to carry font-style: italic (editor renders it upright).' );
		$this->assertStringContainsString( 'font-style: normal', $cite_style, 'Expected the cite to carry font-style: normal to match the editor canvas.' );

		// Other package-provided cite styles must still be present.
		$this->assertStringContainsString( 'font-size: 13px', $cite_style, 'Expected the package font-size to be preserved.' );

		// The quote table must carry the Newspack 2px left bar (not the package 1px).
		$this->assertStringContainsString( 'border-width: 0 0 0 2px', $html, 'Expected the quote to carry a 2px left border to match the post editor.' );
		$this->assertStringNotContainsString( 'border-width: 0 0 0 1px', $html, 'Expected the package 1px quote border to be overridden.' );
	}

	/**
	 * The quote un-italic filter overrides the vendor cite italic in theme.json.
	 *
	 * The Core Initializer merges `core/quote.elements.cite.typography.fontStyle =
	 * "italic"` at priority 10. The quote file registers a filter at priority 11
	 * that merges `"normal"` so the CSS inliner sees `font-style: normal`. This
	 * test simulates both filter calls in order and verifies the final merged
	 * theme.json reports `normal` for the cite element.
	 */
	public function test_quote_theme_json_filter_overrides_vendor_italic() {
		// The override is guarded to the newsletter CPT — set a newsletter as the
		// global post so the filter's context check passes.
		$newsletter_id   = self::factory()->post->create(
			[ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ]
		);
		$GLOBALS['post'] = get_post( $newsletter_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Start with a base theme that mimics the Core Initializer's italic injection.
		$theme = new \WP_Theme_JSON(
			[
				'version' => 3,
				'styles'  => [
					'blocks' => [
						'core/quote' => [
							'elements' => [
								'cite' => [
									'typography' => [
										'fontStyle' => 'italic',
									],
								],
							],
						],
					],
				],
			],
			'default'
		);

		// The override filter runs at priority 11 (after the package defaults).
		$theme = Quote::override_quote_email_styles( $theme );

		unset( $GLOBALS['post'] );

		// The merged theme must report normal for the cite font-style.
		$raw        = $theme->get_raw_data();
		$font_style = $raw['styles']['blocks']['core/quote']['elements']['cite']['typography']['fontStyle'] ?? '';
		$this->assertSame( 'normal', $font_style, 'Expected the quote override filter to set the cite fontStyle to "normal".' );

		// The merged theme must also report the Newspack 2px left border width.
		$border_width = $raw['styles']['blocks']['core/quote']['border']['width'] ?? '';
		$this->assertSame( Quote::BORDER_WIDTH, $border_width, 'Expected the quote override filter to set the 2px left border width.' );
	}

	/**
	 * The quote override is a NO-OP outside the newsletter CPT context.
	 *
	 * `woocommerce_email_editor_theme_json` is a global hook shared with the
	 * WooCommerce block-email editor. When the rendering post is not a newsletter
	 * (here: a regular post, or no post at all), the override must return the theme
	 * untouched so it never bleeds the Newspack quote styles into WC transactional
	 * emails on a site running both editors.
	 */
	public function test_quote_override_is_no_op_for_non_newsletter_context() {
		$base_styles = [
			'version' => 3,
			'styles'  => [
				'blocks' => [
					'core/quote' => [
						'elements' => [
							'cite' => [
								'typography' => [
									'fontStyle' => 'italic',
								],
							],
						],
					],
				],
			],
		];

		// Case 1: a non-newsletter post is the global post → no-op.
		$regular_id      = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$GLOBALS['post'] = get_post( $regular_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$theme_regular = Quote::override_quote_email_styles( new \WP_Theme_JSON( $base_styles, 'default' ) );
		$raw_regular   = $theme_regular->get_raw_data();

		$this->assertSame(
			'italic',
			$raw_regular['styles']['blocks']['core/quote']['elements']['cite']['typography']['fontStyle'] ?? '',
			'On a non-newsletter post the override must not touch the cite fontStyle.'
		);
		$this->assertArrayNotHasKey(
			'border',
			$raw_regular['styles']['blocks']['core/quote'] ?? [],
			'On a non-newsletter post the override must not add the Newspack border.'
		);

		// Case 2: no post in context at all → also a no-op.
		unset( $GLOBALS['post'] );
		$theme_none = Quote::override_quote_email_styles( new \WP_Theme_JSON( $base_styles, 'default' ) );
		$raw_none   = $theme_none->get_raw_data();

		$this->assertSame(
			'italic',
			$raw_none['styles']['blocks']['core/quote']['elements']['cite']['typography']['fontStyle'] ?? '',
			'With no post in context the override must not touch the cite fontStyle.'
		);
		$this->assertArrayNotHasKey(
			'border',
			$raw_none['styles']['blocks']['core/quote'] ?? [],
			'With no post in context the override must not add the Newspack border.'
		);
	}
}
