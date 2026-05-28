<?php
/**
 * Newspack Ads Tabs Block
 *
 * Adapted from https://github.com/10up/publisher-media-kit/.
 *
 * @package Newspack
 */

namespace Newspack_Ads;

/**
 * Newspack Ads Tabs Block
 */
final class Tabs_Item_Block {

	/**
	 * Initialize blocks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Register the block.
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			NEWSPACK_ADS_BLOCKS_PATH . '/tabs-item', // This is the directory where the block.json is found.
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Render callback.
	 *
	 * @param array  $attributes The blocks attributes.
	 * @param string $content    Data returned from InnerBlocks.Content.
	 * @param array  $block      Block information such as context.
	 *
	 * @return string The rendered block markup.
	 */
	public static function render_block( $attributes, $content, $block ) {
		$class_name = ( ! empty( $attributes['className'] ) ) ? $attributes['className'] : '';

		if ( empty( $attributes['header'] ) ) {
			return;
		}
		ob_start();
		?>
		<div class="newspack-ads__tab-content tab-content <?php echo esc_attr( $class_name ); ?>" id="tab-item-<?php echo esc_attr( sanitize_title_with_dashes( $attributes['header'] ) ); ?>" role="tabpanel">
			<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized by block rendering pipeline as in core; raw output to support embeds. ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
Tabs_Item_Block::init();

