<?php
/**
 * My Account Button Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\My_Account_Button;

use Newspack\Reader_Activation;
use Newspack\Newspack_UI_Icons;
use Newspack\Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * My Account Button Block.
 */
final class My_Account_Button_Block {
	/**
	 * Initialize the block.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Register block from metadata.
	 *
	 * @return void
	 */
	public static function register_block() {
		if ( ! \wp_is_block_theme() ) {
			return;
		}
		\register_block_type_from_metadata(
			__DIR__ . '/block.json',
			array(
				'render_callback' => [ __CLASS__, 'render_block' ],
			)
		);
	}

	/**
	 * Get the account URL for the current site.
	 *
	 * @return string
	 */
	private static function get_account_url() {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return \wc_get_account_endpoint_url( 'dashboard' );
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$account_url = \wc_get_page_permalink( 'myaccount' );
			if ( $account_url ) {
				return $account_url;
			}
		}

		$account_page_id = \get_option( 'woocommerce_myaccount_page_id' );
		if ( $account_page_id ) {
			$account_url = \get_permalink( $account_page_id );
			if ( $account_url ) {
				return $account_url;
			}
		}

		return '';
	}

	/**
	 * Render My Account Button Block.
	 *
	 * @param array $attrs Block attributes.
	 *
	 * @return string
	 */
	public static function render_block( $attrs ) {
		if ( ! Reader_Activation::is_enabled() ) {
			return '';
		}

		wp_enqueue_style(
			'newspack-blocks-frontend',
			Newspack::plugin_url() . '/dist/blocks.css',
			[],
			NEWSPACK_PLUGIN_VERSION
		);

		$default_attrs = [
			'signedInLabel'  => __( 'My Account', 'newspack-plugin' ),
			'signedOutLabel' => __( 'Sign in', 'newspack-plugin' ),
			'className'      => '',
		];
		$attrs         = \wp_parse_args( $attrs, $default_attrs );

		$is_signed_in     = \is_user_logged_in();
		$signed_in_label  = '' === trim( (string) $attrs['signedInLabel'] ) ? $default_attrs['signedInLabel'] : $attrs['signedInLabel'];
		$signed_out_label = '' === trim( (string) $attrs['signedOutLabel'] ) ? $default_attrs['signedOutLabel'] : $attrs['signedOutLabel'];
		$label            = $is_signed_in ? $signed_in_label : $signed_out_label;

		// Display mode from block style class in className (default = icon + text).
		$classes = explode( ' ', (string) $attrs['className'] );
		if ( in_array( 'is-style-icon-only', $classes, true ) ) {
			$show_label = false;
			$show_icon  = true;
		} elseif ( in_array( 'is-style-text-only', $classes, true ) ) {
			$show_label = true;
			$show_icon  = false;
		} else {
			$show_label = true;
			$show_icon  = true;
		}

		$account_url = self::get_account_url();

		// Do not render link for authenticated readers if account page doesn't exist.
		if ( empty( $account_url ) && \is_user_logged_in() ) {
			return '';
		}

		if ( $is_signed_in ) {
			$href = $account_url;
			$should_modal_trigger = '';
		} else {
			$href = '#';
			$should_modal_trigger = 'data-newspack-reader-account-link';
		}

		$labels = [
			'signedin'  => $signed_in_label,
			'signedout' => $signed_out_label,
		];

		$extra_classes = [
			'wp-element-button',
			'wp-block-button__link',
			'newspack-reader__account-link',
		];

		// Get default wrapper attributes to extract custom classes.
		$default_wrapper_attributes = \get_block_wrapper_attributes();

		// Extract custom classes (everything except the default block class).
		$custom_classes = [];

		// Parse class attribute from default wrapper.
		if ( \preg_match( '/class=["\']([^"\']+)["\']/', $default_wrapper_attributes, $matches ) ) {
			$all_classes = \explode( ' ', $matches[1] );
			foreach ( $all_classes as $class ) {
				$class = \trim( $class );
				// Only include classes that contain "-size" (e.g., has-small-size).
				if ( ! empty( $class ) && \strpos( $class, '-size' ) !== false ) {
					$custom_classes[] = $class;
				}
			}
		}

		// Build wrapper div classes.
		$wrapper_div_classes = [ 'wp-block-buttons', 'is-layout-flex' ];
		if ( ! empty( $custom_classes ) ) {
			$wrapper_div_classes = \array_merge( $wrapper_div_classes, $custom_classes );
		}

		$wrapper_attribute_args = [
			'class'                   => implode( ' ', $extra_classes ),
			'href'                    => \esc_url_raw( $href ),
			'data-newspack-logged-in' => $is_signed_in ? '1' : '0',
		];
		$wrapper_attributes    = \get_block_wrapper_attributes( $wrapper_attribute_args );

		$link = '<div class="' . \esc_attr( implode( ' ', $wrapper_div_classes ) ) . '">';
		$link .= '<div class="wp-block-button">';
		$link .= '<a ' . $wrapper_attributes . ' data-labels="' . \esc_attr( \wp_json_encode( $labels ) ) . '" ' . $should_modal_trigger . '>';
		if ( $show_icon ) {
			$link .= '<span class="wp-block-newspack-my-account-button__icon" aria-hidden="true">';
			$link .= Newspack_UI_Icons::get_svg( 'account' );
			$link .= '</span>';
		}
		$label_classes = [ 'newspack-reader__account-link__label' ];
		if ( ! $show_label ) {
			$label_classes[] = 'screen-reader-text';
		}
		$link .= '<span class="' . \esc_attr( implode( ' ', $label_classes ) ) . '">' . \esc_html( $label ) . '</span>';
		$link .= '</a>';
		$link .= '</div>';
		$link .= '</div>';

		/**
		 * Filters the HTML for the My Account button block.
		 *
		 * @param string $link HTML for the button.
		 * @param array  $attrs Block attributes.
		 */
		return apply_filters( 'newspack_my_account_button_html', $link, $attrs );
	}
}

My_Account_Button_Block::init();
