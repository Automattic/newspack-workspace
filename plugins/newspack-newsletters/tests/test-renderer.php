<?php
/**
 * Class Newsletters Renderer Test
 *
 * @package Newspack_Newsletters
 */

/**
 * Newsletters Renderer Test.
 */
class Newsletters_Renderer_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		parent::tear_down();
		// Reset stub RDB to prevent test pollution.
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::reset();
	}

	/**
	 * Test the MJML rendering function.
	 */
	public function test_render_mjml_component() {
		$inner_html                                   = '<p>Hello, Newspack!</p>\n';
		Newspack_Newsletters_Renderer::$color_palette = [
			'vivid-purple' => '#db18e6',
		];

		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [],
					'innerBlocks'  => [],
					'innerContent' => [],
					'innerHTML'    => $inner_html,
				]
			),
			'<mj-section padding="0"><mj-column padding="12px" width="100%"><mj-text padding="0" line-height="1.5" font-size="16px" >' . $inner_html . '</mj-text></mj-column></mj-section>',
			'Renders default paragraph'
		);

		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [
						'textColor' => 'vivid-purple',
						'fontSize'  => 'normal',
						'style'     => [
							'color' => [
								'background' => '#4aadd7',
							],
						],
					],
					'innerBlocks'  => [],
					'innerContent' => [],
					'innerHTML'    => $inner_html,
				]
			),
			'<mj-section textColor="vivid-purple" color="#db18e6 !important" background-color="#4aadd7 !important" font-size="16px" padding="0"><mj-column padding="12px" width="100%"><mj-text padding="0" line-height="1.5" font-size="16px"  textColor="vivid-purple" color="#db18e6 !important" container-background-color="#4aadd7 !important">' . $inner_html . '</mj-text></mj-column></mj-section>',
			'Renders styled paragraph'
		);
	}

	/**
	 * Test text alignment is promoted to mj-text's align attribute regardless of
	 * which Gutenberg attribute shape is used:
	 *   - `align` (legacy)
	 *   - top-level `textAlign` (intermediate)
	 *   - `style.typography.textAlign` (WP 7.0+ textAlign block support)
	 */
	public function test_render_text_alignment_variants() {
		$inner_html = '<p class="has-text-align-center">Hello</p>';

		$render = function ( $attrs ) use ( $inner_html ) {
			return Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => $attrs,
					'innerBlocks'  => [],
					'innerContent' => [],
					'innerHTML'    => $inner_html,
				]
			);
		};

		// Anchor assertions to <mj-text …> so the test doesn't accept alignment
		// that only landed on <mj-section> or another wrapper tag.
		$this->assertMatchesRegularExpression(
			'/<mj-text\b[^>]*\balign="center"/',
			$render( [ 'align' => 'center' ] ),
			'Legacy align attribute reaches mj-text'
		);

		$this->assertMatchesRegularExpression(
			'/<mj-text\b[^>]*\balign="center"/',
			$render( [ 'textAlign' => 'center' ] ),
			'Top-level textAlign attribute reaches mj-text'
		);

		$this->assertMatchesRegularExpression(
			'/<mj-text\b[^>]*\balign="center"/',
			$render( [ 'style' => [ 'typography' => [ 'textAlign' => 'center' ] ] ] ),
			'WP 7.0 style.typography.textAlign reaches mj-text'
		);

		$this->assertMatchesRegularExpression(
			'/<mj-text\b[^>]*\balign="right"/',
			$render( [ 'style' => [ 'typography' => [ 'textAlign' => 'right' ] ] ] ),
			'WP 7.0 style.typography.textAlign right reaches mj-text'
		);

		// Top-level attributes win over style.typography to preserve existing behavior.
		$this->assertMatchesRegularExpression(
			'/<mj-text\b[^>]*\balign="left"/',
			$render(
				[
					'align' => 'left',
					'style' => [ 'typography' => [ 'textAlign' => 'right' ] ],
				]
			),
			'Top-level align takes precedence over style.typography.textAlign'
		);
	}

	/**
	 * Test font-size preset mapping in paragraph rendering.
	 */
	public function test_render_font_size_presets() {
		$inner_html = '<p>Hello, Newspack!</p>';

		$render_with_font_size = function ( $font_size ) use ( $inner_html ) {
			$attrs = [];
			if ( null !== $font_size ) {
				$attrs['fontSize'] = $font_size;
			}
			return Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => $attrs,
					'innerBlocks'  => [],
					'innerContent' => [],
					'innerHTML'    => $inner_html,
				]
			);
		};

		// New presets added in this PR.
		$this->assertStringContainsString(
			'font-size="40px"',
			$render_with_font_size( 'xx-large' ),
			'Renders xx-large preset as 40px.'
		);
		$this->assertStringContainsString(
			'font-size="48px"',
			$render_with_font_size( 'xxx-large' ),
			'Renders xxx-large preset as 48px.'
		);
		$this->assertStringContainsString(
			'font-size="72px"',
			$render_with_font_size( 'xxxxxx-large' ),
			'Renders xxxxxx-large preset as 72px.'
		);

		// Unknown presets should fall back to the default 16px without emitting
		// a PHP notice.
		$unknown_output = $render_with_font_size( 'not-a-real-preset' );
		$this->assertStringContainsString(
			'font-size="16px"',
			$unknown_output,
			'Falls back to the default 16px for unknown presets.'
		);
	}

	/**
	 * Filter the OEmbed return value.
	 *
	 * @param array $data The data to return.
	 */
	public function set_oembed_value( $data = [] ) {
		global $newspack_newsletters_test_oembed_data;
		$newspack_newsletters_test_oembed_data = $data;
		add_filter(
			'newspack_newsletters_get_oembed_object',
			function() {
				return new class() {
					public function get_data() { // phpcs:disable Squiz.Commenting.FunctionComment.Missing
						global $newspack_newsletters_test_oembed_data;
						return (object) array_merge(
							[
								'title'            => 'Embed',
								'url'              => 'embed.com',
								'width'            => 480,
								'height'           => 360,
								'thumbnail_url'    => 'embed.com/image',
								'thumbnail_width'  => 480,
								'thumbnail_height' => 360,
								'html'             => 'Embed',
							],
							$newspack_newsletters_test_oembed_data
						);
					}
				};
			}
		);
	}

	/**
	 * Test embed blocks rendering.
	 */
	public function test_render_embed_blocks() {
		$this->set_oembed_value(
			[
				'type'          => 'video',
				'provider_name' => 'YouTube',
			]
		);
		// Video embed.
		$inner_html = '<figure><div>https://www.youtube.com/watch?v=aIRgcb3cQ1Q</div></figure>';
		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/embed',
					'attrs'        => [
						'url' => 'https://www.youtube.com/watch?v=aIRgcb3cQ1Q',
					],
					'innerBlocks'  => [],
					'innerContent' => [],
					'innerHTML'    => $inner_html,
				]
			),
			'<mj-section url="https://www.youtube.com/watch?v=aIRgcb3cQ1Q" padding="0"><mj-column padding="12px" width="100%"><mj-image padding="0" src="embed.com/image" width="480px" height="360px" href="https://www.youtube.com/watch?v=aIRgcb3cQ1Q" /><mj-text align="center" color="#555d66" line-height="1.56" font-size="13px" >Embed - YouTube</mj-text></mj-column></mj-section>',
			'Renders image from video embed block with title as caption'
		);

		$this->set_oembed_value(
			[
				'type'          => 'photo',
				'provider_name' => 'Flickr',
			]
		);
		// Image with custom caption.
		$inner_html = '<figure><div>https://flickr.com/photos/thomashawk/9274246246</div><figcaption>Automattic</figcaption></figure>';
		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/embed',
					'attrs'        => [
						'url' => 'https://flickr.com/photos/thomashawk/9274246246',
					],
					'innerBlocks'  => [],
					'innerContent' => [],
					'innerHTML'    => $inner_html,
				]
			),
			'<mj-section url="https://flickr.com/photos/thomashawk/9274246246" padding="0"><mj-column padding="12px" width="100%"><mj-image src="embed.com" alt="Automattic" width="480" height="360" href="https://flickr.com/photos/thomashawk/9274246246" /><mj-text align="center" color="#555d66" line-height="1.56" font-size="13px" >Automattic - Flickr</mj-text></mj-column></mj-section>',
			'Renders image with inline figcaption as caption'
		);

		// Rich embed as HTML.
		$inner_html = '<figure><div>https://twitter.com/automattic/status/1395447061336711181</div></figure>';
		$this->set_oembed_value(
			[
				'type'          => 'rich',
				'provider_name' => 'Twitter',
			]
		);
		$rendered_string = Newspack_Newsletters_Renderer::render_mjml_component(
			[
				'blockName'    => 'core/embed',
				'attrs'        => [
					'url' => 'https://twitter.com/automattic/status/1395447061336711181',
				],
				'innerBlocks'  => [],
				'innerContent' => [],
				'innerHTML'    => $inner_html,
			]
		);
		$this->assertEquals(
			str_replace( [ "\n", "\r" ], '', $rendered_string ),
			'<mj-section url="https://twitter.com/automattic/status/1395447061336711181" padding="0"><mj-column padding="12px" width="100%"><mj-text padding="0" line-height="1.5" font-size="16px" >Embed</mj-text></mj-column></mj-section>',
			'Renders tweet as HTML'
		);

		// Rich embed as link.
		$inner_html = '<figure><div>https://www.amazon.com/Learning-PHP-MySQL-JavaScript-Javascript/dp/1491978910</div></figure>';
		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/embed',
					'attrs'        => [
						'url' => 'https://www.amazon.com/Learning-PHP-MySQL-JavaScript-Javascript/dp/1491978910',
					],
					'innerBlocks'  => [],
					'innerContent' => [],
					'innerHTML'    => $inner_html,
				]
			),
			'<mj-section url="https://www.amazon.com/Learning-PHP-MySQL-JavaScript-Javascript/dp/1491978910" padding="0"><mj-column padding="12px" width="100%"><mj-text padding="0" line-height="1.5" font-size="16px" >Embed</mj-text></mj-column></mj-section>',
			'Renders invalid rich HTML as link'
		);
	}

	/**
	 * Test buttons blocks rendering.
	 */
	public function test_render_buttons_blocks() {
		// Left aligned button.
		$inner_html = '<button><a>Test Button</a></button>';
		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/buttons',
					'attrs'        => [],
					'innerBlocks'  => [
						[
							'blockName'    => 'core/button',
							'attrs'        => [],
							'innerBlocks'  => [],
							'innerContent' => [ $inner_html ],
							'innerHTML'    => $inner_html,
						],
					],
					'innerContent' => [
						'<div>',
						null,
						'</div>',
					],
					'innerHTML'    => '<div></div>',
				]
			),
			'<mj-section padding="0"><mj-wrapper padding="0" text-align="left"><mj-section padding="0" text-align="left"><mj-column padding="12px" css-class="mj-column-has-width" width="100%"><mj-button align="left" padding="0" inner-padding="12px 24px" line-height="1.5" href="" border-radius="999px" font-size="16px"  font-weight="bold" color="#fff !important" background-color="#32373c">Test Button</mj-button></mj-column></mj-section></mj-wrapper></mj-section>',
			'Renders left aligned button'
		);

		// Center aligned button.
		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/buttons',
					'attrs'        => [
						'layout' => [
							'justifyContent' => 'center',
						],
					],
					'innerBlocks'  => [
						[
							'blockName'    => 'core/button',
							'attrs'        => [],
							'innerBlocks'  => [],
							'innerContent' => [ $inner_html ],
							'innerHTML'    => $inner_html,
						],
					],
					'innerContent' => [
						'<div>',
						null,
						'</div>',
					],
					'innerHTML'    => '<div></div>',
				]
			),
			'<mj-section  padding="0"><mj-wrapper padding="0" text-align="center"><mj-section padding="0" text-align="center"><mj-column padding="12px" css-class="mj-column-has-width" width="100%"><mj-button align="center" padding="0" inner-padding="12px 24px" line-height="1.5" href="" border-radius="999px" font-size="16px"  font-weight="bold" color="#fff !important" background-color="#32373c">Test Button</mj-button></mj-column></mj-section></mj-wrapper></mj-section>',
			'Renders center aligned button'
		);

		// Right aligned button.
		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/buttons',
					'attrs'        => [
						'layout' => [
							'justifyContent' => 'right',
						],
					],
					'innerBlocks'  => [
						[
							'blockName'    => 'core/button',
							'attrs'        => [],
							'innerBlocks'  => [],
							'innerContent' => [ $inner_html ],
							'innerHTML'    => $inner_html,
						],
					],
					'innerContent' => [
						'<div>',
						null,
						'</div>',
					],
					'innerHTML'    => '<div></div>',
				]
			),
			'<mj-section  padding="0"><mj-wrapper padding="0" text-align="right"><mj-section padding="0" text-align="right"><mj-column padding="12px" css-class="mj-column-has-width" width="100%"><mj-button align="right" padding="0" inner-padding="12px 24px" line-height="1.5" href="" border-radius="999px" font-size="16px"  font-weight="bold" color="#fff !important" background-color="#32373c">Test Button</mj-button></mj-column></mj-section></mj-wrapper></mj-section>',
			'Renders right aligned button'
		);

		// Multiple buttons.
		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/buttons',
					'attrs'        => [],
					'innerBlocks'  => [
						[
							'blockName'    => 'core/button',
							'attrs'        => [],
							'innerBlocks'  => [],
							'innerContent' => [ $inner_html ],
							'innerHTML'    => $inner_html,
						],
						[
							'blockName'    => 'core/button',
							'attrs'        => [],
							'innerBlocks'  => [],
							'innerContent' => [ $inner_html ],
							'innerHTML'    => $inner_html,
						],
					],
					'innerContent' => [
						'<div>',
						null,
						'</div>',
					],
					'innerHTML'    => '<div></div>',
				]
			),
			'<mj-section padding="0"><mj-wrapper padding="0" text-align="left"><mj-section padding="0" text-align="left"><mj-column padding="12px" css-class="mj-column-has-width" width="50%"><mj-button align="left" padding="0" inner-padding="12px 24px" line-height="1.5" href="" border-radius="999px" font-size="16px"  font-weight="bold" color="#fff !important" background-color="#32373c">Test Button</mj-button></mj-column><mj-column padding="12px" css-class="mj-column-has-width" width="50%"><mj-button align="left" padding="0" inner-padding="12px 24px" line-height="1.5" href="" border-radius="999px" font-size="16px"  font-weight="bold" color="#fff !important" background-color="#32373c">Test Button</mj-button></mj-column></mj-section></mj-wrapper></mj-section>',
			'Renders multiple buttons'
		);

		// Multiple buttons, with widths.
		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/buttons',
					'attrs'        => [],
					'innerBlocks'  => [
						[
							'blockName'    => 'core/button',
							'attrs'        => [ 'width' => '25' ],
							'innerBlocks'  => [],
							'innerContent' => [ $inner_html ],
							'innerHTML'    => $inner_html,
						],
						[
							'blockName'    => 'core/button',
							'attrs'        => [],
							'innerBlocks'  => [],
							'innerContent' => [ $inner_html ],
							'innerHTML'    => $inner_html,
						],
					],
					'innerContent' => [
						'<div>',
						null,
						'</div>',
					],
					'innerHTML'    => '<div></div>',
				]
			),
			'<mj-section padding="0"><mj-wrapper padding="0" text-align="left"><mj-section padding="0" text-align="left"><mj-column padding="12px" css-class="mj-column-has-width" width="25%"><mj-button align="left" padding="0" inner-padding="12px 24px" line-height="1.5" href="" border-radius="999px" font-size="16px"  font-weight="bold" color="#fff !important" background-color="#32373c" width="100%">Test Button</mj-button></mj-column><mj-column padding="12px" css-class="mj-column-has-width" width="75%"><mj-button align="left" padding="0" inner-padding="12px 24px" line-height="1.5" href="" border-radius="999px" font-size="16px"  font-weight="bold" color="#fff !important" background-color="#32373c">Test Button</mj-button></mj-column></mj-section></mj-wrapper></mj-section>',
			'Renders multiple buttons'
		);
	}

	/**
	 * Test social icons rendering.
	 */
	public function test_render_social_icons() {
		$plugin_path = plugin_dir_url( __DIR__ );
		$this->assertEquals(
			Newspack_Newsletters_Renderer::render_mjml_component(
				[
					'blockName'    => 'core/social-links',
					'attrs'        => [],
					'innerBlocks'  => [
						[
							'blockName'    => 'core/social-link',
							'attrs'        => [
								'url'     => 'https://x.com/hi',
								'service' => 'x',
							],
							'innerBlocks'  => [],
							'innerContent' => [],
							'innerHTML'    => '',
						],
					],
					'innerContent' => [
						'<div>',
						null,
						'</div>',
					],
					'innerHTML'    => '<div></div>',
				]
			),
			'<mj-section padding="0"><mj-column padding="12px" width="100%"><mj-social icon-size="24px" mode="horizontal" border-radius="999px" icon-padding="7px" padding="0" align="left"><mj-social-element href="https://x.com/hi" src="' . $plugin_path . 'assets/white-x.png" background-color="#000000" css-class="social-element" padding="8px" padding-left="0" padding-right="8px"/></mj-social></mj-column></mj-section>',
			'Renders social icons'
		);
	}

	/**
	 * Test other rendering-related function.
	 */
	public function test_aux_functions() {
		$this->assertEquals(
			Newspack_Newsletters_Renderer::process_links( '<a href="//newspack.com">linky<a>' ),
			'<a href="//newspack.com?utm_medium=email">linky<a>',
			'Appends utm_medium=email to links'
		);
		$this->assertEquals(
			Newspack_Newsletters_Renderer::process_links( '<a href="//newspack.com?value=1">linky<a>' ),
			'<a href="//newspack.com?value=1&utm_medium=email">linky<a>',
			'Appends utm_medium=email to links with params'
		);
	}

	/**
	 * Test margin conversion to section padding.
	 */
	public function test_render_margin_as_section_padding() {
		// Preset margin values should be converted to px.
		$output = Newspack_Newsletters_Renderer::render_mjml_component(
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [
					'style' => [
						'spacing' => [
							'margin' => [
								'top'    => 'var:preset|spacing|40',
								'bottom' => 'var:preset|spacing|40',
							],
						],
					],
				],
				'innerBlocks'  => [],
				'innerContent' => [ '<p>Test</p>' ],
				'innerHTML'    => '<p>Test</p>',
			]
		);
		$this->assertStringContainsString(
			'mj-section padding="24px 0 24px 0"',
			$output,
			'Preset margins are converted to px section padding'
		);

		// Pixel margin values should pass through.
		$output = Newspack_Newsletters_Renderer::render_mjml_component(
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [
					'style' => [
						'spacing' => [
							'margin' => [
								'top'    => '10px',
								'bottom' => '20px',
								'left'   => '5px',
								'right'  => '5px',
							],
						],
					],
				],
				'innerBlocks'  => [],
				'innerContent' => [ '<p>Test</p>' ],
				'innerHTML'    => '<p>Test</p>',
			]
		);
		$this->assertStringContainsString(
			'mj-section padding="10px 5px 20px 5px"',
			$output,
			'Pixel margins map directly to section padding'
		);

		// Block without margin should have section padding 0.
		$output = Newspack_Newsletters_Renderer::render_mjml_component(
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerContent' => [ '<p>Test</p>' ],
				'innerHTML'    => '<p>Test</p>',
			]
		);
		$this->assertStringContainsString(
			'mj-section padding="0"',
			$output,
			'Block without margin gets section padding 0'
		);
	}

	/**
	 * Rendering a reusable block component.
	 */
	public function test_reusable_block() {
		$reusable_block_post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_title'   => 'Reusable block.',
				'post_content' => "<!-- wp:paragraph -->\n<p>Hello<\/p>\n<!-- \/wp:paragraph -->",
			]
		);
		$newsletter_post        = self::factory()->post->create(
			[
				'post_type'    => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title'   => 'A newsletter with a reusable block in it.',
				'post_content' => '<!-- wp:block {"ref":' . $reusable_block_post_id . '} /-->',
			]
		);
		$this->assertEquals(
			Newspack_Newsletters_Renderer::post_to_mjml_components(
				get_post( $newsletter_post )
			),
			"<mj-wrapper ref=\"$reusable_block_post_id\"><mj-section padding=\"0\"><mj-column padding=\"12px\" width=\"100%\"><mj-text padding=\"0\" line-height=\"1.5\" font-size=\"16px\" >\n<p>Hello</p>\n</mj-text></mj-column></mj-section></mj-wrapper>",
			'Renders the reusable block into valid markup'
		);
	}

	/**
	 * Rendering with custom CSS.
	 */
	public function test_custom_css() {
		$custom_css_str  = 'p { color: pink; }';
		$newsletter_post = self::factory()->post->create(
			[
				'post_type'    => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title'   => 'A newsletter with custom CSS.',
				'post_content' => "<!-- wp:paragraph -->\n<p>A paragraph with some custom CSS applied.<\/p>\n<!-- \/wp:paragraph -->",
			]
		);
		$post_object     = get_post( $newsletter_post );

		// Add the custom CSS.
		update_post_meta( $post_object->ID, 'custom_css', $custom_css_str );

		$this->assertStringContainsString(
			$custom_css_str,
			Newspack_Newsletters_Renderer::render_post_to_mjml( $post_object ),
			'Rendered email contains the custom CSS.'
		);
	}

	/**
	 * Ensure variables passed to the MJML template are escaped.
	 */
	public function test_render_post_to_mjml_escapes_template_variables() {
		$unsafe_title            = 'Unsafe title with "quotes" & unencoded entities';
		$unsafe_background_color = '#fff" onmouseover="alert(2)';
		$unsafe_text_color       = "#000' onfocus='alert(3)";
		$unsafe_preview_text     = '<em>Preview</em> <script>alert("preview")</script>';
		$unsafe_custom_css       = 'p:before { content: "<svg>"; } <script>alert("css")</script>';

		$newsletter_post = self::factory()->post->create(
			[
				'post_type'    => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_title'   => $unsafe_title,
				'post_content' => "<!-- wp:paragraph -->\n<p>Content<\/p>\n<!-- \/wp:paragraph -->",
			]
		);
		$post            = get_post( $newsletter_post );

		update_post_meta( $post->ID, 'background_color', $unsafe_background_color );
		update_post_meta( $post->ID, 'text_color', $unsafe_text_color );
		update_post_meta( $post->ID, 'preview_text', $unsafe_preview_text );
		update_post_meta( $post->ID, 'custom_css', $unsafe_custom_css );

		$rendered_output = Newspack_Newsletters_Renderer::render_post_to_mjml( $post );

		$this->assertStringContainsString(
			'<mj-title>' . esc_html( $unsafe_title ) . '</mj-title>',
			$rendered_output,
			'Newsletter title should be HTML escaped.'
		);
		$this->assertStringNotContainsString(
			'<mj-title>' . $unsafe_title . '</mj-title>',
			$rendered_output,
			'Newsletter title should not appear unescaped.'
		);

		$this->assertStringContainsString(
			'background-color="' . esc_attr( $unsafe_background_color ) . '"',
			$rendered_output,
			'Background color should be escaped before rendering.'
		);
		$this->assertStringNotContainsString(
			'background-color="' . $unsafe_background_color . '"',
			$rendered_output,
			'Background color should not appear unescaped.'
		);

		$this->assertStringContainsString(
			'color="' . esc_attr( $unsafe_text_color ) . '"',
			$rendered_output,
			'Text color should be escaped before rendering.'
		);
		$this->assertStringNotContainsString(
			'color="' . $unsafe_text_color . '"',
			$rendered_output,
			'Text color should not appear unescaped.'
		);

		$this->assertStringContainsString(
			'<mj-preview>' . esc_html( $unsafe_preview_text ) . '</mj-preview>',
			$rendered_output,
			'Preview text should be escaped before rendering.'
		);
		$this->assertStringNotContainsString(
			'<mj-preview>' . $unsafe_preview_text . '</mj-preview>',
			$rendered_output,
			'Preview text should not appear unescaped.'
		);

		$this->assertStringContainsString(
			esc_html( '<script>alert("css")</script>' ),
			$rendered_output,
			'Custom CSS should be escaped before rendering.'
		);
		$this->assertStringNotContainsString(
			'<script>alert("css")</script>',
			$rendered_output,
			'Custom CSS should not appear unescaped.'
		);
	}

	/**
	 * Test removing unwanted style properties from an HTML string.
	 */
	public function test_remove_unwanted_style_properties() {
		$html = '<div style="border-bottom:1px solid #000;padding-bottom:0;font-size:12px;padding:1em;border:none">';
		$this->assertEquals(
			Newspack_Newsletters_Renderer::remove_unwanted_style_properties( [ 'padding', 'border' ], $html ),
			'<div style="font-size:12px;">',
			'Removes unwanted style properties from an HTML string while leaving other style properties intact.'
		);

		$html = '<p class="has-text-align-center has-primary-variation-color has-light-gray-background-color has-text-color has-background has-normal-font-size" style="padding-bottom:0;padding:1em">The word "padding" outside of style attribute</p>';
		$this->assertEquals(
			Newspack_Newsletters_Renderer::remove_unwanted_style_properties( [ 'padding' ], $html ),
			'<p class="has-text-align-center has-primary-variation-color has-light-gray-background-color has-text-color has-background has-normal-font-size" style="">The word "padding" outside of style attribute</p>',
			'Should not match the word "padding" outside of any style attributes.'
		);

		$html = '<p class="has-text-align-center has-primary-variation-color has-light-gray-background-color has-text-color has-background has-normal-font-size" style="border-bottom:1px solid #000;padding-bottom:0;font-size:12px;padding:1em;border:none">First paragraph with the word "padding" outside of style attribute</p><p class="has-text-align-center has-primary-variation-color has-light-gray-background-color has-text-color has-background has-normal-font-size" style="padding-bottom:0;padding:1em">Second paragraph with the word "padding" outside of style attribute</p>';
		$this->assertEquals(
			Newspack_Newsletters_Renderer::remove_unwanted_style_properties( [ 'padding', 'border' ], $html ),
			'<p class="has-text-align-center has-primary-variation-color has-light-gray-background-color has-text-color has-background has-normal-font-size" style="font-size:12px;">First paragraph with the word "padding" outside of style attribute</p><p class="has-text-align-center has-primary-variation-color has-light-gray-background-color has-text-color has-background has-normal-font-size" style="">Second paragraph with the word "padding" outside of style attribute</p>',
			'Works with multiple style attributes in the same HTML string.'
		);
	}

	/**
	 * Test RDB block rendering with resolved bindings for a single result.
	 *
	 * @covers Newspack_Newsletters_Renderer::render_mjml_component
	 * @covers Newspack_Newsletters_Renderer::get_rdb_context
	 * @covers Newspack_Newsletters_Renderer::clone_blocks_with_index
	 * @covers Newspack_Newsletters_Renderer::resolve_rdb_block_bindings
	 * @covers Newspack_Newsletters_Renderer::update_block_inner_html
	 * @covers Newspack_Newsletters_Renderer::reconstruct_paragraph_html
	 */
	public function test_render_rdb_block_with_single_result() {
		// Reset the mock.
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::reset();
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::set_stub_values(
			[
				'content' => 'Resolved Event Title',
			]
		);

		$block = [
			'blockName'    => 'remote-data-blocks/foundation-event',
			'attrs'        => [
				'remoteData' => [
					'blockName'        => 'remote-data-blocks/foundation-event',
					'results'          => [ [ 'title' => 'Event 1' ] ],
					'enabledOverrides' => [],
					'queryInputs'      => [],
				],
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [
						'metadata' => [
							'bindings' => [
								'content' => [
									'source' => 'remote-data/binding',
									'args'   => [
										'field' => 'title',
									],
								],
							],
						],
					],
					'innerBlocks'  => [],
					'innerContent' => [ '<p>Placeholder</p>' ],
					'innerHTML'    => '<p>Placeholder</p>',
				],
			],
			'innerContent' => [ '<div>', null, '</div>' ],
			'innerHTML'    => '<div></div>',
		];

		$result = Newspack_Newsletters_Renderer::render_mjml_component( $block );

		// Verify that BlockBindings::get_value() was called.
		$calls = \RemoteDataBlocks\Editor\DataBinding\BlockBindings::$calls;
		$this->assertNotEmpty( $calls, 'BlockBindings::get_value() should have been called' );
		$this->assertEquals( 'content', $calls[0]['attribute_name'], 'Should resolve content binding' );

		// Verify the resolved content appears in the output.
		$this->assertStringContainsString(
			'Resolved Event Title',
			$result,
			'Output should contain the resolved binding value'
		);

		// Verify the output contains mj-text (from MJML pipeline).
		$this->assertStringContainsString(
			'<mj-text',
			$result,
			'Output should be rendered through the MJML pipeline'
		);
	}

	/**
	 * Test RDB block rendering with multiple results (template looping).
	 *
	 * @covers Newspack_Newsletters_Renderer::render_mjml_component
	 * @covers Newspack_Newsletters_Renderer::get_rdb_context
	 * @covers Newspack_Newsletters_Renderer::expand_rdb_template_blocks
	 * @covers Newspack_Newsletters_Renderer::clone_blocks_with_index
	 * @covers Newspack_Newsletters_Renderer::resolve_rdb_block_bindings
	 * @covers Newspack_Newsletters_Renderer::update_block_inner_html
	 * @covers Newspack_Newsletters_Renderer::reconstruct_paragraph_html
	 */
	public function test_render_rdb_block_with_multiple_results() {
		// Reset the mock.
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::reset();
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::set_stub_values(
			[
				'content' => 'Event Title',
			]
		);

		$block = [
			'blockName'    => 'remote-data-blocks/template',
			'attrs'        => [
				'remoteData' => [
					'blockName'        => 'remote-data-blocks/template',
					'results'          => [
						[ 'title' => 'Event 1' ],
						[ 'title' => 'Event 2' ],
						[ 'title' => 'Event 3' ],
					],
					'enabledOverrides' => [],
					'queryInputs'      => [],
				],
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [
						'metadata' => [
							'bindings' => [
								'content' => [
									'source' => 'remote-data/binding',
									'args'   => [
										'field' => 'title',
									],
								],
							],
						],
					],
					'innerBlocks'  => [],
					'innerContent' => [ '<p>Placeholder</p>' ],
					'innerHTML'    => '<p>Placeholder</p>',
				],
			],
			'innerContent' => [ '<div>', null, '</div>' ],
			'innerHTML'    => '<div></div>',
		];

		$result = Newspack_Newsletters_Renderer::render_mjml_component( $block );

		// Verify that BlockBindings::get_value() was called for each result.
		$calls = \RemoteDataBlocks\Editor\DataBinding\BlockBindings::$calls;
		$this->assertCount( 3, $calls, 'BlockBindings::get_value() should be called once per result' );

		// Verify each call has the correct index.
		$this->assertEquals( 0, $calls[0]['source_args']['index'], 'First call should have index 0' );
		$this->assertEquals( 1, $calls[1]['source_args']['index'], 'Second call should have index 1' );
		$this->assertEquals( 2, $calls[2]['source_args']['index'], 'Third call should have index 2' );

		// Verify the output contains multiple mj-text elements (one per result).
		$this->assertEquals(
			3,
			substr_count( $result, '<mj-text' ),
			'Output should contain 3 mj-text elements for 3 results'
		);
	}

	/**
	 * Test RDB blocks are not wrapped in extra section/column elements.
	 *
	 * @covers Newspack_Newsletters_Renderer::render_mjml_component
	 */
	public function test_rdb_block_no_extra_wrapping() {
		// Reset the mock.
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::reset();
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::set_stub_values(
			[
				'content' => 'Test Content',
			]
		);

		$block = [
			'blockName'    => 'remote-data-blocks/foundation-event',
			'attrs'        => [
				'remoteData' => [
					'blockName'        => 'remote-data-blocks/foundation-event',
					'results'          => [ [ 'title' => 'Event' ] ],
					'enabledOverrides' => [],
					'queryInputs'      => [],
				],
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [
						'metadata' => [
							'bindings' => [
								'content' => [
									'source' => 'remote-data/binding',
									'args'   => [ 'field' => 'title' ],
								],
							],
						],
					],
					'innerBlocks'  => [],
					'innerContent' => [ '<p>Placeholder</p>' ],
					'innerHTML'    => '<p>Placeholder</p>',
				],
			],
			'innerContent' => [ '<div>', null, '</div>' ],
			'innerHTML'    => '<div></div>',
		];

		$result = Newspack_Newsletters_Renderer::render_mjml_component( $block );

		// The inner paragraph should have its own section/column wrapping from render_mjml_component.
		// But the RDB container block itself should NOT add additional wrapping.
		// Count mj-section occurrences - should be 1 (from the inner paragraph), not 2.
		$section_count = substr_count( $result, '<mj-section' );
		$this->assertEquals(
			1,
			$section_count,
			'RDB block should not add extra section wrapping beyond what inner blocks need'
		);
	}

	/**
	 * Test RDB block with image binding.
	 *
	 * @covers Newspack_Newsletters_Renderer::render_mjml_component
	 * @covers Newspack_Newsletters_Renderer::resolve_rdb_block_bindings
	 * @covers Newspack_Newsletters_Renderer::update_block_inner_html
	 * @covers Newspack_Newsletters_Renderer::update_src_in_html
	 * @covers Newspack_Newsletters_Renderer::update_alt_in_html
	 */
	public function test_render_rdb_block_with_image_binding() {
		// Reset the mock.
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::reset();
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::set_stub_values(
			[
				'url' => 'https://example.com/image.jpg',
				'alt' => 'Event Image',
			]
		);

		$block = [
			'blockName'    => 'remote-data-blocks/foundation-event',
			'attrs'        => [
				'remoteData' => [
					'blockName'        => 'remote-data-blocks/foundation-event',
					'results'          => [ [ 'image' => 'https://example.com/image.jpg' ] ],
					'enabledOverrides' => [],
					'queryInputs'      => [],
				],
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/image',
					'attrs'        => [
						'metadata' => [
							'bindings' => [
								'url' => [
									'source' => 'remote-data/binding',
									'args'   => [ 'field' => 'image_url' ],
								],
								'alt' => [
									'source' => 'remote-data/binding',
									'args'   => [ 'field' => 'image_alt' ],
								],
							],
						],
					],
					'innerBlocks'  => [],
					'innerContent' => [ '<figure class="wp-block-image"><img src="placeholder.jpg" alt=""/></figure>' ],
					'innerHTML'    => '<figure class="wp-block-image"><img src="placeholder.jpg" alt=""/></figure>',
				],
			],
			'innerContent' => [ '<div>', null, '</div>' ],
			'innerHTML'    => '<div></div>',
		];

		$result = Newspack_Newsletters_Renderer::render_mjml_component( $block );

		// Verify the resolved image URL appears in the output.
		$this->assertStringContainsString(
			'https://example.com/image.jpg',
			$result,
			'Output should contain the resolved image URL'
		);

		// Verify the resolved alt text appears in the output.
		$this->assertStringContainsString(
			'Event Image',
			$result,
			'Output should contain the resolved alt text'
		);

		// Verify the output contains mj-image.
		$this->assertStringContainsString(
			'<mj-image',
			$result,
			'Output should contain mj-image element'
		);
	}

	/**
	 * Test RDB block with heading binding.
	 *
	 * @covers Newspack_Newsletters_Renderer::render_mjml_component
	 * @covers Newspack_Newsletters_Renderer::resolve_rdb_block_bindings
	 * @covers Newspack_Newsletters_Renderer::update_block_inner_html
	 * @covers Newspack_Newsletters_Renderer::reconstruct_heading_html
	 * @covers Newspack_Newsletters_Renderer::cleanup_rdb_html
	 */
	public function test_render_rdb_block_with_heading_binding() {
		// Reset the mock.
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::reset();
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::set_stub_values(
			[
				'content' => 'Resolved Heading Text',
			]
		);

		$block = [
			'blockName'    => 'remote-data-blocks/foundation-event',
			'attrs'        => [
				'remoteData' => [
					'blockName'        => 'remote-data-blocks/foundation-event',
					'results'          => [ [ 'title' => 'Event' ] ],
					'enabledOverrides' => [],
					'queryInputs'      => [],
				],
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/heading',
					'attrs'        => [
						'level'    => 2,
						'metadata' => [
							'bindings' => [
								'content' => [
									'source' => 'remote-data/binding',
									'args'   => [ 'field' => 'title' ],
								],
							],
						],
					],
					'innerBlocks'  => [],
					'innerContent' => [ '<h2>Placeholder</h2>' ],
					'innerHTML'    => '<h2>Placeholder</h2>',
				],
			],
			'innerContent' => [ '<div>', null, '</div>' ],
			'innerHTML'    => '<div></div>',
		];

		$result = Newspack_Newsletters_Renderer::render_mjml_component( $block );

		// Verify the resolved heading content appears in the output.
		$this->assertStringContainsString(
			'Resolved Heading Text',
			$result,
			'Output should contain the resolved heading text'
		);

		// Verify the output contains h2 tag.
		$this->assertStringContainsString(
			'<h2>',
			$result,
			'Output should preserve the h2 heading tag'
		);
	}

	/**
	 * Test that RDB blocks with no inner blocks don't call BlockBindings.
	 *
	 * @covers Newspack_Newsletters_Renderer::render_mjml_component
	 * @covers Newspack_Newsletters_Renderer::get_rdb_context
	 * @covers Newspack_Newsletters_Renderer::clone_blocks_with_index
	 */
	public function test_rdb_block_with_no_inner_blocks() {
		// Block without inner blocks (no bindings to resolve).
		$block = [
			'blockName'    => 'remote-data-blocks/foundation-event',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerContent' => [ '<div><p>Static content</p></div>' ],
			'innerHTML'    => '<div><p>Static content</p></div>',
		];

		$result = Newspack_Newsletters_Renderer::render_mjml_component( $block );

		// Verify BlockBindings::get_value() was NOT called since there are no inner blocks.
		$calls = \RemoteDataBlocks\Editor\DataBinding\BlockBindings::$calls;
		$this->assertEmpty( $calls, 'BlockBindings::get_value() should not be called when there are no inner blocks' );
	}

	/**
	 * Test RDB block rendering with nested inner blocks (columns structure).
	 *
	 * @covers Newspack_Newsletters_Renderer::render_mjml_component
	 * @covers Newspack_Newsletters_Renderer::resolve_rdb_block_bindings
	 * @covers Newspack_Newsletters_Renderer::update_block_inner_html
	 * @covers Newspack_Newsletters_Renderer::reconstruct_paragraph_html
	 * @covers Newspack_Newsletters_Renderer::update_src_in_html
	 */
	public function test_render_rdb_block_with_nested_structure() {
		// Reset the mock.
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::reset();
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::set_stub_values(
			[
				'content' => 'Nested Content',
				'url'     => 'https://example.com/nested.jpg',
			]
		);

		$block = [
			'blockName'    => 'remote-data-blocks/foundation-event',
			'attrs'        => [
				'remoteData' => [
					'blockName'        => 'remote-data-blocks/foundation-event',
					'results'          => [ [ 'title' => 'Event' ] ],
					'enabledOverrides' => [],
					'queryInputs'      => [],
				],
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/columns',
					'attrs'        => [],
					'innerBlocks'  => [
						[
							'blockName'    => 'core/column',
							'attrs'        => [ 'width' => '50%' ],
							'innerBlocks'  => [
								[
									'blockName'    => 'core/paragraph',
									'attrs'        => [
										'metadata' => [
											'bindings' => [
												'content' => [
													'source' => 'remote-data/binding',
													'args' => [ 'field' => 'description' ],
												],
											],
										],
									],
									'innerBlocks'  => [],
									'innerContent' => [ '<p>Placeholder</p>' ],
									'innerHTML'    => '<p>Placeholder</p>',
								],
							],
							'innerContent' => [ '<div class="wp-block-column">', null, '</div>' ],
							'innerHTML'    => '<div class="wp-block-column"></div>',
						],
						[
							'blockName'    => 'core/column',
							'attrs'        => [ 'width' => '50%' ],
							'innerBlocks'  => [
								[
									'blockName'    => 'core/image',
									'attrs'        => [
										'metadata' => [
											'bindings' => [
												'url' => [
													'source' => 'remote-data/binding',
													'args' => [ 'field' => 'image' ],
												],
											],
										],
									],
									'innerBlocks'  => [],
									'innerContent' => [ '<figure><img src="placeholder.jpg"/></figure>' ],
									'innerHTML'    => '<figure><img src="placeholder.jpg"/></figure>',
								],
							],
							'innerContent' => [ '<div class="wp-block-column">', null, '</div>' ],
							'innerHTML'    => '<div class="wp-block-column"></div>',
						],
					],
					'innerContent' => [ '<div class="wp-block-columns">', null, null, '</div>' ],
					'innerHTML'    => '<div class="wp-block-columns"></div>',
				],
			],
			'innerContent' => [ '<div>', null, '</div>' ],
			'innerHTML'    => '<div></div>',
		];

		$result = Newspack_Newsletters_Renderer::render_mjml_component( $block );

		// Verify both bindings were resolved.
		$calls = \RemoteDataBlocks\Editor\DataBinding\BlockBindings::$calls;
		$this->assertCount( 2, $calls, 'BlockBindings::get_value() should be called for both bindings' );

		// Verify the output contains mj-column elements.
		$this->assertStringContainsString(
			'<mj-column',
			$result,
			'Output should contain mj-column elements for columns layout'
		);

		// Verify the resolved content appears.
		$this->assertStringContainsString(
			'Nested Content',
			$result,
			'Output should contain the resolved paragraph content'
		);

		$this->assertStringContainsString(
			'https://example.com/nested.jpg',
			$result,
			'Output should contain the resolved image URL'
		);
	}

	/**
	 * Test RDB block with button binding (url and text).
	 *
	 * @covers Newspack_Newsletters_Renderer::render_mjml_component
	 * @covers Newspack_Newsletters_Renderer::resolve_rdb_block_bindings
	 * @covers Newspack_Newsletters_Renderer::update_block_inner_html
	 * @covers Newspack_Newsletters_Renderer::update_href_in_html
	 * @covers Newspack_Newsletters_Renderer::update_link_text_in_html
	 */
	public function test_render_rdb_block_with_button_binding() {
		// Reset the mock.
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::reset();
		\RemoteDataBlocks\Editor\DataBinding\BlockBindings::set_stub_values(
			[
				'url'  => 'https://example.com/event-link',
				'text' => 'Register Now',
			]
		);

		$block = [
			'blockName'    => 'remote-data-blocks/foundation-event',
			'attrs'        => [
				'remoteData' => [
					'blockName'        => 'remote-data-blocks/foundation-event',
					'results'          => [ [ 'link' => 'https://example.com/event-link' ] ],
					'enabledOverrides' => [],
					'queryInputs'      => [],
				],
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/buttons',
					'attrs'        => [],
					'innerBlocks'  => [
						[
							'blockName'    => 'core/button',
							'attrs'        => [
								'metadata' => [
									'bindings' => [
										'url'  => [
											'source' => 'remote-data/binding',
											'args'   => [ 'field' => 'event_url' ],
										],
										'text' => [
											'source' => 'remote-data/binding',
											'args'   => [ 'field' => 'event_cta' ],
										],
									],
								],
							],
							'innerBlocks'  => [],
							'innerContent' => [ '<div class="wp-block-button"><a class="wp-block-button__link" href="https://placeholder.com">Placeholder</a></div>' ],
							'innerHTML'    => '<div class="wp-block-button"><a class="wp-block-button__link" href="https://placeholder.com">Placeholder</a></div>',
						],
					],
					'innerContent' => [ '<div class="wp-block-buttons">', null, '</div>' ],
					'innerHTML'    => '<div class="wp-block-buttons"></div>',
				],
			],
			'innerContent' => [ '<div>', null, '</div>' ],
			'innerHTML'    => '<div></div>',
		];

		$result = Newspack_Newsletters_Renderer::render_mjml_component( $block );

		// Verify the resolved URL appears in the output.
		$this->assertStringContainsString(
			'https://example.com/event-link',
			$result,
			'Output should contain the resolved button URL'
		);

		// Verify the resolved button text appears in the output.
		$this->assertStringContainsString(
			'Register Now',
			$result,
			'Output should contain the resolved button text'
		);

		// Verify the output contains mj-button.
		$this->assertStringContainsString(
			'<mj-button',
			$result,
			'Output should contain mj-button element'
		);
	}
}
