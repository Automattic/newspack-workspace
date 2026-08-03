<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Contextual Prompt Styles Test
 *
 * Covers the wizard-set default styles for the Contextual Prompt block:
 * allowlist sanitization, style-engine CSS output, and the editor delivery
 * path. The CSS must sit at :root :where() specificity so the block's
 * theme.json default design loses by order and per-block styles still win.
 * Publisher-written custom CSS rides along in its own option, validated and
 * scoped to the block.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt styles test case.
 */
class ContextualPromptStylesTest extends WP_UnitTestCase {
	/**
	 * The global text color add_global_text_color() declares.
	 *
	 * @var string
	 */
	private static $global_text_color = '';

	/**
	 * The styles class is inert without the admin opt-in.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		delete_option( Newspack_Popups_Contextual_Prompt_Styles::OPTION_NAME );
		delete_option( Newspack_Popups_Contextual_Prompt_Styles::CUSTOM_CSS_OPTION_NAME );
	}

	/**
	 * A test's theme.json data is cached for the whole request, so drop it.
	 */
	public function tear_down() {
		remove_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'add_global_text_color' ] );
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * Give the site a global text color for the rest of the test.
	 *
	 * @param string $color CSS color value.
	 */
	private function set_global_text_color( $color ) {
		self::$global_text_color = $color;
		remove_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'add_global_text_color' ] );
		add_filter( 'wp_theme_json_data_theme', [ __CLASS__, 'add_global_text_color' ] );
		wp_clean_theme_json_cache();
	}

	/**
	 * Declare a global text color in the theme's theme.json data.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function add_global_text_color( $theme_json ) {
		return $theme_json->update_with(
			[
				'version' => 2,
				'styles'  => [ 'color' => [ 'text' => self::$global_text_color ] ],
			]
		);
	}

	/**
	 * Unknown properties are stripped; known ones survive.
	 */
	public function test_sanitize_allowlist() {
		$sanitized = Newspack_Popups_Contextual_Prompt_Styles::sanitize(
			[
				'color'      => [
					'background' => '#123456',
					'text'       => 'var:preset|color|accent',
					'gradient'   => 'linear-gradient(red, blue)',
				],
				'typography' => [
					'fontSize'   => '18px',
					'fontFamily' => 'serif',
				],
				'spacing'    => [
					'padding' => [
						'top'    => '10px',
						'right'  => '20px',
						'bottom' => '10px',
						'left'   => '20px',
						'inline' => '5px',
					],
					'margin'  => [ 'top' => '10px' ],
				],
				'border'     => [
					'color'  => '#00ff00',
					'width'  => '2px',
					'style'  => 'dashed',
					'radius' => [
						'topLeft'     => '4px',
						'topRight'    => '4px',
						'bottomLeft'  => '4px',
						'bottomRight' => '4px',
					],
				],
				'evil'       => [ 'anything' => 'x' ],
			]
		);

		$this->assertSame( '#123456', $sanitized['color']['background'] );
		$this->assertSame( 'var:preset|color|accent', $sanitized['color']['text'] );
		$this->assertArrayNotHasKey( 'gradient', $sanitized['color'] );
		$this->assertSame( '18px', $sanitized['typography']['fontSize'] );
		$this->assertArrayNotHasKey( 'fontFamily', $sanitized['typography'] );
		$this->assertArrayNotHasKey( 'inline', $sanitized['spacing']['padding'] );
		$this->assertArrayNotHasKey( 'margin', $sanitized['spacing'] );
		$this->assertSame( '4px', $sanitized['border']['radius']['topLeft'] );
		$this->assertArrayNotHasKey( 'evil', $sanitized );
	}

	/**
	 * Per-side border objects are allowed with the same leaf allowlist.
	 */
	public function test_sanitize_split_borders() {
		$sanitized = Newspack_Popups_Contextual_Prompt_Styles::sanitize(
			[
				'border' => [
					'top'    => [
						'color' => '#111111',
						'width' => '1px',
						'style' => 'solid',
						'evil'  => 'x',
					],
					'radius' => '6px',
				],
			]
		);

		$this->assertSame( '1px', $sanitized['border']['top']['width'] );
		$this->assertArrayNotHasKey( 'evil', $sanitized['border']['top'] );
		$this->assertSame( '6px', $sanitized['border']['radius'] );
	}

	/**
	 * A string where the schema expects an object is dropped: only the paths in
	 * SHORTHAND_PATHS (border.radius) take a shorthand, so nothing can smuggle a
	 * `padding` or `border-top` declaration past the leaf allowlist.
	 */
	public function test_sanitize_rejects_unlisted_shorthands() {
		$sanitized = Newspack_Popups_Contextual_Prompt_Styles::sanitize(
			[
				'spacing' => [ 'padding' => '10px' ],
				'border'  => [ 'top' => '1px' ],
			]
		);

		$this->assertSame( [], $sanitized );
	}

	/**
	 * Withdrawing the admin opt-in stops the CSS shipping: init registers no
	 * output hooks at all.
	 */
	public function test_init_requires_the_opt_in() {
		if ( wp_is_block_theme() ) {
			$this->markTestSkipped( 'Block themes keep the class inert regardless of the opt-in.' );
		}
		$callback = [ 'Newspack_Popups_Contextual_Prompt_Styles', 'enqueue_front_end_styles' ];
		remove_action( 'wp_footer', $callback, 1 );

		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );
		Newspack_Popups_Contextual_Prompt_Styles::init();
		$this->assertFalse( has_action( 'wp_footer', $callback ) );

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		Newspack_Popups_Contextual_Prompt_Styles::init();
		$this->assertSame( 1, has_action( 'wp_footer', $callback ) );
	}

	/**
	 * Values that could break out of a declaration are rejected wholesale.
	 */
	public function test_sanitize_rejects_css_injection() {
		$sanitized = Newspack_Popups_Contextual_Prompt_Styles::sanitize(
			[
				'color' => [
					'background' => 'red;}body{display:none',
					'text'       => "#fff\0",
				],
			]
		);

		$this->assertSame( [], $sanitized );
	}

	/**
	 * Function-shaped values are rejected even though every character they use
	 * is individually allowlisted: `url()` and `expression()` could smuggle a
	 * scheme into the declaration.
	 */
	public function test_sanitize_rejects_function_shaped_values() {
		$sanitized = Newspack_Popups_Contextual_Prompt_Styles::sanitize(
			[
				'color'      => [
					'background' => 'url(javascript:alert(1))',
					'text'       => ' EXPRESSION(alert(1))',
				],
				// Nested inside another set of parentheses, so the shape is not
				// at the start of the value.
				'typography' => [ 'fontSize' => '(url(javascript:alert(1)))' ],
			]
		);

		$this->assertSame( [], $sanitized );
	}

	/**
	 * CSS output: style engine declarations inside the zero-plus-root wrapper,
	 * preset refs converted to CSS custom property lookups.
	 */
	public function test_get_css() {
		Newspack_Popups_Contextual_Prompt_Styles::save_styles(
			[
				'color'   => [ 'background' => 'var:preset|color|accent' ],
				'spacing' => [ 'padding' => [ 'top' => '11px' ] ],
				'border'  => [ 'radius' => '9px' ],
			]
		);
		$css = Newspack_Popups_Contextual_Prompt_Styles::get_css();

		$this->assertStringStartsWith( ':root :where(.wp-block-newspack-popups-contextual-prompt){', $css );
		$this->assertStringContainsString( 'background-color:var(--wp--preset--color--accent)', $css );
		$this->assertStringContainsString( 'padding-top:11px', $css );
		$this->assertStringContainsString( 'border-radius:9px', $css );
	}

	/**
	 * The padding rows store a spacing preset reference, or a plain zero for the
	 * scale's first step. Both survive the allowlist and reach the style engine,
	 * which turns the reference into the preset's custom property.
	 */
	public function test_spacing_presets_survive_into_the_css() {
		Newspack_Popups_Contextual_Prompt_Styles::save_styles(
			[
				'spacing' => [
					'padding' => [
						'top'    => 'var:preset|spacing|50',
						'bottom' => 'var:preset|spacing|50',
						'left'   => '0',
					],
				],
			]
		);
		$stored = Newspack_Popups_Contextual_Prompt_Styles::get_styles();

		$this->assertSame( 'var:preset|spacing|50', $stored['spacing']['padding']['top'] );
		$this->assertSame( '0', $stored['spacing']['padding']['left'] );

		$css = Newspack_Popups_Contextual_Prompt_Styles::get_css();

		$this->assertStringContainsString( 'padding-top:var(--wp--preset--spacing--50)', $css );
		$this->assertStringContainsString( 'padding-left:0', $css );
	}

	/**
	 * No saved styles means no CSS and no editor payload.
	 */
	public function test_empty_option_produces_nothing() {
		$this->assertSame( '', Newspack_Popups_Contextual_Prompt_Styles::get_css() );

		$settings = Newspack_Popups_Contextual_Prompt_Styles::filter_block_editor_settings( [ 'styles' => [] ] );
		$this->assertSame( [], $settings['styles'] );
	}

	/**
	 * Saving an empty object clears the option entirely.
	 */
	public function test_save_empty_deletes_option() {
		Newspack_Popups_Contextual_Prompt_Styles::save_styles( [ 'color' => [ 'background' => '#123456' ] ] );
		$this->assertNotEmpty( get_option( Newspack_Popups_Contextual_Prompt_Styles::OPTION_NAME ) );

		$this->assertTrue( Newspack_Popups_Contextual_Prompt_Styles::save_styles( [] ) );
		$this->assertFalse( get_option( Newspack_Popups_Contextual_Prompt_Styles::OPTION_NAME ) );
	}

	/**
	 * An explicitly empty payload is the only reset: a non-empty payload that
	 * sanitizes to nothing is rejected and leaves the saved styles standing.
	 */
	public function test_save_rejects_a_payload_with_no_valid_styles() {
		Newspack_Popups_Contextual_Prompt_Styles::save_styles( [ 'color' => [ 'background' => '#123456' ] ] );

		$result = Newspack_Popups_Contextual_Prompt_Styles::save_styles( [ 'evil' => [ 'x' => 'y' ] ] );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame(
			[ 'color' => [ 'background' => '#123456' ] ],
			Newspack_Popups_Contextual_Prompt_Styles::get_styles()
		);
	}

	/**
	 * Editor delivery: the CSS is appended AFTER whatever core put in settings.styles,
	 * so it wins the cascade against the default-layer design at equal specificity.
	 */
	public function test_editor_settings_append() {
		Newspack_Popups_Contextual_Prompt_Styles::save_styles( [ 'color' => [ 'text' => '#fedcba' ] ] );

		$settings = Newspack_Popups_Contextual_Prompt_Styles::filter_block_editor_settings(
			[ 'styles' => [ [ 'css' => 'body{}' ] ] ]
		);

		$this->assertCount( 2, $settings['styles'] );
		$this->assertStringContainsString( 'color:#fedcba', end( $settings['styles'] )['css'] );
	}

	/**
	 * Defaults surface the block's theme.json default design, presets resolved.
	 */
	public function test_get_defaults_resolves_block_design() {
		$defaults = Newspack_Popups_Contextual_Prompt_Styles::get_defaults();

		$this->assertSame( '#f7f7f7', $defaults['color']['background'] );
		$this->assertSame( '10px', $defaults['border']['radius'] );
		// Preset spacing and font size must come back resolved, not as var:preset
		// references: the wizard's controls read concrete CSS values.
		$this->assertStringNotContainsString( 'var:preset', (string) $defaults['spacing']['padding']['top'] );
		$this->assertNotEmpty( $defaults['typography']['fontSize'] );
		$this->assertStringNotContainsString( 'var:', (string) $defaults['typography']['fontSize'] );
	}

	/**
	 * The block node carries no text color, so defaults stand in the color the
	 * prompt inherits: without it the wizard has nothing to check a chosen
	 * background against.
	 */
	public function test_get_defaults_fills_in_the_inherited_text_color() {
		$block_node = wp_get_global_styles(
			[ 'blocks', Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ],
			[ 'transforms' => [ 'resolve-variables' ] ]
		);
		$this->assertArrayNotHasKey( 'text', $block_node['color'] );

		$defaults = Newspack_Popups_Contextual_Prompt_Styles::get_defaults();
		// This theme sets no global text color either, and is not the Newspack
		// theme, so the fallback is the CSS initial color.
		$this->assertSame( '#000000', $defaults['color']['text'] );
	}

	/**
	 * On the classic Newspack theme the assumed color is the one its stylesheet
	 * renders body text with, not the CSS initial black: the theme declares it in
	 * CSS, where the global styles data cannot see it.
	 */
	public function test_get_defaults_assumes_the_newspack_theme_text_color() {
		$original = get_option( 'template' );
		update_option( 'template', Newspack_Popups_Contextual_Prompt_Styles::NEWSPACK_THEME_TEMPLATE );
		$text = Newspack_Popups_Contextual_Prompt_Styles::get_defaults()['color']['text'];
		update_option( 'template', $original );
		wp_clean_theme_json_cache();

		$this->assertSame( '#111111', $text );
	}

	/**
	 * Another classic theme supplies its own body text color through the filter.
	 */
	public function test_get_defaults_lets_a_filter_set_the_assumed_text_color() {
		add_filter( 'newspack_popups_contextual_prompt_inherited_text_color', [ __CLASS__, 'return_custom_text_color' ] );
		$text = Newspack_Popups_Contextual_Prompt_Styles::get_defaults()['color']['text'];
		remove_filter( 'newspack_popups_contextual_prompt_inherited_text_color', [ __CLASS__, 'return_custom_text_color' ] );

		$this->assertSame( '#333333', $text );
	}

	/**
	 * A theme's own body text color, for the filter test.
	 *
	 * @return string
	 */
	public static function return_custom_text_color() {
		return '#333333';
	}

	/**
	 * An `rgb()`/`rgba()` global text color is handed over as hex, alpha dropped:
	 * the wizard's contrast check reads hex.
	 */
	public function test_get_defaults_converts_an_rgb_inherited_text_color() {
		$this->set_global_text_color( 'rgb(18,52,86)' );
		$this->assertSame( '#123456', Newspack_Popups_Contextual_Prompt_Styles::get_defaults()['color']['text'] );

		$this->set_global_text_color( 'rgba( 18, 52, 86, 0.5 )' );
		$this->assertSame( '#123456', Newspack_Popups_Contextual_Prompt_Styles::get_defaults()['color']['text'] );
	}

	/**
	 * A global text color the wizard cannot read stands nothing in: a contrast
	 * check run against the wrong color would warn the wrong way, which is worse
	 * than the warning simply being absent for that pairing.
	 */
	public function test_get_defaults_omits_an_unreadable_inherited_text_color() {
		$this->set_global_text_color( 'rebeccapurple' );

		$defaults = Newspack_Popups_Contextual_Prompt_Styles::get_defaults();

		$this->assertSame( '#f7f7f7', $defaults['color']['background'] );
		$this->assertArrayNotHasKey( 'text', $defaults['color'] );
	}

	/**
	 * Custom CSS renders inside a STYLE element, so it must not close it, nor end
	 * in a prefix of a closing tag the next stylesheet could complete.
	 */
	public function test_validate_custom_css_rejects_a_premature_style_close() {
		$rejected = [
			'p { color: red; } </style>',
			'p { color: red; } </STYLE >',
			'p { color: red; } </style/',
			'p { color: red; } </style',
			'p { color: red; } </sty',
			'p { color: red; } <',
		];

		foreach ( $rejected as $css ) {
			$result = Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( $css );

			$this->assertWPError( $result, $css );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	/**
	 * An unclosed comment would comment out every style appended to the shared
	 * global-styles handle after it.
	 */
	public function test_validate_custom_css_rejects_an_unclosed_comment() {
		$rejected = [
			"p { color: red; }\n/* unclosed comment",
			'/* unclosed',
		];

		foreach ( $rejected as $css ) {
			$result = Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( $css );

			$this->assertWPError( $result, $css );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	/**
	 * An unbalanced opening brace, for the same reason.
	 */
	public function test_validate_custom_css_rejects_an_unbalanced_opening_brace() {
		$result = Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( 'p { color: red;' );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * An unbalanced closing brace, for the same reason.
	 */
	public function test_validate_custom_css_rejects_an_unbalanced_closing_brace() {
		$rejected = [
			'p { color: red; } }',
			'p { } }',
		];

		foreach ( $rejected as $css ) {
			$result = Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( $css );

			$this->assertWPError( $result, $css );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	/**
	 * An unclosed parenthesis, for the same reason: an unterminated function
	 * consumes input until its closer, so it swallows whatever follows.
	 */
	public function test_validate_custom_css_rejects_an_unbalanced_opening_parenthesis() {
		$rejected = [
			'& { background: url( }',
			'& { color: rgb(0,0,0 }',
		];

		foreach ( $rejected as $css ) {
			$result = Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( $css );

			$this->assertWPError( $result, $css );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	/**
	 * Balanced parentheses pass, and a stray closer is harmless in CSS so it
	 * passes too. Parentheses inside strings and comments are text, not structure.
	 */
	public function test_validate_custom_css_accepts_balanced_and_stray_parentheses() {
		$accepted = [
			"& { background: url( 'a.png' ); width: calc( 100% - 10px ); }",
			'& { width: calc( 100% ) ); }',
			'& { content: "("; }',
			'& { /* ( */ color: red; }',
		];

		foreach ( $accepted as $css ) {
			$this->assertTrue( Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( $css ), $css );
		}
	}

	/**
	 * Everything else passes.
	 */
	public function test_validate_custom_css_accepts_ordinary_css() {
		$accepted = [
			'',
			'color: red;',
			'& a { text-decoration: underline; }',
			'p { /* a complete comment */ color: red; }',
			'p::after { content: "</styles"; }',
		];

		foreach ( $accepted as $css ) {
			$this->assertTrue( Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( $css ), $css );
		}
	}

	/**
	 * A comment opener immediately followed by a slash opens a comment that never
	 * closes.
	 */
	public function test_validate_custom_css_rejects_a_comment_that_never_closes() {
		$result = Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( '/*/' );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * An unterminated quoted string, for the same reason.
	 */
	public function test_validate_custom_css_rejects_an_unterminated_string() {
		$result = Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( '& { content: "unterminated' );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * A brace, comment marker or escaped quote inside a quoted string is text,
	 * not structure.
	 */
	public function test_validate_custom_css_accepts_structural_characters_inside_strings() {
		$accepted = [
			'&::before { content: "{"; }',
			'&::after { content: "}"; }',
			'&::before { content: "/*"; }',
			'&::before { content: "*/"; }',
			'& { background: url("data:image/svg+xml;utf8,<svg><text>{</text></svg>"); }',
			'p { content: "a\\"b"; }',
			"p { content: 'a\\'b'; }",
		];

		foreach ( $accepted as $css ) {
			$this->assertTrue( Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( $css ), $css );
		}
	}

	/**
	 * A bare newline ends a string in the browser, so a comment opened after it
	 * is real and the CSS must be rejected. All three line endings count.
	 */
	public function test_validate_custom_css_rejects_a_string_broken_by_a_bare_newline() {
		$rejected = [
			".a { content: \"x\n/*\n\" }",
			".a { content: \"x\r\n/*\r\n\" }",
			".a { content: \"x\f/*\f\" }",
		];

		foreach ( $rejected as $css ) {
			$result = Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( $css );

			$this->assertWPError( $result, $css );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	/**
	 * A backslash line continuation keeps the string open across the line break,
	 * so only a bare newline fails. Both line-ending forms must keep passing.
	 */
	public function test_validate_custom_css_accepts_a_backslash_line_continuation_in_a_string() {
		$accepted = [
			"p { content: \"a\\\nb\"; }",
			"p { content: \"a\\\r\nb\"; }",
		];

		foreach ( $accepted as $css ) {
			$this->assertTrue( Newspack_Popups_Contextual_Prompt_Styles::validate_custom_css( $css ), $css );
		}
	}

	/**
	 * Saving rejects the same CSS the validator does, leaving the stored value
	 * standing.
	 */
	public function test_save_custom_css_rejects_invalid_css() {
		Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( 'color: red;' );

		$result = Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( 'color: red; </style>' );

		$this->assertWPError( $result );
		$this->assertSame( 'color: red;', Newspack_Popups_Contextual_Prompt_Styles::get_custom_css() );
	}

	/**
	 * Saving rejects structurally invalid CSS the same way, leaving the stored
	 * value standing.
	 */
	public function test_save_custom_css_rejects_structurally_invalid_css() {
		Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( 'color: red;' );

		$result = Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( 'p { color: red;' );

		$this->assertWPError( $result );
		$this->assertSame( 'color: red;', Newspack_Popups_Contextual_Prompt_Styles::get_custom_css() );
	}

	/**
	 * An empty value removes the option entirely.
	 */
	public function test_save_empty_custom_css_deletes_option() {
		Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( 'color: red;' );
		$this->assertNotEmpty( get_option( Newspack_Popups_Contextual_Prompt_Styles::CUSTOM_CSS_OPTION_NAME ) );

		$this->assertTrue( Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( '' ) );
		$this->assertFalse( get_option( Newspack_Popups_Contextual_Prompt_Styles::CUSTOM_CSS_OPTION_NAME ) );
		$this->assertSame( '', Newspack_Popups_Contextual_Prompt_Styles::get_custom_css() );
	}

	/**
	 * Bare declarations are wrapped in the block's :root :where() band, and `&`
	 * stands for the block in a nested rule.
	 */
	public function test_custom_css_is_scoped_to_the_block() {
		Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( "color: red;\n& a { text-decoration: underline; }" );

		$css = Newspack_Popups_Contextual_Prompt_Styles::get_css();

		$this->assertStringContainsString( ':root :where(.wp-block-newspack-popups-contextual-prompt){color: red;}', $css );
		$this->assertStringContainsString( ':root :where(.wp-block-newspack-popups-contextual-prompt a){text-decoration: underline;}', $css );
	}

	/**
	 * The custom CSS follows the style-engine output, so at the equal specificity
	 * the two share, the publisher's own rule wins.
	 */
	public function test_custom_css_follows_the_style_engine_output() {
		Newspack_Popups_Contextual_Prompt_Styles::save_styles( [ 'color' => [ 'background' => '#123456' ] ] );
		Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( 'background-color: #abcdef;' );

		$css = Newspack_Popups_Contextual_Prompt_Styles::get_css();

		$this->assertLessThan( strpos( $css, '#abcdef' ), strpos( $css, '#123456' ) );
	}

	/**
	 * Custom CSS with no style overrides beside it still reaches both delivery
	 * paths.
	 */
	public function test_custom_css_ships_without_any_style_overrides() {
		Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( 'color: red;' );
		$this->assertSame( [], Newspack_Popups_Contextual_Prompt_Styles::get_styles() );

		$css = Newspack_Popups_Contextual_Prompt_Styles::get_css();
		$this->assertStringContainsString( 'color: red;', $css );

		$settings = Newspack_Popups_Contextual_Prompt_Styles::filter_block_editor_settings( [ 'styles' => [] ] );
		$this->assertCount( 1, $settings['styles'] );
		$this->assertSame( $css, $settings['styles'][0]['css'] );
	}

	/**
	 * Custom CSS inherits init()'s opt-in gate.
	 */
	public function test_custom_css_is_not_delivered_before_the_opt_in() {
		if ( wp_is_block_theme() ) {
			$this->markTestSkipped( 'Block themes keep the class inert regardless of the opt-in.' );
		}
		Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( 'color: red;' );
		$callback = [ 'Newspack_Popups_Contextual_Prompt_Styles', 'enqueue_front_end_styles' ];
		remove_action( 'wp_footer', $callback, 1 );

		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );
		Newspack_Popups_Contextual_Prompt_Styles::init();

		$this->assertFalse( has_action( 'wp_footer', $callback ) );
	}

	/**
	 * And its block-theme gate, where the Site Editor's own Additional CSS owns
	 * the block.
	 */
	public function test_custom_css_is_not_delivered_on_a_block_theme() {
		$stylesheet = $this->get_any_block_theme_stylesheet();
		if ( ! $stylesheet ) {
			$this->markTestSkipped( 'No block theme installed.' );
		}
		Newspack_Popups_Contextual_Prompt_Styles::save_custom_css( 'color: red;' );
		$callback = [ 'Newspack_Popups_Contextual_Prompt_Styles', 'enqueue_front_end_styles' ];
		remove_action( 'wp_footer', $callback, 1 );

		$original = get_stylesheet();
		switch_theme( $stylesheet );
		Newspack_Popups_Contextual_Prompt_Styles::init();
		$hooked = has_action( 'wp_footer', $callback );
		switch_theme( $original );

		$this->assertFalse( $hooked );
	}

	/**
	 * Find any installed block theme stylesheet slug.
	 *
	 * @return string|null Theme stylesheet, or null if unavailable.
	 */
	private function get_any_block_theme_stylesheet() {
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			if ( method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme() ) {
				return $stylesheet;
			}
		}
		return null;
	}
}
