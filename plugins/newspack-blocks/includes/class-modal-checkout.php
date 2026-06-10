<?php
/**
 * Newspack Blocks Modal Checkout
 *
 * @package Newspack_Blocks
 */

namespace Newspack_Blocks;

use Newspack_Blocks\Modal_Checkout\Checkout_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Modal Checkout Class.
 */
final class Modal_Checkout {
	/**
	 * Checkout registration flag.
	 *
	 * @var string
	 */
	const CHECKOUT_REGISTRATION_FLAG = '_newspack_checkout_registration';

	/**
	 * Checkout registration order meta key.
	 *
	 * @var string
	 */
	const CHECKOUT_REGISTRATION_ORDER_META_KEY = '_newspack_checkout_registration_meta';

	/**
	 * Whether the modal checkout has been enqueued.
	 *
	 * @var boolean
	 */
	private static $has_modal = false;

	/**
	 * Products that are being rendered a checkout modal for.
	 *
	 * @var true[] Product IDs as keys.
	 */
	private static $products = [];

	/**
	 * Labels for the modal checkout UI.
	 *
	 * @var mixed[]
	 */
	private static $modal_checkout_labels = [];

	/**
	 * Allowed assets for modal checkout.
	 *
	 * @var string[]
	 */
	private static $allowed_scripts = [
		'jquery',
		'google_gtagjs',
		// Newspack.
		'newspack_commons',
		'newspack-newsletters-',
		'newspack-blocks-modal',
		'newspack-blocks-modal-checkout',
		'newspack-wc',
		'newspack-ui',
		'newspack-style',
		'newspack-recaptcha',
		'newspack-woocommerce-style',
		'newspack-reader-activation',
		'newspack-my-account',
		// Woo.
		'woocommerce',
		'WCPAY',
		'Woo',
		'wc-',
		'wc_',
		'wcs-',
		'stripe',
		'select2',
		'selectWoo',
		// Metorik.
		'metorik',
	];

	/**
	 * Allowed styles for modal checkout.
	 *
	 * @var string[]
	 */
	private static $allowed_styles = [
		// Newspack.
		'newspack-newsletters-',
		'newspack-blocks-modal',
		'newspack-blocks-modal-checkout',
		'newspack-wc',
		'newspack-ui',
		'newspack-style',
		'newspack-recaptcha',
		'newspack-woocommerce-style',
		// Woo.
		'woocommerce',
		'WCPAY',
		'Woo',
		'wc-',
		'wc_',
		'wcs-',
		'stripe',
		'select2',
		'selectWoo',
		// Metorik.
		'metorik',
	];

	/**
	 * Supported Payment Gateways
	 *
	 * @var string[]
	 */
	private static $supported_gateways = [
		'bacs', // Direct bank transfer.
		'cheque',
		'cod', // Cash on delivery.
		'ppcp-gateway', // PayPal Payments.
		'stripe',
		'stripe_link', // Stripe Link.
		'stripe_amazon_pay', // Stripe Amazon Pay.
		'woocommerce_payments',
		'woocommerce_payments_apple_pay', // WooPayments Apple Pay.
		'woocommerce_payments_google_pay', // WooPayments Google Pay.
		'ppcp-axo-gateway', // PayPal Accelerated Checkout, used by WooPayments.

	];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Process checkout request.
		add_action( 'wp_ajax_modal_checkout_request', [ __CLASS__, 'process_checkout_request' ] );
		add_action( 'wp_ajax_nopriv_modal_checkout_request', [ __CLASS__, 'process_checkout_request' ] );
		add_action( 'wp', [ __CLASS__, 'process_checkout_request' ] );
		add_action( 'wp_ajax_abandon_modal_checkout', [ __CLASS__, 'process_abandon_checkout' ] );
		add_action( 'wp_ajax_nopriv_abandon_modal_checkout', [ __CLASS__, 'process_abandon_checkout' ] );
		add_action( 'wp_loaded', [ __CLASS__, 'process_checkout_action' ], 21 );

		add_filter( 'wp_redirect', [ __CLASS__, 'pass_url_param_on_redirect' ] );
		add_filter( 'woocommerce_cart_product_cannot_be_purchased_message', [ __CLASS__, 'woocommerce_cart_product_cannot_be_purchased_message' ], 10, 2 );
		add_filter( 'woocommerce_add_error', [ __CLASS__, 'hide_expiry_message_shop_link' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_modal_markup' ], 100 );
		add_action( 'wp_footer', [ __CLASS__, 'render_variation_selection' ], 100 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_filter( 'show_admin_bar', [ __CLASS__, 'show_admin_bar' ] ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected
		add_action( 'template_include', [ __CLASS__, 'get_checkout_template' ] );
		add_filter( 'woocommerce_get_return_url', [ __CLASS__, 'woocommerce_get_return_url' ], 10, 2 );
		add_filter( 'woocommerce_get_checkout_order_received_url', [ __CLASS__, 'woocommerce_get_return_url' ], 10, 2 );
		add_filter( 'wc_get_template', [ __CLASS__, 'wc_get_template' ], 10, 2 );
		add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'woocommerce_checkout_fields' ] );
		add_filter( 'woocommerce_update_order_review_fragments', [ __CLASS__, 'order_review_fragments' ] );
		add_filter( 'woocommerce_cart_needs_payment', [ __CLASS__, 'cart_needs_payment' ] );
		add_filter( 'newspack_recaptcha_verify_captcha', [ __CLASS__, 'recaptcha_verify_captcha' ], 10, 3 );
		add_filter( 'woocommerce_enqueue_styles', [ __CLASS__, 'dequeue_woocommerce_styles' ] );
		add_filter( 'wcs_place_subscription_order_text', [ __CLASS__, 'order_button_text' ], 5 );
		add_filter( 'woocommerce_order_button_text', [ __CLASS__, 'order_button_text' ], 5 );
		add_filter( 'option_woocommerce_subscriptions_order_button_text', [ __CLASS__, 'order_button_text' ], 5 );
		add_action( 'woocommerce_before_checkout_form', [ __CLASS__, 'render_before_checkout_form' ] );
		add_action( 'woocommerce_before_checkout_form', [ __CLASS__, 'render_name_your_price_form' ], 11 );
		add_action( 'woocommerce_checkout_before_customer_details', [ __CLASS__, 'render_before_customer_details' ] );
		add_filter( 'woocommerce_enable_order_notes_field', [ __CLASS__, 'enable_order_notes_field' ] );
		add_action( 'woocommerce_checkout_process', [ __CLASS__, 'wcsg_apply_gift_subscription' ] );
		add_filter( 'woocommerce_order_received_verify_known_shoppers', '__return_false' );
		add_filter( 'woocommerce_order_button_html', [ __CLASS__, 'order_button_html' ], 10, 1 );
		add_action( 'option_woocommerce_default_customer_address', [ __CLASS__, 'ensure_base_default_customer_address' ] );
		add_action( 'default_option_woocommerce_default_customer_address', [ __CLASS__, 'ensure_base_default_customer_address' ] );
		add_action( 'wp_ajax_process_name_your_price_request', [ __CLASS__, 'process_name_your_price_request' ] );
		add_action( 'wp_ajax_nopriv_process_name_your_price_request', [ __CLASS__, 'process_name_your_price_request' ] );
		add_filter( 'option_woocommerce_woocommerce_payments_settings', [ __CLASS__, 'filter_woocommerce_payments_settings' ] );
		add_action( 'init', [ __CLASS__, 'unhook_woocommerce_payments_update_billing_fields' ] );
		add_action( 'init', [ __CLASS__, 'unhook_woocommerce_payments_express_checkout_buttons' ], 20 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'update_password_strength_message' ], 9999 );
		add_filter( 'woocommerce_enforce_password_strength_meter_on_checkout', '__return_true' );

		/** Custom handling for registered users. */
		add_filter( 'woocommerce_checkout_customer_id', [ __CLASS__, 'associate_existing_user' ] );
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'maybe_reset_checkout_registration_flag' ], 10, 2 );
		add_filter( 'woocommerce_checkout_posted_data', [ __CLASS__, 'skip_account_creation' ], 11 );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'maybe_add_checkout_registration_order_meta' ], 10, 1 );
		add_action( 'newpack_blocks_modal_checkout_thankyou', [ __CLASS__, 'reset_checkout_registration_flag' ] );

		// Remove some stuff from the modal checkout page. It's displayed in an iframe, so it should not be treated as a separate page.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'dequeue_scripts' ], PHP_INT_MAX );
		add_filter( 'newspack_reader_activation_should_render_auth', [ __CLASS__, 'is_not_modal_checkout_filter' ] );
		add_filter( 'newspack_enqueue_reader_activation_block', [ __CLASS__, 'is_not_modal_checkout_filter' ] );
		add_filter( 'newspack_enqueue_memberships_block_patterns', [ __CLASS__, 'is_not_modal_checkout_filter' ] );
		add_filter( 'newspack_ads_should_show_ads', [ __CLASS__, 'is_not_modal_checkout_filter' ] );
		add_filter( 'newspack_theme_enqueue_js', [ __CLASS__, 'is_not_modal_checkout_filter' ] );
		add_filter( 'newspack_theme_enqueue_print_styles', [ __CLASS__, 'is_not_modal_checkout_filter' ] );
		add_filter( 'cmplz_site_needs_cookiewarning', [ __CLASS__, 'is_not_modal_checkout_filter' ] );
		add_filter( 'googlesitekit_adsense_tag_blocked', [ __CLASS__, 'is_modal_checkout' ] );
		add_filter( 'jetpack_active_modules', [ __CLASS__, 'jetpack_active_modules' ] );
		add_filter( 'woocommerce_checkout_update_order_review_expired', [ __CLASS__, 'is_not_modal_checkout_filter' ] );
		add_filter( 'woocommerce_checkout_registration_enabled', [ __CLASS__, 'is_modal_checkout_filter' ] );

		// Make the current cart price available to the JavaScript.
		add_action( 'wp_ajax_get_cart_total', [ __CLASS__, 'get_cart_total_js' ] );
		add_action( 'wp_ajax_nopriv_get_cart_total', [ __CLASS__, 'get_cart_total_js' ] );

		// Wrap required checkbox text in a span so it works nicely with the Newspack UI grid layout.
		add_filter( 'woocommerce_form_field_checkbox', [ __CLASS__, 'wrap_required_checkbox_text' ], 10, 4 );

		/**
		 * Ensure that options to limit the number of subscriptions per product are respected.
		 * Note: This is normally called only for regular checkout pages and REST API requests,
		 * so we need to add the filters for modal checkout.
		 *
		 * See: https://github.com/Automattic/woocommerce-subscriptions-core/blob/trunk/includes/class-wcs-limiter.php#L23
		*/
		if ( self::is_modal_checkout() && class_exists( 'WCS_Limiter' ) ) {
			add_filter( 'woocommerce_subscription_is_purchasable', [ 'WCS_Limiter', 'is_purchasable_switch' ], 12, 2 );
			add_filter( 'woocommerce_subscription_variation_is_purchasable', [ 'WCS_Limiter', 'is_purchasable_switch' ], 12, 2 );
			add_filter( 'woocommerce_subscription_is_purchasable', [ 'WCS_Limiter', 'is_purchasable_renewal' ], 12, 2 );
			add_filter( 'woocommerce_subscription_variation_is_purchasable', [ 'WCS_Limiter', 'is_purchasable_renewal' ], 12, 2 );
			add_filter( 'woocommerce_valid_order_statuses_for_order_again', [ 'WCS_Limiter', 'filter_order_again_statuses_for_limited_subscriptions' ] );
		}
		add_filter( 'woocommerce_subscriptions_product_limited_for_user', [ __CLASS__, 'subscriptions_product_limited_for_user' ], 10, 3 );
		add_filter( 'woocommerce_get_privacy_policy_text', [ __CLASS__, 'woocommerce_get_privacy_policy_text' ], 10, 2 );

		// Remove any hooks that aren't supported by the modal checkout.
		add_action( 'wp_loaded', [ __CLASS__, 'remove_hooks' ] );

		// Exclude the modal checkout from 'Coming Soon' mode.
		add_action( 'plugins_loaded', [ __CLASS__, 'disable_coming_soon' ] );
		add_filter( 'woocommerce_coming_soon_exclude', [ __CLASS__, 'disable_coming_soon' ] );
	}

	/**
	 * Add information about modal checkout to cart item data.
	 *
	 * @param array $cart_item_data Cart item data.
	 */
	public static function amend_cart_item_data( $cart_item_data ) {
		if ( self::is_modal_checkout() ) {
			$cart_item_data['newspack_modal_checkout_url'] = \home_url( \add_query_arg( null, null ) );
		}
		return $cart_item_data;
	}

	/**
	 * Dequeue WC styles if on modal checkout.
	 *
	 * @param array $enqueue_styles Array of styles to enqueue.
	 */
	public static function dequeue_woocommerce_styles( $enqueue_styles ) {
		if ( ! self::is_modal_checkout() ) {
			return $enqueue_styles;
		}
		unset( $enqueue_styles['woocommerce-general'] );
		unset( $enqueue_styles['woocommerce-smallscreen'] );
		return $enqueue_styles;
	}

	/**
	 * Get list of supported payment gateways for Modal Checkout.
	 *
	 * @return string[] Supported payment gateways.
	 */
	public static function get_supported_payment_gateways() {
		/**
		 * Filters the list of supported gateways in modal checkout.
		 *
		 * @param array $supported_gateways
		 */
		return apply_filters( 'newspack_blocks_modal_checkout_supported_gateways', self::$supported_gateways );
	}

	/**
	 * Whether any enabled payment gateways are not supported in modal checkout.
	 *
	 * @return bool
	 */
	public static function has_unsupported_payment_gateway() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$supported_gateways = self::get_supported_payment_gateways();
		$all_gateways       = \WC()->payment_gateways()->payment_gateways();

		// Check unsupported gateways directly against admin settings. Some gateways
		// can be omitted from runtime availability due to constraints (like Wompi).
		foreach ( array_keys( $all_gateways ) as $id ) {
			if ( in_array( $id, $supported_gateways, true ) ) {
				continue;
			}
			$settings = get_option( 'woocommerce_' . $id . '_settings', [] );
			if ( is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Process checkout handler for form validation and order complete.
	 */
	public static function process_checkout_action() {
		if ( ! self::is_modal_checkout() || ! isset( $_POST['newspack_blocks_checkout_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$nonce = isset( $_POST['newspack_checkout_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['newspack_checkout_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'newspack_modal_checkout_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'newspack-blocks' ) ] );
			wp_die();
		}

		wc_nocache_headers();

		if ( \WC()->cart->is_empty() ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		// If checkout is already defined as being processed, don't run process_checkout().
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
			return;
		}

		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );

		// Generate a fresh WC nonce so process_checkout() passes its internal verification.
		// We take this approach to ensure compatibility with block theme which causes WC to no longer
		// rely on this checkout nonce requried by process checkout.
		$_REQUEST['woocommerce-process-checkout-nonce'] = wp_create_nonce( 'woocommerce-process_checkout' );

		// If this is a validation-only request, set the flag that tells process_checkout() to only validate the order.
		if ( isset( $_POST['is_validation_only'] ) ) {
			$_POST['woocommerce_checkout_update_totals'] = '1';
		}

		\WC()->checkout()->process_checkout();
	}

	/**
	 * Process checkout request for modal.
	 */
	public static function process_checkout_request() {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$is_newspack_checkout = filter_input( INPUT_GET, 'newspack_checkout', FILTER_SANITIZE_NUMBER_INT );

		if ( ! $is_newspack_checkout ) {
			return;
		}

		$product_id                 = filter_input( INPUT_GET, 'product_id', FILTER_SANITIZE_NUMBER_INT );
		$variation_id               = filter_input( INPUT_GET, 'variation_id', FILTER_SANITIZE_NUMBER_INT );
		$after_success_behavior     = filter_input( INPUT_GET, 'after_success_behavior', FILTER_SANITIZE_SPECIAL_CHARS );
		$after_success_url          = filter_input( INPUT_GET, 'after_success_url', FILTER_SANITIZE_URL );
		$after_success_button_label = filter_input( INPUT_GET, 'after_success_button_label', FILTER_SANITIZE_SPECIAL_CHARS );

		if ( $variation_id ) {
			$product_id = $variation_id;
		}

		if ( ! $product_id ) {
			return;
		}

		$referer    = wp_get_referer();
		$params     = [];
		$parsed_url = wp_parse_url( $referer );

		// Get URL params appended to the referer URL.
		if ( ! empty( $parsed_url['query'] ) ) {
			wp_parse_str( $parsed_url['query'], $params );
		}

		$params = array_merge( $params, compact( 'after_success_behavior', 'after_success_url', 'after_success_button_label' ) );

		if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
			$referer_post_id = wpcom_vip_url_to_postid( $referer );
		} else {
			$referer_post_id = url_to_postid( $referer ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid
		}

		$referer_tags       = [];
		$referer_categories = [];
		$tags               = get_the_tags( $referer_post_id );
		if ( $tags && ! empty( $tags ) ) {
			$referer_tags = array_map(
				function ( $item ) {
					return $item->slug;
				},
				$tags
			);
		}

		$categories = get_the_category( $referer_post_id );
		if ( $categories && ! empty( $categories ) ) {
			$referer_categories = array_map(
				function ( $item ) {
					return $item->slug;
				},
				$categories
			);
		}

		$cart_item_data = self::amend_cart_item_data( [ 'referer' => $referer ] );

		/** Apply NYP custom price */
		$price = filter_input( INPUT_GET, 'price', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( \Newspack_Blocks::can_use_name_your_price() ? \WC_Name_Your_Price_Helpers::is_nyp( $product_id ) : false ) {
			if ( empty( $price ) ) {
				$price = \WC_Name_Your_Price_Helpers::get_suggested_price( $product_id );
			}
			$min_price = \WC_Name_Your_Price_Helpers::get_minimum_price( $product_id );
			$max_price = \WC_Name_Your_Price_Helpers::get_maximum_price( $product_id );
			$price     = ! empty( $max_price ) ? min( $price, $max_price ) : $price;
			$price     = ! empty( $min_price ) ? max( $price, $min_price ) : $price;

			$cart_item_data['nyp'] = (float) \WC_Name_Your_Price_Helpers::standardize_number( $price );
		}

		/**
		* Filters the cart item data for the modal checkout.
		*
		* @param array $cart_item_data Cart item data.
		*/
		$cart_item_data = apply_filters( 'newspack_blocks_modal_checkout_cart_item_data', $cart_item_data );

		\WC()->cart->empty_cart();
		\WC()->cart->add_to_cart( $product_id, 1, 0, [], $cart_item_data );

		// Set checkout registration flag if user is logged not logged in.
		if ( ! is_user_logged_in() ) {
			self::set_checkout_registration_flag();
		}

		$query_args = [];
		if ( ! empty( $referer_tags ) ) {
			$query_args['referer_tags'] = implode( ',', $referer_tags );
		}
		if ( ! empty( $referer_categories ) ) {
			$query_args['referer_categories'] = implode( ',', $referer_categories );
		}

		if ( ! self::has_unsupported_payment_gateway() ) {
			$query_args['modal_checkout'] = 1;
		}

		// Pass through UTM and after_success params so they can be forwarded to the WooCommerce checkout flow.
		foreach ( $params as $param => $value ) {
			if ( 'utm' === substr( $param, 0, 3 ) || 'after_success' === substr( $param, 0, 13 ) ) {
				if ( ! empty( $value ) ) {
					$param                = sanitize_text_field( $param );
					$query_args[ $param ] = sanitize_text_field( $value );
				}
			}
		}

		$checkout_url = add_query_arg(
			$query_args,
			\wc_get_page_permalink( 'checkout' )
		);

		// Get data to send for this purchase.
		$checkout_button_metadata = [
			'currency'   => function_exists( 'get_woocommerce_currency' ) ? \get_woocommerce_currency() : 'USD',
			'amount'     => $price,
			'product_id' => $product_id,
			'referrer'   => $referer,
		];

		/**
		 * Action to fire for checkout button block modal.
		 */
		\do_action( 'newspack_blocks_checkout_button_modal', $checkout_button_metadata );

		$checkout_url = apply_filters( 'newspack_blocks_checkout_url', $checkout_url );

		if ( defined( 'DOING_AJAX' ) ) {
			echo wp_json_encode( [ 'url' => $checkout_url ] );
			exit;
		} else {
			// Redirect to checkout.
			\wp_safe_redirect( $checkout_url );
			exit;
		}
	}

	/**
	 * Process abandon checkout for modal.
	 */
	public static function process_abandon_checkout() {
		if ( ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! self::is_modal_checkout() ) {
			return;
		}

		if ( ! check_ajax_referer( 'newspack_modal_checkout_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'newspack-blocks' ) ] );
			wp_die();
		}

		$cart = \WC()->cart;
		if ( $cart && ! $cart->is_empty() ) {
			$cart->empty_cart();
		}
		self::reset_checkout_registration_flag();

		wp_send_json_success( [ 'message' => __( 'Cart has been emptied.', 'newspack-blocks' ) ] );
		wp_die();
	}

	/**
	 * Filter unsupported Woo Payments features.
	 *
	 * @param array $settings WooCommerce Payments settings.
	 *
	 * @return array Filtered WooCommerce Payments settings.
	 */
	public static function filter_woocommerce_payments_settings( $settings ) {
		if ( ! self::is_modal_checkout() ) {
			return $settings;
		}
		if ( isset( $settings['platform_checkout'] ) ) {
			$settings['platform_checkout'] = 'no';
		}
		return $settings;
	}

	/**
	 * Unhook WooCommerce Payments billing fields update.
	 *
	 * The WC_Payment_Gateway_WCPay->checkout_update_email_field_priority() hook
	 * changes the position of the email address field in the checkout form and
	 * appends a broken Stripelink button to the email input.
	 */
	public static function unhook_woocommerce_payments_update_billing_fields() {
		if ( ! self::is_modal_checkout() ) {
			return;
		}
		if ( ! class_exists( 'WC_Payments' ) ) {
			return;
		}
		$gateway = \WC_Payments::get_gateway();
		if ( ! $gateway ) {
			return;
		}
		$filters = $GLOBALS['wp_filter']['woocommerce_billing_fields'];
		foreach ( $filters as $index => $filter ) {
			$keys = array_keys( $filter );
			foreach ( $keys as $key ) {
				if ( strpos( $key, 'checkout_update_email_field_priority' ) !== false ) {
					remove_filter( 'woocommerce_billing_fields', $key, $index );
				}
			}
		}
	}

	/**
	 * Unhook WooCommerce Payments' express checkout buttons during modal checkout.
	 *
	 * WooPayments 10.4 split Apple Pay / Google Pay into separate gateways. The display
	 * handler attaches display_express_checkout_buttons to woocommerce_checkout_before_customer_details
	 * at init priority 15; when Stripe's express checkout is also active the modal renders
	 * duplicate Apple Pay / Google Pay rows. Removing the hook here suppresses WooPayments'
	 * buttons while leaving Stripe's intact. Hooked on init at priority 20 so WooPayments'
	 * init callback has already attached its actions.
	 */
	public static function unhook_woocommerce_payments_express_checkout_buttons() {
		if ( ! self::is_modal_checkout() ) {
			return;
		}
		if ( ! class_exists( 'WC_Payments' ) || ! class_exists( 'WC_Stripe' ) ) {
			return;
		}
		// Only suppress WooPayments when Stripe is also providing express checkout. Otherwise
		// WooPayments is the only source of Apple Pay / Google Pay in the modal and must render.
		$stripe_settings = get_option( 'woocommerce_stripe_settings', [] );
		if ( 'yes' !== ( $stripe_settings['enabled'] ?? '' )
			|| 'yes' !== ( $stripe_settings['express_checkout'] ?? '' ) ) {
			return;
		}
		if ( ! isset( $GLOBALS['wp_filter']['woocommerce_checkout_before_customer_details'] ) ) {
			return;
		}
		$hook      = $GLOBALS['wp_filter']['woocommerce_checkout_before_customer_details'];
		$callbacks = $hook instanceof \WP_Hook ? $hook->callbacks : (array) $hook;
		foreach ( $callbacks as $priority => $priority_callbacks ) {
			foreach ( array_keys( $priority_callbacks ) as $key ) {
				if ( strpos( $key, 'display_express_checkout_buttons' ) !== false ) {
					remove_action( 'woocommerce_checkout_before_customer_details', $key, $priority );
				}
			}
		}
	}

	/**
	 * Process name your price request for modal.
	 */
	public static function process_name_your_price_request() {
		if ( ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! \Newspack_Blocks::can_use_name_your_price() || ! method_exists( '\WC_Name_Your_Price_Helpers', 'is_nyp' ) ) {
			return;
		}

		check_ajax_referer( 'newspack_checkout_name_your_price' );

		$is_newspack_checkout_nyp = filter_input( INPUT_POST, 'newspack_checkout_name_your_price', FILTER_SANITIZE_NUMBER_INT );
		$product_id               = filter_input( INPUT_POST, 'product_id', FILTER_SANITIZE_NUMBER_INT );

		if ( ! $is_newspack_checkout_nyp || ! $product_id ) {
			return;
		}

		$price     = \WC_Name_Your_Price_Helpers::standardize_number( filter_input( INPUT_POST, 'price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION ) );
		$max_price = \WC_Name_Your_Price_Helpers::get_maximum_price( $product_id );

		if ( ! empty( $max_price ) && $price > $max_price ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						// Translators: %s is the maximum price.
						__( 'Adjusted price must be less than the maximum of %s.', 'newspack-blocks' ),
						\wc_price( $max_price )
					),
				]
			);

			wp_die();
		}

		$cart_item_data = self::amend_cart_item_data( [ 'referer' => wp_get_referer() ] );

		foreach ( \WC()->cart->get_cart() as $cart_item_key => $cart_item ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			if ( $cart_item['product_id'] !== (int) $product_id && $cart_item['variation_id'] !== (int) $product_id ) {
				continue;
			}

			$cart_item_data['nyp'] = $price;
			$cart_item_data['base_price'] = isset( $cart_item['base_price'] ) ? $cart_item['base_price'] : $cart_item['nyp'];

			if ( $price < $cart_item_data['base_price'] ) {
				wp_send_json_error(
					[
						'message' => sprintf(
							// Translators: %s is the name-your-price custom price.
							__( 'Adjusted price must be greater than base price of %s.', 'newspack-blocks' ),
							\wc_price( $cart_item_data['base_price'] )
						),
					]
				);

				wp_die();
			}
		}

		$coupons = \WC()->cart->get_applied_coupons();

		\WC()->cart->empty_cart();
		\WC()->cart->add_to_cart( $product_id, 1, 0, [], $cart_item_data );

		if ( ! empty( $coupons ) ) {
			foreach ( $coupons as $coupon ) {
				\WC()->cart->apply_coupon( $coupon );
			}
		}

		wp_send_json_success(
			[
				'message' => self::get_modal_checkout_labels( 'checkout_nyp_thankyou' ),
				'price'   => \wc_price( $price ),
			]
		);

		wp_die();
	}

	/**
	 * Render the markup necessary for the modal checkout.
	 */
	public static function render_modal_markup() {
		if ( ! self::$has_modal ) {
			return;
		}
		/**
		* Filters the header title for the modal checkout.
		*
		* @param string $title The title.
		*/
		$title        = self::get_modal_checkout_labels( 'checkout_modal_title' );
		$class_prefix = self::get_class_prefix();
		?>
		<div id="newspack_modal_checkout" class="<?php echo esc_attr( "$class_prefix {$class_prefix}__modal-container" ); ?>">
			<div class="<?php echo esc_attr( "{$class_prefix}__modal-container__overlay" ); ?>"></div>
			<div class="<?php echo esc_attr( "{$class_prefix}__modal" ); ?>" role="dialog" aria-modal="true" aria-labelledby="newspack-modal-checkout-label">
				<header class="<?php echo esc_attr( "{$class_prefix}__modal__header" ); ?>">
					<h2 id="newspack-modal-checkout-label"><?php echo esc_html( $title ); ?></h2>
					<button class="<?php echo esc_attr( "{$class_prefix}__button {$class_prefix}__button--icon {$class_prefix}__button--ghost {$class_prefix}__modal__close" ); ?>">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-blocks' ); ?></span>
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false">
							<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/>
						</svg>
					</button>
				</header>
				<section class="<?php echo esc_attr( "{$class_prefix}__modal__content" ); ?>">
					<div class="<?php echo esc_attr( "{$class_prefix}__spinner" ); ?>">
						<span></span>
					</div>
				</section>
				<?php
					// Hacky way to help trap focus in the modal for accessibility, without involving the iframe.
					echo '<span tabindex="0" id="newspack-a11y-last-element"></span>';
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Update product price string for subscriptions to use "per" instead of "/".
	 *
	 * @param string $price_string The price string.
	 */
	public static function update_subscriptions_product_price_string( $price_string ) {
		$price_string = str_replace( ' / ', ' ' . __( 'per', 'newspack-blocks' ) . ' ', $price_string );
		return $price_string;
	}

	/**
	 * Update price to remove empty decimals (.00) if over three digits.
	 *
	 * @param string $price_string The price string.
	 */
	public static function maybe_remove_decimal_spaces( $price_string ) {
		$decimal_separator = wc_get_price_decimal_separator();
		$pattern           = '/\b\d{3,}' . preg_quote( $decimal_separator, '/' ) . '00\b/';
		preg_match( $pattern, $price_string, $matches );
		if ( ! empty( $matches ) ) {
			$replace_pattern = '/' . preg_quote( $decimal_separator, '/' ) . '00$/';
			$price_string    = preg_replace( $pattern, preg_replace( $replace_pattern, '', $matches[0] ), $price_string );
		}
		return $price_string;
	}

	/**
	 * Render variation selection modal for variable products.
	 */
	public static function render_variation_selection() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		if ( ! class_exists( 'Newspack\Subscriptions_Tiers' ) || ! method_exists( 'Newspack\Subscriptions_Tiers', 'render_form' ) ) {
			return;
		}

		add_filter( 'woocommerce_subscriptions_product_price_string', [ __CLASS__, 'update_subscriptions_product_price_string' ], 10, 1 );
		add_filter( 'formatted_woocommerce_price', [ __CLASS__, 'maybe_remove_decimal_spaces' ], 10, 1 );
		/**
		* Filters the header title for the modal checkout.
		*
		* @param string $title The title.
		*/
		$title        = self::get_modal_checkout_labels( 'variation_modal_title' );
		$products     = array_keys( self::$products );
		$class_prefix = self::get_class_prefix();

		foreach ( $products as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ( ! $product->is_type( 'variable' ) && ! $product->is_type( 'variable-subscription' ) && ! $product->is_type( 'grouped' ) ) ) {
				continue;
			}
			?>
			<div
				class="<?php echo esc_attr( "$class_prefix {$class_prefix}__modal-container newspack-blocks__modal-variation" ); ?>"
				data-product-id="<?php echo esc_attr( $product_id ); ?>"
			>
				<div class="<?php echo esc_attr( "{$class_prefix}__modal-container__overlay" ); ?>"></div>
				<div class="<?php echo esc_attr( "{$class_prefix}__modal {$class_prefix}__modal--small" ); ?>" role="dialog" aria-modal="true" aria-labelledby="newspack-modal-checkout-label">
					<header class="<?php echo esc_attr( "{$class_prefix}__modal__header" ); ?>">
						<h2 id="newspack-modal-checkout-label"><?php echo esc_html( $title ); ?></h2>
						<button class="<?php echo esc_attr( "{$class_prefix}__button {$class_prefix}__button--icon {$class_prefix}__button--ghost {$class_prefix}__modal__close" ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-blocks' ); ?></span>
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false">
								<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/>
							</svg>
						</button>
					</header>
					<section class="<?php echo esc_attr( "{$class_prefix}__modal__content" ); ?>">
						<?php \Newspack\Subscriptions_Tiers::render_form( $product ); ?>
					</section>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Enqueue scripts for the checkout page rendered in a modal.
	 */
	public static function enqueue_scripts() {
		if (
			( ! function_exists( 'is_checkout' ) || ! is_checkout() ) &&
			( ! function_exists( 'is_cart' ) || ! is_cart() ) &&
			( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() )
		) {
			return;
		}

		if ( ! self::is_modal_checkout() ) {
			return;
		}

		$dependencies = [ 'jquery', 'wc-checkout' ];
		// Add support reCAPTCHA dependencies, if connected.
		if ( class_exists( 'Newspack\Recaptcha' ) && \Newspack\Recaptcha::can_use_captcha() ) {
			$dependencies[] = \Newspack\Recaptcha::SCRIPT_HANDLE;
		}

		wp_enqueue_script(
			'newspack-blocks-modal-checkout',
			plugins_url( 'dist/modalCheckout.js', \NEWSPACK_BLOCKS__PLUGIN_FILE ),
			$dependencies,
			\NEWSPACK_BLOCKS__VERSION,
			[
				'strategy'  => 'async',
				'in_footer' => true,
			]
		);
		wp_localize_script(
			'newspack-blocks-modal-checkout',
			'newspackBlocksModalCheckout',
			[
				'ajax_url'              => admin_url( 'admin-ajax.php' ),
				'nyp_nonce'             => wp_create_nonce( 'newspack_checkout_name_your_price' ),
				'checkout_nonce'        => wp_create_nonce( 'newspack_modal_checkout_nonce' ),
				'newspack_class_prefix' => self::get_class_prefix(),
				'is_checkout_complete'  => function_exists( 'is_order_received_page' ) && is_order_received_page(),
				'divider_text'          => esc_html__( 'Or', 'newspack-blocks' ),
				'is_error'              => ! is_checkout() && ! is_order_received_page(),
				'labels'                => [
					'billing_details'  => self::get_modal_checkout_labels( 'billing_details' ),
					'shipping_details' => self::get_modal_checkout_labels( 'shipping_details' ),
					'gift_recipient'   => self::get_modal_checkout_labels( 'gift_recipient' ),
					'critical_error'   => self::get_modal_checkout_labels( 'checkout_critical_error' ),
					'complete_button'  => self::order_button_text_with_price(),
				],
			]
		);
		wp_enqueue_style(
			'newspack-blocks-modal-checkout',
			plugins_url( 'dist/modalCheckout.css', \NEWSPACK_BLOCKS__PLUGIN_FILE ),
			[],
			\NEWSPACK_BLOCKS__VERSION
		);
	}

	/**
	 * Dequeue scripts not needed in the modal checkout.
	 */
	public static function dequeue_scripts() {
		/**
		 * Prevents dequeuing of scripts in modal checkout. By default,
		 * Newspack removes unnecessary scripts to improve modal checkout performance.
		 *
		 * @constant NEWSPACK_ALLOW_ALL_CHECKOUT_SCRIPTS
		 * @type     bool
		 * @default  Unnecessary scripts dequeued in modal checkout
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_ALLOW_ALL_CHECKOUT_SCRIPTS', true );
		 */
		if (
			! self::is_modal_checkout() ||
			( defined( 'NEWSPACK_ALLOW_ALL_CHECKOUT_SCRIPTS' ) && NEWSPACK_ALLOW_ALL_CHECKOUT_SCRIPTS )
		) {
			return;
		}

		global $wp_scripts, $wp_styles;

		$payment_gateways       = \WC()->payment_gateways->get_available_payment_gateways();
		$allowed_gateway_assets = [];
		if ( ! empty( $payment_gateways ) ) {
			foreach ( array_keys( $payment_gateways ) as $gateway ) {
				$class                    = get_class( $payment_gateways[ $gateway ] );
				$plugin_file              = ( new \ReflectionClass( $class ) )->getFileName();
				$plugin_base              = \plugin_basename( $plugin_file );
				$plugin_slug              = explode( '/', $plugin_base )[0];
				$allowed_gateway_assets[] = $plugin_slug;
			}
		}

		/**
		 * Filters the allowed styles to render in the modal checkout
		 *
		 * @param string[] $allowed_styles Array of allowed assets handles.
		 */
		$allowed_styles = apply_filters( 'newspack_blocks_modal_checkout_allowed_styles', self::$allowed_styles );
		foreach ( $wp_styles->registered as $handle => $wp_style ) {
			$allowed = false;
			foreach ( $allowed_styles as $allowed_style ) {
				if ( 0 === strpos( $handle, $allowed_style ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! empty( $payment_gateways ) ) {
				foreach ( $allowed_gateway_assets as $gateway ) {
					if ( false !== strpos( $wp_style->src, $gateway ) ) {
						$allowed = true;
						break;
					}
				}
			}
			if ( ! $allowed ) {
				wp_dequeue_style( $handle );
			}
		}

		/**
		 * Filters the allowed scripts to render in the modal checkout
		 *
		 * @param string[] $allowed_scripts Array of allowed assets handles.
		 */
		$allowed_scripts = apply_filters( 'newspack_blocks_modal_checkout_allowed_scripts', self::$allowed_scripts );
		foreach ( $wp_scripts->registered as $handle => $wp_script ) {
			$allowed = false;
			foreach ( $allowed_scripts as $allowed_script ) {
				if ( 0 === strpos( $handle, $allowed_script ) ) {
					$allowed = true;
					break;
				}
			}
			foreach ( $allowed_gateway_assets as $gateway ) {
				if ( $wp_script->src !== null && false !== strpos( $wp_script->src, $gateway ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				wp_dequeue_script( $handle );
			}
		}
	}

	/**
	 * Remove any hooks that may not work nicely with the modal checkout.
	 */
	public static function remove_hooks() {
		if ( ! self::is_modal_checkout() ) {
			return;
		}

		$remove_list = [];

		// reCaptcha for WooCommerce.
		if ( class_exists( 'I13_Woo_Recpatcha' ) ) {
			global $i13_woo_recpatcha;
			array_push(
				$remove_list,
				[
					'hook'     => 'woocommerce_review_order_before_payment',
					'callback' => array( $i13_woo_recpatcha, 'i13woo_extra_checkout_fields' ),
				],
				[
					'hook'     => 'woocommerce_after_checkout_validation',
					'callback' => array( $i13_woo_recpatcha, 'i13_woocomm_validate_checkout_captcha' ),
				],
				[
					'hook'     => 'woocommerce_pay_order_before_submit',
					'callback' => array( $i13_woo_recpatcha, 'i13woo_extra_checkout_fields' ),
				],
				[
					'hook'     => 'woocommerce_review_order_before_submit',
					'callback' => array( $i13_woo_recpatcha, 'i13woo_extra_checkout_fields' ),
				],
				[
					'hook'     => 'woocommerce_pay_order_before_submit',
					'callback' => array( $i13_woo_recpatcha, 'i13woo_extra_checkout_fields_pay_order' ),
				],
				[
					'hook'     => 'woocommerce_proceed_to_checkout',
					'callback' => array( $i13_woo_recpatcha, 'i13_woocommerce_payment_request_btn_captcha' ),
				],
				[
					'hook'     => 'wp_head',
					'callback' => array( $i13_woo_recpatcha, 'i13_add_header_metadata' ),
				]
			);
		}
		// OneSignal.
		if ( class_exists( 'OneSignal_Public' ) ) {
			array_push(
				$remove_list,
				[
					'hook'     => 'wp_head',
					'callback' => 'onesignal_init', // V3.
				],
				[
					'hook'     => 'wp_head',
					'callback' => array( 'OneSignal_Public', 'onesignal_header' ), // V2.
				]
			);
		}

		/**
		 * Filters the hooks to remove from the modal checkout.
		 *
		 * @param string[] $remove_list Array of hooks to remove.
		 */
		$remove_list = apply_filters( 'newspack_blocks_modal_checkout_remove_hooks', $remove_list );

		foreach ( $remove_list as $remove ) {
			$priority = has_action( $remove['hook'], $remove['callback'] );
			remove_action( $remove['hook'], $remove['callback'], $priority );
		}
	}

	/**
	 * Exclude the Modal Checkout from 'Coming Soon' mode.
	 */
	public static function disable_coming_soon() {
		return self::is_modal_checkout();
	}

	/**
	 * Enqueue script for triggering modal checkout.
	 *
	 * @param int $product_id Product ID (optional).
	 */
	public static function enqueue_modal( $product_id = null ) {
		// Don't enqueue the modal if WooCommerce is not available.
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		self::$has_modal = true;
		if ( ! empty( $product_id ) ) {
			self::$products[ $product_id ] = true;
		}
		wp_enqueue_script(
			'newspack-blocks-modal',
			plugins_url( 'dist/modal.js', \NEWSPACK_BLOCKS__PLUGIN_FILE ),
			[],
			\NEWSPACK_BLOCKS__VERSION,
			[
				'strategy'  => 'async',
				'in_footer' => true,
			]
		);
		wp_localize_script(
			'newspack-blocks-modal',
			'newspackBlocksModal',
			[
				'ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'checkout_registration_flag'      => self::CHECKOUT_REGISTRATION_FLAG,
				'newspack_class_prefix'           => self::get_class_prefix(),
				'is_registration_required'        => self::is_registration_required(),
				'has_unsupported_payment_gateway' => self::has_unsupported_payment_gateway(),
				'checkout_url'                    => remove_query_arg( 'my_account_checkout', add_query_arg( 'modal_checkout', '1', wc_get_checkout_url() ) ),
				'labels'                          => [
					'auth_modal_title'     => self::get_modal_checkout_labels( 'auth_modal_title' ),
					'checkout_modal_title' => self::get_modal_checkout_labels( 'checkout_modal_title' ),
					'register_modal_title' => self::get_modal_checkout_labels( 'register_modal_title' ),
					'signin_modal_title'   => self::get_modal_checkout_labels( 'signin_modal_title' ),
					'thankyou_modal_title' => self::get_modal_checkout_labels( 'checkout_success' ),
					'critical_error'       => self::get_modal_checkout_labels( 'checkout_critical_error' ),
				],

				'processing_payment_messages'     => [
					[
						'text'  => __( 'Processing payment...', 'newspack-blocks' ),
						'delay' => 0,
					],
					[
						'text'  => __( 'Processing payment...', 'newspack-blocks' ),
						'delay' => 250,
					],
					[
						'text'  => __( 'Verifying details...', 'newspack-blocks' ),
						'delay' => 8000,
					],
					[
						'text'  => __( 'Finalizing transaction...', 'newspack-blocks' ),
						'delay' => 14000,
					],
				],
			]
		);
		wp_enqueue_style(
			'newspack-blocks-modal',
			plugins_url( 'dist/modal.css', \NEWSPACK_BLOCKS__PLUGIN_FILE ),
			[],
			\NEWSPACK_BLOCKS__VERSION
		);
	}

	/**
	 * Use stripped down template for modal checkout.
	 *
	 * @param string $template The template to render.
	 *
	 * @return string
	 */
	public static function get_checkout_template( $template ) {
		if ( ! self::is_modal_checkout() ) {
			return $template;
		}
		// Ensure we are on a wc page or endpoint.
		if (
			( ! function_exists( 'is_checkout' ) && ! is_checkout() ) &&
			( ! function_exists( 'is_cart' ) && ! is_cart() ) &&
			( ! function_exists( 'is_wc_endpoint_url' ) && ! is_wc_endpoint_url() )
		) {
			return $template;
		}
		$class_prefix = self::get_class_prefix();
		$wc_errors    = wc_get_notices( 'error' );
		ob_start();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<link rel="profile" href="https://gmpg.org/xfn/11" />
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( "$class_prefix {$class_prefix}__modal__content" ); ?> id="newspack_modal_checkout_container">
			<?php
			if ( is_checkout() || is_order_received_page() ) {
				echo do_shortcode( '[woocommerce_checkout]' );
			} else {
				// If template is not checkout or order received we want to render an error and back button in modal checkout context.
				?>
				<div class="newspack-ui__notice newspack-ui__notice--error">
					<div>
						<p>
							<?php
							$message = __( "We're sorry, there was an unexpected error. Please try again in a few minutes.", 'newspack-blocks' );
							if ( ! empty( $wc_errors ) ) {
								$error = $wc_errors[0];
								if ( is_array( $error ) && isset( $error['notice'] ) ) {
									$message = $error['notice'];
								}
							}
							echo esc_html( $message );
							?>
						</p>
					</div>
				</div>
				<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide" id="checkout_error_back" type="submit"><?php esc_html_e( 'Go back', 'newspack-blocks' ); ?></button>
				<?php
			}
				wp_footer();
			?>
		</body>
		</html>
		<?php
		ob_end_flush();
	}

	/**
	 * Get after success button params.
	 */
	private static function get_after_success_params() {
		$request_params = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::is_express_checkout() ) {
			$request_params = [];
			$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? \esc_url_raw( \wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $referrer ) {
				$referrer_query = \wp_parse_url( $referrer, PHP_URL_QUERY );
				\wp_parse_str( $referrer_query, $request_params );
			}
		}
		return array_filter(
			[
				'after_success_behavior'     => isset( $request_params['after_success_behavior'] ) ? sanitize_text_field( wp_unslash( $request_params['after_success_behavior'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'after_success_url'          => isset( $request_params['after_success_url'] ) ? sanitize_url( wp_unslash( $request_params['after_success_url'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'after_success_button_label' => isset( $request_params['after_success_button_label'] ) ? sanitize_text_field( wp_unslash( $request_params['after_success_button_label'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'action_type'                => isset( $request_params['action_type'] ) ? sanitize_text_field( wp_unslash( $request_params['action_type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			]
		);
	}

	/**
	 * Render hidden inputs to pass some params along.
	 */
	private static function render_hidden_inputs() {
		foreach ( self::get_after_success_params() as $key => $value ) {
			?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php
		}
	}

	/**
	 * Return URL for modal checkout "thank you" page.
	 *
	 * @param string   $url The URL to redirect to.
	 * @param WC_Order $order The order related to the transaction.
	 *
	 * @return string
	 */
	public static function woocommerce_get_return_url( $url, $order ) {
		if ( ! self::is_modal_checkout() || self::has_unsupported_payment_gateway() ) {
			return $url;
		}

		$args = array_merge(
			[
				'modal_checkout' => '1',
				'email'          => isset( $_REQUEST['billing_email'] ) ? rawurlencode( \sanitize_email( \wp_unslash( $_REQUEST['billing_email'] ) ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			],
			self::get_after_success_params()
		);

		// Pass order ID for modal checkout templates.
		if ( $order && is_a( $order, 'WC_Order' ) ) {
			$args['order_id'] = $order->get_id();
			$args['key']      = $order->get_order_key();
		}

		// Pass checkout registration flag.
		if ( isset( $_REQUEST[ self::CHECKOUT_REGISTRATION_FLAG ] ) && $_REQUEST[ self::CHECKOUT_REGISTRATION_FLAG ] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$args[ self::CHECKOUT_REGISTRATION_FLAG ] = '1';
		}
		return add_query_arg(
			$args,
			$url
		);
	}

	/**
	 * Update the text used for the Password Strength message from WooCommerce.
	 */
	public static function update_password_strength_message() {
		wp_localize_script(
			'wc-password-strength-meter',
			'pwsL10n',
			array(
				'mismatch' => __( 'Password mismatch', 'newspack-blocks' ),
				'short'    => __( 'Password strength: Very weak', 'newspack-blocks' ),
				'bad'      => __( 'Password strength: Weak', 'newspack-blocks' ),
				'good'     => __( 'Password strength: Medium', 'newspack-blocks' ),
				'strong'   => __( 'Password strength: Strong', 'newspack-blocks' ),
			)
		);
	}

	/**
	 * Use modal checkout template when rendering the checkout form.
	 *
	 * @param string $located       Template file.
	 * @param string $template_name Template name.
	 *
	 * @return string Template file.
	 */
	public static function wc_get_template( $located, $template_name ) {
		if ( ! self::is_modal_checkout() ) {
			return $located;
		}

		$custom_templates = [
			'checkout/form-coupon.php'            => 'src/modal-checkout/templates/form-coupon.php',
			'checkout/form-gift-subscription.php' => 'src/modal-checkout/templates/form-gift-subscription.php',
		];

		// If Newspack UI is present, use our templates.
		if ( self::get_class_prefix() === 'newspack-ui' ) {
			// Replace the login form with the order summary if using the modal checkout. This is
			// for the case where the reader used an existing email address.
			$custom_templates['global/form-login.php']       = 'src/modal-checkout/templates/thankyou.php';
			$custom_templates['checkout/form-checkout.php']  = 'src/modal-checkout/templates/form-checkout.php';
			$custom_templates['checkout/payment-method.php'] = 'src/modal-checkout/templates/payment-method.php';
			$custom_templates['checkout/form-change-payment-method.php'] = 'src/modal-checkout/templates/form-change-payment-method.php';
			$custom_templates['checkout/thankyou.php']       = 'src/modal-checkout/templates/thankyou.php';
		}

		// Only show the woocommerce-subscriptions-gifting fields when we want to.
		if ( 'html-add-recipient.php' === $template_name ) {
			$cart_items = \WC()->cart->get_cart();

			// If there's only one cart item, prefer our custom gift UI. Otherwise, use the default.
			if ( 1 === count( array_values( $cart_items ) ) && class_exists( 'WCS_Gifting' ) ) {
				$custom_templates['html-add-recipient.php'] = 'src/modal-checkout/templates/empty-html-add-recipient.php';
			}
		}

		foreach ( $custom_templates as $original_template => $custom_template ) {
			if ( $template_name === $original_template ) {
				$located = NEWSPACK_BLOCKS__PLUGIN_DIR . $custom_template;
			}
		}

		// This is for the initial display – the markup will be refetched on cart updates (e.g. applying a coupon).
		// Then it'd be handled by the `woocommerce_update_order_review_fragments` filter.
		if ( 'checkout/review-order.php' === $template_name && ! self::should_show_order_details() ) {
			$located = NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/modal-checkout/templates/empty-order-details.php';
		}

		return $located;
	}

	/**
	 * Disable admin bar for modal checkout.
	 *
	 * @param bool $show Whether to show the admin bar.
	 *
	 * @return bool
	 */
	public static function show_admin_bar( $show ) {
		if ( ! self::is_modal_checkout() ) {
			return $show;
		}
		return false;
	}

	/**
	 * Modify fields for modal checkout.
	 *
	 * @param array $fields Checkout fields.
	 *
	 * @return array
	 */
	public static function woocommerce_checkout_fields( $fields ) {
		if ( ! self::is_modal_checkout() ) {
			return $fields;
		}
		$cart = \WC()->cart;
		// Don't modify fields if shipping is required.
		if ( $cart->needs_shipping_address() ) {
			return $fields;
		}
		/**
		 * Temporarily use the same fields as the donation checkout.
		 *
		 * This should soon be replaced with a logic that allows the customization
		 * at the Checkout Button Block level.
		 */
		$billing_fields = apply_filters( 'newspack_blocks_donate_billing_fields_keys', [] );
		if ( empty( $billing_fields ) ) {
			return $fields;
		}
		$billing_keys = array_keys( $fields['billing'] );
		foreach ( $billing_keys as $key ) {
			if ( in_array( $key, $billing_fields, true ) ) {
				continue;
			}
			unset( $fields['billing'][ $key ] );
		}

		/**
		 * Add the form-row-last CSS class to billing phone field.
		 */
		if ( in_array( 'billing_phone', $billing_fields, true ) ) {
			$fields['billing']['billing_phone']['class'] = 'form-row-last';
		}

		return $fields;
	}

	/**
	 * If WooCommerce Subscriptions Gifting extension is available, render its fields.
	 *
	 * @return string HTML for the WooCommerce Subscriptions Gifting fields.
	 */
	public static function maybe_show_wcs_gifting_fields() {
		if ( ! self::is_modal_checkout() ) {
			return;
		}

		// Add custom Gift Subscriptions fields if needed.
		$cart_items = array_values( \WC()->cart->get_cart() );
		if (
			1 === count( $cart_items ) &&
			method_exists( 'WCSG_Cart', 'is_giftable_item' ) &&
			method_exists( 'WCSG_Cart', 'contains_gifted_renewal' ) &&
			function_exists( 'wcs_cart_contains_renewal' )
		) {
			$cart_item = reset( $cart_items );
			if ( \WCSG_Cart::is_giftable_item( $cart_item ) ) {
				$email = ( empty( $cart_item['wcsg_gift_recipients_email'] ) ) ? '' : $cart_item['wcsg_gift_recipients_email'];
				$args  = [
					'email'      => $email,
					'is_renewal' => false,
				];

				if ( \WCSG_Cart::contains_gifted_renewal() ) {
					$recipient_user_id = \WCSG_Cart::get_recipient_from_cart_item( \wcs_cart_contains_renewal() );
					$recipient_user    = \get_userdata( $recipient_user_id );
					if ( $recipient_user && isset( $recipient_user->email ) ) {
						$args['email']      = $recipient_user->user_email;
						$args['is_renewal'] = true;
					}
				}

				\wc_get_template( 'checkout/form-gift-subscription.php', $args );
			}
		}
	}

	/**
	 * If the WooCommerce Subscriptions Gifting extension is available, handle custom form inputs.
	 */
	public static function wcsg_apply_gift_subscription() {
		$cart_items = \WC()->cart->get_cart();
		if (
			1 === count( array_values( $cart_items ) ) &&
			method_exists( 'WCS_Gifting', 'email_belongs_to_current_user' ) &&
			method_exists( 'WCS_Gifting', 'update_cart_item_recipient' ) &&
			method_exists( 'WCSG_Cart', 'is_giftable_item' )
		) {
			$is_gift   = ! empty( filter_input( INPUT_POST, 'newspack_wcsg_is_gift', FILTER_SANITIZE_SPECIAL_CHARS ) );
			$cart_item_key = array_keys( $cart_items )[0];
			$cart_item     = array_values( $cart_items )[0];
			if ( $is_gift && \WCSG_Cart::is_giftable_item( $cart_item ) ) {
				$recipient_email = \sanitize_email( filter_input( INPUT_POST, 'wcsg_gift_recipients_email', FILTER_SANITIZE_EMAIL ) );
				$self_gifting    = \WCS_Gifting::email_belongs_to_current_user( $recipient_email );
				$is_valid_email  = ! $self_gifting && \is_email( $recipient_email );

				// If no errors, attach the recipient's email address to the subscription item.
				if ( $is_valid_email ) {
					\WCS_Gifting::update_cart_item_recipient( $cart_item, $cart_item_key, $recipient_email );
				} else {
					$notice = $self_gifting
						? __( 'Please enter someone else\' email address to receive this gift.', 'newspack-blocks' )
						: __( 'Please enter a valid email address to receive this gift.', 'newspack-blocks' );

					// Handle email validation errors.
					\wc_add_notice(
						$notice,
						'error',
						[ 'id' => 'wcsg_gift_recipients_email' ]
					);
				}
			}
		}
	}

	/**
	 * Whether to show order details table.
	 *
	 * @return bool
	 */
	public static function should_show_order_details() {
		$cart = \WC()->cart;
		if ( $cart->is_empty() ) {
			return false;
		}
		if ( ! empty( $cart->get_applied_coupons() ) ) {
			return true;
		}
		if ( \wc_tax_enabled() && ! $cart->display_prices_including_tax() ) {
			return true;
		}
		if ( 1 < $cart->get_cart_contents_count() ) {
			return true;
		}
		if ( ! empty( $cart->get_fees() ) ) {
			return true;
		}
		if ( method_exists( 'WC_Subscriptions_Switcher', 'cart_contains_switches' ) && \WC_Subscriptions_Switcher::cart_contains_switches( 'any' ) ) {
			return true;
		}
		if ( $cart->needs_shipping_address() ) {
			$shipping       = \WC()->shipping;
			$packages       = $shipping->get_packages();
			$totals         = $cart->get_totals();
			$shipping_rates = [];

			// Find all the shipping rates that apply to the current transaction.
			foreach ( $packages as $package ) {
				if ( ! empty( $package['rates'] ) ) {
					foreach ( $package['rates'] as $rate_key => $rate ) {
						$shipping_rates[ $rate_key ] = $rate;
					}
				}
			}

			// Show details if shipping requires a fee or if there are multiple shipping rates to choose from.
			if ( (float) $totals['total'] !== (float) $totals['subtotal'] || 1 < count( array_values( $shipping_rates ) ) ) {
				return true;
			}
		}

		if ( class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return true;
		}

		// Show details if the cart contains a subscription renewal.
		if ( function_exists( 'wcs_cart_contains_renewal' ) && \wcs_cart_contains_renewal() ) {
			return true;
		}

		return false;
	}

	/**
	 * Customize order review fragments on cart updates.
	 *
	 * @param array $fragments Fragments.
	 *
	 * @return array
	 */
	public static function order_review_fragments( $fragments ) {
		if ( ! self::is_modal_checkout() ) {
			return $fragments;
		}
		if ( ! self::should_show_order_details() ) {
			// Render an empty table so WC knows how to replace it on updates.
			$fragments['.woocommerce-checkout-review-order-table'] = '<table class="shop_table woocommerce-checkout-review-order-table empty"></table>';
		}
		return $fragments;
	}

	/**
	 * Render a generic button to close the modal.
	 *
	 * @param string $button_label The button label.
	 */
	private static function render_close_button( $button_label = '' ) {
		if ( ! $button_label ) {
			$button_label = __( 'Close window', 'newspack-blocks' );
		}
		?>
			<div class="button-container">
				<a
					onclick="parent.newspackCloseModalCheckout(this);"
					class="button close-button"
				>
					<?php echo esc_html( $button_label ); ?>
				</a>
			</div>
		<?php
	}

	/**
	 * Is the current request only to validate billing field inputs on the first modal screen?
	 *
	 * @return bool True if the request is for validation only.
	 */
	private static function is_validation_only() {
		return boolval( filter_input( INPUT_POST, 'is_validation_only', FILTER_SANITIZE_NUMBER_INT ) );
	}

	/**
	 * Determine if the request needs payment.
	 * If we're just validating billing fields at the first modal screen, this should always be false.
	 *
	 * @param bool $needs_payment Whether the cart needs payment.
	 *
	 * @return bool False if we're in modal checkout and validating billing fields.
	 */
	public static function cart_needs_payment( $needs_payment ) {
		if ( self::is_modal_checkout() && self::is_validation_only() ) {
			return false;
		}
		return $needs_payment;
	}

	/**
	 * Prevent reCAPTCHA from being verified for AJAX checkout (e.g. Apple Pay).
	 *
	 * @param bool   $should_verify Whether to verify the captcha.
	 * @param string $url The URL from which the verification request originated.
	 * @param string $context The context that triggered the verification request.
	 */
	public static function recaptcha_verify_captcha( $should_verify, $url, $context = 'unknown' ) {
		if ( 'checkout' !== $context ) {
			return $should_verify;
		}
		if ( self::is_validation_only() ) {
			return false;
		}
		return $should_verify;
	}

	/**
	 * Ensure that the modal_checkout param is passed if we get redirected while inside the modal.
	 * This is so that we can continue hiding site elements that we don't want to show inside the modal.
	 *
	 * @param string $location The path or URL to redirect to.
	 *
	 * @return string
	 */
	public static function pass_url_param_on_redirect( $location ) {
		if ( self::is_modal_checkout() ) {
			$params = [ 'modal_checkout' => 1 ];
			$newspack_popup_id = filter_input( INPUT_GET, 'newspack_popup_id', FILTER_SANITIZE_NUMBER_INT );
			$prompt_title = filter_input( INPUT_GET, 'prompt_title', FILTER_SANITIZE_SPECIAL_CHARS );
			$gate_post_id      = filter_input( INPUT_GET, 'gate_post_id', FILTER_SANITIZE_NUMBER_INT );
			if ( $newspack_popup_id ) {
				$params['newspack_popup_id'] = $newspack_popup_id;
			}
			if ( $prompt_title ) {
				$params['prompt_title'] = $prompt_title;
			}
			if ( $gate_post_id ) {
				$params['gate_post_id'] = $gate_post_id;
			}
			$location = \add_query_arg( $params, $location );
		}
		return $location;
	}

	/**
	 * Alternative error message to show when limiting a subscription product's purchase to only one active subscription.
	 *
	 * @return string
	 */
	public static function get_subscription_limited_message() {
		return sprintf(
			// translators: %s: Site name.
			__( "You're already a subscriber! You can only have one active subscription at a time. Thank you for supporting %s.", 'newspack-blocks' ),
			get_bloginfo( 'name' )
		);
	}

	/**
	 * Alternative error message to show when limiting a subscription product's purchase to one of any status.
	 *
	 * @return string
	 */
	public static function get_subscription_limited_message_any() {
		return __( "You're already a subscriber! You can only have one subscription at a time. If you wish to renew an expired subscription, please sign in and visit the subscriptions page on your account.", 'newspack-blocks' );
	}

	/**
	 * Filters the error message shown when a product can't be added to the cart.
	 *
	 * @param string     $message Message.
	 * @param WC_Product $product_data Product data.
	 *
	 * @return string
	 */
	public static function woocommerce_cart_product_cannot_be_purchased_message( $message, $product_data ) {
		if ( method_exists( 'WCS_Limiter', 'is_purchasable' ) ) {
			$product            = \wc_get_product( $product_data->get_id() );
			$product_limitation = wcs_get_product_limitation( $product->get_id() );

			if ( ! \WCS_Limiter::is_purchasable( false, $product ) ) {
				if ( 'active' === $product_limitation ) {
					$message = self::get_subscription_limited_message();
				} else {
					$message = self::get_subscription_limited_message_any();
				}
			}
		}

		return $message;
	}

	/**
	 * We don't want to show the Shop page link in modal checkout because the Shop page doesn't work well inside.
	 * Unfortunately Woo doesn't provide a filter for this message, so we need to detect it by string matching.
	 * May not work if the message has been translated or modified elsewhere.
	 *
	 * @param string $message The message.
	 * @return string
	 */
	public static function hide_expiry_message_shop_link( $message ) {
		if ( self::is_modal_checkout() && strpos( $message, 'Sorry, your session has expired' ) !== false ) {
			return self::get_modal_checkout_labels( 'checkout_critical_error' );
		}
		return $message;
	}

	/**
	 * Is this request using the modal checkout?
	 */
	public static function is_modal_checkout() {
		// Until we use the modal checkout flow from My Account, we don't want to show the modal checkout thank you template for checkouts originating from My Account.
		if ( method_exists( 'Newspack\WooCommerce_My_Account', 'is_from_my_account' ) && \Newspack\WooCommerce_My_Account::is_from_my_account() ) {
			return false;
		}

		$is_modal_checkout = isset( $_REQUEST['modal_checkout'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $is_modal_checkout && isset( $_REQUEST['post_data'] ) && is_string( $_REQUEST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_modal_checkout = strpos( $_REQUEST['post_data'], 'modal_checkout=1' ) !== false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( self::is_express_checkout() ) {
			$is_modal_checkout = true;
		}

		return $is_modal_checkout;
	}

	/**
	 * Is this transaction using an express checkout method?
	 */
	public static function is_express_checkout() {
		// Get express_payment_type in a way that works for both Stripe and WooPayments.
		$express_payment_info = isset( $_POST['express_payment_type'] ) ? sanitize_text_field( wp_unslash( $_POST['express_payment_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$is_express_checkout = ! empty( $express_payment_info ) && in_array( $express_payment_info, [ 'apple_pay', 'google_pay', 'payment_request_api', 'link' ], true );  // Validate payment request types: https://github.com/woocommerce/woocommerce-gateway-stripe/blob/develop/includes/payment-methods/class-wc-stripe-payment-request.php#L557-L586.

		if ( $is_express_checkout ) {
			$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? \esc_url_raw( \wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $referrer ) {
				$referrer_query = \wp_parse_url( $referrer, PHP_URL_QUERY );
				\wp_parse_str( $referrer_query, $referrer_query_params );
				if ( isset( $referrer_query_params['modal_checkout'] ) && $referrer_query_params['modal_checkout'] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Set the "Place order" button text.
	 *
	 * @param string $text The button text.
	 */
	public static function order_button_text( $text ) {
		if ( ! self::is_modal_checkout() ) {
			return $text;
		}
		$cart = \WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return $text;
		}

		return self::get_modal_checkout_labels( 'checkout_confirm' );
	}

	/**
	 * Get the updated "Place order" button text for JS updates.
	 */
	public static function order_button_text_with_price() {
		$cart = \WC()->cart;
		$is_donation = method_exists( 'Newspack\Donations', 'is_donation_cart' ) && \Newspack\Donations::is_donation_cart();

		// If this isn't a cart, if the cart is empty, or if this is a donation product, bail.
		if ( ! $cart || $cart->is_empty() || $is_donation ) {
			return;
		}

		// Bail if this is a subscription switch.
		if ( method_exists( 'WC_Subscriptions_Switcher', 'cart_contains_switches' ) && \WC_Subscriptions_Switcher::cart_contains_switches( 'any' ) ) {
			return;
		}

		$total = \wp_strip_all_tags( \wc_price( $cart->total ) );

		return sprintf(
			/* translators: 1: Checkout button confirmation text. 2: Order total. */
			__( '%1$s: %2$s', 'newspack-blocks' ),
			self::get_modal_checkout_labels( 'checkout_confirm' ),
			'<span class="cart-price">' . html_entity_decode( $total, ENT_COMPAT ) . '</span>'
		);
	}

	/**
	 * Get the updated price for updating the "Place order" button.
	 */
	public static function get_cart_total_js() {
		$cart = \WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}
		$total = \wp_strip_all_tags( \wc_price( $cart->total ) );
		echo esc_html( html_entity_decode( $total, ENT_COMPAT ) );
		wp_die();
	}

	/**
	 * Render before the checkout form.
	 *
	 * This will render the order summary card.
	 */
	public static function render_before_checkout_form() {
		if ( ! self::is_modal_checkout() ) {
			return;
		}
		$cart = \WC()->cart;
		if ( 1 !== $cart->get_cart_contents_count() ) {
			return;
		}
		$cart_item_key = array_key_first( $cart->get_cart() );
		$cart_item = $cart->get_cart_item( $cart_item_key );
		$product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
		$class_prefix = self::get_class_prefix();
		?>
			<div class="<?php echo esc_attr( "order-details-summary {$class_prefix}__box {$class_prefix}__box--text-center" ); ?>">
			<?php
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hooks.
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) :
				?>
				<p id="modal-checkout-product-details" data-checkout='<?php echo wp_json_encode( Checkout_Data::get_checkout_data( $cart ) ); ?>'>
					<strong>
						<?php
						echo apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key ) . ': '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<?php echo apply_filters( 'woocommerce_cart_item_subtotal', $cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</strong>
				</p>
				<?php
			endif;
			// phpcs:enable
			?>
			</div>
		<?php
	}

	/**
	 * Render name your price form if nyp is active and available.
	 */
	public static function render_name_your_price_form() {
		if ( ! self::is_modal_checkout() || ! function_exists( 'WC' ) || ! method_exists( '\WC_Name_Your_Price_Helpers', 'is_nyp' ) ) {
			return;
		}

		// Only show nyp form for checkout button modal checkout.
		$is_donation = method_exists( 'Newspack\Donations', 'is_donation_cart' ) && \Newspack\Donations::is_donation_cart();
		if ( $is_donation ) {
			return;
		}

		$cart = \WC()->cart;
		if ( 1 !== $cart->get_cart_contents_count() ) {
			return;
		}
		$class_prefix = self::get_class_prefix();
		?>
			<div class="name-your-price">
				<?php
				// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hooks.
				foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) :
					$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
					if ( ! $_product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! \WC_Name_Your_Price_Helpers::is_nyp( $_product ) || ! apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
						continue;
					}
					$currency_symbol = \get_woocommerce_currency_symbol();
					$product_id      = $_product->get_id();
					$price           = $_product->get_price();
					?>
					<form class="modal_checkout_nyp">
						<h3><?php echo esc_html( self::get_modal_checkout_labels( 'checkout_nyp_title' ) ); ?></h3>
						<input type="hidden" name="newspack_checkout_name_your_price" value="1" />
						<input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>" />
						<p class="input-price" >
							<label for="price">
								<span class="currency"><?php echo esc_html( $currency_symbol ); ?></span>
								<input type="number" min="0" step=".01" name="price" placeholder="<?php echo esc_attr( $price ); ?>" onwheel="return false" />
							</label>
							<button type="submit" class="<?php echo esc_attr( "{$class_prefix}__button {$class_prefix}__button--outline" ); ?>">
								<?php echo esc_html( self::get_modal_checkout_labels( 'checkout_nyp_apply' ) ); ?>
							</button>
						</p>
						<p class="result <?php echo esc_attr( "{$class_prefix}__helper-text" ); ?>">
							<?php echo esc_attr( self::get_modal_checkout_labels( 'checkout_nyp' ) ); ?>
						</p>
					</form>
					<?php
				endforeach;
				// phpcs:enable
				?>
			</div>
		<?php
	}

	/**
	 * Render before customer details.
	 *
	 * This will render the hidden inputs necessary for the modal checkout.
	 */
	public static function render_before_customer_details() {
		if ( ! self::is_modal_checkout() ) {
			return;
		}
		?>
		<input type="hidden" name="modal_checkout" value="1" />
		<?php self::render_hidden_inputs(); ?>
		<?php
	}

	/**
	 * Maybe disable order notes in the modal checkout.
	 *
	 * @param bool $enable Whether to enable the order notes field.
	 *
	 * @return bool
	 */
	public static function enable_order_notes_field( $enable ) {
		if ( self::is_modal_checkout() ) {
			$cart = \WC()->cart;
			$billing_fields = apply_filters( 'newspack_blocks_donate_billing_fields_keys', [], $cart );
			return in_array( 'order_comments', $billing_fields, true );
		}
		return $enable;
	}

	/**
	 * Force option for base country for new customers if unset and billing country optional while state is required
	 * unless the NEWSPACK_PREVENT_FORCE_BASE_DEFAULT_CUSTOMER_ADDRESS constant is set.
	 *
	 * If this option is empty AND billing state is set as a required field AND billing country is not,
	 * validation of the state value will fail during modal checkout.
	 *
	 * See Default Customer Location in: https://woo.com/document/configuring-woocommerce-settings/#general-options
	 *
	 * @param string $option_value The value of the default customer address option.
	 *
	 * @return string Option value.
	 */
	public static function ensure_base_default_customer_address( $option_value ) {
		// If the option is set, we're good.
		if ( ! empty( $option_value ) ) {
			return $option_value;
		}

		// Only in modal checkout.
		if ( ! self::is_modal_checkout() ) {
			return $option_value;
		}

		/**
		 * Prevents forcing the WooCommerce base location as the default
		 * customer address in modal checkout. Use if you need standard
		 * geolocation behavior.
		 *
		 * @constant NEWSPACK_PREVENT_FORCE_BASE_DEFAULT_CUSTOMER_ADDRESS
		 * @type     bool
		 * @default  Base address forced in modal checkout
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_PREVENT_FORCE_BASE_DEFAULT_CUSTOMER_ADDRESS', true );
		 */
		if ( defined( 'NEWSPACK_PREVENT_FORCE_BASE_DEFAULT_CUSTOMER_ADDRESS' ) && NEWSPACK_PREVENT_FORCE_BASE_DEFAULT_CUSTOMER_ADDRESS ) {
			return $option_value;
		}

		// If billing state is required but billing country is not, we need to ensure a default location is set.
		if ( defined( '\Newspack\Donations::DONATION_BILLING_FIELDS_OPTION' ) ) {
			$billing_fields = get_option( \Newspack\Donations::DONATION_BILLING_FIELDS_OPTION, [] );
			if ( ! in_array( 'billing_country', $billing_fields, true ) && in_array( 'billing_state', $billing_fields, true ) ) {
				return 'base';
			}
		}

		return $option_value;
	}

	/**
	 * Get user from email.
	 *
	 * @return false|int User ID if found by email address, false otherwise.
	 */
	public static function get_user_id_from_email() {
		$billing_email = filter_input( INPUT_POST, 'billing_email', FILTER_SANITIZE_EMAIL );
		if ( $billing_email ) {
			$customer = \get_user_by( 'email', $billing_email );
			if ( $customer ) {
				return $customer->ID;
			}
		}
		return false;
	}

	/**
	 * If a reader tries to make a purchase with an email address that
	 * has been previously registered, automatically associate the transaction
	 * with the user.
	 *
	 * @param int $customer_id Current customer ID.
	 *
	 * @return int Modified $customer_id
	 */
	public static function associate_existing_user( $customer_id ) {
		if ( ! self::is_modal_checkout() ) {
			return $customer_id;
		}
		$id_from_email = self::get_user_id_from_email();
		if ( $id_from_email ) {
			return $id_from_email;
		}
		return $customer_id;
	}

	/**
	 * Don't force account registration/login on Woo purchases for existing users.
	 *
	 * @param array $data Array of Woo checkout data.
	 *
	 * @return array Modified $data.
	 */
	public static function skip_account_creation( $data ) {
		$should_skip_account_creation = false;

		// Always skip account creation during validation-only requests.
		if ( self::is_validation_only() ) {
			$should_skip_account_creation = true;
		} elseif ( self::is_modal_checkout() && ! empty( $data['billing_email'] ) ) {
			$customer = \get_user_by( 'email', $data['billing_email'] );
			if ( $customer ) {
				$should_skip_account_creation = true;
			}
		}

		if ( $should_skip_account_creation ) {
			$data['createaccount'] = 0;
			\add_filter( 'woocommerce_checkout_registration_required', '__return_false', 9999 );
		}

		return $data;
	}

	/**
	 * The value for the custom WooCommerce Subscriptions Gifting Extension checkbox label.
	 *
	 * @return string Gift checkbox label.
	 */
	public static function subscriptions_gifting_label() {
		$is_donation = method_exists( 'Newspack\Donations', 'is_donation_cart' ) && \Newspack\Donations::is_donation_cart();
		$label       = $is_donation ? self::get_modal_checkout_labels( 'donation_gift_details' ) : self::get_modal_checkout_labels( 'purchase_gift_details' );
		return \apply_filters( 'wcsg_enable_gifting_checkbox_label', $label ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hooks.
	}

	/**
	 * Wrap required checkbox text in a span so it works nicely with the Newspack UI grid layout.
	 *
	 * @param string $field The field HTML.
	 * @param string $key The field key.
	 * @param array  $args The field arguments.
	 * @param string $value The field value.
	 * @return string Modified field HTML.
	 */
	public static function wrap_required_checkbox_text( $field, $key, $args, $value ) {
		if ( ! self::is_modal_checkout() ) {
			return $field;
		}

		if ( ! empty( $args['required'] ) && $args['type'] === 'checkbox' ) {
			// Wrap the label's text and required asterisk in a span.
			$field = preg_replace(
				'/(<label[^>]*>.*?<input[^>]*>)(.*?)(<\/label>)/s',
				'$1<span>$2</span>$3',
				$field
			);
		}
		return $field;
	}

	/**
	 * Filter a value to true dependent on the page not being modal checkout.
	 *
	 * @param bool $value The value.
	 */
	public static function is_modal_checkout_filter( $value ) {
		if ( self::is_modal_checkout() ) {
			return true;
		}
		return $value;
	}

	/**
	 * Filter a value to false dependent on the page not being modal checkout.
	 *
	 * @param bool $value The value.
	 */
	public static function is_not_modal_checkout_filter( $value ) {
		if ( self::is_modal_checkout() ) {
			return false;
		}
		return $value;
	}

	/**
	 * Deactivate all Jetpack modules on the modal checkout.
	 *
	 * @param bool $modules JP modules.
	 */
	public static function jetpack_active_modules( $modules ) {
		if ( self::is_modal_checkout() ) {
			return [];
		}
		return $modules;
	}

	/**
	 * Get the relevant class prefix (newspack-blocks or newspack-ui) depending on whether Newpack plugin is active.
	 */
	private static function get_class_prefix() {
		return class_exists( '\Newspack\Newspack_UI' ) ? 'newspack-ui' : 'newspack-blocks';
	}

	/**
	 * Add newspack ui classes to the "Place order" button html.
	 *
	 * @param string $html The button html.
	 *
	 * @return string The modified button html.
	 */
	public static function order_button_html( $html ) {
		if ( ! self::is_modal_checkout() ) {
			return $html;
		}

		$class_prefix      = self::get_class_prefix();
		$newspack_ui_html = preg_replace( '/class=".*?"/', "class='{$class_prefix}__button {$class_prefix}__button--primary {$class_prefix}__button--wide'", $html );

		return $newspack_ui_html;
	}

	/**
	 * Get modal checkout flow labels.
	 *
	 * @param string|null $key Key of the label to return (optional).
	 *
	 * @return string[]|string The label string or an array of labels keyed by string.
	 */
	public static function get_modal_checkout_labels( $key = null ) {
		if ( empty( self::$modal_checkout_labels ) ) {
			$default_labels = [
				'billing_details'            => __( 'Billing details', 'newspack-blocks' ),
				'shipping_details'           => __( 'Shipping details', 'newspack-blocks' ),
				'gift_recipient'             => __( 'Gift recipient', 'newspack-blocks' ),
				'checkout_modal_title'       => __( 'Complete your transaction', 'newspack-blocks' ),
				'variation_modal_title'      => __( 'Complete your transaction', 'newspack-blocks' ),
				'auth_modal_title'           => __( 'Complete your transaction', 'newspack-blocks' ),
				'signin_modal_title'         => _x(
					'Sign in to complete transaction',
					'Login modal title when logged out user attempts to checkout.',
					'newspack-blocks'
				),
				'register_modal_title'       => _x(
					'Register to complete transaction',
					'Login modal title when unregistered user attempts to checkout',
					'newspack-blocks'
				),
				'after_success'              => __( 'Continue browsing', 'newspack-blocks' ),
				'donation_gift_details'      => __( 'This donation is a gift', 'newspack-blocks' ),
				'purchase_gift_details'      => get_option(
					'woocommerce_subscriptions_gifting_gifting_checkbox_text',
					method_exists( 'Newspack\WooCommerce_Subscriptions_Gifting', 'default_gifting_checkbox_text' ) ?
						\Newspack\WooCommerce_Subscriptions_Gifting::default_gifting_checkbox_text() :
						__( 'This purchase is a gift', 'newspack-blocks' )
				),
				'checkout_confirm'           => __( 'Complete transaction', 'newspack-blocks' ),
				'checkout_confirm_variation' => __( 'Purchase', 'newspack-blocks' ),
				'checkout_edit_billing'      => __( 'Edit billing information', 'newspack-blocks' ),
				'checkout_cancel'            => __( 'Cancel', 'newspack-blocks' ),
				'checkout_success'           => __( 'Transaction successful', 'newspack-blocks' ),
				'checkout_nyp'               => __( "Your contribution directly funds our work. If you're moved to do so, you can opt to pay more than the standard rate.", 'newspack-blocks' ),
				'checkout_nyp_thankyou'      => __( "Thank you for your generosity! We couldn't do this without you!", 'newspack-blocks' ),
				'checkout_nyp_title'         => __( 'Increase your support', 'newspack-blocks' ),
				'checkout_nyp_apply'         => __( 'Apply', 'newspack-blocks' ),
				'checkout_critical_error'    => __( 'We ran into a problem processing this request. Please try again.', 'newspack-blocks' ),
			];

			/**
			* Filters the global labels for modal checkout flow.
			*
			* @param mixed[] $labels Labels keyed by name.
			*/
			$filtered_labels = apply_filters( 'newspack_blocks_modal_checkout_labels', $default_labels );

			// Merge the default and filtered labels to ensure there are no missing labels.
			self::$modal_checkout_labels = array_merge( $default_labels, $filtered_labels );
		}

		if ( ! $key ) {
			return self::$modal_checkout_labels;
		}

		return self::$modal_checkout_labels[ $key ] ?? '';
	}

	/**
	 * Set the checkout registration flag to WC session.
	 */
	public static function set_checkout_registration_flag() {
		\WC()->session->set( self::CHECKOUT_REGISTRATION_FLAG, true );
	}

	/**
	 * Reset the checkout registration flag from WC session.
	 */
	public static function reset_checkout_registration_flag() {
		if ( self::is_checkout_registration() ) {
			\WC()->session->set( self::CHECKOUT_REGISTRATION_FLAG, null );
		}
	}

	/**
	 * Conditionally reset the checkout registration flag from WC session if user exists.
	 *
	 * @param array    $data  Checkout data.
	 * @param WP_Error $error Checkout errors.
	 */
	public static function maybe_reset_checkout_registration_flag( $data, $error ) {
		if ( ! self::is_checkout_registration() || ! isset( $data['billing_email'] ) ) {
			return;
		}

		if ( get_user_by( 'email', $data['billing_email'] ) ) {
			self::reset_checkout_registration_flag();
		}
	}

	/**
	 * Whether the WC session is for checkout registration.
	 */
	public static function is_checkout_registration() {
		return \WC()->session->get( self::CHECKOUT_REGISTRATION_FLAG, false );
	}

	/**
	 * Conditionally adds the checkout registration order meta flag.
	 *
	 * @param WC_Order $order    The order object.
	 *
	 * @return void.
	 */
	public static function maybe_add_checkout_registration_order_meta( $order ) {
		if ( ! self::is_modal_checkout() ) {
			return;
		}

		if ( self::is_checkout_registration() ) {
			$order->add_meta_data( self::CHECKOUT_REGISTRATION_ORDER_META_KEY, true, true );
		}
	}

	/**
	 * Trigger the subscriptions-limiting logic, using the user gleaned from the email address.
	 *
	 * @param bool           $is_limited_for_user If the subscription should be limited.
	 * @param int|WC_Product $product A WC_Product object or the ID of a product.
	 * @param int            $user_id The user ID.
	 */
	public static function subscriptions_product_limited_for_user( $is_limited_for_user, $product, $user_id ) {
		if ( $user_id !== 0 ) {
			return $is_limited_for_user;
		}
		$id_from_email = self::get_user_id_from_email();
		if ( $id_from_email ) {
			$is_limited_for_user = wcs_is_product_limited_for_user( $product, $id_from_email );
			$product_limitation  = wcs_get_product_limitation( $product->get_id() );
			$callback = 'active' === $product_limitation ? 'get_subscription_limited_message' : 'get_subscription_limited_message_any';
			if ( $is_limited_for_user ) {
				add_filter( 'woocommerce_cart_item_removed_message', [ __CLASS__, $callback ], 10, 2 );
			}
		}
		return $is_limited_for_user;
	}

	/**
	 * Whether modal checkout requires registration.
	 *
	 * @return bool
	 */
	public static function is_registration_required() {
		if ( ! class_exists( '\Newspack\Reader_Activation' ) ) {
			return false;
		}

		return \Newspack\Reader_Activation::is_woocommerce_registration_required();
	}

	/**
	 * Filters the WooCommerce registration privacy policy text.
	 *
	 * @param string $text Privacy policy text.
	 * @param string $type Privacy policy text type.
	 *
	 * @return string Privacy policy text.
	 */
	public static function woocommerce_get_privacy_policy_text( $text, $type ) {
		if ( ! self::is_modal_checkout() || ! class_exists( '\Newspack\Reader_Activation' ) ) {
			return $text;
		}

		return \Newspack\Reader_Activation::get_checkout_privacy_policy_text();
	}

	/**
	 * Get post checkout success message text.
	 *
	 * @return string Post checkout success message text.
	 */
	public static function get_post_checkout_success_text() {
		$text = sprintf(
			// Translators: %s is the site name.
			__( 'Thank you for supporting %s. Your transaction was completed successfully.', 'newspack-blocks' ),
			get_option( 'blogname' )
		);

		if ( class_exists( '\Newspack\Reader_Activation' ) && \Newspack\Reader_Activation::is_enabled() ) {
			if ( ! self::is_registration_required() && self::is_checkout_registration() ) {
				$text = \Newspack\Reader_Activation::get_post_checkout_registration_success_text();
			} else {
				$text = \Newspack\Reader_Activation::get_post_checkout_success_text();
			}
		}

		/**
		 * Filters the post checkout success message text.
		 *
		 * @param string $text The post checkout success message text.
		 */
		return apply_filters( 'newspack_modal_checkout_success_text', $text );
	}
}
Modal_Checkout::init();
