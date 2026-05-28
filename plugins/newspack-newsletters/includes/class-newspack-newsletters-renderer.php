<?php
/**
 * Newspack Newsletter Renderer
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Newsletters Renderer Class.
 */
final class Newspack_Newsletters_Renderer {
	/**
	 * The color palette to be used.
	 *
	 * @var Object
	 */
	public static $color_palette = null;

	/**
	 * Newsletter ID being rendered.
	 *
	 * @var int
	 */
	public static $newsletter_id = null;

	/**
	 * The header font.
	 *
	 * @var String
	 */
	protected static $font_header = null;

	/**
	 * The body font.
	 *
	 * @var String
	 */
	protected static $font_body = null;

	/**
	 * Cache of already processed links (avoid recursive processing).
	 *
	 * @var boolean[] Map of link URLs to whether they were processed.
	 */
	protected static $processed_links = [];

	/**
	 * The post permalink, if the post is public.
	 *
	 * @var String
	 */
	protected static $post_permalink = null;

	/**
	 * Inline tags that are allowed to be rendered in a text block.
	 *
	 * @var bool[]|array[] Associative array of tag names to allowed attributes.
	 */
	public static $allowed_inline_tags = [
		's'      => true,
		'b'      => true,
		'strong' => true,
		'i'      => true,
		'em'     => true,
		'span'   => true,
		'u'      => true,
		'small'  => true,
		'sub'    => true,
		'sup'    => true,
		'a'      => [
			'href'   => true,
			'target' => true,
			'rel'    => true,
		],
	];

	/**
	 * Convert a list to HTML attributes.
	 *
	 * @param array $attributes Array of attributes.
	 * @return string HTML attributes as a string.
	 */
	private static function array_to_attributes( $attributes ) {
		// Default: normalize image captions for consistent rendering across themes.
		if ( isset( $attributes['css-class'] ) && 'image-caption' === $attributes['css-class'] ) {
			$attributes['align']   = 'left';
			$attributes['padding'] = '0';
		}
		$attributes = apply_filters( 'newspack_newsletters_mjml_component_attributes', $attributes );
		return join(
			' ',
			array_map(
				function ( $key ) use ( $attributes ) {
					if (
						isset( $attributes[ $key ] ) &&
						( is_string( $attributes[ $key ] ) || is_numeric( $attributes[ $key ] ) ) // Don't convert values that can't be expressed as a string.
					) {
						return $key . '="' . esc_attr( $attributes[ $key ] ) . '"';
					} else {
						return '';
					}
				},
				array_keys( $attributes )
			)
		);
	}

	/**
	 * Get a value for an image alt attribute.
	 *
	 * @param int $attachment_id Attachment ID of the image.
	 *
	 * @return string A value for the alt attribute.
	 */
	private static function get_image_alt( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '';
		}

		$attachment = get_post( $attachment_id );
		// Sanity check.
		if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
			return '';
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( empty( $alt ) ) {
			$alt = $attachment->post_content;
		}
		if ( empty( $alt ) ) {
			$alt = $attachment->post_excerpt;
		}
		if ( empty( $alt ) ) {
			$alt = $attachment->post_title;
		}

		return $alt;
	}

	/**
	 * Get font size based on block attributes.
	 *
	 * @param array $block_attrs Block attributes.
	 * @return string|null Font size, or null when no size attribute is set or the preset is unknown.
	 */
	private static function get_font_size( $block_attrs ) {
		if ( isset( $block_attrs['customFontSize'] ) ) {
			return $block_attrs['customFontSize'] . 'px';
		}
		if ( isset( $block_attrs['fontSize'] ) ) {
			$sizes = array(
				'xx-small'     => '8px',
				'x-small'      => '10px',
				'small'        => '12px',
				'normal'       => '16px',
				'medium'       => '16px',
				'large'        => '24px',
				'huge'         => '36px',
				'x-large'      => '36px',
				'xx-large'     => '40px',
				'xxx-large'    => '48px',
				'xxxx-large'   => '56px',
				'xxxxx-large'  => '64px',
				'xxxxxx-large' => '72px',
			);
			return $sizes[ $block_attrs['fontSize'] ] ?? null;
		}
		return null;
	}

	/**
	 * Get the colors of supported social icons.
	 */
	private static function get_social_icons_services_colors() {
		return [
			'bluesky'   => '#0a7aff',
			'facebook'  => '#1977f2',
			'instagram' => '#f00075',
			'linkedin'  => '#0577b5',
			'mastodon'  => '#6364ff',
			'threads'   => '#000000',
			'tiktok'    => '#000000',
			'tumblr'    => '#011835',
			'twitter'   => '#21a1f3',
			'x'         => '#000000',
			'whatsapp'  => '#25d366',
			'wordpress' => '#3499cd',
			'youtube'   => '#ff0100',
		];
	}

	/**
	 * Get the list of supported social icon services.
	 */
	public static function get_supported_social_icons_services() {
		return array_keys( self::get_social_icons_services_colors() );
	}

	/**
	 * Get the social icon and color based on the block attributes.
	 *
	 * @param string $service_name The service name.
	 * @param array  $block_attrs  Block attributes.
	 *
	 * @return array[
	 *   'icon'  => string,
	 *   'color' => string,
	 * ] The icon and color or empty array if service not found.
	 */
	private static function get_social_icon( $service_name, $block_attrs ) {
		$services_colors = self::get_social_icons_services_colors();
		if ( ! isset( $services_colors[ $service_name ] ) ) {
			return [];
		}
		$icon  = 'white';
		$color = $services_colors[ $service_name ];
		if ( isset( $block_attrs['className'] ) ) {
			if ( 'is-style-filled-black' === $block_attrs['className'] || 'is-style-circle-white' === $block_attrs['className'] ) {
				$icon = 'black';
			} elseif ( 'is-style-filled-primary-text' === $block_attrs['className'] ) {
				$palette = json_decode( get_option( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_PALETTE_META, '{}' ), true );

				if ( isset( $palette['primary-text'] ) && ( $palette['primary-text'] === 'black' || $palette['primary-text'] === '#000000' ) ) {
					$icon = 'black';
				}
			}
			if ( 'is-style-filled-black' === $block_attrs['className'] || 'is-style-filled-white' === $block_attrs['className'] || 'is-style-filled-primary-text' === $block_attrs['className'] ) {
				$color = 'transparent';
			} elseif ( 'is-style-circle-black' === $block_attrs['className'] ) {
				$color = '#000';
			} elseif ( 'is-style-circle-white' === $block_attrs['className'] ) {
				$color = '#fff';
			}
		}
		return [
			'icon'  => sprintf( '%s-%s.png', $icon, $service_name ),
			'color' => $color,
		];
	}

	/**
	 * Get colors based on block attributes.
	 *
	 * @param array $block_attrs Block attributes.
	 * @return array Array of color attributes for MJML component.
	 */
	private static function get_colors( $block_attrs ) {
		$colors = array();

		// For text.
		if ( isset( $block_attrs['textColor'], self::$color_palette[ $block_attrs['textColor'] ] ) ) {
			$colors['color'] = self::$color_palette[ $block_attrs['textColor'] ];
		}
		// customTextColor is set inline, but it's passed here for consistency.
		if ( isset( $block_attrs['customTextColor'] ) ) {
			$colors['color'] = $block_attrs['customTextColor'];
		}
		if ( isset( $block_attrs['backgroundColor'], self::$color_palette[ $block_attrs['backgroundColor'] ] ) ) {
			$colors['background-color'] = self::$color_palette[ $block_attrs['backgroundColor'] ];
		}
		if ( isset( $block_attrs['borderColor'], self::$color_palette[ $block_attrs['borderColor'] ] ) ) {
			$colors['border-color'] = self::$color_palette[ $block_attrs['borderColor'] ];
		}
		// customBackgroundColor is set inline, but not on mjml wrapper element.
		if ( isset( $block_attrs['customBackgroundColor'] ) ) {
			$colors['background-color'] = $block_attrs['customBackgroundColor'];
		}

		// For separators.
		if ( isset( $block_attrs['color'], self::$color_palette[ $block_attrs['color'] ] ) ) {
			$colors['border-color'] = self::$color_palette[ $block_attrs['color'] ];
		}
		if ( isset( $block_attrs['customColor'] ) ) {
			$colors['border-color'] = $block_attrs['customColor'];
		}

		// Custom color handling.
		if ( isset( $block_attrs['style'] ) ) {
			if ( isset( $block_attrs['style']['color']['background'] ) ) {
				$colors['background-color'] = $block_attrs['style']['color']['background'];
			}
			if ( isset( $block_attrs['style']['color']['text'] ) ) {
				$colors['color'] = $block_attrs['style']['color']['text'];
			}
		}

		// Link color handling.
		if ( isset( $block_attrs['style']['elements']['link']['color']['text'] ) ) {
			$link_color = $block_attrs['style']['elements']['link']['color']['text'];
			// Check if the color is a preset.
			if ( strpos( $link_color, 'var:preset|color|' ) === 0 ) {
				// Extract the color name and retrieve it in theme.json.
				$color_name    = str_replace( 'var:preset|color|', '', $link_color );
				$theme_json    = WP_Theme_JSON_Resolver::get_theme_data();
				$color_palette = $theme_json->get_settings()['color']['palette']['theme'] ?? [];
				$hex_color     = '#000000'; // Fallback color.
				if ( ! empty( $color_palette ) && is_array( $color_palette ) ) {
					foreach ( $color_palette as $color ) {
						if ( isset( $color['slug'] ) && $color['slug'] === $color_name ) {
							$hex_color = $color['color']; // Get the Hex value.
							break;
						}
					}
				}
				$colors['link'] = $hex_color; // Set the Hex color.
			} else {
				$colors['link'] = $link_color;
			}
		}

		// Add !important to all colors.
		if ( isset( $colors['color'] ) ) {
			$colors['color'] .= ' !important';
		}
		if ( isset( $colors['background-color'] ) ) {
			$colors['background-color'] .= ' !important';
		}
		if ( isset( $colors['link'] ) ) {
			$colors['link'] .= ' !important';
		}

		return $colors;
	}

	/**
	 * Get spacing value.
	 *
	 * @param string $value Spacing value.
	 *
	 * @return string Spacing value.
	 */
	private static function get_spacing_value( $value ) {
		$presets = [
			'20' => '8px',
			'30' => '16px',
			'40' => '24px',
			'50' => '32px',
			'60' => '32px',
			'70' => '48px',
			'80' => '64px',
		];
		if ( 0 === strpos( $value, 'var' ) ) {
			$preset_key = explode( '|', $value );
			$preset     = end( $preset_key );
			if ( isset( $presets[ $preset ] ) ) {
				return $presets[ $preset ];
			}
			return $preset . 'px';
		}
		return $value;
	}

	/**
	 * Add color attributes and a padding, if component has a background color.
	 *
	 * @param array $attrs Block attributes.
	 * @return array MJML component attributes.
	 */
	private static function process_attributes( $attrs ) {
		$attrs     = array_merge(
			$attrs,
			self::get_colors( $attrs )
		);
		$font_size = self::get_font_size( $attrs );
		if ( isset( $font_size ) ) {
			$attrs['font-size'] = $font_size;
		}

		if ( isset( $attrs['style']['spacing']['padding'] ) ) {
			$padding = array_merge(
				// Make sure we have all padding values set. Blocks with a background color should have a default padding of 12px.
				[
					'top'    => isset( $attrs['backgroundColor'] ) ? '12px' : '0',
					'right'  => isset( $attrs['backgroundColor'] ) ? '12px' : '0',
					'bottom' => isset( $attrs['backgroundColor'] ) ? '12px' : '0',
					'left'   => isset( $attrs['backgroundColor'] ) ? '12px' : '0',
				],
				$attrs['style']['spacing']['padding']
			);
			foreach ( $padding as $key => $value ) {
				$padding[ $key ] = self::get_spacing_value( $value );
			}
			$attrs['padding'] = array_filter( $padding );
			if ( ! empty( $attrs['padding'] ) ) {
				$attrs['padding'] = sprintf( '%s %s %s %s', $padding['top'], $padding['right'], $padding['bottom'], $padding['left'] );
			}
		}

		if ( isset( $attrs['style']['spacing']['margin'] ) ) {
			$margin = array_merge(
				[
					'top'    => '0',
					'right'  => '0',
					'bottom' => '0',
					'left'   => '0',
				],
				$attrs['style']['spacing']['margin']
			);
			foreach ( $margin as $key => $value ) {
				$margin[ $key ] = self::get_spacing_value( $value );
			}
			$attrs['margin'] = sprintf( '%s %s %s %s', $margin['top'], $margin['right'], $margin['bottom'], $margin['left'] );
		}

		if ( ! empty( $attrs['borderRadius'] ) ) {
			$attrs['borderRadius'] = $attrs['borderRadius'] . 'px';
		}
		if ( isset( $attrs['style']['border']['radius'] ) ) {
			$attrs['borderRadius'] = $attrs['style']['border']['radius'];
		}
		if ( isset( $attrs['style']['border']['width'] ) ) {
			$border_color = isset( $attrs['border-color'] ) ? $attrs['border-color'] : 'black';
			$border_style = isset( $attrs['style']['border']['style'] ) ? $attrs['style']['border']['style'] : 'solid';
			$attrs['border'] = $attrs['style']['border']['width'] . ' ' . $border_style . ' ' . $border_color;
		}

		// WP 7.0+ stores text alignment under style.typography.textAlign via the
		// new textAlign block support (paragraph, heading, etc.).
		if ( isset( $attrs['style']['typography']['textAlign'] ) && ! isset( $attrs['align'] ) && ! isset( $attrs['textAlign'] ) ) {
			$attrs['align'] = $attrs['style']['typography']['textAlign'];
		}

		if ( isset( $attrs['textAlign'] ) && ! isset( $attrs['align'] ) ) {
			$attrs['align'] = $attrs['textAlign'];
			unset( $attrs['textAlign'] );
		}

		if ( isset( $attrs['align'] ) && 'full' == $attrs['align'] ) {
			$attrs['full-width'] = 'full-width';
			unset( $attrs['align'] );
		}

		if ( ! isset( $attrs['padding'] ) && isset( $attrs['full-width'] ) && 'full-width' == $attrs['full-width'] && isset( $attrs['background-color'] ) ) {
			$attrs['padding'] = '12px 0';
		}

		return $attrs;
	}

	/**
	 * Append UTM param to links.
	 *
	 * @param string   $html Input HTML.
	 * @param \WP_Post $post Optional post object.
	 * @return string HTML with processed links.
	 */
	public static function process_links( $html, $post = null ) {
		preg_match_all( '/href="([^"]*)"/', $html, $matches );
		$href_params   = $matches[0];
		$urls          = $matches[1];
		$provider      = Newspack_Newsletters::get_service_provider();
		$campaign_name = $post && $provider ? $provider->get_campaign_name( $post ) : false;
		$send_list_id  = $post ? get_post_meta( $post->ID, 'send_list_id', true ) : false;
		$utm_params    = [
			'utm_medium' => 'email',
		];
		if ( ! $campaign_name && $post ) {
			$campaign_name = get_the_title( $post );
		}
		if ( $campaign_name ) {
			$utm_params['utm_campaign'] = rawurlencode( $campaign_name );
		}
		if ( $send_list_id ) {
			$utm_params['utm_source'] = rawurlencode( $send_list_id );
		}
		foreach ( $urls as $index => $url ) {
			/** Skip if link was already processed. */
			if ( ! empty( self::$processed_links[ $url ] ) ) {
				continue;
			}
			/** Link href content can be invalid (placeholder) so we must skip it. */
			if ( ! wp_http_validate_url( $url ) ) {
				continue;
			}
			$url_with_params = apply_filters(
				'newspack_newsletters_process_link',
				add_query_arg(
					$utm_params,
					$url
				),
				$url,
				$post
			);

			self::$processed_links[ $url_with_params ] = true;

			$html = str_replace( $href_params[ $index ], 'href="' . $url_with_params . '"', $html );
		}
		return $html;
	}

	/**
	 * Whether the block is empty.
	 *
	 * @param WP_Block $block The block.
	 *
	 * @return bool Whether the block is empty.
	 */
	public static function is_empty_block( $block ) {
		$blocks_without_inner_html = [
			'core/site-logo',
			'core/site-title',
			'core/site-tagline',
			'newspack-newsletters/ad',
		];

		$empty_block_name = empty( $block['blockName'] );
		$empty_html       = ! in_array( $block['blockName'], $blocks_without_inner_html, true ) && empty( $block['innerHTML'] );

		return $empty_block_name || $empty_html;
	}

	/**
	 * Remove unwanted style properties from style attributes in an HTML string.
	 *
	 * @param array  $properties The properties to remove.
	 * @param string $html The HTML string to remove the properties from.
	 * @return string The HTML string with unwanted properties removed.
	 */
	public static function remove_unwanted_style_properties( $properties, $html ) {
		$style_attributes = '/style="([^"]*)"/i';
		$html       = preg_replace_callback(
			$style_attributes,
			function( $matches ) use ( $properties ) {
				// Remove unwanted properties from the style attribute.
				$properties = preg_replace( '/\b(' . implode( '|', $properties ) . ')[^;]*;?\s*/i', '', $matches[1] );
				return 'style="' . $properties . '"';
			},
			$html
		);
		return $html;
	}

	/**
	 * Get Remote Data Blocks context from a container block.
	 *
	 * @param array $block The block array.
	 * @return array The remote data context or empty array if not found.
	 */
	private static function get_rdb_context( $block ) {
		// Remote data is stored in the block's remoteData attribute.
		if ( isset( $block['attrs']['remoteData'] ) ) {
			return $block['attrs']['remoteData'];
		}
		return [];
	}

	/**
	 * Expand template blocks for multiple query results.
	 * Creates a copy of inner blocks for each result with the appropriate index.
	 *
	 * Heavily inspired by new RDB preview rendering.  See changes to
	 * src/block-editor/binding-sources/remote-data-binding.ts at
	 * https://github.com/Automattic/remote-data-blocks/commit/a4b9249e248140c741e987f949b215be5edabf1d
	 *
	 * @param array $inner_blocks The inner blocks to clone.
	 * @param array $results      The query results.
	 * @param array $remote_data  The full remote data context.
	 * @return array Expanded array of blocks with indices set.
	 */
	private static function expand_rdb_template_blocks( $inner_blocks, $results, $remote_data ) {
		$expanded_blocks = [];

		foreach ( $results as $index => $result ) {
			$source_args = [
				'block'            => $remote_data['blockName'] ?? '',
				'enabledOverrides' => $remote_data['enabledOverrides'] ?? [],
				'index'            => $index,
				'queryKey'         => $remote_data['queryKey'] ?? null,
				'queryInputs'      => $remote_data['queryInputs'] ?? null,
			];

			$cloned_blocks = self::clone_blocks_with_index( $inner_blocks, $source_args );
			$expanded_blocks = array_merge( $expanded_blocks, $cloned_blocks );
		}

		return $expanded_blocks;
	}

	/**
	 * Deep clone blocks and merge source args into all binding configurations.
	 *
	 * @param array $blocks      The blocks to clone.
	 * @param array $source_args The source args to merge (including index).
	 * @return array Cloned blocks with updated binding args.
	 */
	private static function clone_blocks_with_index( $blocks, $source_args ) {
		$cloned = [];

		foreach ( $blocks as $block ) {
			$cloned_block = $block;

			// Update bindings in the block's attributes metadata.
			if ( isset( $cloned_block['attrs']['metadata']['bindings'] ) ) {
				foreach ( $cloned_block['attrs']['metadata']['bindings'] as $target => $binding ) {
					if ( isset( $binding['source'] ) && 'remote-data/binding' === $binding['source'] ) {
						$cloned_block['attrs']['metadata']['bindings'][ $target ]['args'] = array_merge(
							$binding['args'] ?? [],
							$source_args
						);
					}
				}
			}

			// Recursively process inner blocks.
			if ( ! empty( $cloned_block['innerBlocks'] ) ) {
				$cloned_block['innerBlocks'] = self::clone_blocks_with_index( $cloned_block['innerBlocks'], $source_args );
			}

			$cloned[] = $cloned_block;
		}

		return $cloned;
	}

	/**
	 * Resolve Remote Data Blocks bindings for a block and its children.
	 * Updates innerHTML with resolved values.
	 *
	 * @param array $block       The block to resolve.
	 * @param array $remote_data The remote data context.
	 * @return array The block with resolved bindings and updated innerHTML.
	 */
	private static function resolve_rdb_block_bindings( $block, $remote_data ) {
		// Check if RDB's BlockBindings class is available.
		if ( ! class_exists( 'RemoteDataBlocks\Editor\DataBinding\BlockBindings' ) ) {
			return $block;
		}

		$resolved_block = $block;

		// Check if block has bindings.
		if ( isset( $resolved_block['attrs']['metadata']['bindings'] ) ) {
			$resolved_attrs = [];

			// Build a block array with injected context for BlockBindings::get_value().
			$block_with_context = [
				'context'    => [
					'remote-data-blocks/remoteData' => $remote_data,
				],
				'attributes' => $resolved_block['attrs'],
				'name'       => $resolved_block['blockName'],
			];

			foreach ( $resolved_block['attrs']['metadata']['bindings'] as $target => $binding ) {
				if ( isset( $binding['source'] ) && 'remote-data/binding' === $binding['source'] ) {
					$source_args = $binding['args'] ?? [];
					$value = \RemoteDataBlocks\Editor\DataBinding\BlockBindings::get_value(
						$source_args,
						$block_with_context,
						$target
					);

					if ( null !== $value ) {
						$resolved_attrs[ $target ] = $value;
					}
				}
			}

			// Persist resolved values to block attrs and update innerHTML where needed.
			if ( ! empty( $resolved_attrs ) ) {
				if ( ! isset( $resolved_block['attrs'] ) || ! is_array( $resolved_block['attrs'] ) ) {
					$resolved_block['attrs'] = [];
				}
				foreach ( $resolved_attrs as $attr_key => $attr_value ) {
					$resolved_block['attrs'][ $attr_key ] = $attr_value;
				}
				$resolved_block = self::update_block_inner_html( $resolved_block, $resolved_attrs );
			}
		}

		// Recursively resolve inner blocks.
		if ( ! empty( $resolved_block['innerBlocks'] ) ) {
			$resolved_inner_blocks = [];
			foreach ( $resolved_block['innerBlocks'] as $inner_block ) {
				$resolved_inner_blocks[] = self::resolve_rdb_block_bindings( $inner_block, $remote_data );
			}
			$resolved_block['innerBlocks'] = $resolved_inner_blocks;
		}

		return $resolved_block;
	}

	/**
	 * Update block innerHTML based on resolved attribute values.
	 *
	 * @param array $block          The block to update.
	 * @param array $resolved_attrs The resolved attribute values.
	 * @return array The block with updated innerHTML.
	 */
	private static function update_block_inner_html( $block, $resolved_attrs ) {
		$block_name = $block['blockName'];
		$inner_html = $block['innerHTML'] ?? '';

		switch ( $block_name ) {
			case 'core/paragraph':
				if ( isset( $resolved_attrs['content'] ) ) {
					$inner_html = self::reconstruct_paragraph_html( $inner_html, $resolved_attrs['content'] );
				}
				break;

			case 'core/heading':
				if ( isset( $resolved_attrs['content'] ) ) {
					$level = $block['attrs']['level'] ?? 2;
					$inner_html = self::reconstruct_heading_html( $inner_html, $resolved_attrs['content'], $level );
				}
				break;

			case 'core/image':
				if ( isset( $resolved_attrs['url'] ) ) {
					$inner_html = self::update_src_in_html( $inner_html, $resolved_attrs['url'] );
				}
				if ( isset( $resolved_attrs['alt'] ) ) {
					$inner_html = self::update_alt_in_html( $inner_html, $resolved_attrs['alt'] );
				}
				break;

			case 'core/button':
				if ( isset( $resolved_attrs['url'] ) ) {
					$inner_html = self::update_href_in_html( $inner_html, $resolved_attrs['url'] );
				}
				if ( isset( $resolved_attrs['text'] ) ) {
					$inner_html = self::update_link_text_in_html( $inner_html, $resolved_attrs['text'] );
				}
				break;
		}

		$block['innerHTML'] = $inner_html;

		// Also update innerContent to match.
		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			// For blocks without inner blocks, innerContent is typically a single-element array.
			if ( count( $block['innerContent'] ) === 1 && null !== $block['innerContent'][0] ) {
				$block['innerContent'][0] = $inner_html;
			}
		}

		return $block;
	}

	/**
	 * Reconstruct paragraph HTML with new content.
	 *
	 * @param string $original_html The original innerHTML.
	 * @param string $content       The new content.
	 * @return string The reconstructed HTML.
	 */
	private static function reconstruct_paragraph_html( $original_html, $content ) {
		$clean_content = self::cleanup_rdb_html( $content );

		// Try to preserve existing attributes by replacing content within <p> tags.
		if ( preg_match( '/<p([^>]*)>.*<\/p>/is', $original_html, $matches ) ) {
			return '<p' . $matches[1] . '>' . $clean_content . '</p>';
		}
		// Fallback: create simple paragraph.
		return '<p>' . $clean_content . '</p>';
	}

	/**
	 * Reconstruct heading HTML with new content.
	 *
	 * @param string $original_html The original innerHTML.
	 * @param string $content       The new content.
	 * @param int    $level         The heading level.
	 * @return string The reconstructed HTML.
	 */
	private static function reconstruct_heading_html( $original_html, $content, $level ) {
		$clean_content = self::cleanup_rdb_html( $content );
		$tag = 'h' . intval( $level );
		// Try to preserve existing attributes.
		if ( preg_match( '/<' . $tag . '([^>]*)>.*<\/' . $tag . '>/is', $original_html, $matches ) ) {
			return '<' . $tag . $matches[1] . '>' . $clean_content . '</' . $tag . '>';
		}
		// Fallback: create simple heading.
		return '<' . $tag . '>' . $clean_content . '</' . $tag . '>';
	}

	/**
	 * Update src attribute in HTML (for images).
	 *
	 * @param string $html The original HTML.
	 * @param string $url  The new URL.
	 * @return string The updated HTML.
	 */
	private static function update_src_in_html( $html, $url ) {
		// Replace src attribute value.
		return preg_replace_callback(
			'/src="[^"]*"/',
			function ( $matches ) use ( $url ) {
				return 'src="' . esc_url( $url ) . '"';
			},
			$html
		);
	}

	/**
	 * Update alt attribute in HTML (for images).
	 *
	 * @param string $html The original HTML.
	 * @param string $alt  The new alt text.
	 * @return string The updated HTML.
	 */
	private static function update_alt_in_html( $html, $alt ) {
		// Replace alt attribute value.
		if ( preg_match( '/alt="[^"]*"/', $html ) ) {
			return preg_replace_callback(
				'/alt="[^"]*"/',
				function ( $matches ) use ( $alt ) {
					return 'alt="' . esc_attr( $alt ) . '"';
				},
				$html
			);
		}

		// Add alt if not present.
		return preg_replace_callback(
			'/<img/',
			function ( $matches ) use ( $alt ) {
				return '<img alt="' . esc_attr( $alt ) . '"';
			},
			$html
		);
	}

	/**
	 * Update href attribute in HTML (for links/buttons).
	 *
	 * @param string $html The original HTML.
	 * @param string $href The new href value.
	 * @return string The updated HTML.
	 */
	private static function update_href_in_html( $html, $href ) {
		return preg_replace_callback(
			'/href="[^"]*"/',
			function ( $matches ) use ( $href ) {
				return 'href="' . esc_url( $href ) . '"';
			},
			$html
		);
	}

	/**
	 * Update link text in HTML.
	 *
	 * @param string $html The original HTML.
	 * @param string $text The new link text.
	 * @return string The updated HTML.
	 */
	private static function update_link_text_in_html( $html, $text ) {
		return preg_replace_callback(
			'/(<a[^>]*>)(.*?)(<\/a>)/s',
			function ( $matches ) use ( $text ) {
				return $matches[1] . wp_kses_post( $text ) . $matches[3];
			},
			$html
		);
	}

	/**
	 * RDB-specific HTML fixes.
	 *
	 * @param string $content Content from block binding.
	 * @return string Content with RDB-specific fixes applied.
	 */
	private static function cleanup_rdb_html( $content ) {
		if ( preg_match( '/^(.*<span class="rdb-block-label">.*<\/span>)(.*)$/is', $content, $matches ) ) {
			$content = $matches[1] . ': ' . $matches[2];
		}
		return wp_kses_post( $content );
	}

	/**
	 * Convert a Gutenberg block to an MJML component.
	 * MJML component will be put in an mj-column in an mj-section for consistent layout,
	 * unless it's a group or a columns block.
	 *
	 * @param WP_Block $block The block.
	 * @param bool     $is_in_column Whether the component is a child of a column component.
	 * @param bool     $is_in_group Whether the component is a child of a group component.
	 * @param array    $default_attrs Default attributes for the component.
	 * @param bool     $is_in_list_or_quote Whether the component is a child of a list or quote block.
	 * @return string MJML component.
	 */
	public static function render_mjml_component( $block, $is_in_column = false, $is_in_group = false, $default_attrs = [], $is_in_list_or_quote = false ) {
		/**
		 * Filter to short-circuit the markup generation for a block.
		 *
		 * @param string|null $markup The markup to return. If null, the default markup will be generated.
		 * @param WP_Block    $block The block.
		 * @param bool        $is_in_column Whether the component is a child of a column component.
		 * @param bool        $is_in_group Whether the component is a child of a group component.
		 * @param array       $default_attrs Default attributes for the component.
		 * @param bool        $is_in_list_or_quote Whether the component is a child of a list or quote block.
		 * @param int         $newsletter_id The newsletter post ID.
		 *
		 * @return string|null The markup to return. If null, the default markup will be generated.
		 */
		$markup = apply_filters( 'newspack_newsletters_render_mjml_component', null, $block, $is_in_column, $is_in_group, $default_attrs, $is_in_list_or_quote, self::$newsletter_id );
		if ( null !== $markup ) {
			return $markup;
		}

		$block_name    = $block['blockName'];
		$attrs         = $block['attrs'];
		$inner_blocks  = $block['innerBlocks'];
		$inner_html    = $block['innerHTML'];
		$inner_content = isset( $block['innerContent'] ) ? $block['innerContent'] : [ $inner_html ];

		if ( ! isset( $attrs['innerBlocksToInsert'] ) && self::is_empty_block( $block ) ) {
			return '';
		}

		// Verify if block is configured to be web-only.
		if ( isset( $attrs['newsletterVisibility'] ) && 'web' === $attrs['newsletterVisibility'] ) {
			return '';
		}

		$block_mjml_markup = '';
		$attrs             = self::process_attributes( array_merge( $default_attrs, $attrs ) );

		$conditionals = [];
		if ( ! empty( $attrs['conditionalBefore'] ) && ! empty( $attrs['conditionalAfter'] ) ) {
			$conditionals = [
				'before' => $attrs['conditionalBefore'],
				'after'  => $attrs['conditionalAfter'],
			];
		}

		// Save margin before stripping unsupported attrs — it will be used as section padding.
		$block_margin = isset( $attrs['margin'] ) ? $attrs['margin'] : null;

		// Remove block-only attributes and attributes that are not supported by MJML.
		$unsupported_attrs = [
			'newsletterVisibility',
			'conditionalBefore',
			'conditionalAfter',
			'customBackgroundColor',
			'customTextColor',
			'customFontSize',
			'fontSize',
			'backgroundColor',
			'borderColor',
			'margin',
			'style',
		];
		foreach ( $unsupported_attrs as $attr ) {
			if ( isset( $attrs[ $attr ] ) ) {
				unset( $attrs[ $attr ] );
			}
		}

		// Default attributes for the section which will envelop the mj-column.
		// Use the block's margin (if any) as the section's padding, since MJML
		// doesn't support margins — section padding is the equivalent of outer spacing.
		// Exclude visual properties (border, borderRadius) from the section — they
		// belong on the inner content, not on the outer spacing wrapper.
		$section_only_attrs = array_diff_key(
			$attrs,
			array_flip( [ 'border', 'borderRadius', 'border-color' ] )
		);
		$section_attrs = array_merge(
			$section_only_attrs,
			array(
				'padding' => $block_margin ? $block_margin : '0',
			)
		);

		// Default attributes for the column which will envelop the component.
		// Border goes on the column (not section or mj-text) since mj-column
		// supports border while mj-text does not.
		$column_attrs = array(
			'padding' => isset( $attrs['padding'] ) ? $attrs['padding'] : '12px',
		);
		if ( isset( $attrs['border'] ) ) {
			$column_attrs['border'] = $attrs['border'];
		}
		if ( isset( $attrs['borderRadius'] ) ) {
			$column_attrs['border-radius'] = $attrs['borderRadius'];
		}

		$font_family = 'core/heading' === $block_name ? self::$font_header : self::$font_body;

		if ( ! empty( $inner_html ) ) {
			// Replace <mark /> with <span />.
			$inner_html = preg_replace( '/<mark\s(.+?)>(.+?)<\/mark>/is', '<span $1>$2</span>', $inner_html );

			// Remove styles from inner html that are handled by MJML attributes on the container.
			$styles_to_strip = [];
			if ( isset( $attrs['border'] ) || isset( $attrs['padding'] ) ) {
				$styles_to_strip = array_merge( $styles_to_strip, [ 'border', 'padding' ] );
			}
			if ( $block_margin ) {
				$styles_to_strip[] = 'margin';
			}
			if ( ! empty( $styles_to_strip ) ) {
				$inner_html = self::remove_unwanted_style_properties( $styles_to_strip, $inner_html );
			}
		}

		switch ( $block_name ) {
			/**
			 * Text-based blocks.
			 */
			case 'core/paragraph':
			case 'core/heading':
			case 'core/site-title':
			case 'core/site-tagline':
			case 'newspack-newsletters/share':
				$text_attrs = array_merge(
					array(
						'padding'     => '0',
						'line-height' => '1.5',
						'font-size'   => '16px',
						'font-family' => $font_family,
					),
					$attrs
				);

				if ( 'newspack-newsletters/share' === $block_name && ! self::$post_permalink ) {
					// If there's no permalink (which is not set if the post is not public), the share link has no utility.
					return '';
				}

				if ( 'core/site-tagline' === $block_name ) {
					$inner_html = get_bloginfo( 'description' );
				}

				if ( 'core/site-title' === $block_name ) {
					$inner_html = get_bloginfo( 'name' );
					$tag_name   = 'h1';
					if ( isset( $attrs['level'] ) ) {
						$tag_name = 0 === $attrs['level'] ? 'p' : 'h' . (int) $attrs['level'];
					}
					if ( ! ( isset( $attrs['isLink'] ) && ! $attrs['isLink'] ) ) {
						$link_attrs = array(
							'href="' . esc_url( get_bloginfo( 'url' ) ) . '"',
						);
						if ( isset( $attrs['linkTarget'] ) && '_blank' === $attrs['linkTarget'] ) {
							$link_attrs[] = 'target="_blank"';
						}
						$inner_html = sprintf( '<a %1$s>%2$s</a>', implode( ' ', $link_attrs ), esc_html( $inner_html ) );
					}
					$inner_html = sprintf( '<%1$s>%2$s</%1$s>', $tag_name, $inner_html );
				}

				// Initialize the MJML markup.
				$block_mjml_markup = '';

				// Only mj-text has to use container-background-color attr for background color.
				if ( isset( $text_attrs['background-color'] ) ) {
					$text_attrs['container-background-color'] = $text_attrs['background-color'];
					unset( $text_attrs['background-color'] );
				}

				// Padding is applied to the container element, so we need to remove it from block attributes.
				$text_attrs['padding'] = '0';

				// Handle link colors.
				if ( isset( $attrs['link'] ) ) {
					// Apply inline style to links.
					$inner_html = preg_replace(
						'/<a([^>]*?)>/i',
						'<a$1 style="color: ' . esc_attr( $attrs['link'] ) . ';">',
						$inner_html
					);
				}

				// Avoid wrapping markup in `mj-text` if the block is an inner block.
				$block_mjml_markup .= $is_in_list_or_quote ? $inner_html : '<mj-text ' . self::array_to_attributes( $text_attrs ) . '>' . $inner_html . '</mj-text>';
				break;

			/**
			 * Site logo block.
			 */
			case 'core/site-logo':
				$custom_logo_id = get_theme_mod( 'custom_logo' );
				$image          = wp_get_attachment_image_src( $custom_logo_id, 'full' );
				$markup         = '';
				if ( ! empty( $image ) ) {
					$img_attrs = array(
						'padding' => '0',
						'width'   => sprintf( '%spx', isset( $attrs['width'] ) ? $attrs['width'] : '125' ),
						'align'   => isset( $attrs['align'] ) ? $attrs['align'] : 'left',
						'src'     => $image[0],
						'href'    => isset( $attrs['isLink'] ) && ! $attrs['isLink'] ? '' : esc_url( home_url( '/' ) ),
						'target'  => isset( $attrs['linkTarget'] ) && '_blank' === $attrs['linkTarget'] ? '_blank' : '',
						'alt'     => self::get_image_alt( $custom_logo_id ),
					);
					$markup   .= '<mj-image ' . self::array_to_attributes( $img_attrs ) . ' />';
				}
				$block_mjml_markup = $markup;
				break;

			/**
			 * Image block.
			 */
			case 'core/image':
				// Parse block content.
				$dom = new DomDocument();
				libxml_use_internal_errors( true );
				$dom->loadHTML( mb_convert_encoding( $inner_html, 'HTML-ENTITIES', get_bloginfo( 'charset' ) ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
				$img        = $dom->getElementsByTagName( 'img' )->item( 0 );
				$img_src    = $img->getAttribute( 'src' );
				$img_alt    = self::get_image_alt( $attrs['id'] ?? '' );
				$figcaption = $dom->getElementsByTagName( 'figcaption' )->item( 0 );

				$img_attrs = array(
					'padding' => '0',
					'align'   => isset( $attrs['align'] ) ? $attrs['align'] : 'left',
					'src'     => $img_src,
					'alt'     => $img_alt,
				);

				if ( isset( $attrs['sizeSlug'] ) ) {
					if ( 'medium' == $attrs['sizeSlug'] ) {
						$img_attrs['width'] = '300px';
					}
					if ( 'thumbnail' == $attrs['sizeSlug'] ) {
						$img_attrs['width'] = '150px';
					}
				} elseif ( isset( $attrs['className'] ) ) {
					if ( 'size-medium' == $attrs['className'] ) {
						$img_attrs['width'] = '300px';
					}
					if ( 'size-thumbnail' == $attrs['className'] ) {
						$img_attrs['width'] = '150px';
					}
				}
				if ( isset( $attrs['width'] ) ) {
					$img_attrs['width'] = $attrs['width'] . 'px';
				}
				if ( isset( $attrs['height'] ) ) {
					$img_attrs['height'] = $attrs['height'] . 'px';
				}
				if ( isset( $attrs['href'] ) ) {
					$img_attrs['href'] = $attrs['href'];
				} else {
					$maybe_link = $img->parentNode;// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $maybe_link && 'a' === $maybe_link->nodeName && $maybe_link->getAttribute( 'href' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$img_attrs['href'] = trim( $maybe_link->getAttribute( 'href' ) );
					}
				}
				if ( isset( $attrs['className'] ) && strpos( $attrs['className'], 'is-style-rounded' ) !== false ) {
					$img_attrs['border-radius'] = '999px';
				}
				// Apply border to the image itself (matching WordPress core which
				// puts image borders on <img>, not <figure>).
				if ( isset( $attrs['border'] ) ) {
					$img_attrs['border'] = $attrs['border'];
				}
				if ( isset( $attrs['borderRadius'] ) ) {
					$img_attrs['border-radius'] = $attrs['borderRadius'];
				}
				$markup = '<mj-image ' . self::array_to_attributes( $img_attrs ) . ' />';

				if ( $figcaption ) {
					$caption_html  = '';
					$caption_nodes = $figcaption->childNodes; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					foreach ( $caption_nodes as $caption_node ) {
						$caption_html .= $dom->saveHTML( $caption_node );
					}
					$caption_attrs = array(
						'css-class'   => 'image-caption',
						'align'       => 'center',
						'color'       => '#555d66',
						'line-height' => '1.56',
						'font-size'   => '13px',
						'font-family' => $font_family,
					);
					$markup       .= '<mj-text ' . self::array_to_attributes( $caption_attrs ) . '>' . wp_kses(
						$caption_html,
						self::$allowed_inline_tags
					) . '</mj-text>';
				}

				$block_mjml_markup = $markup;
				break;

			/**
			 * Buttons block.
			 */
			case 'core/buttons':
				// Total percentage of button colunns with defined widths.
				$total_defined_width = array_reduce(
					$inner_blocks,
					function ( $acc, $block ) {
						if ( isset( $block['attrs']['width'] ) ) {
							$acc += intval( $block['attrs']['width'] );
						}
						return $acc;
					},
					0
				);

				// Number of button columns with no defined width.
				$no_widths = count(
					array_filter(
						$inner_blocks,
						function ( $block ) {
							return empty( $block['attrs']['width'] );
						}
					)
				);

				$is_multi_row  = false;
				$default_width = ! $no_widths ? 25 : max( 25, floor( ( 100 - $total_defined_width ) / $no_widths ) );
				$alignment     = isset( $attrs['layout'], $attrs['layout']['justifyContent'] ) ? $attrs['layout']['justifyContent'] : 'left';
				$wrapper_attrs = [
					'padding'    => '0',
					'text-align' => $alignment,
				];
				// Apply the buttons container's border and padding to the wrapper.
				if ( isset( $attrs['border'] ) ) {
					$wrapper_attrs['border'] = $attrs['border'];
				}
				if ( isset( $attrs['borderRadius'] ) ) {
					$wrapper_attrs['border-radius'] = $attrs['borderRadius'];
				}
				if ( isset( $attrs['padding'] ) ) {
					$wrapper_attrs['padding'] = $attrs['padding'];
				}
				// Strip border and padding from column_attrs so individual button
				// columns don't inherit the container's border or padding.
				unset( $column_attrs['border'], $column_attrs['border-radius'] );
				$column_attrs['padding'] = '12px';

				// If the total width of the buttons is greater than 100%, reduce the default width.
				if ( ( $default_width * $no_widths ) + $total_defined_width > 100 ) {
					$default_width = 25;
					$is_multi_row  = true;
				}

				$block_mjml_array      = [];
				$is_block_theme        = wp_is_block_theme();
				$default_bg            = $is_block_theme ? '#36f' : '#32373c';
				$default_border_radius = $is_block_theme ? '5px' : '999px';
				foreach ( $inner_blocks as $button_block ) {
					if ( empty( $button_block['innerHTML'] ) ) {
						break;
					}

					// Parse block content.
					$dom = new DomDocument();
					libxml_use_internal_errors( true );
					$dom->loadHTML( htmlspecialchars_decode( htmlentities( mb_convert_encoding( $button_block['innerHTML'], 'UTF-8', get_bloginfo( 'charset' ) ) ) ) );
					$xpath  = new DOMXpath( $dom );
					$anchor = $xpath->query( '//a' )[0];

					if ( ! $anchor ) {
						break;
					}

					$attrs         = self::process_attributes( $button_block['attrs'] );
					$text          = $anchor->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$border_radius = isset( $attrs['borderRadius'] ) ? $attrs['borderRadius'] : $default_border_radius;
					$is_outlined   = isset( $attrs['className'] ) && 'is-style-outline' == $attrs['className'];

					$default_button_attrs = array(
						'align'         => $alignment,
						'padding'       => '0',
						'inner-padding' => '12px 24px',
						'line-height'   => '1.5',
						'href'          => $anchor->getAttribute( 'href' ),
						'border-radius' => $border_radius,
						'font-size'     => ! empty( $attrs['font-size'] ) ? $attrs['font-size'] : '16px',
						'font-family'   => $font_family,
						'font-weight'   => 'bold',
						// Default color - will be replaced by get_colors if there are colors set.
						'color'         => $is_outlined ? $default_bg : '#fff !important',
					);
					if ( $is_outlined ) {
						$default_button_attrs['background-color'] = 'transparent';
					} else {
						$default_button_attrs['background-color'] = $default_bg;
					}
					if ( ! empty( $attrs['background-color'] ) ) {
						$default_button_attrs['background-color'] = $attrs['background-color'];
					}
					if ( ! empty( $attrs['color'] ) ) {
						$default_button_attrs['color'] = $attrs['color'];
					}

					if ( ! empty( $attrs['padding'] ) ) {
						$default_button_attrs['inner-padding'] = $attrs['padding'];
					}
					$button_attrs = array_merge(
						$default_button_attrs,
						self::get_colors( $attrs )
					);

					if ( $is_outlined ) {
						$button_attrs['css-class'] = $attrs['className'];
					}

					$column_attrs['css-class'] = 'mj-column-has-width';
					$column_width              = $default_width;
					if ( ! empty( $attrs['width'] ) ) {
						$column_width                  = $attrs['width'];
						$default_button_attrs['width'] = '100%'; // Buttons with defined width should fill their column.
					}
					$column_attrs['width'] = $column_width . '%';
					$block_mjml_array[] = [
						'<mj-column ' . self::array_to_attributes( $column_attrs ) . '>',
						'<mj-button ' . self::array_to_attributes( $default_button_attrs ) . ">$text</mj-button>",
						'</mj-column>',
					];
				}

				// Inner section only needs alignment — border and padding go on the wrapper.
				$inner_section_attrs = [
					'padding'    => '0',
					'text-align' => $alignment,
				];
				$markup = '<mj-section ' . self::array_to_attributes( $inner_section_attrs ) . '>';
				foreach ( $block_mjml_array as $block_mjml ) {
					$markup .= implode( $block_mjml );
				}
				$markup .= '</mj-section>';

				$block_mjml_markup = '<mj-wrapper ' . self::array_to_attributes( $wrapper_attrs ) . '>' . $markup . '</mj-wrapper>';
				break;

			/**
			 * Separator block.
			 */
			case 'core/separator':
				$is_wide       = isset( $block['attrs']['className'] ) && 'is-style-wide' === $block['attrs']['className'];
				$divider_attrs = array(
					'padding'      => '0',
					'border-width' => '1px',
					'width'        => $is_wide ? '100%' : '128px',
				);
				// Remove colors from section attrs.
				unset( $section_attrs['background-color'] );
				if ( isset( $block['attrs']['backgroundColor'] ) && isset( self::$color_palette[ $block['attrs']['backgroundColor'] ] ) ) {
					$divider_attrs['border-color'] = self::$color_palette[ $block['attrs']['backgroundColor'] ];
				}
				if ( isset( $block['attrs']['style']['color']['background'] ) ) {
					$divider_attrs['border-color'] = $block['attrs']['style']['color']['background'];
				}
				$block_mjml_markup .= '<mj-divider ' . self::array_to_attributes( $divider_attrs ) . '/>';

				break;

			/**
			 * Spacer block.
			 */
			case 'core/spacer':
				$attrs['height']    = $attrs['height'];
				$block_mjml_markup .= '<mj-spacer ' . self::array_to_attributes( $attrs ) . '/>';
				break;

			/**
			 * Social links block.
			 */
			case 'core/social-links':
				$social_wrapper_attrs = array(
					'icon-size'     => '24px',
					'mode'          => 'horizontal',
					'border-radius' => '999px',
					'icon-padding'  => isset( $attrs['className'] ) && 'is-style-filled-primary-text' === $attrs['className'] ? '0px' : '7px',
					'padding'       => '0',
				);
				if ( isset( $attrs['align'] ) ) {
					$social_wrapper_attrs['align'] = $attrs['align'];
				} else {
					$social_wrapper_attrs['align'] = 'left';
				}

				$markup = '<mj-social ' . self::array_to_attributes( $social_wrapper_attrs ) . '>';
				foreach ( $inner_blocks as $index => $link_block ) {
					if ( isset( $link_block['attrs']['url'] ) ) {
						$url = $link_block['attrs']['url'];
						// Handle older version of the block, where innner blocks we named `core/social-link-<service>`.
						$service_name = isset( $link_block['attrs']['service'] ) ? $link_block['attrs']['service'] : str_replace( 'core/social-link-', '', $link_block['blockName'] );
						$social_icon  = self::get_social_icon( $service_name, $attrs );

						if ( ! empty( $social_icon ) ) {
							$img_attrs = array(
								'href'             => $url,
								'src'              => plugins_url( 'assets/' . $social_icon['icon'], __DIR__ ),
								'background-color' => $social_icon['color'],
								'css-class'        => 'social-element',
								'padding'          => '8px',
							);

							if ( $index === 0 || $index === count( $inner_blocks ) - 1 ) {
								$img_attrs['padding-left']  = $index === 0 ? '0' : '8px';
								$img_attrs['padding-right'] = $index === 0 ? '8px' : '0';
							}

							$markup .= '<mj-social-element ' . self::array_to_attributes( $img_attrs ) . '/>';
						}
					}
				}
				$block_mjml_markup .= $markup . '</mj-social>';

				break;

			/**
			 * Single Column block.
			 */
			case 'core/column':
				if ( isset( $attrs['verticalAlignment'] ) ) {
					if ( 'center' === $attrs['verticalAlignment'] ) {
						$column_attrs['vertical-align'] = 'middle';
					} else {
						$column_attrs['vertical-align'] = $attrs['verticalAlignment'];
					}
				}

				if ( isset( $attrs['width'] ) ) {
					$column_attrs['width']     = $attrs['width'];
					$column_attrs['css-class'] = 'mj-column-has-width';
				}

				$markup = '<mj-column ' . self::array_to_attributes( $column_attrs ) . '>';
				foreach ( $inner_blocks as $block ) {
					$markup .= self::render_mjml_component( $block, true, false, $default_attrs );
				}
				$block_mjml_markup = $markup . '</mj-column>';
				break;

			/**
			 * Columns block.
			 */
			case 'core/columns':
				// Some columns might have no width set.
				$widths_sum            = 0;
				$no_width_cols_indexes = [];
				foreach ( $inner_blocks as $i => $block ) {
					if ( isset( $block['attrs']['width'] ) ) {
						$widths_sum += floatval( $block['attrs']['width'] );
					} else {
						array_push( $no_width_cols_indexes, $i );
					}
				}
				foreach ( $no_width_cols_indexes as $no_width_cols_index ) {
					$inner_blocks[ $no_width_cols_index ]['attrs']['width'] = ( 100 - $widths_sum ) / count( $no_width_cols_indexes ) . '%';
				}

				// Recalculate total width including no-width columns that were just assigned.
				$total_width = 0;
				foreach ( $inner_blocks as $block ) {
					if ( isset( $block['attrs']['width'] ) ) {
						$total_width += floatval( $block['attrs']['width'] );
					}
				}

				// If total width exceeds 100%, adjust all columns proportionally.
				if ( $total_width > 100 ) {
					$excess = $total_width - 100;
					$adjustment_per_column = $excess / count( $inner_blocks );

					foreach ( $inner_blocks as $i => $block ) {
						if ( isset( $block['attrs']['width'] ) ) {
							$current_width = floatval( $block['attrs']['width'] );
							$new_width = max( 1, $current_width - $adjustment_per_column ); // Ensure minimum 1% width.
							$inner_blocks[ $i ]['attrs']['width'] = $new_width . '%';
						}
					}
				}

				if ( isset( $attrs['color'] ) ) {
					$default_attrs['color'] = $attrs['color'];
				}

				// Apply the columns block's border and padding to the section.
				if ( isset( $attrs['border'] ) ) {
					$section_attrs['border'] = $attrs['border'];
				}
				if ( isset( $attrs['borderRadius'] ) ) {
					$section_attrs['border-radius'] = $attrs['borderRadius'];
				}
				if ( isset( $attrs['padding'] ) ) {
					$section_attrs['padding'] = $attrs['padding'];
				}

				$stack_on_mobile = ! isset( $attrs['isStackedOnMobile'] ) || true === $attrs['isStackedOnMobile'];
				if ( ! $stack_on_mobile ) {
					$markup = '<mj-group>';
				} else {
					$markup = '';
				}
				foreach ( $inner_blocks as $block ) {
					$markup .= self::render_mjml_component( $block, true, false, $default_attrs );
				}
				if ( ! $stack_on_mobile ) {
					$markup .= '</mj-group>';
				}
				$block_mjml_markup = $markup;
				break;

			/**
			 * Newspack Newsletters Posts Inserter block template.
			 */
			case 'newspack-newsletters/posts-inserter':
				$markup = '';
				foreach ( $attrs['innerBlocksToInsert'] as $block ) {
					$markup .= self::render_mjml_component( $block );
				}
				$block_mjml_markup = $markup;
				break;

			/**
			 * List, list item, and quote blocks.
			 * These blocks may or may not contain innerBlocks with their actual content.
			 */
			case 'core/list':
			case 'core/list-item':
			case 'core/quote':
				$text_attrs = array_merge(
					array(
						'padding'     => '0',
						'line-height' => '1.5',
						'font-size'   => '16px',
						'font-family' => $font_family,
					),
					$attrs
				);

				// When wrapping in a column (top-level list/quote), padding goes
				// on the column, not the mj-text — avoid doubling up.
				if ( ! $is_in_list_or_quote ) {
					$text_attrs['padding'] = '0';
				}

				// If a wrapper block, wrap in mj-text.
				if ( ! $is_in_list_or_quote ) {
					$block_mjml_markup .= '<mj-text ' . self::array_to_attributes( $text_attrs ) . '>';
				}

				$block_mjml_markup .= $inner_content[0];
				if ( ! empty( $inner_blocks ) && 1 < count( $inner_content ) ) {
					foreach ( $inner_blocks as $inner_block ) {
						$block_mjml_markup .= self::render_mjml_component( $inner_block, false, false, [], true );
					}
					$block_mjml_markup .= $inner_content[ count( $inner_content ) - 1 ];
				}

				if ( ! $is_in_list_or_quote ) {
					$block_mjml_markup .= '</mj-text>';

					// List and quote blocks skip generic column wrapping (they are
					// "grouped blocks"). Add an explicit column wrapper so borders
					// and padding from the block are applied — mj-text does not
					// support border, but mj-column does.
					$list_quote_col_attrs = [
						'padding' => isset( $attrs['padding'] ) ? $attrs['padding'] : '12px',
					];
					if ( isset( $attrs['border'] ) ) {
						$list_quote_col_attrs['border'] = $attrs['border'];
					}
					if ( isset( $attrs['borderRadius'] ) ) {
						$list_quote_col_attrs['border-radius'] = $attrs['borderRadius'];
					}
					$block_mjml_markup = '<mj-column ' . self::array_to_attributes( $list_quote_col_attrs ) . '>' . $block_mjml_markup . '</mj-column>';
				}

				break;

			/**
			 * Group block.
			 */
			case 'core/group':
				// There's no color attribute on mj-wrapper, so it has to be passed to children.
				// https://github.com/mjmlio/mjml/issues/1881 .
				if ( isset( $attrs['color'] ) ) {
					$default_attrs['color'] = $attrs['color'];
				}

				$markup = '<mj-wrapper ' . self::array_to_attributes( $attrs ) . '>';
				foreach ( $inner_blocks as $block ) {
					$markup .= self::render_mjml_component( $block, false, true, $default_attrs );
				}
				$block_mjml_markup = $markup . '</mj-wrapper>';
				break;

			/**
			 * Embed block.
			 */
			case 'core/embed':
				/**
				 * Filters the retrieval of the WP oEmbed object. Used for testing purposes
				 *
				 * @param WP_oEmbed $oembed WP_oEmbed object.
				 */
				$oembed = apply_filters( 'newspack_newsletters_get_oembed_object', _wp_oembed_get_object() );
				$data = $oembed->get_data( $attrs['url'] );

				if ( ! $data || empty( $data->type ) ) {
					break;
				}

				$text_attrs = array(
					'padding'     => '0',
					'line-height' => '1.5',
					'font-size'   => '16px',
					'font-family' => $font_family,
				);

				$caption_attrs = array(
					'align'       => 'center',
					'color'       => '#555d66',
					'line-height' => '1.56',
					'font-size'   => '13px',
					'font-family' => $font_family,
				);

				// Parse block caption.
				$dom = new DomDocument();
				libxml_use_internal_errors( true );
				$dom->loadHTML( htmlspecialchars_decode( htmlentities( mb_convert_encoding( $inner_html, 'UTF-8', get_bloginfo( 'charset' ) ) ) ) );
				$xpath      = new DOMXpath( $dom );
				$figcaption = $xpath->query( '//figcaption/text()' )[0];
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$caption = ! empty( $figcaption->wholeText ) && is_string( $figcaption->wholeText ) ? $figcaption->wholeText : '';
				if ( empty( $caption ) && ! empty( $data->title ) && is_string( $data->title ) ) {
					$caption = $data->title;
				}

				$markup = '';

				switch ( $data->type ) {
					case 'photo':
						if ( empty( $data->url ) || empty( $data->width ) || empty( $data->height ) ) {
							break;
						}
						if ( ! is_string( $data->url ) || ! is_numeric( $data->width ) || ! is_numeric( $data->height ) ) {
							break;
						}
						$img_attrs = array(
							'src'    => $data->url,
							'alt'    => $caption,
							'width'  => $data->width,
							'height' => $data->height,
							'href'   => $attrs['url'],
						);
						$markup .= '<mj-image ' . self::array_to_attributes( $img_attrs ) . ' />';
						if ( ! empty( $caption ) ) {
							$markup .= '<mj-text ' . self::array_to_attributes( $caption_attrs ) . '>' . esc_html( $caption ) . ' - ' . esc_html( $data->provider_name ) . '</mj-text>';
						}
						break;
					case 'video':
						if ( ! empty( $data->thumbnail_url ) ) {
							$img_attrs = array(
								'padding' => '0',
								'src'     => $data->thumbnail_url,
								'width'   => $data->thumbnail_width . 'px',
								'height'  => $data->thumbnail_height . 'px',
								'href'    => $attrs['url'],
							);
							$markup   .= '<mj-image ' . self::array_to_attributes( $img_attrs ) . ' />';
							if ( ! empty( $caption ) ) {
								$markup .= '<mj-text ' . self::array_to_attributes( $caption_attrs ) . '>' . esc_html( $caption ) . ' - ' . esc_html( $data->provider_name ) . '</mj-text>';
							}
						} elseif ( ! empty( $caption ) ) {
							$markup .= '<mj-text ' . self::array_to_attributes( $text_attrs ) . '><a href="' . esc_url( $attrs['url'] ) . '">' . esc_html( $caption ) . '</a></mj-text>';
						}
						break;
					case 'rich':
						$html = wp_kses( (string) $data->html, Newspack_Newsletters_Embed::$allowed_html );
						if ( ! empty( $html ) ) {
							$markup .= '<mj-text ' . self::array_to_attributes( $text_attrs ) . '>' . $html . '</mj-text>';
						} elseif ( ! empty( $caption ) ) {
							$markup .= '<mj-text ' . self::array_to_attributes( $text_attrs ) . '><a href="' . esc_url( $attrs['url'] ) . '">' . esc_html( $caption ) . '</a></mj-text>';
						}
						break;
					case 'link':
						if ( ! empty( $caption ) ) {
							$markup .= '<mj-text ' . self::array_to_attributes( $text_attrs ) . '><a href="' . esc_url( $attrs['url'] ) . '">' . esc_html( $caption ) . '</a></mj-text>';
						}
						break;
				}
				$block_mjml_markup = $markup;
				break;
			case 'newspack-newsletters/ad':
				$ad_post = false;
				if ( ! empty( $attrs['adId'] ) ) {
					if ( strpos( $attrs['adId'], 'placement:' ) === 0 ) {
						$ad_post = Newspack_Newsletters\Ads_Placements::get_ad_by_placement( str_replace( 'placement:', '', $attrs['adId'] ), self::$newsletter_id );
					} else {
						$ad_post = get_post( $attrs['adId'] );
					}
				} elseif ( ! empty( self::$newsletter_id ) ) {
					$ads = Newspack_Newsletters\Ads::get_newsletter_ads( self::$newsletter_id );
					foreach ( $ads as $ad ) {
						if ( ! Newspack_Newsletters\Ads::is_ad_inserted( self::$newsletter_id, $ad->ID ) ) {
							$ad_post = $ad;
							break;
						}
					}
				}
				if ( $ad_post ) {
					$block_mjml_markup = self::post_to_mjml_components( $ad_post );
					if ( ! empty( self::$newsletter_id ) ) {
						Newspack_Newsletters\Ads::mark_ad_inserted( self::$newsletter_id, $ad_post->ID );
					}
				}
				break;

			/**
			 * Remote Data Blocks.
			 *
			 * These blocks use WordPress Block Bindings API--values are
			 * resolved at render time, not persisted to post content.
			 *
			 * Following recent changes, binding values are not persisted to
			 * post content; we resolve using RDB's BlockBindings methods.
			 */
			case 'remote-data-blocks/foundation-event':
			case 'remote-data-blocks/foundation-events':
			case 'remote-data-blocks/foundation-location':
			case 'remote-data-blocks/foundation-locations':
			case 'remote-data-blocks/foundation-movie':
			case 'remote-data-blocks/foundation-movies':
			case 'remote-data-blocks/template':
				// Existing (broken) handling if no RDB support available.
				if ( ! class_exists( 'RemoteDataBlocks\Editor\DataBinding\BlockBindings' ) ) {
					$markup = '';
					foreach ( $inner_blocks as $block ) {
						$markup .= self::render_mjml_component( $block, $is_in_column, $is_in_group, $default_attrs );
					}
					$block_mjml_markup = $markup;
					break;
				}

				// Attempt to propagate RDB context to inner blocks.
				$remote_data = self::get_rdb_context( $block );
				$results = $remote_data['results'] ?? [];

				// Expand for multiple results, or add index 0 for single result.
				if ( count( $results ) > 1 ) {
					$blocks_to_render = self::expand_rdb_template_blocks( $inner_blocks, $results, $remote_data );
				} else {
					// Single result or empty - add index 0.
					$source_args = [
						'block'            => $remote_data['blockName'] ?? '',
						'enabledOverrides' => $remote_data['enabledOverrides'] ?? [],
						'index'            => 0,
						'queryKey'         => $remote_data['queryKey'] ?? null,
						'queryInputs'      => $remote_data['queryInputs'] ?? null,
					];
					$blocks_to_render = self::clone_blocks_with_index( $inner_blocks, $source_args );
				}

				// Resolve bindings and update innerHTML.
				$resolved_blocks = array_map(
					function ( $b ) use ( $remote_data ) {
						return self::resolve_rdb_block_bindings( $b, $remote_data );
					},
					$blocks_to_render
				);

				// Render through normal MJML pipeline.
				$markup = '';
				foreach ( $resolved_blocks as $resolved_block ) {
					$markup .= self::render_mjml_component( $resolved_block, $is_in_column, $is_in_group, $default_attrs );
				}
				$block_mjml_markup = $markup;
				break;

		}

		$is_posts_inserter_block = 'newspack-newsletters/posts-inserter' == $block_name;
		$is_grouped_block        = in_array( $block_name, [ 'core/group', 'core/list', 'core/list-item', 'core/quote' ], true );
		// Remote Data Blocks require special handling to resolve bindings.
		$is_rdb_block = strpos( $block_name, 'remote-data-blocks/' ) === 0;

		if (
			! $is_in_column &&
			! $is_in_list_or_quote &&
			! $is_grouped_block &&
			! $is_rdb_block &&
			'core/buttons' != $block_name &&
			'core/columns' != $block_name &&
			'core/column' != $block_name &&
			'core/separator' != $block_name &&
			! $is_posts_inserter_block
		) {
			// For image blocks, the border is on the mj-image element (matching
			// WordPress core), so remove it from the column wrapper.
			if ( 'core/image' === $block_name ) {
				unset( $column_attrs['border'], $column_attrs['border-radius'] );
			}
			$column_attrs['width'] = '100%';
			$block_mjml_markup     = '<mj-column ' . self::array_to_attributes( $column_attrs ) . '>' . $block_mjml_markup . '</mj-column>';
		}

		if ( ! $is_in_column && ! $is_in_list_or_quote && ! $is_posts_inserter_block && ! $is_rdb_block ) {
			$block_mjml_markup = '<mj-section ' . self::array_to_attributes( $section_attrs ) . '>' . $block_mjml_markup . '</mj-section>';
		}

		if ( ! empty( $conditionals ) ) {
			$block_mjml_markup = '<mj-raw>' . $conditionals['before'] . '</mj-raw>' . $block_mjml_markup . '<mj-raw>' . $conditionals['after'] . '</mj-raw>';
		}

		return $block_mjml_markup;
	}

	/** Convert a WP post to an array of non-empty blocks.
	 *
	 * @param WP_Post $post The post.
	 * @return array[] Blocks.
	 */
	private static function get_valid_post_blocks( $post ) {
		// Disable photon for newsletter images (webp is not supported on some email clients).
		add_filter( 'jetpack_photon_skip_image', '__return_true' );
		/**
		 * Filters the newsletter post content before parsing it into blocks.
		 *
		 * @param string  $content The post content.
		 * @param WP_Post $post    The post object.
		 */
		$content = apply_filters( 'newspack_newsletters_newsletter_content', $post->post_content, $post );
		return array_filter(
			parse_blocks( $content ),
			function ( $block ) {
				return null !== $block['blockName'];
			}
		);
	}

	/**
	 * Convert a WP post to MJML components.
	 *
	 * @param WP_Post $post The post.
	 *
	 * @return string MJML markup to be injected into the template.
	 */
	public static function post_to_mjml_components( $post ) {
		$body         = '';
		$valid_blocks = self::get_valid_post_blocks( $post );

		// Build MJML body.
		foreach ( $valid_blocks as $block ) {
			$block_content = '';

			// Convert reusable block to group block.
			// Reusable blocks are CPTs, where the block's ref attribute is the post ID.
			if ( 'core/block' === $block['blockName'] && isset( $block['attrs']['ref'] ) ) {
				$reusable_block_post = get_post( $block['attrs']['ref'] );
				if ( ! empty( $reusable_block_post ) ) {
					$block['blockName']    = 'core/group';
					$block['innerBlocks']  = self::get_valid_post_blocks( $reusable_block_post );
					$block['innerHTML']    = $reusable_block_post->post_content;
					$block['innerContent'] = $reusable_block_post->post_content;
				}
			}

			if ( 'core/group' === $block['blockName'] ) {
				$default_attrs = [];
				$attrs         = self::process_attributes( $block['attrs'] );
				$conditionals  = [];
				if ( ! empty( $attrs['conditionalBefore'] ) && ! empty( $attrs['conditionalAfter'] ) ) {
					$conditionals = [
						'before' => $attrs['conditionalBefore'],
						'after'  => $attrs['conditionalAfter'],
					];
				}
				if ( isset( $attrs['color'] ) ) {
					$default_attrs['color'] = $attrs['color'];
				}
				$mjml_markup = '<mj-wrapper ' . self::array_to_attributes( $attrs ) . '>';
				foreach ( $block['innerBlocks'] as $block ) {
					$inner_block_content = self::render_mjml_component( $block, false, true, $default_attrs );
					$mjml_markup        .= $inner_block_content;
				}
				$block_content = $mjml_markup . '</mj-wrapper>';
				if ( ! empty( $conditionals ) ) {
					$block_content = '<mj-raw>' . $conditionals['before'] . '</mj-raw>' . $block_content . '<mj-raw>' . $conditionals['after'] . '</mj-raw>';
				}
			} else {
				$block_content = self::render_mjml_component( $block );
			}

			$body .= $block_content;
		}

		return self::process_links( $body, $post );
	}

	/**
	 * Convert a WP post to MJML markup.
	 *
	 * @param WP_Post $post The post.
	 * @return string MJML markup.
	 */
	public static function render_post_to_mjml( $post ) {
		self::$newsletter_id = $post->ID;
		self::$color_palette = json_decode( get_option( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_PALETTE_META, false ), true );
		self::$font_header   = get_post_meta( $post->ID, 'font_header', true );
		self::$font_body     = get_post_meta( $post->ID, 'font_body', true );
		$is_public           = get_post_meta( $post->ID, 'is_public', true );

		if ( $is_public ) {
			self::$post_permalink = get_permalink( $post->ID );
		}
		if ( ! in_array( self::$font_header, Newspack_Newsletters::$supported_fonts ) ) {
			self::$font_header = 'Arial';
		}
		if ( ! in_array( self::$font_body, Newspack_Newsletters::$supported_fonts ) ) {
			self::$font_body = 'Georgia';
		}

		$updated = time();
		$title   = $post->post_title; // phpcs:ignore WordPressVIPMinimum.Variables.VariableAnalysis.UnusedVariable

		/**
		 * Generate a string of MJML as the body of the email. We include ads at this stage.
		 */
		$body             = self::post_to_mjml_components( $post ); // phpcs:ignore WordPressVIPMinimum.Variables.VariableAnalysis.UnusedVariable
		$background_color = get_post_meta( $post->ID, 'background_color', true );
		$text_color       = get_post_meta( $post->ID, 'text_color', true );
		$preview_text     = self::get_preview_text( $post );
		$custom_css       = get_post_meta( $post->ID, 'custom_css', true );
		if ( ! $background_color ) {
			$background_color = '#ffffff';
		}
		if ( ! $text_color ) {
			$text_color = '#000000';
		}

		ob_start();
		include __DIR__ . '/email-template.mjml.php';
		return ob_get_clean();
	}

	/**
	 * Retrieve or build a preview text string.
	 * Strip all HTML + merge tags, and truncate to 60 words.
	 *
	 * @param WP_Post $post The post.
	 *
	 * @return string The preview text.
	 */
	public static function get_preview_text( $post ) {
		$preview_text = get_post_meta( $post->ID, 'preview_text', true );
		if ( ! $preview_text ) {
			$preview_text = wp_trim_words( wp_strip_all_tags( get_the_content( null, false, $post ) ), 60 );
		}
		return self::strip_all_merge_tags( $preview_text );
	}

	/**
	 * Strip all Mailchimp merge tag strings from a string.
	 *
	 * @param string $string The string.
	 *
	 * @return string The string with all merge tags stripped.
	 */
	public static function strip_all_merge_tags( $string ) {
		return preg_replace( '/\*\|[^|]+\|\*/', '', $string );
	}

	/**
	 * Retrieve email-compliant HTML for a newsletter CPT.
	 *
	 * @param WP_Post $post The post.
	 * @return string email-compliant HTML.
	 */
	public static function retrieve_email_html( $post ) {
		return get_post_meta( $post->ID, Newspack_Newsletters::EMAIL_HTML_META, true );
	}
}
