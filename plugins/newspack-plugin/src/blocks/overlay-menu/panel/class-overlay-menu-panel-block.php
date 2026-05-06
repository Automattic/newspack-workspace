<?php
/**
 * Overlay Menu Panel Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Overlay_Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Overlay_Menu_Panel_Block Class.
 */
final class Overlay_Menu_Panel_Block {

	// Inline SVG for the close icon.
	const ICON_CLOSE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M13 11.8l6.1-6.3-1-1-6.1 6.2-6.1-6.2-1 1 6.1 6.3-6.5 6.7 1 1 6.5-6.6 6.5 6.6 1-1z"/></svg>';

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
	 * @param string    $content    InnerBlocks HTML.
	 * @param \WP_Block $block      Block instance (provides instanceId context from parent).
	 *
	 * @return string Block HTML.
	 */
	public static function render_block( array $attributes, string $content, \WP_Block $block ) {
		$instance_id      = $block->context['newspack-overlay-menu/instanceId'] ?? '';
		$direction        = $attributes['slideDirection'] ?? 'left';
		$overlay_color    = $attributes['overlayColor'] ?? '';
		$panel_bg_color   = $attributes['panelBackgroundColor'] ?? '';
		$panel_text_color = $attributes['panelTextColor'] ?? '';
		$is_full_screen   = ! empty( $attributes['isFullScreen'] );
		$panel_width      = $attributes['panelWidth'] ?? 'small';

		$valid_directions = [ 'left', 'right' ];
		if ( ! in_array( $direction, $valid_directions, true ) ) {
			$direction = 'left';
		}

		$valid_widths = [ 'x-small', 'small', 'medium', 'large', 'x-large' ];
		if ( ! in_array( $panel_width, $valid_widths, true ) ) {
			$panel_width = 'small';
		}

		if ( $is_full_screen ) {
			$panel_class = 'overlay-menu__panel is-layout-constrained overlay-menu__panel--full-screen';
		} else {
			$panel_class = 'overlay-menu__panel is-layout-constrained overlay-menu__panel--' . $direction . ' overlay-menu__panel--width--' . $panel_width;
		}

		$panel_styles = [];
		if ( $panel_bg_color ) {
			$panel_styles[] = 'background:' . esc_attr( $panel_bg_color );
		}
		if ( $panel_text_color ) {
			$panel_styles[] = 'color:' . esc_attr( $panel_text_color );
		}
		$extra_attributes = [
			'id'                 => 'newspack-overlay-panel-' . $instance_id,
			'class'              => $panel_class,
			'data-overlay-color' => $overlay_color,
			'aria-hidden'        => 'true',
			'inert'              => 'true',
			'role'               => 'dialog',
			'aria-modal'         => 'true',
			'aria-label'         => __( 'Menu', 'newspack-plugin' ),
		];
		if ( $panel_styles ) {
			$extra_attributes['style'] = implode( ';', $panel_styles );
		}
		$wrapper_attributes = get_block_wrapper_attributes( $extra_attributes );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<div class="overlay-menu__close-wrapper">
				<button
					type="button"
					class="overlay-menu__close"
				>
					<span class="overlay-menu__icon" aria-hidden="true">
						<?php echo self::ICON_CLOSE; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<span class="screen-reader-text">
						<?php esc_html_e( 'Close', 'newspack-plugin' ); ?>
					</span>
				</button>
			</div>

			<div class="overlay-menu__content">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

Overlay_Menu_Panel_Block::init();
