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
	 * The width helper restores a percentage width the package stripped to px — parse_value('70%')
	 * emits `width="70"` (70px); the helper restores it to `width="70%"`.
	 */
	public function test_width_helper_restores_percent() {
		$html   = '<td class="x" width="70"><table width="100%"></table></td>';
		$result = Column::preserve_percentage_width( $html, '70%' );
		$this->assertStringContainsString( 'width="70%"', $result, 'Expected the percentage width to be restored on the wrapper cell.' );
		$this->assertStringNotContainsString( 'width="70"', str_replace( 'width="70%"', '', $result ), 'Expected no bare width="70" to remain once the percent is restored.' );
	}

	/**
	 * A pixel width was never stripped by parse_value, so the helper is a no-op.
	 */
	public function test_width_helper_ignores_non_percent() {
		$html = '<td class="x" width="200"><table width="100%"></table></td>';
		$this->assertSame( $html, Column::preserve_percentage_width( $html, '200px' ), 'Expected a non-percentage width to return the HTML unchanged.' );
	}

	/**
	 * An empty width passes through unchanged — the package falls back to the layout width, nothing to restore.
	 */
	public function test_width_helper_ignores_empty_width() {
		$html = '<td class="x" width="600"><table width="100%"></table></td>';
		$this->assertSame( $html, Column::preserve_percentage_width( $html, '' ), 'Expected an empty width to return the HTML unchanged.' );
	}

	/**
	 * The width helper restores decimal percentage widths — parse_value('33.33%') emits `width="33.33"`.
	 */
	public function test_width_helper_restores_decimal_percent() {
		$html   = '<td class="x" width="33.33"><table width="100%"></table></td>';
		$result = Column::preserve_percentage_width( $html, '33.33%' );
		$this->assertStringContainsString( 'width="33.33%"', $result, 'Expected the decimal percentage width to be restored.' );
		$this->assertStringNotContainsString( 'width="33.33"', str_replace( 'width="33.33%"', '', $result ), 'Expected no bare width="33.33" to remain.' );
	}

	/**
	 * The width helper normalizes trailing-zero percentages — `30.0%` is emitted as `width="30"` by the
	 * package, so the helper targets `30` (not `30.0`) to restore `width="30%"`.
	 */
	public function test_width_helper_normalizes_trailing_zero_percent() {
		$html   = '<td class="x" width="30"><table width="100%"></table></td>';
		$result = Column::preserve_percentage_width( $html, '30.0%' );
		$this->assertStringContainsString( 'width="30%"', $result, 'Expected the normalized percentage width to be restored on width="30".' );
		$this->assertStringNotContainsString( 'width="30"', str_replace( 'width="30%"', '', $result ), 'Expected no bare width="30" to remain.' );
	}

	/**
	 * The width helper only rewrites the first (wrapper cell) width occurrence — later identical
	 * values are left untouched so unrelated cells are never rewritten.
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
	 * Maps any block name to a lazily-instantiated renderer class — registration is data-driven,
	 * not a hardcoded list.
	 */
	public function test_add_registers_an_arbitrary_block_override() {
		Block_Renderer_Registry::add( 'test/dummy', Column::class );

		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'test/dummy' ] );

		$this->assertInstanceOf( Column::class, $settings['render_email_callback'][0], 'add() should register an override for any block name.' );
	}

	/**
	 * Glob discovery loads override files so they self-register — the only test that exercises the
	 * glob path in isolation; autoloading would mask a broken glob for known classes like Blocks\Column.
	 */
	public function test_discover_registers_overrides_via_glob() {
		Block_Renderer_Registry::discover( __DIR__ . '/fixtures/block-renderers' );

		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'test/fixture-block' ] );

		$this->assertArrayHasKey( 'render_email_callback', $settings, 'Expected discover() to have loaded the fixture file and registered its block.' );
		$this->assertIsCallable( $settings['render_email_callback'], 'The discovered override should map to a callable render callback.' );
	}

	/**
	 * A non-renderer override class is skipped (no fatal) — the registry requires the class to be
	 * a package block-renderer subclass; stdClass fails that check.
	 */
	public function test_non_renderer_override_fails_closed() {
		Block_Renderer_Registry::add( 'test/not-a-renderer', \stdClass::class );

		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'test/not-a-renderer' ] );

		$this->assertArrayNotHasKey( 'render_email_callback', $settings, 'A non-renderer class must not be bound as a render callback.' );
	}

	/**
	 * A renderer whose constructor throws is skipped (no fatal) — the is_subclass_of() guard passes
	 * for a valid subclass that throws, so the registry wraps `new` in a try/catch.
	 */
	public function test_uninstantiable_override_fails_closed() {
		require_once __DIR__ . '/fixtures/class-throwing-block-renderer.php';
		Block_Renderer_Registry::add( 'test/throws', \Newspack\Newsletters\Email_Renderers\Blocks\Throwing_Block_Renderer::class );

		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'test/throws' ] );

		$this->assertArrayNotHasKey( 'render_email_callback', $settings, 'A renderer that throws on construction must not be bound, and must not fatal.' );
	}

	/**
	 * A block with no override (e.g. core/paragraph) passes through with no render_email_callback injected.
	 */
	public function test_registry_leaves_unmapped_block_untouched() {
		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'core/paragraph' ] );
		$this->assertArrayNotHasKey( 'render_email_callback', $settings, 'Expected no render_email_callback to be added for an unmapped block.' );
	}

	/**
	 * The Newspack Column renderer must extend the package Column (not the abstract base) to inherit
	 * its no-op add_spacer() — otherwise an extra email-block-layout wrapper wraps each column.
	 */
	public function test_column_renderer_extends_package_column() {
		$this->assertTrue(
			is_subclass_of( Column::class, Package_Column::class ),
			'The Newspack Column renderer must extend the package Column so it inherits the no-op add_spacer().'
		);
	}

	/**
	 * A real two-column render preserves both percentage widths and has no per-column
	 * email-block-layout double-wrapper (the f1 regression fixed by inheriting the package's no-op add_spacer()).
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
	 * The Newspack Quote renderer must extend the package Quote to inherit all layout logic — the
	 * cite-italic fix is in theme.json, so no render_content override is needed.
	 */
	public function test_quote_renderer_extends_package_quote() {
		$this->assertTrue(
			is_subclass_of( Quote::class, Package_Quote::class ),
			'The Newspack Quote renderer must extend the package Quote so all layout logic is inherited.'
		);
	}

	/**
	 * A rendered quote cite does NOT carry font-style:italic — the package theme.json forces italic
	 * but the editor canvas renders the cite upright, so the override must correct this.
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
	 * The quote un-italic filter (priority 11) overrides the vendor cite italic (priority 10) in theme.json,
	 * leaving the merged result as font-style:normal with the Newspack 2px left border.
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
	 * The quote override is a no-op outside the newsletter CPT — the filter is global and must not
	 * bleed Newspack quote styles into WC transactional emails on a site running both editors.
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

	/**
	 * The render-start pass sets render_email_callback on non-metadata blocks (e.g. posts-inserter
	 * registered via register_block_type()) which the block_type_metadata_settings filter never fires for.
	 */
	public function test_render_start_pass_sets_callback_on_non_metadata_block() {
		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( 'newspack-newsletters/posts-inserter' ) ) {
			\Newspack_Newsletters::register_blocks();
		}
		$block_type = $registry->get_registered( 'newspack-newsletters/posts-inserter' );
		unset( $block_type->render_email_callback );

		Block_Renderer_Registry::apply_to_registered_blocks();

		$this->assertTrue( isset( $block_type->render_email_callback ), 'Expected the render-start pass to set render_email_callback on the non-metadata block.' );
		$this->assertIsCallable( $block_type->render_email_callback, 'The render_email_callback must be callable.' );
		$this->assertInstanceOf(
			\Newspack\Newsletters\Email_Renderers\Blocks\Posts_Inserter::class,
			$block_type->render_email_callback[0],
			'The callback must be bound to the Newspack Posts_Inserter renderer.'
		);
	}

	/**
	 * The render-start pass is a no-op (idempotent) when a metadata-registered block already has a callback.
	 */
	public function test_render_start_pass_does_not_clobber_existing_callback() {
		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( 'core/column' );
		$this->assertInstanceOf( \WP_Block_Type::class, $block_type, 'core/column must be registered for this test.' );

		$sentinel                          = static function () {
			return '';
		};
		$block_type->render_email_callback = $sentinel;

		Block_Renderer_Registry::apply_to_registered_blocks();

		$this->assertSame( $sentinel, $block_type->render_email_callback, 'Expected the existing callback to be left untouched.' );

		unset( $block_type->render_email_callback );
	}
}
