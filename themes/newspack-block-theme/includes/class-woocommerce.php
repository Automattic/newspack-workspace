<?php
/**
 * Newspack Block Theme WooCommerce integration.
 *
 * @link https://woocommerce.com/
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration class.
 * Handles all WooCommerce-specific functionality and theme support.
 */
final class WooCommerce {
	/**
	 * Initializer.
	 */
	public static function init() {
		// Only initialize if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// This theme doesn't have a traditional sidebar.
		\remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

		// Register theme features.
		\add_action( 'after_setup_theme', [ __CLASS__, 'theme_support' ] );
		\add_filter( 'hooked_block_types', [ __CLASS__, 'remove_wc_hooked_blocks' ], 10, 4 );

		// Remove WooCommerce styles.
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'remove_woocommerce_blocktheme_styles' ], 100 );
		\add_filter( 'woocommerce_enqueue_styles', [ __CLASS__, 'remove_woocommerce_general_styles' ] );
	}

	/**
	 * Add WooCommerce theme support.
	 *
	 * @since Newspack Block Theme 1.0
	 *
	 * @return void
	 */
	public static function theme_support() {
		\add_theme_support(
			'woocommerce',
			[
				'thumbnail_image_width' => 300,
				'single_image_width'    => 1296,
			]
		);
		\add_theme_support( 'wc-product-gallery-zoom' );
		\add_theme_support( 'wc-product-gallery-lightbox' );
		\add_theme_support( 'wc-product-gallery-slider' );
	}

	/**
	 * Remove WooCommerce block theme styles.
	 *
	 * @since Newspack Block Theme 1.0.22
	 *
	 * @return void
	 */
	public static function remove_woocommerce_blocktheme_styles() {
		\wp_dequeue_style( 'woocommerce-blocktheme' );
		\wp_deregister_style( 'woocommerce-blocktheme' );
	}

	/**
	 * Remove WooCommerce general styles.
	 *
	 * @since Newspack Block Theme 1.0.22
	 *
	 * @param array $enqueue_styles The styles to enqueue.
	 * @return array The modified styles.
	 */
	public static function remove_woocommerce_general_styles( $enqueue_styles ) {
		unset( $enqueue_styles['woocommerce-general'] );
		return $enqueue_styles;
	}

	/**
	 * Remove WooCommerce blocks from being hooked into navigation blocks.
	 *
	 * Filters the list of hooked block types to remove WooCommerce blocks.
	 *
	 * @since Newspack Block Theme 1.0
	 *
	 * @param array  $hooked_block_types The list of hooked block types.
	 * @param string $position           The relative position of the hooked blocks.
	 * @param string $anchor_block_type  The anchor block type.
	 * @param array  $context            The block context.
	 * @return array Modified list of hooked block types with WooCommerce blocks removed.
	 */
	public static function remove_wc_hooked_blocks( $hooked_block_types, $position, $anchor_block_type, $context ) {
		// Only filter navigation blocks.
		if ( 'core/navigation' !== $anchor_block_type ) {
			return $hooked_block_types;
		}

		// Remove WooCommerce blocks from being hooked.
		$wc_blocks_to_remove = [
			'woocommerce/mini-cart',
			'woocommerce/customer-account',
		];

		foreach ( $wc_blocks_to_remove as $block_type ) {
			$key = array_search( $block_type, $hooked_block_types, true );
			if ( false !== $key ) {
				unset( $hooked_block_types[ $key ] );
			}
		}

		return $hooked_block_types;
	}
}

WooCommerce::init();
