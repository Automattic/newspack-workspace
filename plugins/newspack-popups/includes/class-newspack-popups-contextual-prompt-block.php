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
		add_filter( 'render_block_' . self::BLOCK_NAME, [ __CLASS__, 'add_analytics_attributes' ], 10, 2 );
		add_filter( 'render_block_data', [ __CLASS__, 'inherit_accent_color' ], 10, 3 );
	}

	/**
	 * Stamp analytics hooks on the rendered wrapper so the view script can report
	 * seen/clicked events. Done at render (not in saved content) so the values stay
	 * live and the markup carries no stale ids.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public static function add_analytics_attributes( $block_content, $block = [] ) {
		if ( '' === trim( (string) $block_content ) ) {
			return $block_content;
		}

		$post_id  = (int) get_the_ID();
		$cta_type = self::use_donate_block() ? 'donate_block' : 'button';

		// Add the data attributes to the opening tag of the wrapper only.
		return preg_replace(
			'/^(\s*<[a-z0-9]+)\b/i',
			'$1'
				. ' data-newspack-cp-post-id="' . esc_attr( (string) $post_id ) . '"'
				. ' data-newspack-cp-cta="' . esc_attr( $cta_type ) . '"'
				. ' data-newspack-cp-placement="' . esc_attr( self::get_placement( $post_id ) ) . '"',
			$block_content,
			1
		);
	}

	/**
	 * Where the prompt sits in the story, as a coarse bucket: top / mid / end.
	 *
	 * The block is body content and there is exactly one per post (multiple:false),
	 * so placement is its position among the article's top-level blocks — measured,
	 * not the framing the editor first chose, since the block can be moved after
	 * insertion. This is the "which placement converts best" grant metric.
	 *
	 * @param int $post_id The article.
	 * @return string 'top' | 'mid' | 'end' | 'unknown'.
	 */
	public static function get_placement( $post_id ) {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return 'unknown';
		}

		$blocks = array_values(
			array_filter(
				parse_blocks( $post->post_content ),
				function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);
		$total = count( $blocks );
		if ( $total < 1 ) {
			return 'unknown';
		}

		$index = null;
		foreach ( $blocks as $i => $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				$index = $i;
				break;
			}
		}
		if ( null === $index ) {
			// Nested inside a group/columns — position can't be bucketed cleanly.
			return 'unknown';
		}

		if ( 1 === $total ) {
			return 'top';
		}

		$ratio = $index / ( $total - 1 );
		if ( $ratio <= 1 / 3 ) {
			return 'top';
		}
		if ( $ratio >= 2 / 3 ) {
			return 'end';
		}
		return 'mid';
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
	 * Site-wide override ("fund-drive mode"): while active, replace the copy of
	 * every Contextual Prompt block at render time. Stored copy is untouched, so
	 * turning the override off restores each story's own prompt.
	 *
	 * @param string $block_content Rendered block markup.
	 * @return string
	 */
	public static function maybe_apply_override( $block_content ) {
		if ( ! class_exists( 'Newspack_Popups_Settings' ) || ! Newspack_Popups_Settings::is_override_active() ) {
			return $block_content;
		}

		$override = trim( (string) get_option( 'newspack_contextual_prompts_override_body', '' ) );
		if ( '' === $override ) {
			return $block_content;
		}

		// Swap the copy paragraph's text; structure and CTA stay untouched. The
		// callback form is required: the override is admin-entered text that could
		// contain `$`, which preg_replace would misread as a backreference (so an
		// override like "Give $5" would be corrupted), and esc_html does not escape it.
		$escaped = esc_html( $override );
		return preg_replace_callback(
			'/(<p\b[^>]*>).*?(<\/p>)/s',
			function ( $matches ) use ( $escaped ) {
				return $matches[1] . $escaped . $matches[2];
			},
			$block_content,
			1
		);
	}
}
