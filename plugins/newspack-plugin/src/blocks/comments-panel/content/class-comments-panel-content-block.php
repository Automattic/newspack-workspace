<?php
/**
 * Comments Panel Content Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Comments_Panel;

defined( 'ABSPATH' ) || exit;

/**
 * Comments_Panel_Content_Block Class.
 */
final class Comments_Panel_Content_Block {

	/**
	 * Whether the panel has already been rendered on this request.
	 * Comments are page-level, so only one panel is output per page.
	 *
	 * @var bool
	 */
	private static $rendered = false;

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
	 * Block render callback. Outputs the panel once per page; subsequent calls
	 * render nothing so multiple trigger buttons all control a single panel.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    InnerBlocks HTML.
	 *
	 * @return string Block HTML.
	 */
	public static function render_block( array $attributes, string $content ) {
		if ( self::$rendered ) {
			return '';
		}
		self::$rendered = true;

		$overlay_color = $attributes['overlayColor'] ?? '';

		$panel_class = 'comments-panel__panel is-layout-constrained comments-panel__panel--right';

		$extra_attributes = [
			'id'                 => 'newspack-comments-panel',
			'class'              => $panel_class,
			'data-overlay-color' => $overlay_color,
			'aria-hidden'        => 'true',
			'inert'              => 'true',
			'role'               => 'dialog',
			'aria-modal'         => 'true',
			'aria-label'         => __( 'Comments', 'newspack-plugin' ),
		];
		$wrapper_attributes = get_block_wrapper_attributes( $extra_attributes );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<div class="comments-panel__close-wrapper">
				<button type="button" class="comments-panel__close">
					<span class="comments-panel__icon" aria-hidden="true">
						<?php \Newspack\Newspack_UI_Icons::print_svg( 'close' ); ?>
					</span>
					<span class="screen-reader-text">
						<?php esc_html_e( 'Close', 'newspack-plugin' ); ?>
					</span>
				</button>
			</div>

			<div class="comments-panel__content">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

Comments_Panel_Content_Block::init();
