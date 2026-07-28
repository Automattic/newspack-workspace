<?php
/**
 * Contextual Prompt block (prototype).
 *
 * Server side of the newspack-popups/contextual-prompt block: registration so
 * Global Styles can target it, the default design expressed as theme.json data
 * (block supports only — no CSS), and the render pipeline that normalizes each
 * prompt's CTA to the site's donation platform and applies the site-wide
 * override, all at the parsed-block level.
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
		add_filter( 'render_block_data', [ __CLASS__, 'prepare_block_data' ] );
		add_filter( 'render_block_' . self::BLOCK_NAME, [ __CLASS__, 'suppress_empty_prompt' ], 9, 2 );
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
		$cta_type = self::get_cta_type( is_array( $block ) ? $block : [] );

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
	 * A prompt with no copy — generation failed and the post was published
	 * anyway — renders nothing rather than a CTA-only card. Checked against the
	 * final normalized block (post-override), like get_cta_type(): an empty
	 * authored paragraph whose copy the active site-wide override supplies still
	 * renders.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block, post render_block_data filters.
	 * @return string
	 */
	public static function suppress_empty_prompt( $block_content, $block = [] ) {
		return self::has_copy( is_array( $block ) ? $block : [] ) ? $block_content : '';
	}

	/**
	 * Whether any copy paragraph carries visible text.
	 *
	 * @param array $parsed_block Parsed prompt block.
	 * @return bool
	 */
	private static function has_copy( $parsed_block ) {
		foreach ( $parsed_block['innerBlocks'] ?? [] as $child ) {
			if ( 'core/paragraph' !== ( $child['blockName'] ?? '' ) ) {
				continue;
			}
			$text = html_entity_decode( wp_strip_all_tags( (string) ( $child['innerHTML'] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
			if ( '' !== trim( str_replace( "\xC2\xA0", ' ', $text ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The CTA actually rendered, read from the parsed block after the render
	 * pipeline's normalization and override have reshaped it: the configured
	 * platform alone can disagree with the markup (a button override on a native
	 * site, or an off-site setup with no destination, where the CTA is dropped).
	 *
	 * @param array $block The parsed block, post render_block_data filters.
	 * @return string 'donate_block' | 'button' | 'none'.
	 */
	private static function get_cta_type( $block ) {
		$cta = self::find_cta( $block );
		if ( null === $cta ) {
			return 'none';
		}
		return 'newspack-blocks/donate' === $cta['name'] ? 'donate_block' : 'button';
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
	 * Insights. Falls back to a plain button for off-site donation setups, and
	 * whenever the donate block itself isn't registered (Newspack Blocks
	 * inactive) — an unregistered child would render nothing, losing the ask.
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
		$use_donate_block = (bool) apply_filters( 'newspack_contextual_prompts_use_donate_block', $default );

		return $use_donate_block && \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' );
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
	 * The card's default colors.
	 *
	 * A block theme's style variations re-point the palette, so a literal value
	 * survives a switch that the page around it does not: the shipped Nocturne
	 * variation puts `contrast` at white, which the card's copy inherits, and a
	 * fixed light background would leave white text on a near-white card. Naming
	 * the slugs instead makes the card follow whichever variation is active, and
	 * pinning the text color keeps it tied to the background rather than the
	 * canvas.
	 *
	 * Classic themes have no variations, so the designed value stands and the
	 * copy keeps inheriting the theme's body color, which the Customizer owns.
	 *
	 * @return array Color node for theme.json.
	 */
	private static function default_colors() {
		if ( ! wp_is_block_theme() ) {
			return [ 'background' => '#f7f7f7' ];
		}

		return [
			'background' => 'var:preset|color|base-2',
			'text'       => 'var:preset|color|contrast',
		];
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
							'border'     => [ 'radius' => '10px' ],
							'color'      => self::default_colors(),
							// Body copy size. Block themes define `medium` themselves;
							// classic themes resolve it from core's default set, which
							// stays available even where the theme's own sizes replace
							// it in the editor's picker.
							'typography' => [ 'fontSize' => 'var:preset|font-size|medium' ],
							'spacing'    => [
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
	 * Shape every Contextual Prompt for render: normalize its CTA to the site's
	 * current donation platform, then apply the site-wide override when active.
	 * Operates on parsed block data, so children are swapped structurally rather
	 * than by regexing rendered markup.
	 *
	 * @param array $parsed_block The block being rendered.
	 * @return array
	 */
	public static function prepare_block_data( $parsed_block ) {
		if ( self::BLOCK_NAME !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $parsed_block;
		}

		$parsed_block = self::normalize_cta( $parsed_block );

		if ( class_exists( 'Newspack_Popups_Settings' ) && Newspack_Popups_Settings::is_override_active() ) {
			$parsed_block = self::apply_override( $parsed_block );
		}

		return $parsed_block;
	}

	/**
	 * Locate the CTA child: the donate block or the buttons wrapper.
	 *
	 * @param array $parsed_block Parsed prompt block.
	 * @return array|null [ 'index' => int, 'name' => string ], or null.
	 */
	private static function find_cta( $parsed_block ) {
		foreach ( $parsed_block['innerBlocks'] ?? [] as $index => $child ) {
			if ( in_array( $child['blockName'] ?? '', [ 'newspack-blocks/donate', 'core/buttons' ], true ) ) {
				return [
					'index' => $index,
					'name'  => $child['blockName'],
				];
			}
		}
		return null;
	}

	/**
	 * A block's CTA type is fixed when it is inserted, so after a change of
	 * donation platform the stored CTA can disagree with the site. Normalize at
	 * render: the native platform renders the donate form, off-site renders a
	 * button to the donor landing page — or copy only when none is configured.
	 * Matching CTAs pass through untouched, preserving per-story customization.
	 *
	 * @param array $parsed_block Parsed prompt block.
	 * @return array
	 */
	private static function normalize_cta( $parsed_block ) {
		$cta = self::find_cta( $parsed_block );
		if ( null === $cta ) {
			return $parsed_block;
		}

		if ( self::use_donate_block() ) {
			if ( 'core/buttons' === $cta['name'] ) {
				$parsed_block['innerBlocks'][ $cta['index'] ] = self::build_donate_child();
			}
			return $parsed_block;
		}

		$needs_destination = 'newspack-blocks/donate' === $cta['name']
			// A plain-button CTA without a destination anywhere — a fresh insert
			// made before a donor landing page was configured — is a dead ask.
			// Buttons carrying any URL pass through untouched.
			|| ! self::buttons_have_destination( $parsed_block['innerBlocks'][ $cta['index'] ] );

		if ( $needs_destination ) {
			$url = Newspack_Popups::get_donor_landing_url();
			if ( '' === $url ) {
				// No destination to point a button at: render the copy alone
				// rather than a dead button or a form on a disabled platform.
				return self::remove_child( $parsed_block, $cta['index'] );
			}
			$parsed_block['innerBlocks'][ $cta['index'] ] = self::build_buttons_child( $url, __( 'Donate', 'newspack-popups' ) );
		}

		return $parsed_block;
	}

	/**
	 * Whether a buttons wrapper contains at least one button with a destination,
	 * as a URL attribute or an href in its markup.
	 *
	 * @param array $buttons Parsed core/buttons child.
	 * @return bool
	 */
	private static function buttons_have_destination( $buttons ) {
		foreach ( $buttons['innerBlocks'] ?? [] as $child ) {
			if ( '' !== trim( (string) ( $child['attrs']['url'] ?? '' ) ) ) {
				return true;
			}
			if ( false !== strpos( (string) ( $child['innerHTML'] ?? '' ), 'href=' ) ) {
				return true;
			}
			if ( ! empty( $child['innerBlocks'] ) && self::buttons_have_destination( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Site-wide override ("fund-drive mode"): swap the copy of every prompt, and
	 * in button mode replace the CTA with the override button. Stored content is
	 * untouched — turning the override off restores each story's own prompt.
	 *
	 * @param array $parsed_block Parsed prompt block, already normalized.
	 * @return array
	 */
	private static function apply_override( $parsed_block ) {
		$body = trim( (string) get_option( 'newspack_contextual_prompts_override_body', '' ) );
		if ( '' !== $body ) {
			$parsed_block = self::replace_copy( $parsed_block, $body );
		}

		if ( 'button' === Newspack_Popups_Settings::get_override_cta() ) {
			$label = trim( (string) get_option( 'newspack_contextual_prompts_override_label', '' ) );
			$child = self::build_buttons_child(
				(string) get_option( 'newspack_contextual_prompts_override_url', '' ),
				'' !== $label ? $label : __( 'Donate', 'newspack-popups' )
			);

			$cta = self::find_cta( $parsed_block );
			if ( null !== $cta ) {
				$parsed_block['innerBlocks'][ $cta['index'] ] = $child;
			} else {
				$parsed_block = self::append_child( $parsed_block, $child );
			}
		}

		return $parsed_block;
	}

	/**
	 * Replace the text of the first paragraph child.
	 *
	 * Uses preg_replace_callback, not preg_replace, so a literal $1 / ${1} / \1
	 * in the override copy — "Give $5 today" — is never expanded as a
	 * backreference.
	 *
	 * @param array  $parsed_block Parsed prompt block.
	 * @param string $body         Replacement copy, unescaped.
	 * @return array
	 */
	private static function replace_copy( $parsed_block, $body ) {
		foreach ( $parsed_block['innerBlocks'] ?? [] as $index => $child ) {
			if ( 'core/paragraph' !== ( $child['blockName'] ?? '' ) ) {
				continue;
			}
			$swap = function ( $html ) use ( $body ) {
				return preg_replace_callback(
					'#(<p\b[^>]*>).*?(</p>)#s',
					function ( $matches ) use ( $body ) {
						return $matches[1] . esc_html( $body ) . $matches[2];
					},
					(string) $html,
					1
				);
			};

			$child['innerHTML'] = $swap( $child['innerHTML'] );
			foreach ( $child['innerContent'] as $chunk_index => $chunk ) {
				if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
					$child['innerContent'][ $chunk_index ] = $swap( $chunk );
				}
			}
			$parsed_block['innerBlocks'][ $index ] = $child;
			break;
		}

		return $parsed_block;
	}

	/**
	 * Parsed newspack-blocks/donate child in the block's default style. The
	 * accent color is stamped when it renders, by inherit_accent_color().
	 *
	 * @return array
	 */
	private static function build_donate_child() {
		return [
			'blockName'    => 'newspack-blocks/donate',
			'attrs'        => [ 'className' => 'is-style-modern' ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * Parsed core/buttons child holding a single link button.
	 *
	 * @param string $url   Button destination.
	 * @param string $label Button label, unescaped.
	 * @return array
	 */
	private static function build_buttons_child( $url, $label ) {
		$anchor = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></div>';
		return [
			'blockName'    => 'core/buttons',
			'attrs'        => [],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/button',
					'attrs'        => [ 'url' => $url ],
					'innerBlocks'  => [],
					'innerHTML'    => $anchor,
					'innerContent' => [ $anchor ],
				],
			],
			'innerHTML'    => '<div class="wp-block-buttons"></div>',
			'innerContent' => [ '<div class="wp-block-buttons">', null, '</div>' ],
		];
	}

	/**
	 * Remove the Nth child and its innerContent placeholder.
	 *
	 * The innerContent array interleaves HTML chunks with one null placeholder
	 * per child, in order — the placeholder count must track the child count or
	 * the block renders misaligned.
	 *
	 * @param array $parsed_block Parsed block.
	 * @param int   $index        Child index to remove.
	 * @return array
	 */
	private static function remove_child( $parsed_block, $index ) {
		array_splice( $parsed_block['innerBlocks'], $index, 1 );

		$seen = 0;
		foreach ( $parsed_block['innerContent'] as $chunk_index => $chunk ) {
			if ( null !== $chunk ) {
				continue;
			}
			if ( $seen === $index ) {
				array_splice( $parsed_block['innerContent'], $chunk_index, 1 );
				break;
			}
			++$seen;
		}

		return $parsed_block;
	}

	/**
	 * Append a child, inserting its innerContent placeholder before the block's
	 * closing markup chunk.
	 *
	 * @param array $parsed_block Parsed block.
	 * @param array $child        Parsed child block.
	 * @return array
	 */
	private static function append_child( $parsed_block, $child ) {
		$parsed_block['innerBlocks'][] = $child;

		$last_chunk = count( $parsed_block['innerContent'] ) - 1;
		while ( $last_chunk >= 0 && null === $parsed_block['innerContent'][ $last_chunk ] ) {
			--$last_chunk;
		}
		array_splice( $parsed_block['innerContent'], max( 0, $last_chunk ), 0, [ null ] );

		return $parsed_block;
	}
}
