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
	 * Remove the images uploaded by image_block(); the database rollback leaves files.
	 */
	public function tear_down() {
		$this->remove_added_uploads();
		parent::tear_down();
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
	 * An ad's content is sized to the ad block and not indented by the email gutter
	 * again: a 612px image inside an extra 24px indent would force the email table wider
	 * than the card for everything after it.
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

	/**
	 * Create an ad with the given content and return the ad block that places it.
	 *
	 * @param string $content Ad block markup.
	 * @return string
	 */
	private function ad_block( string $content ): string {
		$ad_id = self::factory()->post->create(
			[
				'post_type'    => Ads::CPT,
				'post_status'  => 'publish',
				'post_title'   => 'Ad',
				'post_content' => wp_slash( $content ),
			]
		);
		return '<!-- wp:newspack-newsletters/ad {"adId":"' . $ad_id . '"} /-->';
	}

	/**
	 * Unsized columns in an ad are sized to the ad's own width (half of a 306px column),
	 * not to the full email width the first parse assumed.
	 */
	public function test_ad_columns_fit_a_narrow_host() {
		$ad_columns = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';
		$html       = $this->render(
			'<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"50%"} --><div class="wp-block-column" style="flex-basis:50%">'
			. $this->ad_block( $ad_columns )
			. '</div><!-- /wp:column --><!-- wp:column {"width":"50%"} --><div class="wp-block-column" style="flex-basis:50%"><!-- wp:paragraph --><p>Side</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->'
		);

		// 306px less the 32px gap, split in two; the second column carries the gap.
		$this->assertStringContainsString( 'width="137"', $html, 'Expected the ad\'s first column to take half of its 306px, less the gap.' );
		$this->assertStringContainsString( 'width="169"', $html, 'Expected the ad\'s second column to add the gap to its half.' );
	}

	/**
	 * Preset side padding inside an ad (`var:preset|spacing|50`, 32px) narrows the
	 * content within it, as it does at the top level.
	 */
	public function test_ad_content_respects_preset_padding() {
		list( , $image ) = $this->image_block();
		$html            = $this->render(
			$this->ad_block( '<!-- wp:group {"style":{"spacing":{"padding":{"left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}}} --><div class="wp-block-group">' . $image . '</div><!-- /wp:group -->' )
		);

		$this->assertSame( [ 548 ], self::image_widths( $html ), 'Expected the image to fit inside 2 × 32px of padding.' );
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
	 * column (290px each, with the 32px gap), not to the full email width (330px each).
	 */
	public function test_columns_in_zero_padding_band_fit_content_column() {
		$columns = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';
		$html    = $this->render( '<!-- wp:group {"align":"full","style":{"color":{"background":"#fafafa"},"spacing":{"padding":{"top":"40px","right":"0px","bottom":"40px","left":"0px"}}}} --><div class="wp-block-group alignfull has-background" style="background-color:#fafafa;padding-top:40px;padding-right:0px;padding-bottom:40px;padding-left:0px">' . $columns . '</div><!-- /wp:group -->' );

		$this->assertStringNotContainsString( 'width="330"', $html, 'Expected no column sized to half the full email width.' );
		$this->assertStringContainsString( 'width="290"', $html, 'Expected the first column to take half the 612px content column, less the gap.' );
		$this->assertStringContainsString( 'width="322"', $html, 'Expected the second column to add the gap to its half.' );
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

		$this->assertMatchesRegularExpression( '/class="layout-flex-item" style="[^"]*width:\s*300px/', $html, 'Expected the button cell to take half the 612px content width, less half the 12px gap.' );
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

	/**
	 * Inserted posts keep the Posts Inserter's own 8px gap between their blocks, even
	 * inside the columns of a side-by-side item, where the newsletter's own blocks get none.
	 */
	public function test_posts_inserter_keeps_its_gap_inside_columns() {
		$inner   = '<div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Image</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Title</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Excerpt</p><!-- /wp:paragraph --></div><!-- /wp:column --></div>';
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

		$this->assertMatchesRegularExpression( '/margin-top:\s*8px;?"><table[^>]*>\s*<tbody>\s*<tr>\s*<td[^>]*>\s*<p[^>]*>Excerpt/', $this->render( $content ) );
	}

	// ------------------------------------------------------------------
	// Site logo (NPPD-2608)
	// ------------------------------------------------------------------

	/**
	 * The logo image is block-level and keeps its alignment.
	 */
	public function test_site_logo_image_is_block_level() {
		$html = Site_Logo::prepare_image( '<div class="wp-block-site-logo"><a href="/"><img width="120" src="logo.png" class="custom-logo" style="max-width:100%"/></a></div>', 'center' );

		$this->assertStringContainsString( 'style="max-width:100%;display:block;vertical-align:middle;margin-left:auto;margin-right:auto;"', $html );
	}

	/**
	 * A vertically centered column gets a valid `vertical-align: middle` (and `valign`),
	 * not `center`, which Firefox and strict email clients treat as baseline alignment.
	 */
	public function test_centered_column_is_vertically_middle() {
		$html = $this->render( '<!-- wp:columns {"verticalAlignment":"center"} --><div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center"} --><div class="wp-block-column is-vertically-aligned-center"><!-- wp:paragraph --><p>Logo</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column {"verticalAlignment":"center"} --><div class="wp-block-column is-vertically-aligned-center"><!-- wp:paragraph --><p>Date</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->' );

		$this->assertStringNotContainsString( 'vertical-align: center', $html );
		preg_match_all( '/<td\b[^>]*class="block wp-block-column email-block-column"[^>]*>/', $html, $cells );
		$this->assertCount( 2, $cells[0] );
		foreach ( $cells[0] as $cell ) {
			$this->assertStringContainsString( 'valign="middle"', $cell );
			$this->assertMatchesRegularExpression( '/vertical-align:\s*middle/', $cell );
		}
	}

	/**
	 * Two-column markup with one paragraph per column (or two in the first).
	 *
	 * @param string $columns_attrs JSON attributes for the columns block.
	 * @param string $second_attrs  JSON attributes for the second column.
	 * @return string
	 */
	private static function two_columns( string $columns_attrs = '', string $second_attrs = '' ): string {
		return '<!-- wp:columns' . ( $columns_attrs ? ' ' . $columns_attrs : '' ) . ' --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Name</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Role</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column' . ( $second_attrs ? ' ' . $second_attrs : '' ) . ' --><div class="wp-block-column"><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';
	}

	/**
	 * Columns get the editor's default 32px gap, carried by the second column's padding
	 * so the row still fits the 612px content column, and dropped on phones.
	 */
	public function test_columns_get_editor_gap_without_widening() {
		$html = $this->render( self::two_columns() );

		$this->assertStringContainsString( 'width="290"', $html, 'Expected the first column to take half the row less the gap.' );
		$this->assertStringContainsString( 'width="322"', $html, 'Expected the second column to include the gap.' );
		$this->assertMatchesRegularExpression( '/<td\b[^>]*class="[^"]*\bnewspack-columns-gap\b[^"]*"[^>]*padding-left:\s*32px/', $html, 'Expected the gap as padding on the second column\'s content cell.' );
		$this->assertDoesNotMatchRegularExpression( '/class="block wp-block-column email-block-column"[^>]*padding-left/', $html, 'Expected no gap padding on top of the column width.' );
		$this->assertMatchesRegularExpression( '/@media[^{]*max-width:\s*660px\)\s*\{(?:[^{}]*\{[^}]*\})*?\s*\.newspack-columns-gap\s*\{\s*padding-left:\s*0\s*!important/', $html, 'Expected the gap to come off when columns stack.' );
		$this->assertDoesNotMatchRegularExpression( '/\.newspack-columns-gap\s*\{[^}]*padding-right/', $html, 'Expected the column\'s own end-side padding to stay on phones.' );
	}

	/**
	 * A single blockGap value applies to both axes, as in the editor: its horizontal part
	 * becomes the later columns' gap padding and its vertical part stays.
	 */
	public function test_single_block_gap_sets_both_axes() {
		$blocks = Block_Normalizer::normalize( parse_blocks( self::two_columns( '{"style":{"spacing":{"blockGap":"20px"}}}' ) ) );

		$this->assertSame( [ 'top' => '20px' ], $blocks[0]['attrs']['style']['spacing']['blockGap'] );
		$this->assertSame( '20px', $blocks[0]['innerBlocks'][1]['attrs']['style']['spacing']['padding']['left'] );
		$this->assertArrayNotHasKey( 'padding', $blocks[0]['innerBlocks'][0]['attrs']['style']['spacing'] ?? [], 'Expected the first column to get no gap.' );
	}

	/**
	 * A column that would paint the gap keeps a gap the author set, through the engine,
	 * but gets no default gap: the engine adds it on top of the column widths.
	 */
	public function test_painted_column_gets_only_an_authored_gap() {
		$painted = '{"style":{"color":{"background":"#eeeeee"}}}';

		$default = Block_Normalizer::normalize( parse_blocks( self::two_columns( '', $painted ) ) );
		$this->assertArrayNotHasKey( 'blockGap', $default[0]['attrs']['style']['spacing'] ?? [], 'Expected no default gap that would widen the row.' );
		$this->assertArrayNotHasKey( 'spacing', $default[0]['innerBlocks'][1]['attrs']['style'], 'Expected no gap padding on a column with a background.' );

		$authored = Block_Normalizer::normalize( parse_blocks( self::two_columns( '{"style":{"spacing":{"blockGap":{"left":"20px"}}}}', $painted ) ) );
		$this->assertSame( [ 'left' => '20px' ], $authored[0]['attrs']['style']['spacing']['blockGap'], 'Expected the author\'s gap left to the engine.' );
	}

	/**
	 * A gap the width calculation can't measure (anything but px or a spacing preset) is
	 * left to the engine instead of becoming column padding.
	 */
	public function test_unmeasurable_gap_is_left_to_engine() {
		$blocks = Block_Normalizer::normalize( parse_blocks( self::two_columns( '{"style":{"spacing":{"blockGap":{"left":"1.5rem"}}}}' ) ) );

		$this->assertSame( [ 'left' => '1.5rem' ], $blocks[0]['attrs']['style']['spacing']['blockGap'] );
		$this->assertArrayNotHasKey( 'padding', $blocks[0]['innerBlocks'][1]['attrs']['style']['spacing'] ?? [] );
	}

	/**
	 * Sibling blocks are 12px apart, as the editor's block padding puts them, except
	 * inside a column, where the editor gives them no padding.
	 */
	public function test_block_gap_matches_editor() {
		$html = $this->render( '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->' . self::two_columns() );

		$this->assertMatchesRegularExpression( '/margin-top:\s*12px;?"><table[^>]*>\s*<tbody>\s*<tr>\s*<td[^>]*>\s*<p[^>]*>Second/', $html, 'Expected 12px between top-level blocks.' );
		$this->assertDoesNotMatchRegularExpression( '/margin-top:\s*\d+px;?"><table[^>]*>\s*<tbody>\s*<tr>\s*<td[^>]*>\s*<p[^>]*>Role/', $html, 'Expected no gap between blocks inside a column.' );
	}

	/**
	 * A spacer takes no padding in the editor, so the blocks next to it are only half a
	 * block gap away from it.
	 */
	public function test_spacer_neighbours_get_half_gap() {
		$html = $this->render( '<!-- wp:paragraph --><p>Above</p><!-- /wp:paragraph --><!-- wp:spacer {"height":"16px"} --><div style="height:16px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer --><!-- wp:paragraph --><p>Below</p><!-- /wp:paragraph -->' );

		$this->assertMatchesRegularExpression( '/margin-top:\s*6px;?"><table[^>]*>\s*<tbody>\s*<tr>\s*<td[^>]*>\s*<p[^>]*>Below/', $html, 'Expected 6px between the spacer and the next block.' );
		$this->assertStringNotContainsString( 'margin-top: 12px', $html, 'Expected no full block gap next to the spacer.' );
	}

	/**
	 * Blocks inside a quote sit flush, as the editor renders them, and a full-width block
	 * is only half a gap from its neighbour, since the editor doesn't pad it.
	 */
	public function test_quote_and_full_width_gaps_match_editor() {
		$html = $this->render( '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>One</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote --><!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph --><!-- wp:separator {"align":"full"} --><hr class="wp-block-separator alignfull has-alpha-channel-opacity"/><!-- /wp:separator -->' );

		$this->assertDoesNotMatchRegularExpression( '/margin-top:\s*[1-9]\d*px;?"><table[^>]*>\s*<tbody>\s*<tr>\s*<td[^>]*>\s*<p[^>]*>Two/', $html, 'Expected no gap between blocks inside a quote.' );
		$this->assertMatchesRegularExpression( '/margin-top:\s*6px/', $html, 'Expected half a gap above the full-width block.' );
	}

	/**
	 * List items are a block gap apart, as their padding puts them in the editor.
	 */
	public function test_list_items_are_spaced() {
		$html = $this->render( '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One</li><!-- /wp:list-item --><!-- wp:list-item --><li>Two</li><!-- /wp:list-item --></ul><!-- /wp:list -->' );

		$this->assertMatchesRegularExpression( '/<li style="[^"]*margin-top:\s*12px[^"]*">Two/', $html, 'Expected the second item a block gap below the first.' );
		$this->assertDoesNotMatchRegularExpression( '/<li style="[^"]*margin-top:\s*[1-9][^"]*">One/', $html, 'Expected no gap above the first item.' );
	}

	/**
	 * Splitting the email around a full-width band keeps the email's top and bottom
	 * padding at the edges of the email only, not around the band, and a band without
	 * padding of its own gets the editor's 24px.
	 */
	public function test_band_adds_no_wrapper_padding() {
		$html = $this->render( '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph --><!-- wp:group {"align":"full","style":{"color":{"background":"#f0e6d2"}}} --><div class="wp-block-group alignfull has-background" style="background-color:#f0e6d2"><!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->' );

		preg_match_all( '/<div class="email_layout_wrapper"[^>]*style="([^"]*)"/', $html, $wrappers );
		$this->assertCount( 2, $wrappers[1], 'Expected the content on either side of the band in its own wrapper.' );
		$this->assertMatchesRegularExpression( '/padding-top:\s*24px/', $wrappers[1][0], 'Expected the top of the email to keep its padding.' );
		$this->assertMatchesRegularExpression( '/padding-bottom:\s*0\b/', $wrappers[1][0], 'Expected no padding above the band.' );
		$this->assertMatchesRegularExpression( '/padding-top:\s*0\b/', $wrappers[1][1], 'Expected no padding below the band.' );
		$this->assertMatchesRegularExpression( '/padding-bottom:\s*24px/', $wrappers[1][1], 'Expected the bottom of the email to keep its padding.' );
		$this->assertStringContainsString( 'padding-top:24px;padding-bottom:24px;', $html, 'Expected the band\'s default padding.' );
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

	/**
	 * A cover's preset gradient overlay is drawn over its image at the dim ratio, not
	 * replaced by the default black.
	 */
	public function test_cover_gradient_overlay_is_drawn() {
		list( $id ) = $this->image_block();
		$src        = esc_url( wp_get_attachment_url( $id ) );
		$html       = $this->render( '<!-- wp:cover {"url":"' . $src . '","id":' . $id . ',"dimRatio":50,"gradient":"luminous-vivid-amber-to-luminous-vivid-orange"} --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim has-background-gradient has-luminous-vivid-amber-to-luminous-vivid-orange-gradient-background"></span><img class="wp-block-cover__image-background wp-image-' . $id . '" alt="" src="' . $src . '" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Cover</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->' );

		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5 );
		$this->assertStringNotContainsString( 'linear-gradient(rgba(0, 0, 0', $html );
		$this->assertMatchesRegularExpression( '/background-image:\s*linear-gradient\(135deg,\s*rgba\(252, 185, 0, 0\.5\) 0%,\s*rgba\(255, 105, 0, 0\.5\) 100%\),\s*url\(/', $html, 'Expected the gradient at 50% over the image.' );
		$this->assertStringContainsString( 'background-color: rgb(252, 185, 0)', $html, 'Expected the first stop as the fallback color.' );
	}

	/**
	 * A custom gradient's stops are faded by the dim ratio, in hex and rgba alike.
	 */
	public function test_cover_custom_gradient_stops_are_faded() {
		$table = '<table class="email-block-cover" style="background-image: url(\'https://example.test/a.jpg\'); background-size: cover;"><tr><td>Cover</td></tr></table>';

		$html = html_entity_decode( Cover::apply_gradient_overlay( $table, 'linear-gradient(90deg,#ff0000 0%,rgba(0,0,255,0.5) 100%)', 40 ), ENT_QUOTES | ENT_HTML5 );
		$this->assertStringContainsString( "background-image: linear-gradient(90deg,rgba(255, 0, 0, 0.4) 0%,rgba(0, 0, 255, 0.2) 100%), url('https://example.test/a.jpg')", $html );
		$this->assertSame( $table, Cover::apply_gradient_overlay( $table, '', 40 ), 'Expected no change for an unresolved gradient.' );
	}

	/**
	 * Custom gradients that could break out of the style attribute, add a layer or an
	 * image, or use stops email clients can't resolve are dropped.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unusable_gradients(): array {
		return [
			'declaration break' => [ 'linear-gradient(red,blue);color:red', 'color:red' ],
			'image layer'       => [ 'linear-gradient(red,red),url(//tracker.example/p.gif)', 'tracker.example' ],
			'css variable stop' => [ 'linear-gradient(var(--wp--preset--color--accent) 0%,#ffffff 100%)', 'var(--wp--preset' ],
		];
	}

	/**
	 * An unusable custom gradient is dropped.
	 *
	 * @dataProvider unusable_gradients
	 *
	 * @param string $gradient Custom gradient.
	 * @param string $leak     Text that must not reach the email.
	 */
	public function test_unusable_custom_gradient_is_dropped( string $gradient, string $leak ) {
		$html = $this->render( '<!-- wp:cover {"url":"https://example.test/a.jpg","dimRatio":50,"customGradient":' . wp_json_encode( $gradient ) . '} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" alt="" src="https://example.test/a.jpg"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Cover</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->' );

		$this->assertStringNotContainsString( $leak, html_entity_decode( $html, ENT_QUOTES | ENT_HTML5 ) );
	}

	// ------------------------------------------------------------------
	// Social icons (NPPD-2609, NPPD-2613, NPPD-2614)
	// ------------------------------------------------------------------

	/**
	 * Social-links markup with the given block attributes.
	 *
	 * @param array    $attrs    Block attributes.
	 * @param string[] $services Services, one link each.
	 * @return string
	 */
	private static function social( array $attrs, array $services = [ 'facebook' ] ): string {
		$links = '';
		foreach ( $services as $service ) {
			$links .= '<!-- wp:social-link {"url":"https://' . $service . '.example.test/x","service":"' . $service . '"} /-->';
		}
		return '<!-- wp:social-links ' . ( $attrs ? wp_json_encode( $attrs ) : '{}' ) . ' --><ul class="wp-block-social-links">' . $links . '</ul><!-- /wp:social-links -->';
	}

	/**
	 * The pill table of an icon.
	 *
	 * @param string $html  Rendered HTML.
	 * @param int    $index Icon position.
	 * @return string
	 */
	private static function pill( string $html, int $index = 0 ): string {
		preg_match_all( '/<table[^>]*style="[^"]*display:\s*inline-table[^"]*"[^>]*>/', $html, $matches );
		return $matches[0][ $index ] ?? '';
	}

	/**
	 * Icon sizes and the padding vanilla WordPress gives each (a quarter of the icon).
	 *
	 * @return array
	 */
	public static function social_icon_sizes(): array {
		return [
			'normal (24px)' => [ [], '6px' ],
			'large (36px)'  => [ [ 'size' => 'has-large-icon-size' ], '9px' ],
		];
	}

	/**
	 * Icons are padded in px around the icon, on the cell, in proportion to their size.
	 *
	 * @dataProvider social_icon_sizes
	 *
	 * @param array  $attrs   Social-links block attributes.
	 * @param string $padding Expected padding.
	 */
	public function test_social_icon_padding_is_px( array $attrs, string $padding ) {
		$html = $this->render( self::social( $attrs ) );

		$this->assertStringNotContainsString( 'padding: 0.25em', $html, 'Expected the em padding to be replaced.' );
		$this->assertMatchesRegularExpression( '/<td[^>]*style="[^"]*padding:\s*' . $padding . ';"/', $html );
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
	 * A service the package has no icon for doesn't shift the brand colors of the
	 * icons after it.
	 */
	public function test_social_brand_colors_follow_their_service() {
		$html = $this->render( self::social( [], [ 'snapchat', 'facebook' ] ) );

		$this->assertMatchesRegularExpression( '/background-color:\s*#0866ff/i', self::pill( $html, 1 ), 'Expected the second icon to keep Facebook\'s brand color.' );
	}

	/**
	 * Newspack's style variants and what each renders: pill background (null for none)
	 * and icon file.
	 *
	 * @return array
	 */
	public static function social_style_variants(): array {
		return [
			'filled black' => [ 'is-style-filled-black', null, 'assets/black-facebook.png' ],
			'filled white' => [ 'is-style-filled-white', null, 'facebook-white.png' ],
			'circle black' => [ 'is-style-circle-black', '#000000', 'facebook-white.png' ],
			'circle white' => [ 'is-style-circle-white', '#ffffff', 'assets/black-facebook.png' ],
		];
	}

	/**
	 * Newspack's style variants set the background and the icon color.
	 *
	 * @dataProvider social_style_variants
	 *
	 * @param string      $class_name Style variant class.
	 * @param string|null $background Expected pill background, or null for none.
	 * @param string      $icon       Expected icon file.
	 */
	public function test_social_style_variants( string $class_name, ?string $background, string $icon ) {
		$html = $this->render( self::social( [ 'className' => $class_name ] ) );

		if ( null === $background ) {
			$this->assertStringNotContainsString( 'background-color', self::pill( $html ) );
		} else {
			$this->assertMatchesRegularExpression( '/background-color:\s*' . preg_quote( $background, '/' ) . '/i', self::pill( $html ) );
		}
		$this->assertStringContainsString( $icon, $html );
	}

	// ------------------------------------------------------------------
	// Links (NPPD-2615)
	// ------------------------------------------------------------------

	/**
	 * Links in text are underlined and inherit the text color, while a block's own link
	 * color still wins.
	 */
	public function test_text_links_are_underlined() {
		$html = $this->render( '<!-- wp:paragraph --><p>A <a href="https://example.test/a">plain link</a></p><!-- /wp:paragraph --><!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"#cc0000"}}}}} --><p class="has-link-color">A <a href="https://example.test/b">red link</a></p><!-- /wp:paragraph -->' );

		$this->assertMatchesRegularExpression( '/<a\b[^>]*style="(?=[^"]*text-decoration:\s*underline)(?=[^"]*color:\s*inherit)[^"]*"[^>]*>plain link/', $html );
		$this->assertMatchesRegularExpression( '/<a\b[^>]*style="[^"]*color:\s*#c(c0000|00)[^"]*"[^>]*>red link/i', $html );
	}

	/**
	 * A text link followed by an image keeps its underline; only a link around an image
	 * loses it.
	 */
	public function test_text_link_before_image_keeps_underline() {
		list( $id ) = $this->image_block();
		$src        = esc_url( wp_get_attachment_url( $id ) );
		$html       = $this->render( '<!-- wp:paragraph --><p><a href="https://example.test/more">Read more</a><img src="' . $src . '" alt="" width="20"/></p><!-- /wp:paragraph -->' );

		$this->assertMatchesRegularExpression( '/<a\b[^>]*style="[^"]*text-decoration:\s*underline[^"]*"[^>]*>Read more/', $html );
	}

	/**
	 * A link holding text and then an image is a text link and keeps its underline.
	 */
	public function test_link_with_text_and_image_keeps_underline() {
		list( $id ) = $this->image_block();
		$src        = esc_url( wp_get_attachment_url( $id ) );
		$html       = $this->render( '<!-- wp:paragraph --><p><a href="https://example.test/more">Read more <img src="' . $src . '" alt="" width="20"/></a></p><!-- /wp:paragraph -->' );

		$this->assertMatchesRegularExpression( '/<a\b[^>]*style="[^"]*text-decoration:\s*underline[^"]*"[^>]*>Read more/', $html );
	}
}
