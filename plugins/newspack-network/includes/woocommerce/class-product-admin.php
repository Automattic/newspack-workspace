<?php
/**
 * Newspack Network Admin customizations for WooCommerce products.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce;

/**
 * Handles admin tweaks for WooCommerce products.
 *
 * Adds a metabox to the product edit screen to allow the user to add a network id metadata.
 */
class Product_Admin {

	/**
	 * The network id meta key.
	 *
	 * @var string
	 */
	const NETWORK_ID_META_KEY = '_newspack_network_product_id';

	/**
	 * Get the Network ID for a product, falling back to the parent product
	 * for variations of a variable-subscription.
	 *
	 * @param int $product_id The product ID.
	 * @return string The Network ID, or empty string if not set.
	 */
	public static function get_network_id( $product_id ) {
		$network_id = get_post_meta( $product_id, self::NETWORK_ID_META_KEY, true );
		if ( ! empty( $network_id ) ) {
			return $network_id;
		}

		// If this is a variation, check the parent product.
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product && $product->get_parent_id() ) {
				return get_post_meta( $product->get_parent_id(), self::NETWORK_ID_META_KEY, true );
			}
		}

		return '';
	}

	/**
	 * Initializer.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'save_post_product', [ __CLASS__, 'save_meta_box' ] );
	}

	/**
	 * Adds a meta box to the product edit screen.
	 */
	public static function add_meta_box() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		global $post;
		$product = wc_get_product( $post );
		if ( ! $product || ! $product->is_type( [ 'subscription', 'variable-subscription' ] ) ) {
			return;
		}
		add_meta_box(
			'newspack-network-product-meta-box',
			__( 'Newspack Network', 'newspack-network' ),
			[ __CLASS__, 'render_meta_box' ],
			'product',
			'side'
		);
	}

	/**
	 * Renders the meta box.
	 *
	 * @param \WP_Post $post The post object.
	 */
	public static function render_meta_box( $post ) {
		$network_id = get_post_meta( $post->ID, self::NETWORK_ID_META_KEY, true );
		wp_nonce_field( 'newspack_network_save_product', 'newspack_network_save_product_nonce' );
		?>
		<label for="newspack-network-product-id"><?php esc_html_e( 'Network ID', 'newspack-network' ); ?></label>
		<input type="text" id="newspack-network-product-id" name="newspack_network_product_id" value="<?php echo esc_attr( $network_id ); ?>" style="width:100%;" />
		<p class="description"><?php esc_html_e( 'If set, this product will be linked to products with the same Network ID on other sites in the network. Users with an active subscription to any linked product will be granted access across all sites.', 'newspack-network' ); ?></p>
		<?php
	}

	/**
	 * Saves the meta box.
	 *
	 * @param int $post_id The post ID.
	 */
	public static function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['newspack_network_save_product_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( $_POST['newspack_network_save_product_nonce'] ), 'newspack_network_save_product' )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product || ! $product->is_type( [ 'subscription', 'variable-subscription' ] ) ) {
			return;
		}

		$network_id = sanitize_text_field( wp_unslash( $_POST['newspack_network_product_id'] ?? '' ) );

		update_post_meta( $post_id, self::NETWORK_ID_META_KEY, $network_id );

		/**
		 * Triggers an action when a product's network id is saved.
		 *
		 * @param int $post_id The product post ID.
		 */
		do_action( 'newspack_network_save_product', $post_id );
	}
}
