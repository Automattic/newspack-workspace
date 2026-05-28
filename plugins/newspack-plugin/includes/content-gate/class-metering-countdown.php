<?php
/**
 * WooCommerce Content Gate metering countdown banner.
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * WooCommerce Content Gate metering countdown banner class.
 */
class Metering_Countdown {

	const OPTION_PREFIX = 'np_countdown_banner_';

	/**
	 * Whether the countdown banner is enabled.
	 *
	 * @var bool
	 */
	private static $is_enabled = null;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'wp_footer', [ __CLASS__, 'print_cta' ] );
		add_filter( 'body_class', [ __CLASS__, 'filter_body_class' ] );
		add_filter( 'newspack_ads_placement_data', [ __CLASS__, 'filter_ads_placement_data' ], 10, 2 );
	}

	/**
	 * Get all settings with default values for the countdown banner.
	 *
	 * @return array Default countdown settings.
	 */
	public static function get_default_settings() {
		$primary_product = Subscriptions_Tiers::get_primary_subscription_tier_product();
		return [
			'enabled'        => false,
			'style'          => 'light',
			'cta_label'      => __( 'Subscribe now and get unlimited access.', 'newspack-plugin' ),
			'button_label'   => __( 'Subscribe now', 'newspack-plugin' ),
			'cta_type'       => 'product',
			'cta_product_id' => $primary_product ? $primary_product->get_id() : 0,
			'cta_url'        => '',
		];
	}

	/**
	 * Get all settings for the countdown banner.
	 *
	 * @param string $key Optional key to get a specific setting. If not provided, all settings will be returned.
	 *
	 * @return array|mixed Countdown banner settings, or a specific setting if a key is provided.
	 */
	public static function get_settings( $key = null ) {
		$settings = self::get_default_settings();
		if ( $key && isset( $settings[ $key ] ) ) {
			return self::sanitize_setting( $key, get_option( self::OPTION_PREFIX . $key, $settings[ $key ] ) );
		}
		foreach ( $settings as $key => $value ) {
			$settings[ $key ] = self::sanitize_setting( $key, get_option( self::OPTION_PREFIX . $key, $value ) );
		}
		return $settings;
	}

	/**
	 * Sanitize a setting.
	 *
	 * @param string $key The setting key.
	 * @param mixed  $value The setting value.
	 *
	 * @return mixed The sanitized setting value or WP_Error if setting key is invalid.
	 */
	public static function sanitize_setting( $key, $value ) {
		$default_settings = self::get_default_settings();
		if ( ! isset( $default_settings[ $key ] ) ) {
			// translators: %s is the setting key.
			return new \WP_Error( 'newspack_countdown_banner_invalid_setting', sprintf( __( 'Invalid setting key: %s.', 'newspack-plugin' ), $key ) );
		}
		if ( $key === 'style' && ! in_array( $value, [ 'light', 'dark' ], true ) ) {
			return $default_settings[ $key ];
		}
		if ( $key === 'cta_type' && ! in_array( $value, [ 'product', 'url' ], true ) ) {
			return $default_settings[ $key ];
		}
		if ( $key === 'cta_product_id' && ! is_numeric( $value ) ) {
			return $default_settings[ $key ];
		}
		if ( $key === 'cta_url' ) {
			return sanitize_url( $value );
		}
		if ( is_bool( $default_settings[ $key ] ) || is_numeric( $default_settings[ $key ] ) ) {
			return (int) $value;
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Update settings for the countdown banner.
	 *
	 * @param array $settings New countdown settings.
	 *
	 * @return array|\WP_Error Updated countdown settings or error if update fails.
	 */
	public static function update_settings( $settings ) {
		$current_settings = self::get_settings();
		foreach ( $settings as $key => $value ) {
			if ( ! isset( $current_settings[ $key ] ) ) {
				continue;
			}
			$sanitized = self::sanitize_setting( $key, $value );
			if ( is_wp_error( $sanitized ) ) {
				return $sanitized;
			}
			if ( $sanitized === $current_settings[ $key ] ) {
				continue;
			}
			$updated = update_option( self::OPTION_PREFIX . $key, $sanitized );
			if ( ! $updated ) {
				return new \WP_Error( 'newspack_countdown_banner_update_failed', __( 'Failed to update countdown banner settings.', 'newspack-plugin' ) );
			}
			$current_settings[ $key ] = $sanitized;
		}
		return $current_settings;
	}

	/**
	 * Whether the countdown banner should be displayed.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( is_bool( self::$is_enabled ) ) {
			return self::$is_enabled;
		}
		// Only when singular and if enabled in the settings.
		self::$is_enabled = ! is_admin() && is_singular() && self::get_settings( 'enabled' );
		if ( false === self::$is_enabled ) {
			return self::$is_enabled;
		}

		// In customizer preview.
		if ( self::$is_enabled && is_customize_preview() ) {
			return self::$is_enabled;
		}

		// In the frontend only when the post is metered.
		if ( ! Metering::is_metering() || ! Content_Gate::is_post_restricted() ) {
			self::$is_enabled = false;
			return self::$is_enabled;
		}

		return self::$is_enabled;
	}

	/**
	 * Print the subscribe button.
	 */
	public static function print_subscribe_button() {
		if ( ! class_exists( 'Newspack_Blocks' ) || ! class_exists( 'Newspack_Blocks\Modal_Checkout' ) || ! class_exists( 'Newspack_Blocks\Modal_Checkout\Checkout_Data' ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$settings     = self::get_settings();
		$button_label = $settings['button_label'];
		$button_class = 'dark' === $settings['style'] ? 'newspack-ui__button--primary-light' : 'newspack-ui__button--accent';

		$cta_type = $settings['cta_type'];
		if ( $cta_type === 'url' ) {
			$cta_url = $settings['cta_url'];
			if ( $cta_url ) {
				?>
				<a href="<?php echo esc_url( $cta_url ); ?>" class="newspack-ui__button newspack-ui__button--x-small <?php echo esc_attr( $button_class ); ?>"><?php echo esc_html( $button_label ); ?></a>
				<?php
				return;
			}
		}

		// If CTA type is 'product', try a modal checkout using the primary subscription tier product.
		if ( $cta_type === 'product' ) {
			$product_id = self::get_settings( 'cta_product_id' );
			$product    = function_exists( 'wc_get_product' ) ? \wc_get_product( $product_id ) : null;
			if ( ! $product ) {
				return;
			}
			\Newspack_Blocks\Modal_Checkout::enqueue_modal( $product->get_id() );
			\Newspack_Blocks::enqueue_view_assets( 'checkout-button' );
			$checkout_data = \Newspack_Blocks\Modal_Checkout\Checkout_Data::get_checkout_data( $product );
			?>
			<div class="wp-block-newspack-blocks-checkout-button">
				<form data-checkout="<?php echo esc_attr( wp_json_encode( $checkout_data ) ); ?>" target="newspack_modal_checkout_iframe">
					<input type="hidden" name="newspack_checkout" value="1" />
					<input type="hidden" name="modal_checkout" value="1" />
					<input type="hidden" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>" />
					<button type="submit" class="newspack-ui__button newspack-ui__button--x-small <?php echo esc_attr( $button_class ); ?>"><?php echo esc_html( $button_label ); ?></button>
				</form>
			</div>
			<?php
		}
	}

	/**
	 * Print the countdown banner.
	 *
	 * @return void
	 */
	public static function print_cta() {
		if ( ! self::is_enabled() ) {
			return;
		}
		$settings    = self::get_settings();
		$style_class = sprintf( 'is-style-%s', $settings['style'] );
		$classes     = [ $style_class ];
		$total_views = Metering::get_total_metered_views( \is_user_logged_in() );
		if ( false === $total_views ) {
			return;
		}
		$views = min( Metering::get_current_user_metered_views(), $total_views );
		if ( $views === 0 || Metering::is_frontend_metering() ) {
			$classes[] = 'newspack-countdown-banner__cta--hidden';
		}
		$metering_settings = Metering::get_registered_settings( Content_Gate::get_gate_post_id() );
		$registered_count  = $metering_settings['count'];
		?>
		<div class="newspack-ui">
			<div class="banner newspack-countdown-banner__cta <?php echo esc_attr( implode( ' ', $classes ) ); ?>">
				<div class="wrapper newspack-countdown-banner__cta__content">
					<div class="newspack-countdown-banner__cta__content__wrapper">
						<span class="newspack-countdown-banner__cta__content__countdown newspack-ui__font--s">
							<strong>
							<?php
								echo wp_kses_post(
									/**
									 * Filter the countdown message that shows how many metered articles the user has viewed.
									 * Sanitized via wp_kses_post, so basic HTML is allowed.
									 *
									 * @param string $message The countdown message HTML string.
									 * @param int $views The current number of metered views.
									 * @param int $total_views The total number of allowed views per period.
									 * @param string $metering_period The metering period.
									 * @return string The filtered countdown message HTML string.
									 */
									apply_filters(
										'newspack_countdown_banner_countdown_message',
										sprintf(
											/* translators: 1: current number of metered views, 2: total metered views, 3: the metering period. */
											__( '<span class="newspack-countdown-banner__views">%1$d</span>/<span class="newspack-countdown-banner__total_views">%2$d</span> free articles this %3$s', 'newspack-plugin' ),
											$views,
											$total_views,
											Metering::get_metering_period()
										),
										$views,
										$total_views,
										Metering::get_metering_period()
									)
								);
							?>
							</strong>
						</span>
						<span class="newspack-countdown-banner__cta__content__message newspack-ui__font--xs">
							<?php echo esc_html( $settings['cta_label'] ); ?>
							<?php if ( ! \is_user_logged_in() ) : ?>
								<?php if ( $registered_count > 0 ) : ?>
									<a href="#register_modal"><?php echo esc_html( __( 'Create an account', 'newspack-plugin' ) ); ?></a>.
								<?php else : ?>
									<a href="#signin_modal"><?php echo esc_html( __( 'Sign in to an existing account', 'newspack-plugin' ) ); ?></a>.
								<?php endif; ?>
							<?php endif; ?>
						</span>
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
		if ( self::is_enabled() ) {
			$classes[] = 'newspack-has-countdown-banner';
		}
		return $classes;
	}

	/**
	 * Disable the sticky footer ad placement when rendering the countdown banner.
	 *
	 * @param array  $data          The ads placement data.
	 * @param string $placement_key The placement key.
	 *
	 * @return array The filtered ads placement data.
	 */
	public static function filter_ads_placement_data( $data, $placement_key ) {
		if ( ! self::is_enabled() ) {
			return $data;
		}
		if ( $placement_key === 'sticky' ) {
			$data['enabled'] = false;
		}
		return $data;
	}
}
Metering_Countdown::init();
