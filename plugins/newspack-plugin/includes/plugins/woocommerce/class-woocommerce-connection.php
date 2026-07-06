<?php
/**
 * Connection with WooCommerce's features.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Connection with WooCommerce's features.
 */
class WooCommerce_Connection {
	/**
	 * Statuses considered active.
	 */
	const ACTIVE_SUBSCRIPTION_STATUSES = [ 'active', 'pending-cancel' ];
	const ACTIVE_ORDER_STATUSES = [ 'processing', 'completed' ];


	/**
	 * These are the status a subscription can have for us to consider it from a former subscriber.
	 */
	const FORMER_SUBSCRIBER_STATUSES = [ 'on-hold', 'cancelled', 'expired' ];

	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		include_once __DIR__ . '/class-woocommerce-logs.php';
		include_once __DIR__ . '/class-woocommerce-cli.php';
		include_once __DIR__ . '/class-woocommerce-cover-fees.php';
		include_once __DIR__ . '/class-woocommerce-emails.php';
		include_once __DIR__ . '/class-woocommerce-order-utm.php';
		include_once __DIR__ . '/class-woocommerce-products.php';
		include_once __DIR__ . '/class-woocommerce-checkout.php';
		include_once __DIR__ . '/class-woocommerce-product-validator.php';
		include_once __DIR__ . '/class-woocommerce-duplicate-orders.php';
		include_once __DIR__ . '/class-woocommerce-update-payment-notice.php';
		include_once __DIR__ . '/class-woocommerce-custom-currency-symbol.php';

		\add_action( 'admin_init', [ __CLASS__, 'disable_woocommerce_setup' ] );
		// Newspack configures WooCommerce itself; suppress WC's first-run setup-wizard
		// redirect (runs before WC's admin_redirects at the default priority of 10).
		\add_action( 'admin_init', [ __CLASS__, 'prevent_setup_wizard_redirect' ], 1 );
		\add_filter( 'woocommerce_prevent_automatic_wizard_redirect', '__return_true' );
		\add_action( 'wp_loaded', [ __CLASS__, 'disable_legacy_form_checkout' ], 1 );
		\add_filter( 'option_woocommerce_subscriptions_allow_switching', [ __CLASS__, 'force_allow_subscription_switching' ], 10, 2 );
		\add_filter( 'option_woocommerce_subscriptions_allow_switching_nyp_price', [ __CLASS__, 'force_allow_subscription_switching' ], 10, 2 );
		\add_filter( 'option_woocommerce_subscriptions_enable_retry', [ __CLASS__, 'force_allow_failed_payment_retry' ] );
		\add_filter( 'default_option_woocommerce_subscriptions_allow_switching', [ __CLASS__, 'force_allow_subscription_switching' ], 10, 2 );
		\add_filter( 'default_option_woocommerce_subscriptions_allow_switching_nyp_price', [ __CLASS__, 'force_allow_subscription_switching' ], 10, 2 );
		\add_filter( 'default_option_woocommerce_subscriptions_enable_retry', [ __CLASS__, 'force_allow_failed_payment_retry' ] );
		\add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_update_reader_display_name' ], 10, 2 );
		\add_filter( 'woocommerce_related_products', [ __CLASS__, 'disable_related_products' ] );
		\add_action( 'cli_init', [ __CLASS__, 'register_cli_commands' ] );

		// Emails.
		\add_filter( 'woocommerce_order_status_completed_notification', [ __CLASS__, 'send_customizable_receipt_email' ] );
		\add_action( 'cancelled_subscription_notification', [ __CLASS__, 'send_customizable_cancellation_email' ] );

		// woocommerce-memberships-for-teams plugin.
		\add_filter( 'wc_memberships_for_teams_product_team_user_input_fields', [ __CLASS__, 'wc_memberships_for_teams_product_team_user_input_fields' ] );
		\add_filter( 'woocommerce_form_field_args', [ __CLASS__, 'wc_memberships_for_teams_filter_team_name_in_form' ], 10, 3 );

		\add_action( 'woocommerce_payment_complete', [ __CLASS__, 'order_paid' ], 101 );
		\add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'rate_limit_checkout' ], 10, 2 );
		\add_filter( 'woocommerce_add_payment_method_form_is_valid', [ __CLASS__, 'rate_limit_payment_methods' ] );

		// Always save Stripe payment method to existing subscriptions and set as default.
		\add_filter( 'wc_stripe_save_to_subs_checked', '__return_true' );
		\add_action( 'woocommerce_new_payment_token', [ __CLASS__, 'set_payment_method_as_default' ], 10, 2 );
		\add_action( 'wc_stripe_payment_fields_stripe', [ __CLASS__, 'add_payment_method_form_notice' ], 5 );

		\add_filter( 'page_template', [ __CLASS__, 'page_template' ] );
		\add_filter( 'get_post_metadata', [ __CLASS__, 'get_post_metadata' ], 10, 3 );
	}

	/**
	 * Check if the given status is considered active in Woo Subscriptions.
	 *
	 * @param string $status Subscription status.
	 *
	 * @return boolean
	 */
	public static function is_subscription_active( $status ) {
		$status = str_replace( 'wc-', '', $status ); // Normalize status strings.
		return in_array( $status, self::ACTIVE_SUBSCRIPTION_STATUSES, true );
	}

	/**
	 * Register CLI command
	 *
	 * @return void
	 */
	public static function register_cli_commands() {
		\WP_CLI::add_command( 'newspack-woocommerce', 'Newspack\\WooCommerce_Cli' );
	}

	/**
	 * Hide WooCommerce's setup task list. Newspack does the setup behind the scenes.
	 */
	public static function disable_woocommerce_setup() {
		if ( class_exists( '\Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists' ) ) {
			$task_list = \Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists::get_list( 'setup' );
			if ( $task_list ) {
				$task_list->hide();
			}
		}
	}

	/**
	 * Prevent WooCommerce from redirecting to its setup wizard after activation.
	 *
	 * WC sets the `_wc_activation_redirect` transient on activation and consumes it on
	 * the next admin page load to redirect to the onboarding/setup wizard. Newspack
	 * installs and configures WooCommerce as part of Audience Management setup, so this
	 * redirect would yank the admin out of the Newspack flow. Clearing the transient
	 * (the common trigger across WC versions) suppresses the redirect; the wizard is
	 * still reachable manually.
	 */
	public static function prevent_setup_wizard_redirect() {
		if ( \get_transient( '_wc_activation_redirect' ) ) {
			\delete_transient( '_wc_activation_redirect' );
		}
	}

	/**
	 * Remove support for the legacy form-based checkout.
	 * It's not necessary because all sites use modal or ajax checkout.
	 */
	public static function disable_legacy_form_checkout() {
		/**
		 * Re-enables the legacy WooCommerce form-based checkout process.
		 * By default, Newspack uses modal or AJAX checkout for better UX.
		 *
		 * @constant NEWSPACK_ALLOW_LEGACY_FORM_CHECKOUT
		 * @type     bool
		 * @default  Legacy form checkout disabled (uses modal/ajax checkout)
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_ALLOW_LEGACY_FORM_CHECKOUT', true );
		 */
		if ( defined( 'NEWSPACK_ALLOW_LEGACY_FORM_CHECKOUT' ) && NEWSPACK_ALLOW_LEGACY_FORM_CHECKOUT ) {
			return;
		}

		if ( class_exists( 'WC_Form_Handler' ) ) {
			\remove_action( 'wp_loaded', [ 'WC_Form_Handler', 'checkout_action' ], 20 );

			// Throw error if someone attempts a POST to the Checkout.
			if ( filter_input( INPUT_POST, 'woocommerce_checkout_place_order', FILTER_SANITIZE_SPECIAL_CHARS ) ) {
				http_response_code( 403 );
				exit();
			}
		}
	}

	/**
	 * Disable related products on product pages.
	 *
	 * @param array $related_products Related products.
	 *
	 * @return array
	 */
	public static function disable_related_products( $related_products ) {
		/**
		 * Re-enables WooCommerce related products display on product pages.
		 * Newspack disables these by default for a cleaner donation/membership experience.
		 *
		 * @constant NEWSPACK_ALLOW_RELATED_PRODUCTS
		 * @type     bool
		 * @default  Related products disabled on product pages
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_ALLOW_RELATED_PRODUCTS', true );
		 */
		if ( defined( 'NEWSPACK_ALLOW_RELATED_PRODUCTS' ) && NEWSPACK_ALLOW_RELATED_PRODUCTS ) {
			return $related_products;
		}
		return [];
	}

	/**
	 * Donations actions when order is processed.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function order_paid( $order_id ) {
		$product_id = Donations::get_order_donation_product_id( $order_id );

		/** Bail if not a donation order. */
		if ( false === $product_id ) {
			return;
		}

		/**
		 * Fires when a donation order is processed.
		 *
		 * @param int $order_id   Order post ID.
		 * @param int $product_id Donation product post ID.
		 */
		\do_action( 'newspack_donation_order_processed', $order_id, $product_id );
	}

	/**
	 * Get client IP.
	 *
	 * @return string|null Client IP.
	 */
	private static function get_client_ip() {
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ) as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				foreach ( explode( ',', $_SERVER[ $key ] ) as $ip ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below
					$ip = trim( $ip );

					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
						return $ip;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Check if rate limiting by user should be enabled for checkout and payment methods.
	 *
	 * @return bool
	 */
	public static function rate_limiting_enabled() {
		/**
		 * Number of seconds to wait before allowing the same user to attempt
		 * another checkout action. Set to 0 to disable rate limiting.
		 * Helps prevent duplicate orders and abuse.
		 *
		 * @constant NEWSPACK_CHECKOUT_RATE_LIMIT
		 * @type     int
		 * @default  Rate limiting disabled (0)
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_CHECKOUT_RATE_LIMIT', 90 );
		 */
		return defined( 'NEWSPACK_CHECKOUT_RATE_LIMIT' ) && is_int( NEWSPACK_CHECKOUT_RATE_LIMIT ) && 0 !== NEWSPACK_CHECKOUT_RATE_LIMIT && class_exists( 'WC_Rate_Limiter' );
	}

	/**
	 * Check the rate limit for the current user or IP.
	 * Currently locked behind a NEWSPACK_CHECKOUT_RATE_LIMIT environment constant, for controlled rollout.
	 *
	 * @param string $action_name   The action the user is trying to perform.
	 * @param string $error_message Error message to display or return if the user should be rate-limited.
	 * @param bool   $return_error  If true and the user should be rate-limited, return a WP_Error with the given message instead of a boolean value.
	 *
	 * @return bool|WP_Error True or WP_Error if the rate limit is exceeded, false otherwise.
	 */
	public static function rate_limit_by_user( $action_name, $error_message = '', $return_error = false ) {
		$rate_limited = false;
		if ( ! self::rate_limiting_enabled() ) {
			return $rate_limited;
		}
		if ( ! $error_message ) {
			$error_message = __( 'Please wait a moment before trying again.', 'newspack-plugin' );
		}
		$user_id    = get_current_user_id();
		$now        = time();
		$rate_limit = defined( 'NEWSPACK_CHECKOUT_RATE_LIMIT' ) ? (int) NEWSPACK_CHECKOUT_RATE_LIMIT : 90; // Number of seconds to wait before allowing the same user to attempt another checkout action. Default: 90.
		if ( 0 === $rate_limit ) {
			return $rate_limited; // If $rate_limit is 0 seconds, no need to proceed.
		}

		// If not logged in, use IP.
		if ( ! $user_id ) {
			$user_id = self::get_client_ip();
		}
		if ( ! $user_id ) {
			return $rate_limited;
		}
		$hashed_user_id = \wp_hash( $user_id, 'nonce' );
		$user_action    = "{$action_name}_{$hashed_user_id}";
		$rate_limited   = \WC_Rate_Limiter::retried_too_soon( $user_action );
		if ( $rate_limited ) {
			if ( $return_error ) {
				return new \WP_Error( 'newspack_rate_limit', $error_message );
			} else {
				self::add_wc_notice( $error_message, 'error' );
			}
		}

		\WC_Rate_Limiter::set_rate_limit( $user_action, $rate_limit );
		return $rate_limited;
	}

	/**
	 * Rate limit checkout attempts per user.
	 *
	 * @param  array    $posted_data An array of posted data.
	 * @param  WP_Error $errors Validation error.
	 */
	public static function rate_limit_checkout( $posted_data, $errors ) {
		// Don't rate limit if there are other checkout errors.
		if ( $errors->has_errors() ) {
			return;
		}
		self::rate_limit_by_user( 'checkout', __( 'Please wait a moment before trying to complete this transaction again.', 'newspack-plugin' ) );
	}

	/**
	 * Rate limit new payment methods per user.
	 *
	 * @param bool $is_valid Whether the form is valid.
	 *
	 * @return bool
	 */
	public static function rate_limit_payment_methods( $is_valid ) {
		if ( self::rate_limit_by_user( 'add_payment_method', __( 'Please wait a moment before trying to add a new payment method.', 'newspack-plugin' ) ) ) {
			return false;
		}

		return $is_valid;
	}

	/**
	 * Set newly added Stripe payment method as default and update all active
	 * subscriptions.
	 *
	 * @param int               $token_id Payment token ID.
	 * @param \WC_Payment_Token $token    Payment token object.
	 */
	public static function set_payment_method_as_default( $token_id, $token ) {
		// Bail if not in the payment methods page.
		if ( ! function_exists( 'is_payment_methods_page' ) || ! is_payment_methods_page() ) {
			return;
		}

		// Bail if not a Stripe token.
		if ( 'stripe' !== $token->get_gateway_id() ) {
			return;
		}

		// Set as default payment method for the customer.
		$token->set_default( true );
		$token->save();
		\WC_Payment_Tokens::set_users_default( $token->get_user_id(), $token_id );
	}

	/**
	 * Add a notice to the Stripe gateway when adding a new payment method.
	 */
	public static function add_payment_method_form_notice() {
		// Bail if not in the payment methods page.
		if ( ! function_exists( 'is_payment_methods_page' ) || ! is_payment_methods_page() ) {
			return;
		}

		// Check if user has active subscriptions.
		$has_active_subscriptions = false;
		if ( function_exists( 'wcs_get_users_subscriptions' ) ) {
			$subscriptions = wcs_get_users_subscriptions( get_current_user_id() );
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->has_status( self::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
					$has_active_subscriptions = true;
					break;
				}
			}
		}
		if ( ! $has_active_subscriptions ) {
			return;
		}
		?>
		<p class="newspack-new-payment-method-notice">
			<?php
			\esc_html_e( 'The card will be automatically saved to your active subscriptions and set as your default payment method.', 'newspack-plugin' );
			?>
		</p>
		<?php
	}

	/**
	 * Does the given user have any subscriptions with an active status?
	 * Can optionally pass an array of product IDs. If given, only subscriptions
	 * that have at least one of the given product IDs will be returned.
	 *
	 * If the subscription was a gift, it will return true only if the given user is the recipient.
	 *
	 * @param int   $user_id User ID.
	 * @param array $product_ids Optional array of product IDs to filter by.
	 *
	 * @return int[] Array of active subscription IDs.
	 */
	public static function get_active_subscriptions_for_user( $user_id, $product_ids = [] ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return [];
		}
		$subcriptions = array_reduce(
			array_keys( \wcs_get_users_subscriptions( $user_id ) ),
			function( $acc, $subscription_id ) use ( $product_ids, $user_id ) {
				$subscription = \wcs_get_subscription( $subscription_id );
				$has_required_products = false;
				if ( $subscription->has_status( self::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
					if ( ! empty( $product_ids ) ) {
						foreach ( $product_ids as $product_id ) {
							if ( $subscription->has_product( $product_id ) ) {
								$has_required_products = true;
								break;
							}
						}
					} else {
						$has_required_products = true;
					}
				}
				if ( $has_required_products ) {
					if ( ! class_exists( 'WCS_Gifting' ) || ! \WCS_Gifting::is_gifted_subscription( $subscription ) || (int) \WCS_Gifting::get_recipient_user( $subscription ) === (int) $user_id ) {
						$acc[] = $subscription_id;
					}
				}
				return $acc;
			},
			[]
		);

		return $subcriptions;
	}

	/**
	 * Force values for subscription switching options to ON unless the
	 * NEWSPACK_PREVENT_WC_SUBS_ALLOW_SWITCHING_OVERRIDE constant is set.
	 * This affects the following "Allow Switching" options:
	 *
	 * - Between Subscription Variations
	 * - Between Grouped Subscriptions
	 * - Change Name Your Price subscription amount
	 *
	 * @param bool   $can_switch Whether the subscription amount can be switched.
	 * @param string $option_name The name of the option.
	 *
	 * @return string Option value.
	 */
	public static function force_allow_subscription_switching( $can_switch, $option_name ) {
		/**
		 * Prevents Newspack from forcing subscription switching options to ON.
		 * By default, Newspack enables switching between variations, grouped
		 * subscriptions, and Name Your Price amounts.
		 *
		 * @constant NEWSPACK_PREVENT_WC_SUBS_ALLOW_SWITCHING_OVERRIDE
		 * @type     bool
		 * @default  Subscription switching forced ON
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_PREVENT_WC_SUBS_ALLOW_SWITCHING_OVERRIDE', true );
		 */
		if ( defined( 'NEWSPACK_PREVENT_WC_SUBS_ALLOW_SWITCHING_OVERRIDE' ) && NEWSPACK_PREVENT_WC_SUBS_ALLOW_SWITCHING_OVERRIDE ) {
			return $can_switch;
		}

		// Subscriptions' default switching options are combined into a single options row with possible values 'no', 'variable', 'grouped', or 'variable_grouped'.
		if ( 'woocommerce_subscriptions_allow_switching' === $option_name ) {
			return 'variable_grouped';
		}

		// Other options added by the woocommerce_subscriptions_allow_switching_options filter are either 'yes' or 'no'.
		return 'yes';
	}

	/**
	 * Force option for allowing retries for failed payments to ON unless the
	 * NEWSPACK_PREVENT_WC_ALLOW_FAILED_PAYMENT_RETRIES_OVERRIDE constant is set.
	 *
	 * See: https://woo.com/document/subscriptions/failed-payment-retry/
	 *
	 * @param bool $should_retry Whether WooCommerce should automatically retry failed payments.
	 *
	 * @return string Option value.
	 */
	public static function force_allow_failed_payment_retry( $should_retry ) {
		/**
		 * Prevents Newspack from forcing failed payment automatic retries to ON.
		 * By default, Newspack enables automatic retry of failed subscription payments.
		 *
		 * @constant NEWSPACK_PREVENT_WC_ALLOW_FAILED_PAYMENT_RETRIES_OVERRIDE
		 * @type     bool
		 * @default  Failed payment retries forced ON
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_PREVENT_WC_ALLOW_FAILED_PAYMENT_RETRIES_OVERRIDE', true );
		 */
		if ( defined( 'NEWSPACK_PREVENT_WC_ALLOW_FAILED_PAYMENT_RETRIES_OVERRIDE' ) && NEWSPACK_PREVENT_WC_ALLOW_FAILED_PAYMENT_RETRIES_OVERRIDE ) {
			return $should_retry;
		}

		return 'yes';
	}

	/**
	 * Send the customizable receipt or welcome email instead of WooCommerce's default receipt.
	 *
	 * @param int $order_id The order ID.
	 *
	 * @return bool True if the email was sent.
	 */
	public static function send_customizable_receipt_email( $order_id ) {
		$order = \wc_get_order( $order_id );

		if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		// If there are no donation products in the order, do not override the default WC receipt email.
		$has_donation_product = \Newspack\Donations::get_order_donation_product_id( $order->get_id() ) !== false;
		if ( ! $has_donation_product ) {
			return false;
		}

		$email_type      = Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'];
		$email_sent_meta = '_newspack_receipt_email_sent';

		// If this is a new registration, and the welcome email is enabled, send the welcome email instead.
		if ( $order->get_meta( '_newspack_checkout_registration_meta' ) && Emails::can_send_email( Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'] ) ) {
			$email_type      = Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'];
			$email_sent_meta = '_newspack_welcome_email_sent';
		}

		// If the customizable email isn't enabled bail.
		if ( ! Emails::can_send_email( $email_type ) ) {
			return false;
		}

		if ( $order->get_meta( $email_sent_meta, true ) ) {
			return false;
		}

		$frequencies = [
			'month' => __( 'Monthly', 'newspack-plugin' ),
			'year'  => __( 'Yearly', 'newspack-plugin' ),
		];
		$product_map = [];
		foreach ( $frequencies as $frequency => $label ) {
			$product_id = Donations::get_donation_product( $frequency );
			if ( $product_id ) {
				$product_map[ $product_id ] = $label;
			}
		}

		$items = $order->get_items();
		$item  = array_shift( $items );

		// Replace content placeholders.
		$placeholders = [
			[
				'template' => '*BILLING_NAME*',
				'value'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			],
			[
				'template' => '*BILLING_FIRST_NAME*',
				'value'    => $order->get_billing_first_name(),
			],
			[
				'template' => '*BILLING_LAST_NAME*',
				'value'    => $order->get_billing_last_name(),
			],
			[
				'template' => '*BILLING_FREQUENCY*',
				'value'    => $product_map[ $item->get_product_id() ] ?? __( 'One-time', 'newspack-plugin' ),
			],
			[
				'template' => '*PRODUCT_NAME*',
				'value'    => $item->get_name(),
			],
			[
				'template' => '*AMOUNT*',
				'value'    => \wp_strip_all_tags( $order->get_formatted_order_total() ),
			],
			[
				'template' => '*DATE*',
				'value'    => $order->get_date_created()->date_i18n(),
			],
			[
				'template' => '*PAYMENT_METHOD*',
				'value'    => __( 'Card', 'newspack-plugin' ) . ' – ' . $order->get_payment_method(),
			],
			[
				'template' => '*RECEIPT_URL*',
				'value'    => sprintf( '<a href="%s">%s</a>', $order->get_view_order_url(), __( 'My Account', 'newspack-plugin' ) ),
			],
			[
				'template' => '*ACCOUNT_URL*',
				'value'    => function_exists( '\wc_get_account_endpoint_url' ) ? \wc_get_account_endpoint_url( 'dashboard' ) : get_bloginfo( 'wpurl' ),
			],
		];

		$sent = Emails::send_email(
			$email_type,
			$order->get_billing_email(),
			$placeholders
		);
		if ( $sent ) {
			$order->add_meta_data( $email_sent_meta, true, true );
			return false;
		}
		return true;
	}

	/**
	 * Send the customizable cancellation email in addition to WooCommerce Subscription's default.
	 * We still want to allow WCS to send its cancellation email since this targets the store admin.
	 *
	 * @param WC_Subscription $subscription  The order object for the cancellation email.
	 *
	 * @return bool True if the email was sent.
	 */
	public static function send_customizable_cancellation_email( $subscription ) {
		// If we don't have a valid subscription, or the customizable email isn't enabled, bail.
		if (
			! is_a( $subscription, 'WC_Subscription' )
			|| ! Emails::can_send_email( Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'] )
			|| ! $subscription->has_status( [ 'pending-cancel', 'cancelled' ] )
		) {
			return false;
		}

		$email_sent_meta = '_newspack_subscription_cancelled_email_sent';
		if ( $subscription->get_meta( $email_sent_meta, true ) ) {
			return false;
		}

		$frequencies = [
			'month' => __( 'Monthly', 'newspack-plugin' ),
			'year'  => __( 'Yearly', 'newspack-plugin' ),
		];
		$product_map = [];
		foreach ( $frequencies as $frequency => $label ) {
			$product_id = Donations::get_donation_product( $frequency );
			if ( $product_id ) {
				$product_map[ $product_id ] = $label;
			}
		}

		$items = $subscription->get_items();

		if ( ! empty( $items ) ) {
			$item         = array_shift( $items );
			$is_donation  = Donations::is_donation_product( $item->get_product_id() );
			// Replace content placeholders.
			$placeholders = [
				[
					'template' => '*BILLING_NAME*',
					'value'    => trim( $subscription->get_billing_first_name() . ' ' . $subscription->get_billing_last_name() ),
				],
				[
					'template' => '*BILLING_FIRST_NAME*',
					'value'    => $subscription->get_billing_first_name(),
				],
				[
					'template' => '*BILLING_LAST_NAME*',
					'value'    => $subscription->get_billing_last_name(),
				],
				[
					'template' => '*BILLING_FREQUENCY*',
					'value'    => $product_map[ $item->get_product_id() ] ?? __( 'One-time', 'newspack-plugin' ),
				],
				[
					'template' => '*PRODUCT_NAME*',
					'value'    => $item->get_name(),
				],
				[
					'template' => '*END_DATE*',
					'value'    => wcs_format_datetime( wcs_get_datetime_from( $subscription->get_date( 'end' ) ) ),
				],
				[
					'template' => '*BUTTON_TEXT*',
					'value'    => $is_donation ? __( 'Restart Donation', 'newspack-plugin' ) : __( 'Restart Subscription', 'newspack-plugin' ),
				],
				[
					'template' => '*CANCELLATION_DATE*',
					'value'    => wcs_format_datetime( wcs_get_datetime_from( $subscription->get_date( 'cancelled' ) ) ),
				],
				[
					'template' => '*CANCELLATION_TITLE*',
					'value'    => $is_donation ? __( 'Donation Cancelled', 'newspack-plugin' ) : __( 'Subscription Cancelled', 'newspack-plugin' ),
				],
				[
					'template' => '*CANCELLATION_TYPE*',
					'value'    => $is_donation ? __( 'recurring donation', 'newspack-plugin' ) : __( 'subscription', 'newspack-plugin' ),
				],
				[
					'template' => '*SUBSCRIPTION_URL*',
					'value'    => $subscription->get_view_order_url(),
				],
			];

			$sent = Emails::send_email(
				Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'],
				$subscription->get_billing_email(),
				$placeholders
			);
			if ( $sent ) {
				$subscription->add_meta_data( $email_sent_meta, true, true );
				return false;
			}
			return true;
		}
	}

	/**
	 * If the reader completes an order, check if they have a generic display name.
	 * If they do and they also have a billing first and/or last name, we can upgrade
	 * the display name to match their provided billing name.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order Completed order.
	 */
	public static function maybe_update_reader_display_name( $order_id, $order ) {
		$customer_id = $order->get_customer_id();
		if ( ! Reader_Activation::reader_has_generic_display_name( $customer_id ) ) {
			return;
		}

		// If they have a generated display name, construct it from billing name.
		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();
		if ( ! empty( $first_name ) || ! empty( $last_name ) ) {
			$display_name = trim( "$first_name $last_name" );
			\wp_update_user(
				[
					'ID'           => $customer_id,
					'display_name' => $display_name,
				]
			);
		}
	}

	/**
	 * Get an array of product IDs associated with the given order ID.
	 *
	 * @param int     $order_id Order ID.
	 * @param boolean $include_donations If true, include donation products, otherwise omit them.
	 * @return array Array of product IDs associated with this order.
	 */
	public static function get_products_for_order( $order_id, $include_donations = false ) {
		$product_ids = [];
		if ( ! function_exists( 'wc_get_order' ) ) {
			return $product_ids;
		}

		$order       = \wc_get_order( $order_id );
		$order_items = $order->get_items();

		foreach ( $order_items as $item ) {
			$product_id = $item->get_product_id();
			if ( $include_donations || ! Donations::is_donation_product( $product_id ) ) {
				$product_ids[] = $product_id;
			}
		}

		return $product_ids;
	}

	/**
	 * Add a WC notice.
	 *
	 * @param string $message Message to display.
	 * @param string $type Type of notice.
	 */
	public static function add_wc_notice( $message, $type ) {
		if ( ! function_exists( '\wc_add_notice' ) || ! function_exists( 'WC' ) ) {
			return;
		}
		if ( ! WC()->session ) {
			return;
		}
		\wc_add_notice( $message, $type );
	}

	/**
	 * Should override the template for the given page?
	 */
	private static function should_override_template() {
		/**
		 * Disables Newspack's WooCommerce template overrides for checkout,
		 * cart, and my-account pages. Set to true to use default WC templates.
		 *
		 * @constant NEWSPACK_DISABLE_WC_TEMPLATE_OVERRIDE
		 * @type     bool
		 * @default  WooCommerce template overrides enabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_DISABLE_WC_TEMPLATE_OVERRIDE', true );
		 */
		if ( defined( 'NEWSPACK_DISABLE_WC_TEMPLATE_OVERRIDE' ) && NEWSPACK_DISABLE_WC_TEMPLATE_OVERRIDE ) {
			return false;
		}
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( wp_is_block_theme() ) {
			return false;
		}
		return is_checkout() || is_cart() || is_account_page();
	}

	/**
	 * Override page templates for WC pages.
	 *
	 * @param string $template Template path.
	 */
	public static function page_template( $template ) {
		if ( self::should_override_template() ) {
			return get_theme_file_path( '/single-wide.php' );
		}
		return $template;
	}

	/**
	 * Override post meta for WC pages.
	 *
	 * @param mixed  $value    Meta value to return.
	 * @param int    $id       Post ID.
	 * @param string $meta_key Meta key.
	 */
	public static function get_post_metadata( $value, $id, $meta_key ) {
		if (
			'_wp_page_template' === $meta_key &&
			self::should_override_template() &&
			'page' === get_post_type( $id )
		) {
			return 'single-wide';
		}
		return $value;
	}

	/**
	 * Fix woocommerce-memberships-for-teams when on /order-pay page. This page is available
	 * from edit order screen -> "Customer payment page" link when the order is pending payment.
	 * It allows the customer to pay for the order.
	 * If woocommerce-memberships-for-teams is used, a cart validation error prevents the customer from
	 * paying for the order because the team name is not set. This filter sets the team name from the order item.
	 *
	 * @param array $fields associative array of user input fields.
	 */
	public static function wc_memberships_for_teams_product_team_user_input_fields( $fields ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $fields['team_name'] ) || ! empty( $_REQUEST['team_name'] ) ) {
			return $fields;
		}
		global $wp;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['resubscribe'] ) && 'my-account' === $wp->query_vars['pagename'] ) {
			$order_id_to_fix = sanitize_text_field( $_GET['resubscribe'] );
		} elseif ( isset( $wp->query_vars['order-pay'] ) ) {
			$order_id_to_fix = sanitize_text_field( $wp->query_vars['order-pay'] );
		} elseif ( isset( $_REQUEST['subscription_renewal_early'] ) ) {
			$order_id_to_fix = sanitize_text_field( $_REQUEST['subscription_renewal_early'] );
		} else {
			return $fields;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$team_name = self::get_membership_team_name_from_order_id( $order_id_to_fix );
		if ( ! empty( $team_name ) ) {
			$_REQUEST['team_name'] = $team_name;
		}

		return $fields;
	}

	/**
	 * Get the membership team name associated with an order ID (if any).
	 *
	 * Attempts to find the team name by checking the order items for team
	 * membership information. If no team is found in the order items, it falls
	 * back to retrieving the team name from the user associated with the order.
	 *
	 * @param int $order_id The ID of the order to retrieve the team name from.
	 *
	 * @return string The membership team name if found, or an empty string if
	 *                  not found or if required functions are not available.
	 */
	public static function get_membership_team_name_from_order_id( $order_id ): string {
		if ( empty( $order_id ) || ! function_exists( '\wc_get_order' ) || ! function_exists( '\wc_memberships_for_teams_get_team_for_order_item' ) ) {
			return '';
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		foreach ( $order->get_items() as $item ) {
			try {
				$team = \wc_memberships_for_teams_get_team_for_order_item( $item );
				if ( $team ) {
					return $team->get_name();
				}
			} catch ( \Exception $e ) {
				Logger::log( 'Exception thrown when trying to get team name from order: ' . $e->getMessage() );
			}
		}

		$user = $order->get_user();
		if ( $user ) {
			return self::get_membership_team_name_from_user( $user->ID );
		}

		return '';
	}

	/**
	 * Retrieves the membership team name for a given user.
	 *
	 * This function attempts to find an appropriate team name for a user based on their
	 * billing information or display name. It first checks for a billing company name,
	 * then falls back to billing first and last name, and finally uses the user's display name.
	 *
	 * @param int $user_id The ID of the user for whom to retrieve the team name.
	 *
	 * @return string The determined team name. Returns an empty string if the user is not found.
	 */
	public static function get_membership_team_name_from_user( $user_id ): string {
		$team_user = get_user_by( 'ID', $user_id );
		if ( ! $team_user ) {
			return '';
		}

		$company = get_user_meta( $user_id, 'billing_company', true );
		if ( ! empty( $company ) ) {
			return $company;
		}

		$billing_first_name = get_user_meta( $user_id, 'billing_first_name', true );
		$billing_last_name  = get_user_meta( $user_id, 'billing_last_name', true );
		if ( ! empty( $billing_first_name ) || ! empty( $billing_last_name ) ) {
			$team_name = trim( $billing_first_name . ' ' . $billing_last_name );
		} else {
			$team_name = $team_user->display_name;
		}

		// Translators: %s is the user's billing first and last name – or company name.
		return sprintf( __( "%s's Team", 'newspack-plugin' ), $team_name );
	}


	/**
	 * Filter callback for the team name in WooCommerce forms.
	 *
	 * This is only relevant for the "team_name" field. It will try to figure out if we need to
	 * fix the team name – and if we do, then get it from the order if it's available.
	 *
	 * @param array  $args  The original arguments for the form field.
	 * @param string $key   The key of the form field.
	 * @param mixed  $value The value of the form field.
	 *
	 * @return array The arguments for the form field.
	 */
	public static function wc_memberships_for_teams_filter_team_name_in_form( $args, $key, $value ) {
		if ( 'team_name' !== $key || ! empty( $args['default'] ) || ! empty( $value ) || is_admin() ) {
			return $args;
		}
		global $wp;
		// Try to figure out if we need to fix the team name – and if we do, then grab the order ID
		// from the relevant param.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$order_id_to_fix = sanitize_text_field( $wp->query_vars['order-pay'] );
		} elseif ( ! empty( $_GET['switch-subscription'] ) && $wp->query_vars['product'] ) {
			$order_id_to_fix = sanitize_text_field( $_GET['switch-subscription'] );
		} else {
			return $args;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$team_name = self::get_membership_team_name_from_order_id( $order_id_to_fix );
		if ( ! empty( $team_name ) ) {
			$args['default'] = $team_name;
		}

		return $args;
	}
}

WooCommerce_Connection::init();
