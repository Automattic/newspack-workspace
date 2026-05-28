<?php
/**
 * Correction Item Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Correction_Item;

use Newspack\Corrections;

defined( 'ABSPATH' ) || exit;

/**
 * Corrections Item class.
 */
final class Correction_Item_Block {
	/**
	 * Initializes the block.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block.
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array  $attributes The block attributes.
	 * @param string $content    The block content.
	 * @param object $block      The block.
	 *
	 * @return string The block HTML.
	 */
	public static function render_block( array $attributes, string $content, $block ) {
		$correction_id = $block->context['postId'] ?? null;

		if ( empty( $correction_id ) || Corrections::POST_TYPE !== $block->context['postType'] ) {
			return '';
		}

		$correction = get_post( $correction_id );

		if ( empty( $correction ) ) {
			return '';
		}

		$block_wrapper_attributes = get_block_wrapper_attributes(
			[
				'class' => 'correction__item',
			]
		);

		ob_start();
		$correction_content = $correction->post_content;
		$correction_date    = \get_the_date( get_option( 'date_format' ), $correction->ID );
		$correction_time    = \get_the_time( get_option( 'time_format' ), $correction->ID );
		$correction_heading = sprintf(
			'%s, %s %s:',
			Corrections::get_correction_type( $correction->ID ),
			$correction_date,
			$correction_time
		);
		$correction_related_post = get_post_meta( $correction->ID, Corrections::CORRECTION_POST_ID_META, true );
		$corrections_post_url    = get_permalink( $correction_related_post );
		?>
			<div <?php echo $block_wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<strong class="correction__item-title">
					<?php echo esc_html( $correction_heading ); ?>
				</strong>
				<span class="correction__item-content">
					<?php echo esc_html( $correction_content ); ?>
				</span>
				<a class="correction__post-link" href="<?php echo esc_url( $corrections_post_url ); ?>">
					<?php echo esc_html( get_the_title( $correction_related_post ) ); ?>
				</a>
			</div>
		<?php
		return ob_get_clean();
	}
}

Correction_Item_Block::init();
