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
final class Tabs_Block {

	/**
	 * Initialize blocks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_filter( 'render_block', [ __CLASS__, 'render_tab_navigation' ], 10, 2 );
	}

	/**
	 * Register the block.
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			NEWSPACK_ADS_BLOCKS_PATH . '/tabs', // This is the directory where the block.json is found.
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
		$class_names = [
			'wp-block-newspack-tabs',
			'tabs',
			'horizontal',
			! empty( $attributes['align'] ) ? 'alignwide' : null,
			! empty( $attributes['className'] ) ? $attributes['className'] : null,
		];
		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', array_filter( $class_names ) ) ); ?>">
			<!-- Tabs Placeholder -->
			<div class="newspack-ads__tab-group tab-group">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized by block rendering pipeline as in core; raw output to support embeds. ?>
			</div> <!-- /.newspack-ads__tab-group -->
		</div> <!-- /.tabs -->
		<?php
		return ob_get_clean();
	}

	/**
	 * Add Tab Controls
	 *
	 * @param  string $block_content The block content.
	 * @param  array  $block The block data.
	 * @return string String of rendered HTML.
	 */
	public static function render_tab_navigation( $block_content, $block ) {
		if ( 'newspack/tabs' !== $block['blockName'] ) {
			return $block_content;
		}

		if ( $block['innerBlocks'] ) {

			// Add tab navigation controls.
			$tabs = '<div class="tab-control"><div class="tabs-header">
				<ul class="newspack-ads__tab-list tab-list" role="tablist">';

			if ( is_array( $block['innerBlocks'] ) ) {
				foreach ( $block['innerBlocks'] as $inner_block ) {

					$header = $inner_block['attrs']['header'];
					$id     = 'tab-item-' . sanitize_title_with_dashes( $header );

					$tabs .= '<li class="newspack-ads__tab-item tab-item">
						<a href="#' . esc_attr( $id ) . '" role="tab" aria-controls="' . esc_attr( $id ) . '">' . esc_html( $header ) . '</a>
					</li>';
				}
				$tabs .= '</ul></div></div>';
			}

			$block_content = str_replace( '<!-- Tabs Placeholder -->', $tabs, $block_content );

		}

		return $block_content;
	}
}
Tabs_Block::init();

