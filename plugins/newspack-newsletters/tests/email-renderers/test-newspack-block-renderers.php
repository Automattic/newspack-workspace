<?php
/**
 * Class Newspack Block Renderers Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry;
use Newspack\Newsletters\Email_Renderers\Blocks\Posts_Inserter;
use Newspack\Newsletters\Email_Renderers\Blocks\Share;
use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Tests the Newspack dynamic-block overrides for the WC email engine:
 * posts-inserter (renders inserted children through do_blocks) and share
 * (emits the saved anchor only when the newsletter is public).
 */
class Test_Newspack_Block_Renderers extends WP_UnitTestCase {
	/**
	 * Run override discovery so the self-registering renderers are mapped.
	 */
	public function set_up() {
		parent::set_up();
		Block_Renderer_Registry::init();
	}

	/**
	 * Fully processes nested blocks and leaks no raw `<!-- wp:` delimiters —
	 * raw innerHTML is passed through do_blocks(), not concatenated.
	 */
	public function test_posts_inserter_renders_nested_blocks_without_delimiters() {
		$children = [
			[
				// A real inserted child carries the block's INNER html (no outer
				// `<!-- wp:columns -->` delimiter — that is implied by blockName).
				'blockName' => 'core/columns',
				'innerHTML' => '<div class="wp-block-columns">'
					. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
					. '</div>',
			],
		];

		$result = Posts_Inserter::render_inserted_blocks( $children );

		$this->assertStringContainsString( 'wp-block-columns', $result, 'Expected the nested columns block to be rendered.' );
		$this->assertStringContainsString( 'Hello', $result, 'Expected the inner paragraph content to survive.' );
		$this->assertStringNotContainsString( '<!-- wp:', $result, 'Expected no raw block-comment delimiters to leak into the output.' );
	}

	/**
	 * The posts-inserter helper returns an empty string for no children.
	 */
	public function test_posts_inserter_empty_array_renders_nothing() {
		$this->assertSame( '', Posts_Inserter::render_inserted_blocks( [] ), 'Expected an empty children array to render nothing.' );
	}

	/**
	 * Concatenates children in document order.
	 */
	public function test_posts_inserter_preserves_child_order() {
		$children = [
			[ 'innerHTML' => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->' ],
			[ 'innerHTML' => '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->' ],
		];

		$result = Posts_Inserter::render_inserted_blocks( $children );

		// Assert both children rendered before comparing positions — otherwise a
		// missing child makes strpos() return false (== 0) and the order check
		// could pass for the wrong reason.
		$this->assertStringContainsString( 'First', $result, 'Expected the first child to render.' );
		$this->assertStringContainsString( 'Second', $result, 'Expected the second child to render.' );
		$this->assertLessThan(
			strpos( $result, 'Second' ),
			strpos( $result, 'First' ),
			'Expected children to render in document order.'
		);
		$this->assertStringNotContainsString( '<!-- wp:', $result, 'Expected no raw block-comment delimiters to leak.' );
	}

	/**
	 * Flat-layout blocks (heading/paragraph/image) get 6px top/bottom padding; a columns child
	 * (a whole post) does not — it uses the post-gap margin instead.
	 */
	public function test_posts_inserter_pads_flat_layout_blocks() {
		$flat = [
			[
				'blockName' => 'core/heading',
				'attrs'     => [ 'level' => 3 ],
				'innerHTML' => '<h3 class="wp-block-heading">Title</h3>',
			],
			[
				'blockName' => 'core/paragraph',
				'attrs'     => [],
				'innerHTML' => '<p>June 18, 2026</p>',
			],
		];
		$flat_result = Posts_Inserter::render_inserted_blocks( $flat );
		$this->assertSame(
			2,
			substr_count( $flat_result, 'padding-top: 6px; padding-bottom: 6px' ),
			'Expected each flat-layout block to be wrapped in a 6px top/bottom padded cell.'
		);

		$columns = [
			[
				'blockName' => 'core/columns',
				'attrs'     => [],
				'innerHTML' => '<div class="wp-block-columns"></div>',
			],
		];
		$columns_result = Posts_Inserter::render_inserted_blocks( $columns );
		$this->assertStringNotContainsString(
			'padding-top: 6px; padding-bottom: 6px',
			$columns_result,
			'Expected a side-by-side columns child not to get the flat-block padding.'
		);
	}

	/**
	 * Serialize a posts-inserter block with the given children exactly as the editor stores it.
	 *
	 * Uses serialize_block() so child HTML is JSON-escaped — avoiding kses stripping bare `<` on
	 * wp_insert_post. A naive wp_json_encode() approach does not survive kses on save.
	 *
	 * @param array $children innerBlocksToInsert child blocks (blockName/innerHTML).
	 * @return string Serialized posts-inserter block markup.
	 */
	private function serialize_posts_inserter( array $children ): string {
		return serialize_block(
			[
				'blockName'    => 'newspack-newsletters/posts-inserter',
				'attrs'        => [ 'innerBlocksToInsert' => $children ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Create a newsletter CPT post with the given block markup, slashed for kses safety.
	 *
	 * @param string $content Serialized block markup.
	 * @return int Created post ID.
	 */
	private function create_newsletter_with_content( string $content ): int {
		return self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Posts inserter newsletter',
				'post_content' => wp_slash( $content ),
			]
		);
	}

	/**
	 * End-to-end regression: a real posts-inserter render emits column email tables with no raw
	 * block-comment delimiters — the fix for the block being registered without metadata so the
	 * block_type_metadata_settings filter never wired up its render_email_callback, causing delimiter leakage.
	 */
	public function test_posts_inserter_integration_renders_columns() {
		Editor_Bootstrap::init();

		// A real inserted child carries the block's INNER html — no outer
		// `<!-- wp:columns -->` delimiter (that is implied by blockName).
		$columns_inner = '<div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Left column body</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Right column body</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '</div>';

		$content = $this->serialize_posts_inserter(
			[
				[
					'blockName'    => 'core/heading',
					'attrs'        => [],
					'innerHTML'    => '<h2>Latest posts</h2>',
					'innerContent' => [ '<h2>Latest posts</h2>' ],
					'innerBlocks'  => [],
				],
				[
					'blockName'    => 'core/columns',
					'attrs'        => [],
					'innerHTML'    => $columns_inner,
					'innerContent' => [ $columns_inner ],
					'innerBlocks'  => [],
				],
			]
		);

		$post_id = $this->create_newsletter_with_content( $content );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		// The heading and both column bodies must survive into the email.
		$this->assertStringContainsString( 'Latest posts', $html, 'Expected the inserted heading content to appear.' );
		$this->assertStringContainsString( 'Left column body', $html, 'Expected the left column body to appear.' );
		$this->assertStringContainsString( 'Right column body', $html, 'Expected the right column body to appear.' );

		// The columns must be rendered as the email-block column markup, not leaked raw.
		$this->assertStringContainsString( 'wp-block-column', $html, 'Expected the inserted columns to render as column markup.' );

		// The outer columns block must be email-rendered with its width wrapper, not
		// left as a raw div that overflows the email body — the override must render
		// the whole child block, not only its inner blocks.
		$this->assertStringContainsString( 'email-block-columns', $html, 'Expected the inserted columns to get the email-block-columns width wrapper.' );

		// No raw block-comment delimiters may leak into the email body — this is the bug.
		$this->assertStringNotContainsString( '<!-- wp:', $html, 'Expected no raw block-comment delimiters in the rendered email.' );
	}

	/**
	 * Post-item text links render underlined with color:inherit — the package emits no colour or underline,
	 * so the override applies them. Image-wrapping anchors are left alone.
	 */
	public function test_posts_inserter_styles_text_links_for_email() {
		Editor_Bootstrap::init();

		$inner = '<div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:image --><figure class="wp-block-image"><a href="https://example.com/p"><img src="https://example.com/i.jpg" alt=""/></a></figure><!-- /wp:image -->'
			. '<!-- wp:heading --><h3 class="wp-block-heading"><a href="https://example.com/p">My Post Title</a></h3><!-- /wp:heading -->'
			. '</div><!-- /wp:column --></div>';
		$content = $this->serialize_posts_inserter(
			[
				[
					'blockName'    => 'core/columns',
					'attrs'        => [],
					'innerHTML'    => $inner,
					'innerContent' => [ $inner ],
					'innerBlocks'  => [],
				],
			]
		);
		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter_with_content( $content ) ) );

		// The post title link is underlined and inherits its colour (so a custom
		// heading colour is respected; black by default). Style order-independent.
		$this->assertMatchesRegularExpression(
			'/<a\b[^>]*style="(?=[^"]*text-decoration:\s*underline)(?=[^"]*color:\s*inherit)[^"]*"[^>]*>\s*My Post Title/i',
			$html,
			'Expected the post title link to render underlined and inherit its colour for email.'
		);
		// Image-wrapping anchors are not given the text-link underline.
		$this->assertDoesNotMatchRegularExpression(
			'/<a\b[^>]*text-decoration:\s*underline[^>]*><img/i',
			$html,
			'Expected image-wrapping links to be left alone.'
		);
	}

	/**
	 * The share builder wraps the content in a single anchor with the href.
	 */
	public function test_share_builder_wraps_anchor() {
		$result = Share::build_share_html( 'mailto:?body=x', 'Share this' );

		$this->assertStringContainsString( '<a href="mailto:?body=x"', $result, 'Expected the anchor to carry the share href.' );
		$this->assertMatchesRegularExpression( '/<a\b[^>]*style="(?=[^"]*text-decoration:\s*underline)(?=[^"]*color:\s*inherit)[^"]*"/i', $result, 'Expected the anchor to be underlined and inherit the text colour, matching the editor default.' );
		$this->assertStringContainsString( 'Share this', $result, 'Expected the link text to be preserved.' );
		$this->assertStringContainsString( 'newspack-newsletters-share-block', $result, 'Expected the share-block paragraph class.' );
		$this->assertSame( 1, substr_count( $result, '<a ' ), 'Expected exactly one anchor in the share markup.' );
	}

	/**
	 * The share builder renders nothing when there is no href to link to.
	 */
	public function test_share_builder_empty_href_renders_nothing() {
		$this->assertSame( '', Share::build_share_html( '', 'Share this' ), 'Expected an empty href to render nothing.' );
	}

	/**
	 * The share builder inlines background/text colors and applies 6px/12px padding when a
	 * background is present; no color styles when unset.
	 */
	public function test_share_builder_applies_colors() {
		$styled = Share::build_share_html( 'mailto:?body=x', 'Share this', '#003da5', '#ffffff', '44px' );
		$this->assertStringContainsString( 'background-color: #003da5', $styled, 'Expected the resolved background colour inline.' );
		$this->assertStringContainsString( 'color: #ffffff', $styled, 'Expected the resolved text colour inline.' );
		$this->assertStringContainsString( 'padding: 6px 12px', $styled, 'Expected the background block to get the editor padding.' );
		$this->assertStringContainsString( 'font-size: 44px', $styled, 'Expected the resolved font size inline.' );

		$plain = Share::build_share_html( 'mailto:?body=x', 'Share this' );
		$this->assertStringNotContainsString( 'background-color', $plain, 'Expected no background style when no colour is set.' );
		$this->assertStringNotContainsString( 'padding:', $plain, 'Expected no padding when no background is set.' );
		$this->assertStringNotContainsString( 'font-size', $plain, 'Expected no font-size style when no size is set.' );
	}

	/**
	 * The share resolvers turn named theme.json color/fontSize presets into inline values —
	 * including digit-bearing slugs (e.g. `h1`) and the var:preset colour form; unknown slugs resolve to ''.
	 */
	public function test_share_resolves_named_presets() {
		$inject = function ( $data ) {
			return $data->update_with(
				[
					'version'  => 2,
					'settings' => [
						'color'      => [
							'palette' => [
								[
									'slug'  => 'brand',
									'color' => '#abcdef',
									'name'  => 'Brand',
								],
							],
						],
						'typography' => [
							'fontSizes' => [
								[
									'slug' => 'h1',
									'size' => '77px',
									'name' => 'H1',
								],
							],
						],
					],
				]
			);
		};
		add_filter( 'wp_theme_json_data_theme', $inject );
		\WP_Theme_JSON_Resolver::clean_cached_data();

		$resolve = function ( $method, $arg ) {
			$reflection = new \ReflectionMethod( Share::class, $method );
			$reflection->setAccessible( true );
			return $reflection->invoke( null, $arg );
		};

		try {
			$this->assertSame( '#abcdef', $resolve( 'resolve_color', 'brand' ), 'Expected a named colour preset to resolve to its hex.' );
			$this->assertSame( '#abcdef', $resolve( 'resolve_color', 'var:preset|color|brand' ), 'Expected the var:preset colour form to resolve too.' );
			$this->assertSame( '', $resolve( 'resolve_color', 'nope' ), 'Expected an unknown colour slug to resolve to an empty string.' );
			$this->assertSame( '77px', $resolve( 'resolve_font_size', 'h1' ), 'Expected a digit-bearing preset slug to resolve to its size, not be read as a literal.' );
			$this->assertSame( '44px', $resolve( 'resolve_font_size', '44px' ), 'Expected a literal size to be returned as-is.' );
			$this->assertSame( '', $resolve( 'resolve_font_size', 'nope' ), 'Expected an unknown font-size slug to resolve to an empty string.' );
		} finally {
			remove_filter( 'wp_theme_json_data_theme', $inject );
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}
	}

	/**
	 * A public newsletter renders the share anchor (the href is not in the block comment, so the
	 * override must recover it from the inner HTML).
	 */
	public function test_share_integration_public_renders_anchor() {
		Editor_Bootstrap::init();

		// Mirror production: `content` is an HTML-sourced RichText attribute, so it
		// is NOT serialized into the block comment — the link text lives only in the
		// saved anchor. The override must recover it from the inner HTML.
		$content = '<!-- wp:newspack-newsletters/share {"href":"mailto:?body=read-this"} -->'
			. '<p class="newspack-newsletters-share-block"><a href="mailto:?body=read-this">Share this</a></p>'
			. '<!-- /wp:newspack-newsletters/share -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Public share newsletter',
				'post_content' => $content,
			]
		);
		update_post_meta( $post_id, 'is_public', 1 );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( 'mailto:?body=read-this', $html, 'Expected the share anchor href in a public newsletter email.' );
		$this->assertStringContainsString( 'Share this', $html, 'Expected the share link text in a public newsletter email.' );
	}

	/**
	 * A non-public newsletter renders no share anchor — without is_public meta the share link points nowhere.
	 */
	public function test_share_integration_non_public_renders_no_anchor() {
		Editor_Bootstrap::init();

		// Mirror production: `content` is an HTML-sourced RichText attribute, so it
		// is NOT serialized into the block comment — the link text lives only in the
		// saved anchor. The override must recover it from the inner HTML.
		$content = '<!-- wp:newspack-newsletters/share {"href":"mailto:?body=read-this"} -->'
			. '<p class="newspack-newsletters-share-block"><a href="mailto:?body=read-this">Share this</a></p>'
			. '<!-- /wp:newspack-newsletters/share -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Non-public share newsletter',
				'post_content' => $content,
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringNotContainsString( 'mailto:?body=read-this', $html, 'Expected no share anchor href in a non-public newsletter email.' );
	}

	/**
	 * A posts-inserter nested in a column constrains its inserted image to the column
	 * width, not the full email width — otherwise the image overflows and blows the
	 * email layout out (the 4-article-grid layout).
	 */
	public function test_posts_inserter_image_constrained_to_column_width() {
		Editor_Bootstrap::init();

		// A real attachment so the package image renderer resolves a size and emits a
		// width (it drops images it can't resolve). canola.jpg is 640px wide.
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$src           = wp_get_attachment_url( $attachment_id );
		$image_inner   = '<figure class="wp-block-image size-full"><img class="wp-image-' . $attachment_id . '" src="' . esc_url( $src ) . '" alt=""/></figure>';
		$inserter      = $this->serialize_posts_inserter(
			[
				[
					'blockName'    => 'core/image',
					'attrs'        => [
						'id'       => $attachment_id,
						'sizeSlug' => 'full',
					],
					'innerHTML'    => $image_inner,
					'innerContent' => [ $image_inner ],
					'innerBlocks'  => [],
				],
			]
		);
		$content = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column {"width":"50%"} --><div class="wp-block-column" style="flex-basis:50%">' . $inserter . '</div><!-- /wp:column -->'
			. '<!-- wp:column {"width":"50%"} --><div class="wp-block-column" style="flex-basis:50%"><!-- wp:paragraph --><p>Side</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter_with_content( $content ) ) );

		$this->assertMatchesRegularExpression( '/<img\b[^>]*\bwidth="\d+"/', $html, 'Expected the inserted image to render with an explicit width.' );
		preg_match( '/<img\b[^>]*\bwidth="(\d+)"/', $html, $matches );
		$this->assertLessThan( 400, (int) $matches[1], 'Expected the column-nested posts-inserter image to be constrained to the column width, not the full email width.' );
	}

	/**
	 * The image-on-top (flat) layout left-aligns the inserted image. The posts-inserter
	 * stores the featured image with `align: center`, but in the email the image must
	 * sit flush-left to line up with the heading and excerpt below it (which are always
	 * left-aligned) — otherwise a sub-column-width image floats centered and looks broken.
	 */
	public function test_posts_inserter_flat_image_is_left_aligned() {
		Editor_Bootstrap::init();

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$src           = wp_get_attachment_url( $attachment_id );
		$image_inner   = '<figure class="wp-block-image aligncenter size-full"><img class="wp-image-' . $attachment_id . '" src="' . esc_url( $src ) . '" alt=""/></figure>';
		$content       = $this->serialize_posts_inserter(
			[
				[
					'blockName'    => 'core/image',
					'attrs'        => [
						'id'       => $attachment_id,
						'align'    => 'center',
						'sizeSlug' => 'full',
					],
					'innerHTML'    => $image_inner,
					'innerContent' => [ $image_inner ],
					'innerBlocks'  => [],
				],
				[
					'blockName'    => 'core/heading',
					'attrs'        => [],
					'innerHTML'    => '<h3>Post title</h3>',
					'innerContent' => [ '<h3>Post title</h3>' ],
					'innerBlocks'  => [],
				],
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $this->create_newsletter_with_content( $content ) ) );

		$this->assertMatchesRegularExpression( '/class="email-image-cell"\s+align="left"/', $html, 'Expected the flat-layout image cell to be left-aligned.' );
		$this->assertDoesNotMatchRegularExpression( '/class="email-image-cell"\s+align="center"/', $html, 'Expected the flat-layout image not to be centered.' );
	}
}
