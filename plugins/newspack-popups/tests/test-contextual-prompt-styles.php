<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Contextual Prompt Styles Test
 *
 * Covers the wizard-set default styles for the Contextual Prompt block:
 * allowlist sanitization, style-engine CSS output, and the editor delivery
 * path. The CSS must sit at :root :where() specificity so the block's
 * theme.json default design loses by order and per-block styles still win.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt styles test case.
 */
class ContextualPromptStylesTest extends WP_UnitTestCase {
	/**
	 * The styles class is inert without the admin opt-in.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		delete_option( Newspack_Popups_Contextual_Prompt_Styles::OPTION_NAME );
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

		Newspack_Popups_Contextual_Prompt_Styles::save_styles( [] );
		$this->assertFalse( get_option( Newspack_Popups_Contextual_Prompt_Styles::OPTION_NAME ) );
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
		// Preset spacing must come back resolved, not as a var:preset reference.
		$this->assertStringNotContainsString( 'var:preset', (string) $defaults['spacing']['padding']['top'] );
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
		// This theme sets no global text color either, so the fallback is the CSS
		// initial one.
		$this->assertSame( '#000000', $defaults['color']['text'] );
	}
}
