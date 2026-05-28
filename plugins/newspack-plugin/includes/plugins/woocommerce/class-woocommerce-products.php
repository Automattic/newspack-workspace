<?php
/**
 * Extensions for WooCommerce products.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Connection with WooCommerce's features.
 */
class WooCommerce_Products {

	const DONATION_FLAG_META_KEY = '_newspack_is_donation';
	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_enqueue_scripts' ] );
		\add_filter( 'product_type_options', [ __CLASS__, 'show_custom_product_options' ] );
		\add_action( 'woocommerce_variation_options', [ __CLASS__, 'show_custom_variation_options' ], 10, 3 );
		\add_action( 'woocommerce_product_options_pricing', [ __CLASS__, 'show_custom_product_pricing_options' ] );
		\add_action( 'woocommerce_variation_options_pricing', [ __CLASS__, 'show_custom_variation_pricing_options' ], 10, 3 );
		\add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_custom_product_options' ] );
		\add_action( 'woocommerce_admin_process_variation_object', [ __CLASS__, 'save_custom_variation_options' ], 30, 2 );
		\add_filter( 'woocommerce_order_item_needs_processing', [ __CLASS__, 'require_order_processing' ], 10, 2 );
		\add_filter( 'woocommerce_product_description_heading', '__return_false', 10, 2 );
		\add_filter( 'woocommerce_product_additional_information_heading', '__return_false', 10, 2 );
	}

	/**
	 * Enqueue admin scripts.
	 */
	public static function admin_enqueue_scripts() {
		if ( ! function_exists( 'wcs_get_page_screen_id' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen->id !== 'product' ) {
			return;
		}
		wp_enqueue_script(
			'newspack-products-custom-options',
			Newspack::plugin_url() . '/dist/other-scripts/custom-product-options.js',
			[],
			NEWSPACK_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * Get custom product options. Product option values are always booleans but represented by string.
	 * See: https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/wc-formatting-functions.php#L37
	 *
	 * @return array Keyed array of custom product options.
	 */
	public static function get_custom_options() {
		$custom_options = [
			'newspack_autocomplete_orders' => [
				'id'            => '_newspack_autocomplete_orders',
				'wrapper_class' => '',
				'label'         => __( 'Auto-complete orders', 'newspack-plugin' ),
				'description'   => __( 'Allow orders containing this product to automatically complete upon successful payment.', 'newspack-plugin' ),
				'default'       => 'yes',
				'product_types' => [ 'simple', 'variation', 'subscription', 'subscription_variation' ],
				'type'          => 'boolean',
			],
			'newspack_is_donation'         => [
				'id'            => self::DONATION_FLAG_META_KEY,
				'wrapper_class' => '',
				'label'         => __( 'Donation product', 'newspack-plugin' ),
				'description'   => __( 'Flag this product as a donation. Donation products use donation-specific checkout, reporting, and reader activation behaviors. For variable products, set on the parent and all variations inherit.', 'newspack-plugin' ),
				'default'       => 'no',
				'product_types' => [ 'simple', 'subscription', 'grouped', 'variable', 'variable-subscription' ],
				'type'          => 'boolean',
			],
		];

		/**
		 * Filters the custom product options.
		 *
		 * @param array $custom_options Keyed array of custom product options.
		 */
		return apply_filters( 'newspack_custom_product_options', $custom_options );
	}

	/**
	 * Get custom product pricing options.
	 *
	 * @return array Keyed array of custom product pricing options.
	 */
	public static function get_custom_product_pricing_options() {
		/**
		 * Filters the custom product pricing options.
		 *
		 * @param array $custom_product_pricing_options Keyed array of custom product pricing options.
		 */
		return apply_filters( 'newspack_custom_product_pricing_options', [] );
	}

	/**
	 * Get config for a custom product option.
	 *
	 * @param string $option_name The name of the option.
	 *
	 * @return array|null The config for the option, or null if the option doesn't exist.
	 */
	public static function get_custom_option_config( $option_name ) {
		$custom_options = self::get_custom_options();
		return isset( $custom_options[ $option_name ] ) ? $custom_options[ $option_name ] : null;
	}

	/**
	 * Get the value of a custom product option or pricing option, taking defaults into account.
	 *
	 * @param \WC_Product|int $product The product object or ID.
	 * @param string          $option_name The name of the option.
	 *
	 * @return bool|null The value of the option as converted by wc_string_to_bool,
	 *                   or null if the product or option isn't valid.
	 */
	public static function get_custom_option_value( $product, $option_name ) {
		$custom_options = array_merge( self::get_custom_options(), self::get_custom_product_pricing_options() );
		if ( ! isset( $custom_options[ $option_name ] ) ) {
			return null;
		}

		$product = is_a( $product, 'WC_Product' ) ? $product : \wc_get_product( $product );
		if ( ! $product ) {
			return null;
		}

		$custom_option = isset( $custom_options[ $option_name ] ) ? $custom_options[ $option_name ] : null;
		if ( ! $custom_option ) {
			return null;
		}
		$meta_key      = $custom_option['id'];
		$option_value  = $product->meta_exists( $meta_key ) ? $product->get_meta( $meta_key ) : $custom_option['default'];
		$value_type    = $custom_option['type'];
		if ( $value_type === 'boolean' ) {
			return \wc_string_to_bool( $option_value );
		} elseif ( $value_type === 'number' ) {
			return intval( $option_value );
		}
		return $option_value;
	}

	/**
	 * Add custom options to the Product Data panel.
	 * See: https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/admin/wc-admin-functions.php#L574
	 *
	 * @param array $options Keyed array of product type options.
	 *
	 * @return array
	 */
	public static function show_custom_product_options( $options ) {
		$product = \wc_get_product( get_the_ID() );
		if ( ! $product ) {
			return $options;
		}

		$custom_options = self::get_custom_options();
		foreach ( $custom_options as $option_key => $option_config ) {
			$product_type   = $product->get_type();
			$is_new_product = $product_type === 'simple' && $product->get_status() === 'auto-draft' && ! $product->get_date_created() && ! $product->get_date_modified();
			if ( isset( $option_config['product_types'] ) && ! $is_new_product && ! in_array( $product_type, $option_config['product_types'], true ) ) {
				continue;
			}
			if ( ! isset( $options[ $option_key ] ) ) {
				$options[ $option_key ] = $option_config;
			}
		}
		return $options;
	}

	/**
	 * Add custom options to product variations.
	 *
	 * @param int     $loop The index of the variation within its parent product.
	 * @param array   $variation_data The variation data.
	 * @param WP_Post $variation The variation's post object.
	 */
	public static function show_custom_variation_options( $loop, $variation_data, $variation ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$custom_options = self::get_custom_options();
		$variation      = \wc_get_product( $variation->ID );

		foreach ( $custom_options as $option_key => $option_config ) {
			if ( isset( $option_config['product_types'] ) && ! in_array( $variation->get_type(), $option_config['product_types'], true ) ) {
				continue;
			}
			$meta_key = $option_config['id'];
			?>
			<label class="tips" data-tip="<?php echo esc_attr( $option_config['description'] ); ?>">
				<?php echo esc_html( $option_config['label'] ); ?>
				<input
					type="checkbox" class="checkbox variable_<?php echo esc_attr( $option_key ); ?>"
					name="<?php echo esc_attr( $meta_key . '[' . $loop . ']' ); ?>"
					<?php \checked( self::get_custom_option_value( $variation, $option_key ), true ); ?>
				/>
			</label>
			<?php
		}
	}

	/**
	 * Add custom pricing options to the Product Data panel.
	 *
	 * @param array $options Keyed array of product pricing options.
	 *
	 * @return array
	 */
	public static function show_custom_product_pricing_options( $options ) {
		$product = \wc_get_product( get_the_ID() );
		if ( ! $product ) {
			return $options;
		}

		$custom_options = self::get_custom_product_pricing_options();
		foreach ( $custom_options as $option_key => $option_config ) {
			$product_type   = $product->get_type();
			$is_new_product = $product_type === 'simple' && $product->get_status() === 'auto-draft' && ! $product->get_date_created() && ! $product->get_date_modified();
			if ( isset( $option_config['product_types'] ) && ! $is_new_product && ! in_array( $product_type, $option_config['product_types'], true ) ) {
				continue;
			}
			$option_type = $option_config['type'];
			if ( $option_type === 'select' && isset( $option_config['options'] ) ) {
				\woocommerce_wp_select( $option_config );
			}
			if ( $option_type === 'number' || $option_type === 'text' ) {
				\woocommerce_wp_text_input( $option_config );
			}
		}
	}

	/**
	 * Add custom pricing options to product variations.
	 *
	 * @param int     $loop The index of the variation within its parent product.
	 * @param array   $variation_data The variation data.
	 * @param WP_Post $variation The variation's post object.
	 */
	public static function show_custom_variation_pricing_options( $loop, $variation_data, $variation ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}


		$custom_options = self::get_custom_product_pricing_options();
		$variation      = \wc_get_product( $variation->ID );
		foreach ( $custom_options as $option_key => $option_config ) {
			if ( isset( $option_config['product_types'] ) && ! in_array( $variation->get_type(), $option_config['product_types'], true ) ) {
				continue;
			}
			$option_id             = $option_config['id'];
			$option_type           = $option_config['type'];
			$option_config['value'] = isset( $variation_data[ $option_id ][0] ) ? $variation_data[ $option_id ][0] : null;
			$option_config['id']    = $option_id . '_' . $loop;
			$option_config['name']  = $option_id . '[' . $loop . ']';

			// Add form-row class for variations.
			$option_config['wrapper_class'] = isset( $option_config['wrapper_class'] ) ? $option_config['wrapper_class'] . ' form-row' : 'form-row';

			if ( $option_type === 'select' && isset( $option_config['options'] ) ) {
				\woocommerce_wp_select( $option_config );
			}
			if ( $option_type === 'number' || $option_type === 'text' ) {
				\woocommerce_wp_text_input( $option_config );
			}
		}
	}

	/**
	 * Save custom product option values when a product is saved.
	 *
	 * @param int $post_id The product post ID being saved.
	 */
	public static function save_custom_product_options( $post_id ) {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_bool_to_string' ) ) {
			return;
		}

		$product = \wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		// Both this handler and WC core's WC_Meta_Box_Product_Data::save are
		// hooked on `woocommerce_process_product_meta` at priority 10, so the
		// order depends on registration timing. Newspack typically registers
		// first, which means when saving a brand-new product whose type is
		// changing via the dropdown, $product->get_type() still returns the
		// auto-draft default ("simple") at this point. Read the POSTed
		// product-type as the source of truth, matching WC core's own pattern
		// in WC_Meta_Box_Product_Data::save().
		$product_type = ! empty( $_POST['product-type'] ) ? sanitize_title( wp_unslash( $_POST['product-type'] ) ) : $product->get_type(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$custom_options = array_merge( self::get_custom_options(), self::get_custom_product_pricing_options() );
		foreach ( $custom_options as $option_key => $option_config ) {
			if ( isset( $option_config['product_types'] ) && ! in_array( $product_type, $option_config['product_types'], true ) ) {
				continue;
			}
			$meta_key   = $option_config['id'];
			$value_type = $option_config['type'];
			$option_value = null;
			if ( $value_type === 'boolean' ) {
				$option_value = isset( $_POST[ $meta_key ] ) ? \wc_bool_to_string( true ) : \wc_bool_to_string( false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} elseif ( $value_type === 'number' ) {
				$option_value = isset( $_POST[ $meta_key ] ) ? intval( $_POST[ $meta_key ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} else {
				$option_value = isset( $_POST[ $meta_key ] ) ? sanitize_text_field( $_POST[ $meta_key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			$product->update_meta_data( $option_config['id'], $option_value );
		}

		$product->save();
	}

	/**
	 * Save custom variation option values when a variation is saved.
	 *
	 * @param WC_Product_Variation $variation The variation object being saved.
	 * @param int                  $i The index of the variation within its parent product.
	 */
	public static function save_custom_variation_options( $variation, $i ) {
		$is_legacy = is_numeric( $variation );

		// Need to instantiate the product object on WC<3.8.
		if ( $is_legacy ) {
			$variation = \wc_get_product( $variation );
		}
		if ( ! $variation ) {
			return;
		}

		$custom_options = array_merge( self::get_custom_options(), self::get_custom_product_pricing_options() );
		foreach ( $custom_options as $option_key => $option_config ) {
			if ( isset( $option_config['product_types'] ) && ! in_array( $variation->get_type(), $option_config['product_types'], true ) ) {
				continue;
			}
			$meta_key   = $option_config['id'];
			$value_type = $option_config['type'];
			$option_value = null;
			if ( $value_type === 'boolean' ) {
				$option_value = isset( $_POST[ $meta_key ][ $i ] ) && ( $_POST[ $meta_key ][ $i ] === 'yes' || $_POST[ $meta_key ][ $i ] === 'on' ) ? \wc_bool_to_string( true ) : \wc_bool_to_string( false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} elseif ( $value_type === 'number' ) {
				$option_value = isset( $_POST[ $meta_key ][ $i ] ) ? intval( $_POST[ $meta_key ][ $i ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} else {
				$option_value = isset( $_POST[ $meta_key ][ $i ] ) ? sanitize_text_field( $_POST[ $meta_key ][ $i ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			$variation->update_meta_data( $meta_key, $option_value );
		}

		// Save the meta on WC<3.8.
		if ( $is_legacy ) {
			$variation->save();
		}
	}

	/**
	 * If the order item is tied to a product that's set to autocomplete, then allow the order to autocomplete.
	 * By default, Woo will require processing for any order containing items that aren't both downloadable and virtual.
	 *
	 * @param boolean    $needs_proccessing If true, the item needs processing. If not, allow the order to autocomplete.
	 * @param WC_Product $product The product associated with this order item.
	 */
	public static function require_order_processing( $needs_proccessing, $product ) {
		$config = self::get_custom_option_config( 'newspack_autocomplete_orders' );
		if ( ! $config || ( isset( $config['product_types'] ) && ! in_array( $product->get_type(), $config['product_types'], true ) ) ) {
			return $needs_proccessing;
		}
		return self::get_custom_option_value( $product, 'newspack_autocomplete_orders' ) ? false : $needs_proccessing;
	}
}

WooCommerce_Products::init();
