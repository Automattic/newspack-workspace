<?php
/**
 * Overlay Menu Trigger Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Overlay_Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Overlay_Menu_Trigger_Block Class.
 */
final class Overlay_Menu_Trigger_Block {

	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block type.
	 *
	 * @return void
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
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Unused — no InnerBlocks.
	 * @param \WP_Block $block      Block instance (provides instanceId context from parent).
	 *
	 * @return string Block HTML.
	 */
	public static function render_block( array $attributes, string $content, \WP_Block $block ) {
		$instance_id  = $block->context['newspack-overlay-menu/instanceId'] ?? '';
		$default_text = __( 'Menu', 'newspack-plugin' );
		$trigger_text = $attributes['triggerText'] ?? $default_text;
		// Whitespace-only values would leave the button without an accessible name.
		if ( '' === trim( (string) $trigger_text ) ) {
			$trigger_text = $default_text;
		}

		// Display mode from block style class in className (default = icon + text).
		$classes    = explode( ' ', (string) ( $attributes['className'] ?? '' ) );
		$show_icon  = ! in_array( 'is-style-text-only', $classes, true );
		$text_class = in_array( 'is-style-icon-only', $classes, true ) ? 'screen-reader-text' : '';

		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'overlay-menu__trigger wp-block-button__link wp-element-button' ] );

		ob_start();
		?>
		<div class="wp-block-buttons is-layout-flex">
			<div class="wp-block-button">
				<button
					<?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					type="button"
					aria-expanded="false"
					aria-controls="newspack-overlay-panel-<?php echo esc_attr( $instance_id ); ?>"
				>
					<?php if ( $show_icon ) : ?>
						<span class="overlay-menu__icon" aria-hidden="true">
							<?php \Newspack\Newspack_UI_Icons::print_svg( 'menu' ); ?>
						</span>
					<?php endif; ?>
					<span class="<?php echo esc_attr( $text_class ); ?>">
						<?php echo esc_html( $trigger_text ); ?>
					</span>
				</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

Overlay_Menu_Trigger_Block::init();
