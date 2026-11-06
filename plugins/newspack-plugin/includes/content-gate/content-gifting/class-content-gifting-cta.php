<?php
/**
 * Newspack Content Gifting Call-to-action functionality.
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * Content Gifting Call-to-action class.
 */
class Content_Gifting_CTA {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'wp_footer', [ __CLASS__, 'print_cta' ] );
		add_filter( 'body_class', [ __CLASS__, 'filter_body_class' ] );
		add_filter( 'newspack_ads_placement_data', [ __CLASS__, 'filter_ads_placement_data' ], 10, 2 );
	}

	/**
	 * Disable the sticky footer ad placement when rendering
	 * the gifted article CTA.
	 *
	 * @param array  $data          The ads placement data.
	 * @param string $placement_key The placement key.
	 *
	 * @return array The filtered ads placement data.
	 */
	public static function filter_ads_placement_data( $data, $placement_key ) {
		if ( ! Content_Gifting::is_gifted_post() ) {
			return $data;
		}
		if ( $placement_key === 'sticky' ) {
			$data['enabled'] = false;
		}
		return $data;
	}

	/**
	 * Get CTA label.
	 *
	 * @return string The CTA label.
	 */
	public static function get_cta_label() {
		return (string) get_option( 'newspack_content_gifting_cta_label', __( 'This article has been gifted to you by someone who values great journalism.', 'newspack-plugin' ) );
	}

	/**
	 * Set CTA label.
	 *
	 * @param string $label The CTA label.
	 *
	 * @return void
	 */
	public static function set_cta_label( $label ) {
		update_option( 'newspack_content_gifting_cta_label', $label );
	}

	/**
	 * Get CTA type: product or url.
	 *
	 * @return string The CTA type.
	 */
	public static function get_cta_type() {
		$type = (string) get_option( 'newspack_content_gifting_cta_type', 'product' );
		return in_array( $type, [ 'product', 'url' ], true ) ? $type : 'product';
	}

	/**
	 * Set CTA type.
	 *
	 * @param string $type The CTA type.
	 *
	 * @return void
	 */
	public static function set_cta_type( $type ) {
		$type = in_array( $type, [ 'product', 'url' ], true ) ? $type : 'product';
		update_option( 'newspack_content_gifting_cta_type', $type );
	}

	/**
	 * Get CTA product ID. Used if the CTA type is `product`.
	 * Defaults to the primary subscription tier product, if available.
	 *
	 * @return int The CTA product ID.
	 */
	public static function get_cta_product_id() {
		$primary_product = Subscriptions_Tiers::get_primary_subscription_tier_product();
		$product_id = get_option( 'newspack_content_gifting_cta_product_id', $primary_product ? $primary_product->get_id() : 0 );
		return $product_id;
	}

	/**
	 * Set CTA product IDs.
	 *
	 * @param int $product_id The CTA product ID.
	 *
	 * @return void
	 */
	public static function set_cta_product_id( $product_id ) {
		if ( function_exists( 'wc_get_product' ) && ! \wc_get_product( $product_id ) ) {
			$primary_product = Subscriptions_Tiers::get_primary_subscription_tier_product();
			$product_id = $primary_product ? $primary_product->get_id() : 0;
		}
		update_option( 'newspack_content_gifting_cta_product_id', (int) $product_id );
	}

	/**
	 * Get CTA url.  Used if the CTA type is `url`.
	 *
	 * @return string The CTA url.
	 */
	public static function get_cta_url() {
		return (string) get_option( 'newspack_content_gifting_cta_url', '' );
	}

	/**
	 * Set CTA url.
	 *
	 * @param string $url The CTA url.
	 *
	 * @return void
	 */
	public static function set_cta_url( $url ) {
		update_option( 'newspack_content_gifting_cta_url', $url );
	}

	/**
	 * Get button label.
	 *
	 * @return string The button label.
	 */
	public static function get_button_label() {
		return (string) get_option( 'newspack_content_gifting_button_label', __( 'Subscribe now', 'newspack-plugin' ) );
	}

	/**
	 * Set button label.
	 *
	 * @param string $label The button label.
	 *
	 * @return void
	 */
	public static function set_button_label( $label ) {
		update_option( 'newspack_content_gifting_button_label', $label );
	}

	/**
	 * Print the subscribe button.
	 */
	public static function print_subscribe_button() {
		if ( ! class_exists( 'Newspack_Blocks' ) || ! class_exists( 'Newspack_Blocks\Modal_Checkout' ) || ! class_exists( 'Newspack_Blocks\Modal_Checkout\Checkout_Data' ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$button_label = self::get_button_label();
		$button_class = Content_Banner_Style::get_button_class( Content_Banner_Style::get_render_style( self::get_style() ) );

		$cta_type = self::get_cta_type();
		if ( $cta_type === 'url' ) {
			$cta_url = self::get_cta_url();
			if ( $cta_url ) {
				?>
				<a href="<?php echo esc_url( $cta_url ); ?>" class="newspack-ui__button <?php echo esc_attr( $button_class ); ?>"><?php echo esc_html( $button_label ); ?></a>
				<?php
				return;
			}
		}

		// If CTA type is 'product', try a modal checkout using the primary subscription tier product.
		if ( $cta_type === 'product' ) {
			$product_id = self::get_cta_product_id();
			$product    = function_exists( 'wc_get_product' ) ? \wc_get_product( $product_id ) : null;
			if ( ! $product ) {
				return;
			}
			\Newspack_Blocks\Modal_Checkout::enqueue_modal( $product_id );
			\Newspack_Blocks::enqueue_view_assets( 'checkout-button', 'defer' );
			$checkout_data = \Newspack_Blocks\Modal_Checkout\Checkout_Data::get_checkout_data( $product );
			?>
			<div class="wp-block-newspack-blocks-checkout-button">
				<form data-checkout="<?php echo esc_attr( wp_json_encode( $checkout_data ) ); ?>" target="newspack_modal_checkout_iframe">
					<input type="hidden" name="newspack_checkout" value="1" />
					<input type="hidden" name="modal_checkout" value="1" />
					<input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>" />
					<button type="submit" class="newspack-ui__button <?php echo esc_attr( $button_class ); ?>"><?php echo esc_html( $button_label ); ?></button>
				</form>
			</div>
			<?php
		}
	}

	/**
	 * Get CTA style.
	 *
	 * @return string The style, 'light', 'dark' or 'theme'.
	 */
	public static function get_style() {
		return Content_Banner_Style::sanitize_style( get_option( 'newspack_content_gifting_cta_style', Content_Banner_Style::DEFAULT_STYLE ) );
	}

	/**
	 * Set CTA style.
	 *
	 * @param string $style The style value.
	 *
	 * @return void
	 */
	public static function set_style( $style ) {
		update_option( 'newspack_content_gifting_cta_style', Content_Banner_Style::sanitize_style( $style ) );
	}

	/**
	 * Get the Theme style's banner background color.
	 *
	 * @return string A hex color, or an empty string for the default.
	 */
	public static function get_bg_color(): string {
		return Content_Banner_Style::sanitize_color( get_option( 'newspack_content_gifting_cta_bg_color', '' ) );
	}

	/**
	 * Set the Theme style's banner background color.
	 *
	 * @param mixed $color A hex color, or an empty string for the default.
	 *
	 * @return void
	 */
	public static function set_bg_color( mixed $color ): void {
		update_option( 'newspack_content_gifting_cta_bg_color', Content_Banner_Style::sanitize_color( $color ) );
	}

	/**
	 * Get the Theme style's button background color.
	 *
	 * @return string A hex color, or an empty string for the default.
	 */
	public static function get_button_color(): string {
		return Content_Banner_Style::sanitize_color( get_option( 'newspack_content_gifting_cta_button_color', '' ) );
	}

	/**
	 * Set the Theme style's button background color.
	 *
	 * @param mixed $color A hex color, or an empty string for the default.
	 *
	 * @return void
	 */
	public static function set_button_color( mixed $color ): void {
		update_option( 'newspack_content_gifting_cta_button_color', Content_Banner_Style::sanitize_color( $color ) );
	}

	/**
	 * Hook the cta.
	 */
	public static function print_cta() {
		if ( ! Content_Gifting::is_gifted_post() ) {
			return;
		}
		// Don't render CTA if user already has access to the post.
		if ( ! Content_Gate::is_post_restricted() ) {
			return;
		}
		$style        = Content_Banner_Style::get_render_style( self::get_style() );
		$inline_style = Content_Banner_Style::get_inline_style( $style, self::get_bg_color(), self::get_button_color() );
		?>
		<div class="newspack-ui">
			<div class="banner newspack-content-gifting__cta <?php echo esc_attr( sprintf( 'is-style-%s', $style ) ); ?>"<?php echo $inline_style ? ' style="' . esc_attr( $inline_style ) . '"' : ''; ?>>
				<div class="wrapper newspack-content-gifting__cta__content">
					<div class="newspack-ui__font--s">
						<?php echo esc_html( self::get_cta_label() ); ?>
						<?php if ( ! is_user_logged_in() ) : ?>
							<div class="newspack-ui__font--xs newspack-content-gifting__cta__content__links">
								<?php if ( Metering::has_metering( get_the_ID() ) ) : ?>
									<a href="#register_modal"><?php echo esc_html( __( 'Create an account', 'newspack-plugin' ) ); ?></a>
								<?php else : ?>
									<a href="#signin_modal"><?php echo esc_html( __( 'Sign in to an existing account', 'newspack-plugin' ) ); ?></a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
					<?php self::print_subscribe_button(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Filter the body class.
	 *
	 * @param array $classes The body classes.
	 *
	 * @return array The filtered body classes.
	 */
	public static function filter_body_class( $classes ) {
		if ( Content_Gifting::is_gifted_post() ) {
			$classes[] = 'newspack-is-gifted-post';
		}
		return $classes;
	}
}
Content_Gifting_CTA::init();
