<?php
/**
 * Avatar Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Avatar;

use Newspack;
use Newspack\Bylines;

defined( 'ABSPATH' ) || exit;

/**
 * Avatar_Block Class
 */
final class Avatar_Block {
	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'init', [ __CLASS__, 'register_block_styles' ] );
	}

	/**
	 * Register newspack avatar block styles.
	 *
	 * @return void
	 */
	public static function register_block_styles(): void {
		if ( is_plugin_active( 'co-authors-plus/co-authors-plus.php' ) && function_exists( 'get_coauthors' ) ) {
			register_block_style(
				'newspack/avatar',
				[
					'name'  => 'overlapped',
					'label' => __( 'Overlapped', 'newspack-plugin' ),
				]
			);
		}
	}

	/**
	 * Register newpack avatar block.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
				'uses_context'    => [ 'postId', 'postType', 'newspack-blocks/author' ],
			]
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array     $attributes The block attributes.
	 * @param string    $content    The block content.
	 * @param \WP_Block $block      The block.
	 *
	 * @return string The block HTML.
	 */
	public static function render_block( array $attributes, string $content, \WP_Block $block ): string {
		$image_size     = $attributes['size'] ?? 48;
		$link_to_author = $attributes['linkToAuthorArchive'] ?? false;

		// Check for parent block context first (nested mode - single author).
		$author_from_parent = $block->context['newspack-blocks/author'] ?? null;
		if ( ! empty( $author_from_parent ) ) {
			return self::render_single_author_avatar( $author_from_parent, $attributes );
		}

		// Standalone mode: get authors from post context.
		$post_id = $block->context['postId'] ?? null;

		if ( empty( $post_id ) ) {
			return '';
		}

		$authors = self::get_avatar_authors( $post_id );

		if ( empty( $authors ) ) {
			return '';
		}

		$overlap_mask_style = self::get_overlap_mask_style( $attributes );
		$wrapper_style      = '--avatar-size: ' . esc_attr( $image_size ) . 'px;';
		if ( $overlap_mask_style ) {
			$wrapper_style .= ' ' . $overlap_mask_style;
		}

		$wrapper_attributes = get_block_wrapper_attributes( [ 'style' => $wrapper_style ] );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php
			foreach ( $authors as $index => $author ) :
				self::render_avatar_image(
					get_avatar_url( $author->ID, [ 'size' => $image_size * 2 ] ),
					$author->display_name,
					get_author_posts_url( $author->ID ),
					$image_size,
					$link_to_author,
					$attributes
				);
			endforeach;
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single author's avatar from parent block context.
	 *
	 * @param array $author     Author data from parent context.
	 * @param array $attributes Block attributes.
	 *
	 * @return string The avatar HTML.
	 */
	public static function render_single_author_avatar( array $author, array $attributes ): string {
		$image_size     = $attributes['size'] ?? 48;
		$link_to_author = $attributes['linkToAuthorArchive'] ?? false;

		// Resolve avatar at the block's own size. The parent context's 'avatar' key
		// signals that a real (non-default) avatar exists; avatarHideDefault controls
		// whether to fall back to the gravatar default when no custom avatar is set.
		$has_parent_avatar = ! empty( $author['avatar'] );
		$hide_default      = ! empty( $author['avatarHideDefault'] );

		if ( ! empty( $author['id'] ) && ( $has_parent_avatar || ! $hide_default ) ) {
			$avatar_url = get_avatar_url( $author['id'], [ 'size' => $image_size * 2 ] );
		} else {
			$avatar_url = '';
		}

		if ( empty( $avatar_url ) ) {
			return '';
		}

		$author_name = $author['name'] ?? '';
		$author_url  = $author['url'] ?? '';

		$wrapper_attributes = get_block_wrapper_attributes( [ 'style' => '--avatar-size: ' . esc_attr( $image_size ) . 'px;' ] );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php
			self::render_avatar_image(
				$avatar_url,
				$author_name,
				$author_url,
				$image_size,
				$link_to_author,
				$attributes
			);
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single avatar image, optionally wrapped in a link.
	 *
	 * @param string $avatar_url      Avatar image URL.
	 * @param string $author_name     Author display name (used as alt text).
	 * @param string $author_url      Author archive URL (empty to skip the link).
	 * @param int    $image_size      Avatar size in pixels.
	 * @param bool   $link_to_author  Whether to wrap the image in a link.
	 * @param array  $attributes      Block attributes (for border props).
	 *
	 * @return void Outputs directly (callers are inside ob_start).
	 */
	private static function render_avatar_image( string $avatar_url, string $author_name, string $author_url, int $image_size, bool $link_to_author, array $attributes ): void {
		$border_attributes = function_exists( 'get_block_core_avatar_border_attributes' )
			? get_block_core_avatar_border_attributes( $attributes )
			: [
				'class' => '',
				'style' => '',
			];

		$class     = 'avatar avatar-' . esc_attr( $image_size ) . ' photo wp-block-newspack-avatar__image ' . ( $border_attributes['class'] ?? '' );
		$show_link = $link_to_author && ! empty( $author_url );
		?>
		<div class="newspack-avatar-wrapper">
			<?php if ( $show_link ) : ?>
				<a href="<?php echo esc_url( $author_url ); ?>" class="wp-block-newspack-avatar__link">
			<?php endif; ?>
			<img
				src="<?php echo esc_url( $avatar_url ); ?>"
				class="<?php echo esc_attr( $class ); ?>"
				alt="<?php echo esc_attr( $author_name ); ?>"
				width="<?php echo esc_attr( $image_size ); ?>"
				height="<?php echo esc_attr( $image_size ); ?>"
				style="<?php echo esc_attr( $border_attributes['style'] ?? '' ); ?>"
			/>
			<?php if ( $show_link ) : ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Generate a --overlap-mask CSS custom property for the overlapped avatar style.
	 *
	 * Builds an SVG mask with a <rect rx="..."> cutout that adapts to the
	 * actual border-radius, producing a clean notch where the next avatar overlaps.
	 *
	 * @param array $attributes Block attributes.
	 *
	 * @return string CSS fragment (e.g. "--overlap-mask: url(data:...);") or empty string.
	 */
	private static function get_overlap_mask_style( array $attributes ): string {
		if ( false === strpos( $attributes['className'] ?? '', 'is-style-overlapped' ) ) {
			return '';
		}

		$radius = $attributes['style']['border']['radius'] ?? '100%';

		// Per-corner object: use the value if all corners are the same.
		if ( is_array( $radius ) ) {
			$values = array_values( $radius );
			if ( count( array_unique( $values ) ) === 1 ) {
				$radius = $values[0];
			} else {
				return '';
			}
		}

		if ( ! is_string( $radius ) ) {
			return '';
		}

		$image_size        = $attributes['size'] ?? 48;
		$cutout_scale      = 1.1; // The cutout rect is slightly larger than the avatar for a visible gap.
		$cutout_x_fraction = 0.75; // Horizontal position where the cutout starts, as a fraction of avatar size.
		$svg_size          = $image_size * $cutout_scale;
		$offset            = ( $svg_size - $image_size ) / 2;

		// Convert border-radius to px first.
		if ( str_ends_with( $radius, '%' ) ) {
			$radius_px = ( (float) $radius / 100 ) * $image_size;
		} elseif ( str_ends_with( $radius, 'rem' ) || str_ends_with( $radius, 'em' ) ) {
			// Approximate em/rem using the 16px browser default base font size.
			$radius_px = (float) $radius * 16;
		} else {
			// px or plain number.
			$radius_px = (float) $radius;
		}

		if ( ! is_finite( $radius_px ) ) {
			return '';
		}

		// Offset the rounded rectangle equally on all sides for a border-like shape.
		$cutout_rx = round( max( 0, min( $svg_size / 2, $radius_px + $offset ) ), 2 );

		// SVG mask: an internal <mask> uses luminance (white=visible, black=hidden)
		// to cut out the overlap notch, then a white rect is painted through it.
		// The result has real alpha transparency, which works with CSS mask-image's
		// default alpha mode across all browsers.
		$svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$image_size} {$image_size}'>"
			. "<defs><mask id='m'><rect width='{$image_size}' height='{$image_size}' fill='white'/>"
			. "<rect x='" . ( $image_size * $cutout_x_fraction - $offset ) . "' y='" . ( -$offset ) . "' width='{$svg_size}' height='{$svg_size}' rx='{$cutout_rx}' ry='{$cutout_rx}' fill='black'/>"
			. '</mask></defs>'
			. "<rect width='{$image_size}' height='{$image_size}' fill='white' mask='url(#m)'/>"
			. '</svg>';

		return '--overlap-mask: url(data:image/svg+xml,' . rawurlencode( $svg ) . ');';
	}

	/**
	 * Get the authors whose avatars should be displayed.
	 *
	 * Resolution order:
	 * 1. Custom byline authors (if byline feature is enabled and active for this post).
	 *    If the byline is active but contains no author shortcodes, returns empty — the
	 *    avatar block should not render for text-only bylines like "By Staff Reporter".
	 * 2. CoAuthors Plus authors.
	 * 3. Default WordPress post author.
	 *
	 * @param int $post_id Post ID.
	 * @return array Author objects, or empty array if no authors to display.
	 */
	private static function get_avatar_authors( int $post_id ): array {
		// Custom byline takes full control when active.
		if ( class_exists( 'Newspack\Bylines' ) && Bylines::is_enabled() ) {
			$byline_is_active = get_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
			if ( $byline_is_active ) {
				$byline_authors = Bylines::get_post_byline_authors( $post_id );
				// Return whatever the byline provides — even if empty. A text-only byline
				// (no [Author] shortcodes) intentionally produces no avatars.
				return ! empty( $byline_authors ) ? array_filter( $byline_authors ) : [];
			}
		}

		// CoAuthors Plus.
		if ( function_exists( 'get_coauthors' ) ) {
			$coauthors = get_coauthors( $post_id );
			if ( ! empty( $coauthors ) ) {
				return $coauthors;
			}
		}

		// Default WordPress author.
		$default_author = get_userdata( get_post_field( 'post_author', $post_id ) );
		return $default_author ? [ $default_author ] : [];
	}
}

Avatar_Block::init();
