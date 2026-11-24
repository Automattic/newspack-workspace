<?php
/**
 * Regression tests for the WC renderer visual fixes (NPPD-2188, NPPD-2608 to NPPD-2618).
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Block_Normalizer;
use Newspack\Newsletters\Email_Renderers\Blocks\Cover;
use Newspack\Newsletters\Email_Renderers\Blocks\Site_Logo;
use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;
use Newspack\Newsletters\Email_Renderers\Theme_Json_Builder;
use Newspack_Newsletters\Ads;

/**
 * Email output of the WC renderer matches the editor for the cases found in the
 * NPPD-1975 email-client review.
 */
class Test_WC_Renderer_Visual_Fixes extends WP_UnitTestCase {

	/**
	 * Boot the WC editor package so render_wc() can render newsletters.
	 */
	public function set_up() {
		parent::set_up();
		Editor_Bootstrap::init();
	}

	/**
	 * Render newsletter content through the WC engine.
	 *
	 * @param string $content Block markup.
	 * @return string Rendered email HTML.
	 */
	private function render( string $content ): string {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Visual fixes newsletter',
				'post_content' => wp_slash( $content ),
			]
		);
		return Renderer_Controller::render_wc( get_post( $post_id ) );
	}

	/**
	 * A core/image block for a real 640px-wide attachment, so the package resolves its size.
	 *
	 * @return array{0:int,1:string} Attachment ID and serialized image block.
	 */
	private function image_block(): array {
		$id  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$src = esc_url( wp_get_attachment_url( $id ) );
		return [ $id, '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . $src . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->' ];
	}

	/**
	 * Widths of every `<img>` in the HTML.
	 *
	 * @param string $html HTML.
	 * @return int[]
	 */
	private static function image_widths( string $html ): array {
		preg_match_all( '/<img\b[^>]*\bwidth="(\d+)"/', $html, $matches );
		// Skip the 1px open-tracking pixel.
		return array_values( array_filter( array_map( 'intval', $matches[1] ), fn( $width ) => $width > 1 ) );
	}

	// ------------------------------------------------------------------
	// Nested content: Posts Inserter and ads (NPPD-2610, NPPD-2611)
	// ------------------------------------------------------------------

	/**
	 * A top-level Posts Inserter's image spans the content column (612px), not 48px less.
	 */
	public function test_posts_inserter_image_fills_content_width() {
		list( $id ) = $this->image_block();
		$inner      = '<figure class="wp-block-image size-full"><img src="' . esc_url( wp_get_attachment_url( $id ) ) . '" alt="" class="wp-image-' . $id . '"/></figure>';
		$content    = serialize_block(
			[
				'blockName'    => 'newspack-newsletters/posts-inserter',
				'attrs'        => [
					'innerBlocksToInsert' => [
						[
							'blockName'    => 'core/image',
							'attrs'        => [
								'id'       => $id,
								'sizeSlug' => 'full',
							],
							'innerHTML'    => $inner,
							'innerContent' => [ $inner ],
							'innerBlocks'  => [],
						],
					],
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

		$this->assertSame( [ 612 ], self::image_widths( $this->render( $content ) ), 'Expected the inserted image to span the 612px content column.' );
	}

	/**
	 * An ad's content is sized to the ad block and not indented by the email gutter again.
	 * Before the fix its image kept the full 612px inside an extra 24px indent, forcing
	 * the email table wider than the card for everything that followed.
	 */
	public function test_ad_content_is_not_indented_twice() {
		list( , $image ) = $this->image_block();
		$ad_id           = self::factory()->post->create(
			[
				'post_type'    => Ads::CPT,
				'post_status'  => 'publish',
				'post_title'   => 'Ad',
				'post_content' => wp_slash( $image . '<!-- wp:paragraph --><p>Sponsored</p><!-- /wp:paragraph -->' ),
			]
		);
		$html = $this->render( '<!-- wp:newspack-newsletters/ad {"adId":"' . $ad_id . '"} /-->' );

		$this->assertSame( [ 612 ], self::image_widths( $html ), 'Expected the ad image to fit the 612px content column.' );
		$this->assertLessThanOrEqual( 1, substr_count( $html, 'class="email-root-padding"' ), 'Expected the ad\'s own blocks not to get the email gutter again.' );
	}

	// ------------------------------------------------------------------
	// Full-width groups (NPPD-2616, NPPD-2618)
	// ------------------------------------------------------------------

	/**
	 * Content inside a full-width band lines up with the rest of the email: its blocks
	 * already carry the email gutter, so the band adds no second inset.
	 */
	public function test_band_content_is_not_inset_twice() {
		$html = $this->render( '<!-- wp:group {"align":"full","style":{"color":{"background":"#f0e6d2"}}} --><div class="wp-block-group alignfull has-background" style="background-color:#f0e6d2"><!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );

		$this->assertStringContainsString( 'max-width:660px;margin:0 auto;padding-left:0;padding-right:0;', $html, 'Expected the band wrapper to add no inset of its own.' );
		$this->assertStringContainsString( 'class="email-root-padding" style="padding-left: 24px; padding-right: 24px;"', $html, 'Expected the band content to keep the email gutter.' );
	}

	/**
	 * A full-width group without a background keeps its content in the content column,
	 * as in the editor, instead of running to the edge of the email.
	 */
	public function test_plain_full_width_group_keeps_content_in_column() {
		$html = $this->render( '<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"40px","right":"0px","bottom":"28px","left":"0px"}}}} --><div class="wp-block-group alignfull" style="padding-top:40px;padding-right:0px;padding-bottom:28px;padding-left:0px"><!-- wp:paragraph --><p>Byline</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );

		$this->assertMatchesRegularExpression( '/class="email-root-padding" style="padding-left: 24px; padding-right: 24px;"/', $html, 'Expected the group to keep the email gutter.' );
	}

	/**
	 * Columns in a full-width band with zero side padding are sized to the content
	 * column. Before the fix they were sized to the full email width (330px each).
	 */
	public function test_columns_in_zero_padding_band_fit_content_column() {
		$columns = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';
		$html    = $this->render( '<!-- wp:group {"align":"full","style":{"color":{"background":"#fafafa"},"spacing":{"padding":{"top":"40px","right":"0px","bottom":"40px","left":"0px"}}}} --><div class="wp-block-group alignfull has-background" style="background-color:#fafafa;padding-top:40px;padding-right:0px;padding-bottom:40px;padding-left:0px">' . $columns . '</div><!-- /wp:group -->' );

		$this->assertStringNotContainsString( 'width="330"', $html, 'Expected no column sized to half the full email width.' );
		$this->assertSame( 2, substr_count( $html, 'width="306"' ), 'Expected both columns sized to half the 612px content column.' );
	}

	/**
	 * The normalizer's full-width group rules.
	 */
	public function test_normalizer_full_width_group_rules() {
		$plain = Block_Normalizer::normalize(
			parse_blocks( '<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"40px","left":"0px","right":"0"}}}} --><div class="wp-block-group alignfull"></div><!-- /wp:group -->' )
		);
		$this->assertArrayNotHasKey( 'align', $plain[0]['attrs'], 'Expected a plain full-width group to lose its full alignment.' );
		$this->assertSame( [ 'top' => '40px' ], $plain[0]['attrs']['style']['spacing']['padding'], 'Expected zero side padding to be dropped and the rest kept.' );

		$band = Block_Normalizer::normalize(
			parse_blocks( '<!-- wp:group {"align":"full","backgroundColor":"primary","style":{"spacing":{"padding":{"left":"0px","right":"0px"}}}} --><div class="wp-block-group alignfull has-primary-background-color has-background"></div><!-- /wp:group -->' )
		);
		$this->assertSame( 'full', $band[0]['attrs']['align'], 'Expected a full-width group with a background to stay full width.' );
		$this->assertSame( [], $band[0]['attrs']['style']['spacing']['padding'], 'Expected its zero side padding to be dropped.' );

		$padded = parse_blocks( '<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"left":"40px","right":"40px"}}}} --><div class="wp-block-group alignfull"></div><!-- /wp:group -->' );
		$this->assertSame( $padded, Block_Normalizer::normalize( $padded ), 'Expected a group with real side padding to be left alone.' );
	}

	// ------------------------------------------------------------------
	// Render-time block supports (NPPD-2188, NPPD-2617)
	// ------------------------------------------------------------------

	/**
	 * A WordPress 7.1 button width (`style.dimensions.width`) maps to the `width`
	 * attribute the engine sizes buttons from.
	 */
	public function test_normalizer_maps_button_width() {
		$blocks = Block_Normalizer::normalize(
			parse_blocks( '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"style":{"dimensions":{"width":"50%"}}} --><div class="wp-block-button"><a class="wp-block-button__link">A</a></div><!-- /wp:button --><!-- wp:button {"width":25,"style":{"dimensions":{"width":"75%"}}} --><div class="wp-block-button"><a class="wp-block-button__link">B</a></div><!-- /wp:button --><!-- wp:button {"style":{"dimensions":{"width":"120px"}}} --><div class="wp-block-button"><a class="wp-block-button__link">C</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' )
		);
		$buttons = $blocks[0]['innerBlocks'];

		$this->assertSame( 50.0, $buttons[0]['attrs']['width'], 'Expected a 7.1 percentage width to map to the width attribute.' );
		$this->assertSame( 25, $buttons[1]['attrs']['width'], 'Expected an existing width attribute to win.' );
		$this->assertArrayNotHasKey( 'width', $buttons[2]['attrs'], 'Expected a non-percentage width to be ignored.' );
	}

	/**
	 * A WordPress 7.1 button set to 50% renders at half the content width.
	 */
	public function test_wp71_button_width_is_rendered() {
		$html = $this->render( '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"style":{"dimensions":{"width":"50%"}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.test">Half</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' );

		$this->assertMatchesRegularExpression( '/class="layout-flex-item" style="[^"]*width:\s*298px/', $html, 'Expected the button cell to take half the 612px content width.' );
	}

	/**
	 * Core's block-hooks pass over `the_content` uses core's parser, so the widths the
	 * engine computes while parsing aren't serialized back into the content.
	 */
	public function test_block_hooks_pass_uses_core_parser() {
		Block_Normalizer::add_hooks();
		try {
			Block_Normalizer::start_block_hooks_pass( '' );
			$this->assertSame( \WP_Block_Parser::class, apply_filters( 'block_parser_class', 'Email_Parser' ) );
			Block_Normalizer::end_block_hooks_pass( '' );
			$this->assertSame( 'Email_Parser', apply_filters( 'block_parser_class', 'Email_Parser' ) );
		} finally {
			Block_Normalizer::remove_hooks();
		}
	}

	// ------------------------------------------------------------------
	// Posts Inserter image-left gap (NPPD-2616)
	// ------------------------------------------------------------------

	/**
	 * Image-left post items keep a 16px gap between the image and the text.
	 */
	public function test_posts_inserter_side_by_side_items_have_gap() {
		$inner   = '<div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Image</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div><!-- /wp:column --></div>';
		$content = serialize_block(
			[
				'blockName'    => 'newspack-newsletters/posts-inserter',
				'attrs'        => [
					'innerBlocksToInsert' => [
						[
							'blockName'    => 'core/columns',
							'attrs'        => [],
							'innerHTML'    => $inner,
							'innerContent' => [ $inner ],
							'innerBlocks'  => [],
						],
					],
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

		$this->assertMatchesRegularExpression( '/padding-left:\s*16px/', $this->render( $content ), 'Expected a 16px gap before the text column.' );
	}

	// ------------------------------------------------------------------
	// Site logo (NPPD-2608)
	// ------------------------------------------------------------------

	/**
	 * The logo image is block-level, keeps its alignment and drops web-only attributes.
	 */
	public function test_site_logo_image_is_block_level() {
		$html = Site_Logo::prepare_image( '<div class="wp-block-site-logo"><a href="/"><img width="120" src="logo.png" class="custom-logo" loading="lazy" decoding="async" srcset="a 1x" sizes="10px" style="max-width:100%"/></a></div>', 'center' );

		$this->assertStringContainsString( 'style="max-width:100%;display:block;vertical-align:middle;margin-left:auto;margin-right:auto;"', $html );
		foreach ( [ 'loading=', 'decoding=', 'srcset=', 'sizes=' ] as $attribute ) {
			$this->assertStringNotContainsString( $attribute, $html, 'Expected web-only image attributes to be removed.' );
		}
	}

	/**
	 * The site logo renders through the override in an email.
	 */
	public function test_site_logo_renders_block_level_in_email() {
		list( $id ) = $this->image_block();
		set_theme_mod( 'custom_logo', $id );
		$html = $this->render( '<!-- wp:site-logo /-->' );
		remove_theme_mod( 'custom_logo' );

		$this->assertMatchesRegularExpression( '/<img\b[^>]*class="custom-logo"[^>]*style="[^"]*display:\s*block/', $html );
	}

	// ------------------------------------------------------------------
	// Cover overlay (NPPD-2612)
	// ------------------------------------------------------------------

	/**
	 * The overlay is layered over the background image, with its color as fallback.
	 */
	public function test_cover_overlay_is_layered_over_image() {
		$table = '<table class="email-block-cover wp-block-cover" style="width: 100%; background-image: url(\'https://example.test/a.jpg\'); background-size: cover;"><tr><td></td></tr></table>';
		$html  = Cover::apply_overlay( $table, '#1B2A4A', 50 );

		$this->assertStringContainsString( "background-image: linear-gradient(rgba(27, 42, 74, 0.5), rgba(27, 42, 74, 0.5)), url('https://example.test/a.jpg')", html_entity_decode( $html, ENT_QUOTES | ENT_HTML5 ) );
		$this->assertStringContainsString( 'background-color: #1B2A4A;', $html );
		$this->assertSame( $table, Cover::apply_overlay( $table, '#000000', 0 ), 'Expected no change at a zero dim ratio.' );
	}

	/**
	 * A cover with an image and a dim ratio renders its overlay in the email.
	 */
	public function test_cover_overlay_renders_in_email() {
		list( $id ) = $this->image_block();
		$src        = esc_url( wp_get_attachment_url( $id ) );
		$html       = $this->render( '<!-- wp:cover {"url":"' . $src . '","id":' . $id . ',"dimRatio":40,"customOverlayColor":"#000000"} --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-40 has-background-dim" style="background-color:#000000"></span><img class="wp-block-cover__image-background wp-image-' . $id . '" alt="" src="' . $src . '" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Cover</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->' );

		$this->assertStringContainsString( 'linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4))', html_entity_decode( $html ) );
	}

	// ------------------------------------------------------------------
	// Social icons (NPPD-2609, NPPD-2613, NPPD-2614)
	// ------------------------------------------------------------------

	/**
	 * Social-links markup with the given block attributes.
	 *
	 * @param array $attrs Block attributes.
	 * @return string
	 */
	private static function social( array $attrs ): string {
		return '<!-- wp:social-links ' . ( $attrs ? wp_json_encode( $attrs ) : '{}' ) . ' --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://facebook.example.test/x","service":"facebook"} /--></ul><!-- /wp:social-links -->';
	}

	/**
	 * The pill table of the first icon.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private static function pill( string $html ): string {
		preg_match( '/<table[^>]*style="[^"]*display:\s*inline-table[^"]*"[^>]*>/', $html, $matches );
		return $matches[0] ?? '';
	}

	/**
	 * Icons are padded in px around the icon, on the cell, like vanilla WordPress.
	 */
	public function test_social_icon_padding_is_px() {
		$html = $this->render( self::social( [] ) );

		$this->assertStringNotContainsString( 'padding: 0.25em', $html, 'Expected the em padding to be replaced.' );
		$this->assertMatchesRegularExpression( '/<td[^>]*style="[^"]*padding:\s*6px;"/', $html, 'Expected 6px of padding around a 24px icon.' );
	}

	/**
	 * A custom dark icon color keeps the brand background and uses black icons.
	 */
	public function test_social_custom_icon_color_keeps_brand_background() {
		$html = $this->render(
			self::social(
				[
					'iconColor'      => 'black',
					'iconColorValue' => '#000000',
				] 
			) 
		);

		$this->assertMatchesRegularExpression( '/background-color:\s*#[0-9a-f]{6}/i', self::pill( $html ), 'Expected the brand background to be kept.' );
		$this->assertStringContainsString( 'assets/black-facebook.png', $html, 'Expected the black icon for a dark icon color.' );
	}

	/**
	 * Newspack's style variants set the background and the icon color.
	 */
	public function test_social_style_variants() {
		$filled_black = $this->render( self::social( [ 'className' => 'is-style-filled-black' ] ) );
		$this->assertStringNotContainsString( 'background-color', self::pill( $filled_black ), 'Expected filled black to have no background.' );
		$this->assertStringContainsString( 'assets/black-facebook.png', $filled_black );

		$circle_black = $this->render( self::social( [ 'className' => 'is-style-circle-black' ] ) );
		$this->assertMatchesRegularExpression( '/background-color:\s*#000000/', self::pill( $circle_black ) );
		$this->assertStringContainsString( 'facebook-white.png', $circle_black );

		$circle_white = $this->render( self::social( [ 'className' => 'is-style-circle-white' ] ) );
		$this->assertMatchesRegularExpression( '/background-color:\s*#ffffff/', self::pill( $circle_white ) );
		$this->assertStringContainsString( 'assets/black-facebook.png', $circle_white );

		$filled_white = $this->render( self::social( [ 'className' => 'is-style-filled-white' ] ) );
		$this->assertStringNotContainsString( 'background-color', self::pill( $filled_white ) );
		$this->assertStringContainsString( 'facebook-white.png', $filled_white );
	}

	// ------------------------------------------------------------------
	// Links (NPPD-2615)
	// ------------------------------------------------------------------

	/**
	 * The email theme underlines links and gives them the surrounding text color.
	 */
	public function test_theme_json_styles_links() {
		$post_id = self::factory()->post->create( [ 'post_type' => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$link    = Theme_Json_Builder::build( get_post( $post_id ) )['styles']['elements']['link'];

		$this->assertSame( 'underline', $link['typography']['textDecoration'] );
		$this->assertSame( 'inherit', $link['color']['text'] );
	}

	/**
	 * Links in text are underlined and inherit the text color, while a block's own link
	 * color still wins.
	 */
	public function test_text_links_are_underlined() {
		$html = $this->render( '<!-- wp:paragraph --><p>A <a href="https://example.test/a">plain link</a></p><!-- /wp:paragraph --><!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"#cc0000"}}}}} --><p class="has-link-color">A <a href="https://example.test/b">red link</a></p><!-- /wp:paragraph -->' );

		$this->assertMatchesRegularExpression( '/<a\b[^>]*style="(?=[^"]*text-decoration:\s*underline)(?=[^"]*color:\s*inherit)[^"]*"[^>]*>plain link/', $html );
		$this->assertMatchesRegularExpression( '/<a\b[^>]*style="[^"]*color:\s*#c(c0000|00)[^"]*"[^>]*>red link/i', $html );
	}
}
