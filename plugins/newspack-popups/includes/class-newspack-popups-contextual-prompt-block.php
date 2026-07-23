<?php
/**
 * Contextual Prompt block (prototype).
 *
 * Server side of the newspack-popups/contextual-prompt block: registration so
 * Global Styles can target it, the default design expressed as theme.json data
 * (block supports only — no CSS), and the site-wide override applied at render.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contextual Prompt block class.
 */
final class Newspack_Popups_Contextual_Prompt_Block {
	const BLOCK_NAME = 'newspack-popups/contextual-prompt';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_filter( 'wp_theme_json_data_default', [ __CLASS__, 'default_design' ] );
		add_filter( 'render_block_' . self::BLOCK_NAME, [ __CLASS__, 'maybe_apply_override' ] );
		add_filter( 'render_block_data', [ __CLASS__, 'inherit_accent_color' ], 10, 3 );
	}

	/**
	 * The theme's accent color: the "accent" palette color on block themes, the
	 * Newspack primary color on the classic theme.
	 *
	 * @return string|null Hex color, or null when none can be resolved.
	 */
	public static function get_accent_color() {
		$palette = wp_get_global_settings( [ 'color', 'palette' ] );
		foreach ( [ 'custom', 'theme' ] as $origin ) {
			foreach ( $palette[ $origin ] ?? [] as $color ) {
				if ( 'accent' === ( $color['slug'] ?? '' ) && ! empty( $color['color'] ) ) {
					return $color['color'];
				}
			}
		}

		if ( function_exists( 'Newspack\newspack_get_theme_colors' ) ) {
			$colors = \Newspack\newspack_get_theme_colors();
			if ( ! empty( $colors['primary_color'] ) ) {
				return $colors['primary_color'];
			}
		}

		return null;
	}

	/**
	 * Whether the CTA is the native Newspack donate block rather than a plain
	 * button. Defaults to true when the publisher uses Newspack (WooCommerce)
	 * donations — then reader conversions classify as donations in analytics /
	 * Insights. Falls back to a plain button for off-site donation setups.
	 *
	 * @return bool
	 */
	public static function use_donate_block() {
		$default = method_exists( '\Newspack\Donations', 'is_platform_wc' ) && \Newspack\Donations::is_platform_wc();

		/**
		 * Filters whether Contextual Prompts render the native donate block.
		 *
		 * @param bool $use_donate_block Whether to use the donate block.
		 */
		return (bool) apply_filters( 'newspack_contextual_prompts_use_donate_block', $default );
	}

	/**
	 * Make the donate CTA inside a Contextual Prompt use the theme's accent
	 * color instead of the donate block's default. Always resolved at render so
	 * it tracks theme changes — any stored buttonColor (stamped by the editor
	 * for preview parity) is cosmetic only.
	 *
	 * @param array         $parsed_block The block being rendered.
	 * @param array         $source_block Unmodified copy of the block.
	 * @param WP_Block|null $parent_block The parent in the render tree.
	 * @return array
	 */
	public static function inherit_accent_color( $parsed_block, $source_block, $parent_block ) {
		if (
			'newspack-blocks/donate' !== ( $parsed_block['blockName'] ?? '' )
			|| empty( $parent_block )
			|| self::BLOCK_NAME !== $parent_block->name
		) {
			return $parsed_block;
		}

		$accent = self::get_accent_color();
		if ( $accent ) {
			$parsed_block['attrs']['buttonColor'] = $accent;
		}

		return $parsed_block;
	}

	/**
	 * Server-side registration, mirroring the client block.json. Required for
	 * Global Styles (Styles → Blocks) to list the block and for layout/spacing
	 * supports to be applied at render.
	 */
	public static function register_block() {
		register_block_type(
			self::BLOCK_NAME,
			[
				'api_version' => 3,
				'title'       => __( 'Campaigns: Contextual Prompt', 'newspack-popups' ),
				'description' => __( 'A story-specific donation ask. Copy is generated from the story and editable; the call to action follows your donation settings.', 'newspack-popups' ),
				'category'    => 'newspack',
				'attributes'  => [
					'layout' => [
						'type'    => 'object',
						'default' => [ 'type' => 'default' ],
					],
				],
				'supports'    => [
					'align'                => [ 'wide', 'full' ],
					'html'                 => false,
					'lock'                 => false,
					'multiple'             => false,
					'reusable'             => false,
					'layout'               => [
						'default'            => [ 'type' => 'default' ],
						'allowJustification' => false,
						'allowSwitching'     => false,
					],
					'shadow'               => true,
					'color'                => [
						'background' => true,
						'text'       => true,
					],
					'spacing'              => [
						'padding'  => true,
						'margin'   => true,
						'blockGap' => true,
					],
					'dimensions'           => [
						'minHeight' => true,
					],
					'typography'           => [
						'fontSize'                      => true,
						'lineHeight'                    => true,
						'__experimentalFontFamily'      => true,
						'__experimentalFontWeight'      => true,
						'__experimentalFontStyle'       => true,
						'__experimentalTextTransform'   => true,
						'__experimentalTextDecoration'  => true,
						'__experimentalLetterSpacing'   => true,
						'__experimentalDefaultControls' => [ 'fontSize' => true ],
					],
					'__experimentalBorder' => [
						'radius' => true,
						'color'  => true,
						'width'  => true,
						'style'  => true,
					],
				],
			]
		);
	}

	/**
	 * The default design, as theme.json data. Sits in the default layer so a
	 * theme or the publisher (Styles → Blocks → Campaigns: Contextual Prompt)
	 * overrides it, and applies to every instance retroactively.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Default theme.json data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function default_design( $theme_json ) {
		return $theme_json->update_with(
			[
				'version' => 3,
				'styles'  => [
					'blocks' => [
						self::BLOCK_NAME => [
							'border'  => [ 'radius' => '10px' ],
							'color'   => [ 'background' => '#f7f7f7' ],
							'spacing' => [
								'padding'  => [
									'top'    => 'var:preset|spacing|50',
									'right'  => 'var:preset|spacing|50',
									'bottom' => 'var:preset|spacing|50',
									'left'   => 'var:preset|spacing|50',
								],
								'blockGap' => 'var:preset|spacing|30',
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Site-wide override ("fund-drive mode"): while active, replace the copy — and,
	 * on plain-button sites, the CTA destination — of every Contextual Prompt block
	 * at render time. Stored copy and button are untouched, so turning the override
	 * off restores each story's own prompt.
	 *
	 * @param string $block_content Rendered block markup.
	 * @return string
	 */
	public static function maybe_apply_override( $block_content ) {
		if ( ! class_exists( 'Newspack_Popups_Settings' ) || ! Newspack_Popups_Settings::is_override_active() ) {
			return $block_content;
		}

		$body = trim( (string) get_option( 'newspack_contextual_prompts_override_body', '' ) );
		if ( '' === $body ) {
			return $block_content;
		}

		// Swap the copy paragraph's text. preg_replace_callback, not preg_replace, so a
		// literal $1 / ${1} / \1 in the override copy — "Give $5 today" — is never
		// expanded as a backreference.
		$block_content = preg_replace_callback(
			'#(<p\b[^>]*>).*?(</p>)#s',
			function ( $matches ) use ( $body ) {
				return $matches[1] . esc_html( $body ) . $matches[2];
			},
			$block_content,
			1
		);

		// Repoint the CTA from what the block actually renders, not the site's current
		// donation platform: a block's CTA type is fixed when it is inserted, so a
		// plain-button prompt inserted before a switch to native donations still needs
		// its button repointed. apply_override_to_button() targets only the plain-button
		// anchor and no-ops on the native donate block (which owns its own destination),
		// so this is safe to run in either mode.
		$block_content = self::apply_override_to_button(
			$block_content,
			(string) get_option( 'newspack_contextual_prompts_override_url', '' ),
			trim( (string) get_option( 'newspack_contextual_prompts_override_label', '' ) )
		);

		return $block_content;
	}

	/**
	 * Repoint the plain-button CTA at the override destination and label.
	 *
	 * The button renders as `<a class="wp-block-button__link …" href="…">Label</a>`.
	 * The href is set with the HTML API (safe attribute handling), and the label with
	 * preg_replace_callback so a `$1`/`\1` sequence in publisher copy can't expand.
	 *
	 * @param string $html  Rendered block markup.
	 * @param string $url   Override button URL.
	 * @param string $label Override button label.
	 * @return string
	 */
	private static function apply_override_to_button( $html, $url, $label ) {
		$url   = trim( $url );
		$label = trim( $label );

		if ( '' !== $url && class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$tags = new \WP_HTML_Tag_Processor( $html );
			if ( $tags->next_tag(
				[
					'tag_name'   => 'a',
					'class_name' => 'wp-block-button__link',
				]
			) ) {
				$tags->set_attribute( 'href', esc_url( $url ) );
				$html = $tags->get_updated_html();
			}
		}

		if ( '' !== $label ) {
			$html = preg_replace_callback(
				'#(<a\b[^>]*\bwp-block-button__link\b[^>]*>).*?(</a>)#is',
				function ( $matches ) use ( $label ) {
					return $matches[1] . esc_html( $label ) . $matches[2];
				},
				$html,
				1
			);
		}

		return $html;
	}
}
