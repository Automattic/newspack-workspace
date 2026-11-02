<?php
/**
 * Newspack "My Account" customizations v1.x.x.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Content_Gate;
use Newspack\Group_Subscription;
use Newspack\Memberships;
use Newspack\Reader_Activation;
use Newspack\Reader_Data;
use Newspack\WooCommerce_Connection;
use Newspack\WooCommerce_My_Account;
use Newspack\Newspack_UI;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack "My Account" customizations v1.x.x.
 */
class My_Account_UI_V1 {
	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		\add_filter( 'page_template', [ __CLASS__, 'page_template' ], 11 );
		\add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 11 );
		\add_filter( 'wc_get_template', [ __CLASS__, 'wc_get_template' ], 1, 5 );
		\add_filter( 'wc_get_template', [ __CLASS__, 'wc_get_notice_template' ], 11, 2 );
		\add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'my_account_menu_items' ], 1001 );
		\add_filter( 'newspack_myaccount_required_fields', [ __CLASS__, 'account_settings_required_fields' ] );
		\add_action( 'newspack_woocommerce_after_edit_account_form', [ __CLASS__, 'delete_account_modal' ] );
		\add_action( 'newspack_after_delete_account', [ __CLASS__, 'handle_after_delete_account' ] );
		\add_action( 'wp_footer', [ __CLASS__, 'add_after_delete_account_notice' ] );
		\add_action( 'woocommerce_subscription_details_table', [ __CLASS__, 'cancel_subscription_modal' ] );
		\add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		\add_filter( 'option_woocommerce_myaccount_add_payment_method_endpoint', [ __CLASS__, 'add_payment_method_endpoint' ] );
		\add_filter( 'default_option_woocommerce_myaccount_add_payment_method_endpoint', [ __CLASS__, 'add_payment_method_endpoint' ] );
		\add_action( 'template_redirect', [ __CLASS__, 'redirect_payment_information_endpoint' ] );
		\add_action( 'template_redirect', [ __CLASS__, 'redirect_empty_orders_endpoint' ] );
		\add_action( 'template_redirect', [ __CLASS__, 'redirect_empty_payment_methods_endpoint' ] );
		\add_action( 'newspack_woocommerce_after_account_payment_methods', [ __CLASS__, 'add_payment_method_modal' ] );
		\add_action( 'newspack_woocommerce_after_account_payment_methods', [ __CLASS__, 'delete_payment_method_modals' ] );
		\add_action( 'newspack_woocommerce_after_account_addresses', [ __CLASS__, 'add_address_modals' ] );
		\add_action( 'newspack_woocommerce_after_account_addresses', [ __CLASS__, 'delete_address_modals' ] );
		\add_action( 'woocommerce_after_save_address_validation', [ __CLASS__, 'handle_delete_address_submission' ], 10, 4 );
		\add_filter( 'woocommerce_address_to_edit', [ __CLASS__, 'reorder_address_fields' ], PHP_INT_MAX, 2 );
		\add_action( 'woocommerce_account_content', [ __CLASS__, 'render_content_around_shortcode' ], 0 );
		\add_action( 'newspack_subscription_header', [ __CLASS__, 'render_subscription_header' ] );
		\add_filter( 'woocommerce_my_subscriptions_payment_method', [ __CLASS__, 'formatted_payment_method' ], 10, 2 );
		\add_action( 'woocommerce_account_content', [ __CLASS__, 'render_page_title' ], 1 );
		\add_action( 'woocommerce_account_content', [ __CLASS__, 'print_urgent_notices' ], 2 );
		\add_action( 'woocommerce_my_account_my_orders_column_order-status', [ __CLASS__, 'render_order_status_column' ] );
		\add_action( 'woocommerce_my_account_my_orders_column_order-number', [ __CLASS__, 'render_order_number_column' ] );
		\add_filter( 'woocommerce_account_orders_columns', [ __CLASS__, 'orders_columns' ] );
	}

	/**
	 * Render My Account pages with a no-header/no-footer page template.
	 *
	 * @param string $template The template.
	 * @return string The template file path.
	 */
	public static function page_template( $template ) {
		// Only in My Account.
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			return $template;
		}

		// Only if the user is logged in.
		if ( ! \is_user_logged_in() ) {
			return $template;
		}

		return __DIR__ . '/templates/v1/my-account.php';
	}

	/**
	 * Add a body class to the My Account page.
	 *
	 * @param array $classes The body classes.
	 * @return array The body classes.
	 */
	public static function add_body_class( $classes ) {
		// Only in My Account.
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			return $classes;
		}

		if ( is_user_logged_in() ) {
			$classes[] = 'newspack-ui';
		}
		$classes[] = 'newspack-my-account';
		$classes[] = 'newspack-my-account--v1';
		if ( ! \is_user_logged_in() ) {
			$classes[] = 'newspack-my-account--logged-out';
		} else {
			$classes[] = 'newspack-my-account--logged-in';
		}
		return $classes;
	}

	/**
	 * Enqueue assets.
	 */
	public static function enqueue_assets() {
		if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return;
		}
		$script_data = [
			'myAccountUrl' => wc_get_account_endpoint_url( 'dashboard' ),
			'labels'       => [
				'resubscribe_title'           => __( 'Renew subscription', 'newspack-plugin' ),
				'renewal_early_title'         => __( 'Renew subscription early', 'newspack-plugin' ),
				'change_payment_method_title' => __( 'Change payment method', 'newspack-plugin' ),
				'switch_subscription_title'   => __( 'Change subscription', 'newspack-plugin' ),
				'invite_link_copied'          => __( 'Invite link copied.', 'newspack-plugin' ),
				'invite_link_regenerated'     => __( 'New invite link copied. Any older link no longer works.', 'newspack-plugin' ),
				'invite_link_copy_failed'     => __( 'Couldn\'t copy the invite link to your clipboard. Copy it manually:', 'newspack-plugin' ),
				'invite_link_disabled'        => __( 'Invite link disabled. You can create a new link any time.', 'newspack-plugin' ),
				'group_name_updated'          => __( 'Name updated.', 'newspack-plugin' ),
			],
			'rest'         => [
				'base_url'   => get_rest_url(),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'namespaces' => [
					'group' => Group_Subscription_API::NAMESPACE,
				],
			],
		];

		// Only in My Account.
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			\wp_enqueue_script(
				'newspack-account-frontend',
				\Newspack\Newspack::plugin_url() . '/dist/account-frontend.js',
				[],
				\Newspack\Newspack::asset_version( 'account-frontend' ),
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
			\wp_localize_script(
				'newspack-account-frontend',
				'newspackMyAccountV1',
				$script_data
			);
		} else {
			\wp_enqueue_script(
				'newspack-my-account-v1',
				\Newspack\Newspack::plugin_url() . '/dist/my-account-v1.js',
				[ 'newspack-ui' ],
				\Newspack\Newspack::asset_version( 'my-account-v1' ),
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
			\wp_localize_script(
				'newspack-my-account-v1',
				'newspackMyAccountV1',
				$script_data
			);

			// Dequeue styles from the Newspack theme first, for a fresh start.
			\wp_dequeue_style( 'newspack-woocommerce-style' );
			\wp_enqueue_style(
				'newspack-my-account-v1',
				\Newspack\Newspack::plugin_url() . '/dist/my-account-v1.css',
				[],
				\Newspack\Newspack::asset_version( 'my-account-v1' )
			);
		}
	}

	/**
	 * Whether to override WooCommerce notice templates with the v1 versions: inline
	 * error and info notices for signed-in readers, snackbars for success notices
	 * and for the signed-out account forms, which sit outside newspack-ui.
	 *
	 * Only applies on My Account pages after the main query has run.
	 *
	 * @return bool
	 */
	private static function should_override_notice_template() {
		return function_exists( 'is_account_page' )
			&& function_exists( 'did_action' )
			&& \did_action( 'wp' )
			&& \is_account_page();
	}

	/**
	 * Queue WooCommerce notices as snackbars, which print in their own newspack-ui
	 * container. Errors and urgent notices stay until dismissed.
	 *
	 * @param array[] $notices WooCommerce notices, each with `notice` and `data` keys.
	 * @param string  $type    Notice type: success, info, warning or error.
	 */
	public static function add_snackbar_notices( array $notices, string $type ): void {
		foreach ( $notices as $notice ) {
			$is_urgent = \apply_filters( 'newspack_ui_notice_is_urgent', false, $notice['notice'] );
			Newspack_UI::add_notice(
				$notice['notice'],
				[
					'id'             => uniqid( 'newspack-myaccount-' . $type . '-' ),
					'type'           => 'info' === $type && $is_urgent ? 'warning' : $type,
					'autohide'       => 'error' !== $type && ! $is_urgent,
					'active_on_load' => true,
				]
			);
		}
	}

	/**
	 * Take the urgent notices out of WooCommerce's queue and print them inline, so
	 * they sit under the page title on classic and block themes alike. The
	 * subscription page titles itself in its header, so they follow that header.
	 */
	public static function print_urgent_notices(): void {
		if ( ! function_exists( 'wc_get_notices' ) || ! function_exists( 'wc_set_notices' ) ) {
			return;
		}
		$urgent    = [];
		$remaining = [];
		foreach ( \wc_get_notices() as $type => $notices ) {
			foreach ( (array) $notices as $notice ) {
				$message = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : (string) $notice;
				if ( \apply_filters( 'newspack_ui_notice_is_urgent', false, $message ) ) {
					$urgent[] = is_array( $notice ) ? $notice : [
						'notice' => $message,
						'data'   => [],
					];
				} else {
					$remaining[ $type ][] = $notice;
				}
			}
		}
		if ( empty( $urgent ) ) {
			return;
		}
		\wc_set_notices( $remaining );
		if ( \get_query_var( 'view-subscription' ) ) {
			\add_action(
				'newspack_woocommerce_after_subscription_header',
				function() use ( $urgent ) {
					self::render_notice_template( $urgent );
				}
			);
			return;
		}
		self::render_notice_template( $urgent );
	}

	/**
	 * Render notices through the v1 notice template, which prints urgent notices
	 * inline. Included directly, since block themes swap WooCommerce's own notice
	 * templates for theirs.
	 *
	 * @param array[] $notices WooCommerce notices, each with `notice` and `data` keys.
	 */
	private static function render_notice_template( array $notices ): void {
		include __DIR__ . '/templates/v1/notices/notice.php';
	}

	/**
	 * WC's page templates hijacking.
	 *
	 * @param string $template      Template path.
	 * @param string $template_name Template name.
	 */
	public static function wc_get_template( $template, $template_name ) {
		switch ( $template_name ) {
			case 'myaccount/navigation.php':
				return __DIR__ . '/templates/v1/navigation.php';
			case 'myaccount/form-edit-account.php':
				return __DIR__ . '/templates/v1/account-settings.php';
			case 'myaccount/payment-methods.php':
				return __DIR__ . '/templates/v1/payment-information.php';
			case 'myaccount/form-edit-address.php':
				return __DIR__ . '/templates/v1/form-edit-address.php';
			case 'myaccount/my-subscriptions.php':
				return __DIR__ . '/templates/v1/my-subscriptions.php';
			case 'myaccount/view-order.php':
				return __DIR__ . '/templates/v1/view-order.php';
			case 'myaccount/view-subscription.php':
				return __DIR__ . '/templates/v1/view-subscription.php';
			case 'myaccount/subscription-details.php':
				return __DIR__ . '/templates/v1/subscription-details.php';
			case 'myaccount/subscription-totals-table.php':
				return __DIR__ . '/templates/v1/subscription-totals-table.php';
			case 'myaccount/related-orders.php':
				return __DIR__ . '/templates/v1/related-orders.php';
			case 'myaccount/related-subscriptions.php':
				return __DIR__ . '/templates/v1/related-subscriptions.php';
			case 'myaccount/group-picker.php':
				return __DIR__ . '/templates/v1/group-picker.php';
			case 'myaccount/group.php':
				return __DIR__ . '/templates/v1/group.php';
			case 'myaccount/group-subscription-members.php':
				return __DIR__ . '/templates/v1/group-subscription-members.php';
			case 'order/order-again.php':
				return __DIR__ . '/templates/v1/order-again.php';
			case 'order/order-details-customer.php':
				if ( ! function_exists( 'is_wc_endpoint_url' ) || ! \is_wc_endpoint_url( 'view-order' ) ) {
					return $template;
				}
				return __DIR__ . '/templates/v1/order-details-customer.php';
			default:
				return $template;
		}
	}

	/**
	 * Use the v1 notice templates on My Account pages. Hooked after WooCommerce
	 * Blocks swaps in its own notice templates on block themes, so both kinds of
	 * theme print the same notices.
	 *
	 * @param string $template      Template path.
	 * @param string $template_name Template name.
	 * @return string Template path.
	 */
	public static function wc_get_notice_template( $template, $template_name ) {
		if ( ! in_array( $template_name, [ 'notices/error.php', 'notices/notice.php', 'notices/success.php' ], true ) || ! self::should_override_notice_template() ) {
			return $template;
		}
		return __DIR__ . '/templates/v1/' . $template_name;
	}

	/**
	 * Add query var for the "Payment Information" page.
	 *
	 * @param array $vars Query var.
	 *
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'add-payment-method';
		return $vars;
	}

	/**
	 * Modify nav menu items.
	 *
	 * @param array $items Menu items.
	 * @return array Modified menu items.
	 */
	public static function my_account_menu_items( $items ) {
		// Remove logout menu item (to be replaced in our custom template).
		unset( $items['customer-logout'] );

		// Rename "Payment Methods" to "Payment information".
		if ( isset( $items['payment-methods'] ) ) {
			$items['payment-methods'] = __( 'Payment information', 'newspack-plugin' );
		}

		// Rename singular "My Subscription" to "Subscription" while keeping plural "Subscriptions" as-is.
		// Compare against WooCommerce Subscriptions' translated string so this also works in non-English sites.
		if ( isset( $items['subscriptions'] ) && __( 'My Subscription', 'woocommerce-subscriptions' ) === $items['subscriptions'] ) {
			$items['subscriptions'] = __( 'Subscription', 'newspack-plugin' );
		}

		// Remove "Addresses" (replaced by custom "Payment information" page).
		unset( $items['edit-address'] );

		// Hide "Orders" for anyone with no orders — even admins/non-readers shouldn't see a tab
		// that only renders the "No order has been made yet" empty state.
		if ( isset( $items['orders'] ) && ! self::current_user_has_orders() ) {
			unset( $items['orders'] );
		}

		// Hide "Payment information" for group members who have no orders of their own —
		// they're accessing content via someone else's subscription.
		if ( isset( $items['payment-methods'] ) && self::should_suppress_payment_information() ) {
			unset( $items['payment-methods'] );
		}

		// Sidebar entry for native group management. Visibility gated by the Access Control
		// feature flag, manager-of-at-least-one-group, AND the existing `Memberships::is_active()`
		// suppression already used by group-subscription UI.
		// Inserted immediately after Subscriptions so the two related entries stay adjacent.
		if ( Content_Gate::is_newspack_feature_enabled() && ! Memberships::is_active() ) {
			$managed = Group_Subscription::get_managed_subscriptions_for_user( \get_current_user_id() );
			$count   = count( $managed );
			if ( $count > 0 ) {
				$label_variant = $count > 1 ? 'plural' : 'singular';
				$group_entry   = [ 'group' => Group_Subscription::get_label( $label_variant ) ];
				if ( isset( $items['subscriptions'] ) ) {
					$position = array_search( 'subscriptions', array_keys( $items ), true );
					$items    = array_merge(
						array_slice( $items, 0, $position + 1, true ),
						$group_entry,
						array_slice( $items, $position + 1, null, true )
					);
				} else {
					$items = array_merge( $items, $group_entry );
				}
			}
		}

		return $items;
	}

	/**
	 * Check if the current page is a subscription page.
	 *
	 * @param string $endpoint The current My Account page endpoint.
	 * @return bool Whether the current page is a subscription page.
	 */
	public static function is_subscription_page( $endpoint ) {
		if ( 'subscriptions' !== $endpoint ) {
			return false;
		}
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-subscription' );
	}

	/**
	 * Remove required fields from the account settings form.
	 *
	 * @param array $required_fields The required fields.
	 * @return array The required fields.
	 */
	public static function account_settings_required_fields( $required_fields ) {
		unset( $required_fields['account_display_name'] );
		return $required_fields;
	}

	/**
	 * Display a series of modals to request account deletion.
	 */
	public static function delete_account_modal() {
		$user = \wp_get_current_user();

		// Only if the user is logged in and a reader.
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( $user ) ) {
			return;
		}

		// If the user has clicked the button from the delete account email, show the confirmation modal.
		$delete_account_form = filter_input( INPUT_GET, WooCommerce_My_Account::DELETE_ACCOUNT_FORM, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! empty( $delete_account_form ) ) {
			return self::delete_account_confirmation_modal();
		}

		$active_subscriptions = Reader_Data::get_data( $user->ID, 'active_subscriptions' );
		if ( is_string( $active_subscriptions ) ) {
			$active_subscriptions = json_decode( $active_subscriptions );
		}
		$active_donations         = Reader_Data::get_bool( $user->ID, 'is_donor' );
		$newsletter_subscriptions = Reader_Data::get_data( $user->ID, 'newsletter_subscribed_lists' );
		if ( is_string( $newsletter_subscriptions ) ) {
			$newsletter_subscriptions = json_decode( $newsletter_subscriptions );
		}

		$has_alternatives = ! empty( $active_subscriptions ) || ! empty( $newsletter_subscriptions ) || $active_donations;

		ob_start();
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					// Translators: %s will be displayed only if the user has active subscriptions or newsletter subscriptions.
					__( 'Deleting your account is permanent and cannot be undone. All your data will be removed from our systems. %s', 'newspack-plugin' ),
					$has_alternatives ? __( 'Your newsletter subscriptions and all recurring payments will be cancelled.', 'newspack-plugin' ) : ''
				)
			);
			?>
		</p>
		<?php
		$alternative_rows = [];
		if ( ! empty( $active_subscriptions ) || $active_donations ) {
			$alternative_rows[] = [
				'title'       => __( 'Subscriptions', 'newspack-plugin' ),
				'description' => __( 'Review and cancel active subscriptions.', 'newspack-plugin' ),
				'button'      => __( 'Manage subscriptions', 'newspack-plugin' ),
				'href'        => My_Account::get_endpoint_url( 'subscriptions' ),
			];
		}
		if ( ! empty( $newsletter_subscriptions ) ) {
			$alternative_rows[] = [
				'title'       => __( 'Newsletters', 'newspack-plugin' ),
				'description' => __( 'Update your newsletter preferences.', 'newspack-plugin' ),
				'button'      => __( 'Manage newsletters', 'newspack-plugin' ),
				'href'        => My_Account::get_endpoint_url( 'newsletters' ),
			];
		}
		if ( ! empty( $alternative_rows ) ) :
			?>
		<p class="newspack-ui__color-text-gray"><?php esc_html_e( 'Instead of deleting your account, you may want to:', 'newspack-plugin' ); ?></p>
		<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2 newspack-ui__spacing-top--2">
			<hr>
			<?php foreach ( $alternative_rows as $row ) : ?>
				<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-0">
					<p class="newspack-ui__font--medium"><?php echo esc_html( $row['title'] ); ?></p>
					<div class="newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--vertical-below-mobile newspack-ui__stack--justify-between newspack-ui__stack--align-baseline newspack-ui__stack--gap-0">
						<p class="newspack-ui__helper-text newspack-ui__spacing-top--0 newspack-ui__spacing-right--3"><?php echo esc_html( $row['description'] ); ?></p>
						<a class="newspack-ui__link" href="<?php echo esc_url( $row['href'] ); ?>"><?php echo esc_html( $row['button'] ); ?></a>
					</div>
				</div>
				<hr>
			<?php endforeach; ?>
		</div>
			<?php
		endif;
		$content_send_email = ob_get_clean();

		ob_start();
		?>
		<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--align-center newspack-ui__stack--gap-half">
			<span class="newspack-ui__icon newspack-ui__icon--large newspack-ui__icon--success">
				<?php Newspack_UI_Icons::print_svg( 'published' ); ?>
			</span>
			<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1">
				<h2 class="newspack-ui__font--m"><?php esc_html_e( 'Your account deletion has been requested.', 'newspack-plugin' ); ?></h2>
				<p class="newspack-ui__color-text-gray"><?php esc_html_e( 'We just sent instructions on how to delete your account to', 'newspack-plugin' ); ?> <strong><?php echo esc_html( $user->user_email ); ?></strong>.</p>
			</div>
		</div>
		<?php
		$content_email_sent = ob_get_clean();

		Newspack_UI::generate_modal(
			[
				'id'    => 'delete-account',
				'steps' => [
					'request'    => [
						'title'   => __( 'Delete account', 'newspack-plugin' ),
						'content' => $content_send_email,
						'size'    => $has_alternatives ? 'medium' : 'small',
						'actions' => [
							'cancel'  => [
								'label'  => __( 'Cancel', 'newspack-plugin' ),
								'type'   => 'outline',
								'action' => 'close',
							],
							'confirm' => [
								'label' => __( 'Delete account', 'newspack-plugin' ),
								'type'  => [ 'destructive', 'outline' ],
								'fetch' => [
									'url'    => \rest_url( 'newspack/v1/delete-account' ),
									'method' => 'POST',
									'next'   => 'email-sent',
									'nonce'  => \wp_create_nonce( 'wp_rest' ),
									'body'   => [
										'user_id' => $user->ID,
									],
								],
							],
						],
					],
					'email-sent' => [
						'hide_header' => true,
						'content'     => $content_email_sent,
						'size'        => 'small',
						'actions'     => [
							'continue' => [
								'label'  => __( 'Continue', 'newspack-plugin' ),
								'type'   => 'primary',
								'action' => 'close',
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Display a confirmation modal to confirm account deletion.
	 */
	public static function delete_account_confirmation_modal() {
		$delete_account_form = WooCommerce_My_Account::DELETE_ACCOUNT_FORM;
		$nonce_value         = filter_input( INPUT_GET, $delete_account_form, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$token               = filter_input( INPUT_GET, 'token', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$transient_token     = \get_transient( 'np_reader_account_delete_' . \get_current_user_id() );
		if ( ! \wp_verify_nonce( $nonce_value, $delete_account_form ) ) {
			WooCommerce_Connection::add_wc_notice( __( 'Invalid nonce.', 'newspack-plugin' ), 'error' );
			return;
		}
		if ( ! $token || ! $transient_token || ! hash_equals( (string) $transient_token, (string) $token ) ) {
			WooCommerce_Connection::add_wc_notice( __( 'Invalid token.', 'newspack-plugin' ), 'error' );
			return;
		}
		ob_start();
		?>
		<p>
			<?php esc_html_e( 'Confirm to delete your account permanently.', 'newspack-plugin' ); ?>&nbsp;
			<strong><?php esc_html_e( 'Caution, this action is irreversible!', 'newspack-plugin' ); ?></strong>
		</p>
		<input type="hidden" name="<?php echo \esc_attr( $delete_account_form ); ?>" value="<?php echo \esc_attr( $nonce_value ); ?>">
		<input type="hidden" name="token" value="<?php echo \esc_attr( $token ); ?>">
		<input type="hidden" name="confirm_delete" value="1" />
		<?php
		$content = ob_get_clean();

		// Modal to confirm that the email was sent.
		Newspack_UI::generate_modal(
			[
				'id'      => 'delete-account',
				'title'   => __( 'Delete account', 'newspack-plugin' ),
				'content' => $content,
				'size'    => 'small',
				'form'    => 'POST',
				'state'   => 'open',
				'actions' => [
					'continue' => [
						'label' => __( 'Delete account', 'newspack-plugin' ),
						'type'  => 'destructive',
					],
					'cancel'   => [
						'label'  => __( 'Cancel', 'newspack-plugin' ),
						'type'   => 'outline',
						'action' => 'close',
					],
				],
			]
		);
	}

	/**
	 * Handle after delete account.
	 */
	public static function handle_after_delete_account() {
		\wp_safe_redirect(
			\add_query_arg(
				WooCommerce_My_Account::AFTER_ACCOUNT_DELETION_PARAM,
				1,
				\home_url()
			)
		);
		exit;
	}

	/**
	 * Show a notice after the account is deleted.
	 */
	public static function add_after_delete_account_notice() {
		$account_deleted = filter_input( INPUT_GET, WooCommerce_My_Account::AFTER_ACCOUNT_DELETION_PARAM, FILTER_VALIDATE_BOOLEAN );
		if ( $account_deleted ) {
			Newspack_UI::add_notice(
				__( 'Your account has been deleted.', 'newspack-plugin' ),
				[
					'id'       => 'after-delete-account',
					'autohide' => true,
				]
			);
		}
	}

	/**
	 * Confirmation modal to cancel a subscription.
	 * Rendered when viewing a single subscription page.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 */
	public static function cancel_subscription_modal( $subscription ) {
		// Only if the user is logged in and a reader.
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}

		if ( ! $subscription || ! is_a( $subscription, 'WC_Subscription' ) ) {
			return;
		}
		$next_payment = $subscription->get_time( 'next_payment' );
		if (
			// See: wcs_get_all_user_actions_for_subscription().
			$subscription->can_be_updated_to( 'cancelled' ) && ! $subscription->is_one_payment() &&
			(
				( $subscription->has_status( 'on-hold' ) && empty( $next_payment ) ) ||
				$next_payment > 0
			)
		) {
			$next_payment_date = $next_payment ? $subscription->get_date_to_display( 'next_payment' ) : null;
			ob_start();
			?>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
					// Translators: %s is either the next payment date, or a generic explanation of when the subscription will end if cancelled.
						__( 'If you cancel now, your subscription will remain active until %s.', 'newspack-plugin' ),
						"<strong>$next_payment_date</strong>" ?? __( 'the end of your current billing period', 'newspack-plugin' )
					)
				);
				?>
			</p>
			<p class="newspack-ui__color-text-gray">
				<?php esc_html_e( 'After this, your subscription access will end unless you choose to renew.', 'newspack-plugin' ); ?>
			</p>
			<?php
			$content = ob_get_clean();
			Newspack_UI::generate_modal(
				[
					'id'      => 'confirm-subscription-cancellation',
					'title'   => __( 'Cancel subscription', 'newspack-plugin' ),
					'content' => $content,
					'actions' => [
						'confirm' => [
							'label' => __( 'Cancel subscription', 'newspack-plugin' ),
							'type'  => 'destructive',
							'url'   => \wcs_get_users_change_status_link( $subscription->get_id(), 'cancelled' ),
						],
						'cancel'  => [
							'label'  => __( 'Keep subscription', 'newspack-plugin' ),
							'type'   => 'outline',
							'action' => 'close',
						],
					],
				]
			);
		}
	}

	/**
	 * Set "Add Payment Method" endpoint to "payment-methods".
	 * The add-payment-method form is now rendered via a modal.
	 *
	 * @return string
	 */
	public static function add_payment_method_endpoint() {
		return \get_option( 'woocommerce_myaccount_payment_methods_endpoint', 'payment-methods' );
	}

	/**
	 * Redirect "Addresses" to the "Payment Information" page.
	 */
	public static function redirect_payment_information_endpoint() {
		if ( function_exists( 'is_account_page' ) && \is_account_page() ) {
			global $wp;
			$current_url = \trailingslashit( \home_url( $wp->request ) );
			if ( \trailingslashit( \wc_get_account_endpoint_url( 'edit-address' ) ) === $current_url ) {
				\wp_safe_redirect( \wc_get_account_endpoint_url( 'payment-methods' ) );
				exit;
			}
		}
	}

	/**
	 * Redirect the Orders endpoint to the My Account dashboard for any user with no orders.
	 * Pairs with hiding the menu item in `my_account_menu_items()` so bookmarks / typed URLs
	 * don't dead-end on the empty state.
	 */
	public static function redirect_empty_orders_endpoint() {
		self::maybe_redirect_dead_end_endpoint( 'orders' );
	}

	/**
	 * Redirect the Payment Methods endpoint to the dashboard for group members with no orders.
	 */
	public static function redirect_empty_payment_methods_endpoint() {
		self::maybe_redirect_dead_end_endpoint( 'payment-methods' );
	}

	/**
	 * Shared redirect helper: bounce a dead-end My Account endpoint to the dashboard so a
	 * bookmark / typed URL matches the hidden nav item. The condition mirrors the per-endpoint
	 * nav-hiding in `my_account_menu_items()`: Orders is hidden for any user with no orders,
	 * Payment Information for a group member without orders or saved billing data.
	 *
	 * @param string $endpoint WooCommerce account endpoint slug.
	 */
	private static function maybe_redirect_dead_end_endpoint( $endpoint ) {
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			return;
		}
		global $wp;
		$current_url = \trailingslashit( \home_url( $wp->request ) );
		if ( \trailingslashit( \wc_get_account_endpoint_url( $endpoint ) ) !== $current_url ) {
			return;
		}
		$should_redirect = 'orders' === $endpoint
			? ! self::current_user_has_orders()
			: self::should_suppress_payment_information();
		if ( ! $should_redirect ) {
			return;
		}
		\wp_safe_redirect( \wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

	/**
	 * Whether to hide payment information for the current reader.
	 *
	 * @return bool True when the reader is a group member without their own orders or billing data.
	 */
	private static function should_suppress_payment_information() {
		if ( Memberships::is_active() ) {
			return false;
		}
		$user_id = \get_current_user_id();
		if ( ! $user_id || ! class_exists( __NAMESPACE__ . '\\Group_Subscription' ) ) {
			return false;
		}
		$group_subscription_ids = Group_Subscription::get_group_subscriptions_for_user( $user_id, true );
		if ( empty( $group_subscription_ids ) ) {
			return false;
		}
		if ( self::current_user_has_orders() ) {
			return false;
		}
		return ! self::current_user_has_saved_billing_data();
	}

	/**
	 * Whether the current reader has saved billing data (payment methods or billing/shipping address).
	 *
	 * @return bool
	 */
	private static function current_user_has_saved_billing_data() {
		static $cache = [];
		$user_id = \get_current_user_id();
		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}
		if ( ! $user_id ) {
			$cache[ $user_id ] = false;
			return $cache[ $user_id ];
		}
		if ( function_exists( 'wc_get_customer_saved_methods_list' ) && ! empty( \wc_get_customer_saved_methods_list( $user_id ) ) ) {
			$cache[ $user_id ] = true;
			return $cache[ $user_id ];
		}
		foreach ( [ 'billing_address_1', 'shipping_address_1' ] as $meta_key ) {
			if ( \get_user_meta( $user_id, $meta_key, true ) ) {
				$cache[ $user_id ] = true;
				return $cache[ $user_id ];
			}
		}
		$cache[ $user_id ] = false;
		return $cache[ $user_id ];
	}

	/**
	 * Whether the current user has any orders. Per-request memoized.
	 *
	 * @return bool
	 */
	private static function current_user_has_orders() {
		static $cache = [];
		$user_id = \get_current_user_id();
		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}
		if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
			$cache[ $user_id ] = false;
			return $cache[ $user_id ];
		}
		$cache[ $user_id ] = (bool) \wc_get_orders(
			[
				'customer_id' => $user_id,
				'limit'       => 1,
				'return'      => 'ids',
				'status'      => 'any',
			]
		);
		return $cache[ $user_id ];
	}

	/**
	 * Render confirmation modals for deleting saved payment methods.
	 *
	 * @param bool $has_methods Whether there are saved payment methods to render.
	 */
	public static function delete_payment_method_modals( $has_methods ) {
		if ( ! $has_methods ) {
			return;
		}

		$saved_methods = \wc_get_customer_saved_methods_list( \get_current_user_id() );

		if ( empty( $saved_methods ) || ! is_array( $saved_methods ) ) {
			return;
		}

		foreach ( $saved_methods as $methods ) {
			foreach ( $methods as $method ) {
				$delete_url = $method['actions']['delete']['url'] ?? '';

				if ( empty( $delete_url ) ) {
					continue;
				}

				$modal_suffix = \sanitize_html_class( md5( $delete_url ) );
				$brand        = ! empty( $method['method']['brand'] ) ? \wc_get_credit_card_type_label( $method['method']['brand'] ) : '';
				$last4        = $method['method']['last4'] ?? '';
				$expires      = $method['expires'] ?? '';

				// Parse the URL to extract query parameters.
				$parsed_url   = \wp_parse_url( $delete_url );
				$query_params = [];

				if ( ! empty( $parsed_url['query'] ) ) {
					\parse_str( $parsed_url['query'], $query_params );
				}

				// Reconstruct the base URL without query parameters.
				$form_action_base = \remove_query_arg( array_keys( $query_params ), $delete_url );

				ob_start();
				?>
				<p><?php \esc_html_e( 'Are you sure you want to delete this payment method from your account?', 'newspack-plugin' ); ?></p>
				<div class="payment-method-preview newspack-ui__font--s newspack-ui__font--bold">
					<?php
					$lines = [];

					if ( $brand ) {
						$lines[] = $brand;
					}

					if ( $last4 ) {
						$lines[] = sprintf(
							/* translators: last 4 digits. */
							__( 'Ending in %s', 'newspack-plugin' ),
							$last4
						);
					}

					if ( $expires ) {
						$lines[] = sprintf(
							/* translators: expiration date. */
							__( 'Exp. %s', 'newspack-plugin' ),
							$expires
						);
					}

					if ( ! empty( $lines ) ) {
						echo implode( '<br>', array_map( 'esc_html', $lines ) );
					}
					?>
				</div>
				<?php
				// Add query parameters as hidden form fields.
				foreach ( $query_params as $param_name => $param_value ) {
					printf(
						'<input type="hidden" name="%1$s" value="%2$s">',
						\esc_attr( $param_name ),
						\esc_attr( $param_value )
					);
				}

				$content = ob_get_clean();

				Newspack_UI::generate_modal(
					[
						'id'          => 'delete-payment-method-' . $modal_suffix,
						'title'       => __( 'Delete payment method', 'newspack-plugin' ),
						'content'     => $content,
						'size'        => 'small',
						'form'        => 'GET',
						'form_action' => $form_action_base,
						'form_id'     => 'delete_payment_method_' . $modal_suffix,
						'actions'     => [
							'delete' => [
								'label' => __( 'Delete payment method', 'newspack-plugin' ),
								'type'  => 'destructive',
							],
							'cancel' => [
								'label'  => __( 'Cancel', 'newspack-plugin' ),
								'type'   => 'outline',
								'action' => 'close',
							],
						],
					]
				);
			}
		}
	}

	/**
	 * Render the "Add Payment Method" modal.
	 */
	public static function add_payment_method_modal() {
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}
		// Set the query var so payment gateways can detect the add-payment-method context.
		global $wp;
		$wp->query_vars['add-payment-method'] = true;
		ob_start();
		\woocommerce_account_add_payment_method();
		$content = ob_get_clean();
		Newspack_UI::generate_modal(
			[
				'id'              => 'add-payment-method',
				'title'           => __( 'Add payment method', 'newspack-plugin' ),
				'content'         => $content,
				'content_is_safe' => true, // Allow the contents of `woocommerce_account_add_payment_method` to be rendered as is.
				'size'            => 'small',
				'form'            => 'POST',
				'form_class'      => 'newspack-ui__accordion newspack-ui__accordion--open',
				'form_id'         => 'add_payment_method',
			]
		);
	}

	/**
	 * Render the "Add Address" modal.
	 */
	public static function add_address_modals() {
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}
		$address_types = [ 'billing' => __( 'Billing', 'newspack-plugin' ) ];
		if ( ! \wc_ship_to_billing_address_only() && \wc_shipping_enabled() ) {
			$address_types['shipping'] = __( 'Shipping', 'newspack-plugin' );
		}
		$address_types = \apply_filters( 'woocommerce_my_account_get_addresses', $address_types );
		foreach ( $address_types as $address_type => $address_name ) {
			$address = \wc_get_account_formatted_address( $address_type );

			ob_start();
			\woocommerce_account_edit_address( $address_type );
			$content = ob_get_clean();

			$edit_address_url = \add_query_arg(
				'edit-address',
				$address_type,
				\wc_get_endpoint_url( 'edit-address', $address_type )
			);

			Newspack_UI::generate_modal(
				[
					'id'              => 'edit-address-' . $address_type,
					'title'           => ! empty( $address ) ? sprintf(
						// Translators: %s is the address type.
						__( 'Edit %s address', 'newspack-plugin' ),
						$address_type
					) : sprintf(
						// Translators: %s is the address type.
						__( 'Add %s address', 'newspack-plugin' ),
						$address_type
					),
					'content'         => $content,
					'content_is_safe' => true, // Allow the contents of `woocommerce_account_edit_address` to be rendered as is.
					'size'            => 'medium',
					'form'            => 'POST',
					'form_id'         => 'edit_address_' . $address_type,
					'form_action'     => $edit_address_url,
				]
			);
		}
	}

	/**
	 * Render the "Delete Address" confirmation modals.
	 */
	public static function delete_address_modals() {
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}

		$address_types = [ 'billing' => __( 'Billing', 'newspack-plugin' ) ];
		if ( ! \wc_ship_to_billing_address_only() && \wc_shipping_enabled() ) {
			$address_types['shipping'] = __( 'Shipping', 'newspack-plugin' );
		}
		$address_types = \apply_filters( 'woocommerce_my_account_get_addresses', $address_types );

		foreach ( $address_types as $address_type => $address_name ) {
			$address = \wc_get_account_formatted_address( $address_type );

			// Only create delete modal if address exists.
			if ( ! empty( $address ) ) {
				ob_start();
				?>
				<p>
					<?php esc_html_e( 'Are you sure you want to delete this address from your account?', 'newspack-plugin' ); ?>
				</p>
				<div class="address-preview newspack-ui__font--bold">
					<?php echo wp_kses_post( $address ); ?>
				</div>

				<?php
				// Create hidden form fields to clear the address.
					$fields = [
						$address_type . '_first_name' => '',
						$address_type . '_last_name'  => '',
						$address_type . '_company'    => '',
						$address_type . '_address_1'  => '',
						$address_type . '_address_2'  => '',
						$address_type . '_city'       => '',
						$address_type . '_postcode'   => '',
						$address_type . '_country'    => '',
						$address_type . '_state'      => '',
						'save_address'                => 'Save address',
						'action'                      => 'edit_address',
						'newspack_delete_address'     => $address_type,
					];

					// Add email and phone for billing addresses.
					if ( 'billing' === $address_type ) {
						$fields['billing_email'] = '';
						$fields['billing_phone'] = '';
					}

					foreach ( $fields as $field_name => $field_value ) {
						echo '<input type="hidden" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $field_value ) . '">';
					}
					$request_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?? '';
					?>
					<input type="hidden" id="woocommerce-delete-address-nonce-<?php echo esc_attr( $address_type ); ?>" name="woocommerce-edit-address-nonce" value="<?php echo esc_attr( \wp_create_nonce( 'woocommerce-edit_address' ) ); ?>">
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( \wp_unslash( $request_uri ) ); ?>">
					<?php
					$content            = ob_get_clean();
					$delete_address_url = \add_query_arg(
						'edit-address',
						$address_type,
						\wc_get_endpoint_url( 'edit-address', $address_type )
					);

					Newspack_UI::generate_modal(
						[
							'id'          => 'delete-address-' . $address_type,
							'title'       => sprintf(
								// Translators: %s is the address type.
								__( 'Delete %s address', 'newspack-plugin' ),
								$address_type
							),
							'content'     => $content,
							'size'        => 'small',
							'form'        => 'POST',
							'form_action' => $delete_address_url,
							'form_id'     => 'delete_address_' . $address_type,
							'actions'     => [
								'delete' => [
									'label' => __( 'Delete address', 'newspack-plugin' ),
									'type'  => 'destructive',
								],
								'cancel' => [
									'label'  => __( 'Cancel', 'newspack-plugin' ),
									'type'   => 'outline',
									'action' => 'close',
								],
							],
						]
					);
			}
		}
	}

	/**
	 * Handle delete address submission.
	 *
	 * @param int    $user_id      User ID being saved.
	 * @param string $address_type Type of address; 'billing' or 'shipping'.
	 */
	public static function handle_delete_address_submission( $user_id, $address_type ) {
		$delete_request = filter_input( INPUT_POST, 'newspack_delete_address', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( empty( $delete_request ) || $delete_request !== $address_type ) {
			return;
		}

		$notices = \wc_get_notices();

		if ( empty( $notices ) || empty( $notices['error'] ) ) {
			return;
		}

		unset( $notices['error'] );
		\wc_set_notices( $notices );
	}

	/**
	 * Reorder address fields.
	 *
	 * @param array  $address The address.
	 * @param string $load_address The address type (billing or shipping).
	 * @return array The address.
	 */
	public static function reorder_address_fields( $address, $load_address ) {
		// Move state before postcode.
		if ( isset( $address[ $load_address . '_state' ] ) ) {
			$address[ $load_address . '_state' ]['priority'] = 80;
		}

		if ( isset( $address[ $load_address . '_postcode' ] ) ) {
			$address[ $load_address . '_postcode' ]['priority'] = 90;
		}

		// Move email before phone by setting its priority to phone's priority minus 1.
		if ( isset( $address[ $load_address . '_phone' ] ) && isset( $address[ $load_address . '_email' ] ) ) {
			$address[ $load_address . '_email' ]['priority'] = $address[ $load_address . '_phone' ]['priority'] - 1;
		}

		return $address;
	}

	/**
	 * Render the content around the [woocommerce_my_account] shortcode inside the
	 * woocommerce-MyAccount-content context.
	 *
	 * This is necessary because of the highly customized grid layout.
	 */
	public static function render_content_around_shortcode() {
		// Only allow custom content under the dashboard (root) or edit-account (default redirect) pages.
		global $wp;
		if ( ! isset( $wp->query_vars['page'] ) && ! empty( $wp->query_vars ) && ! isset( $wp->query_vars['edit-account'] ) ) {
			return;
		}

		$content = get_the_content();
		if ( empty( $content ) ) {
			return;
		}
		$parts = explode( '[woocommerce_my_account]', $content );

		$before = ! empty( $parts[0] ) ? $parts[0] : '';
		if ( ! empty( $before ) ) {
			add_action(
				'woocommerce_account_content',
				function() use ( $before ) {
					echo apply_filters( 'the_content', $before ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				9 // Right before the shortcode.
			);
		}

		$after = ! empty( $parts[1] ) ? $parts[1] : '';
		if ( ! empty( $after ) ) {
			add_action(
				'woocommerce_account_content',
				function() use ( $after ) {
					echo apply_filters( 'the_content', $after ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				11 // Right after the shortcode.
			);
		}
	}

	/**
	 * Render subscription actions as a dropdown menu.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public static function render_subscription_header( $subscription ) {
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			return;
		}
		$args = [
			'actions'      => \wcs_get_all_user_actions_for_subscription( $subscription, \get_current_user_id() ),
			'subscription' => $subscription,
		];
		\load_template( __DIR__ . '/templates/v1/subscription-header.php', true, $args );
	}

	/**
	 * Format the payment method for the subscription details table.
	 *
	 * @param string           $payment_method The payment method.
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return string The formatted payment method.
	 */
	public static function formatted_payment_method( $payment_method, $subscription ) {
		// Strip "via" from the payment method (case-insensitive).
		$payment_method = str_ireplace( 'via ', '', $payment_method );
		return $payment_method;
	}

	/**
	 * The current page's title, and whether it repeats the current navigation item.
	 *
	 * @return array{title: string, matches_nav: bool}|null The title and whether it matches the navigation, or null when the page renders its own.
	 */
	public static function get_page_title(): ?array {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_account_menu_items' ) ) {
			return null;
		}
		$endpoint = \WC()->query->get_current_endpoint();
		// The subscription and group pages title themselves, with the product or group name.
		if ( in_array( $endpoint, [ 'view-subscription', Group_Subscription_MyAccount::GROUP_ENDPOINT ], true ) ) {
			return null;
		}
		$menu_items = \wc_get_account_menu_items();
		if ( $endpoint && isset( $menu_items[ $endpoint ] ) ) {
			return [
				'title'       => $menu_items[ $endpoint ],
				'matches_nav' => true,
			];
		}
		// Payment methods and addresses share this page, which the menu calls "Payment information".
		if ( 'payment-methods' === $endpoint ) {
			return [
				'title'       => __( 'Payment information', 'newspack-plugin' ),
				'matches_nav' => false,
			];
		}
		if ( $endpoint ) {
			$title = \WC()->query->get_endpoint_title( $endpoint );
			return $title ? [
				'title'       => $title,
				'matches_nav' => false,
			] : null;
		}
		foreach ( $menu_items as $menu_endpoint => $label ) {
			if ( \wc_is_current_account_menu_item( $menu_endpoint ) ) {
				return [
					'title'       => $label,
					'matches_nav' => true,
				];
			}
		}
		return null;
	}

	/**
	 * Render the page title at the top of the account content.
	 */
	public static function render_page_title(): void {
		if ( self::render_order_header() ) {
			return;
		}
		$page_title = self::get_page_title();
		if ( empty( $page_title ) ) {
			return;
		}
		$classes = [ 'newspack-my-account__page-title', 'newspack-ui__font--l' ];
		if ( $page_title['matches_nav'] ) {
			$classes[] = 'newspack-my-account__page-title--matches-nav';
		}
		printf( '<h1 class="%s">%s</h1>', esc_attr( implode( ' ', $classes ) ), wp_kses_post( $page_title['title'] ) );
	}

	/**
	 * Render the order page header: the title, the order's status and its actions.
	 *
	 * @return bool Whether the header was rendered.
	 */
	private static function render_order_header(): bool {
		if ( 'view-order' !== \WC()->query->get_current_endpoint() ) {
			return false;
		}
		$order_id = \absint( \get_query_var( 'view-order' ) );
		$order    = $order_id ? \wc_get_order( $order_id ) : false;
		if ( ! $order || ! \current_user_can( 'view_order', $order_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's meta capability, checked the same way by the view-order endpoint.
			return false;
		}
		\remove_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' );
		\remove_action( 'woocommerce_order_details_after_order_table', 'WC_Subscriptions_Order::add_subscriptions_to_view_order_templates' );
		\load_template(
			__DIR__ . '/templates/v1/order-header.php',
			false,
			[
				'order'   => $order,
				'actions' => array_diff_key( \wc_get_account_orders_actions( $order ), [ 'view' => true ] ),
			]
		);
		return true;
	}

	/**
	 * The status modifier for an order or subscription status: success for settled
	 * states, warning for failed and pending-payment ones, error for cancelled and
	 * expired ones, neutral for the rest.
	 *
	 * @param string $status Order or subscription status, without the `wc-` prefix.
	 * @return string The `newspack-ui__status` modifier, or an empty string for neutral.
	 */
	public static function get_status_modifier( string $status ): string {
		$status = str_replace( 'wc-', '', $status );
		if ( in_array( $status, [ 'active', 'completed' ], true ) ) {
			return 'success';
		}
		if ( in_array( $status, [ 'failed', 'pending' ], true ) ) {
			return 'warning';
		}
		if ( in_array( $status, [ 'cancelled', 'expired' ], true ) ) {
			return 'error';
		}
		return '';
	}

	/**
	 * The icon that marks an order or subscription status: a tick for settled
	 * states, a crossed circle for ended ones, the warning notice's triangle for
	 * a failed payment, and a clock or part-filled circle for states still in progress.
	 *
	 * @param string $status Order or subscription status, without the `wc-` prefix.
	 * @return string A `Newspack_UI_Icons` icon name.
	 */
	public static function get_status_icon( string $status ): string {
		$status = str_replace( 'wc-', '', $status );
		$icons  = [
			'active'         => 'published',
			'completed'      => 'published',
			'cancelled'      => 'notAllowed',
			'expired'        => 'notAllowed',
			'failed'         => Newspack_UI::get_notice_icons()['warning'],
			'pending-cancel' => 'scheduled',
		];
		return $icons[ $status ] ?? 'pending';
	}

	/**
	 * Render a status as an icon and its label. The icon is decorative; the label carries the status.
	 *
	 * @param string $status Order or subscription status.
	 * @param string $label  Label to show.
	 */
	public static function render_status( string $status, string $label ): void {
		self::print_status( self::get_status_modifier( $status ), self::get_status_icon( $status ), $label );
	}

	/**
	 * Print a status: its decorative icon, then its label.
	 *
	 * @param string $modifier The `newspack-ui__status` modifier, or an empty string for neutral.
	 * @param string $icon     A `Newspack_UI_Icons` icon name.
	 * @param string $label    Label to show.
	 */
	private static function print_status( string $modifier, string $icon, string $label ): void {
		?>
		<span class="newspack-ui__status<?php echo $modifier ? ' newspack-ui__status--' . esc_attr( $modifier ) : ''; ?>"><?php Newspack_UI_Icons::print_svg( $icon ); ?><?php echo esc_html( $label ); ?></span>
		<?php
	}

	/**
	 * Whether a subscription is on hold because a renewal payment failed: its latest
	 * renewal order is failed or pending payment, and the reader can pay it, so the
	 * label agrees with the Pay action that order's page offers.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return bool
	 */
	public static function subscription_payment_failed( $subscription ): bool {
		if ( ! $subscription->has_status( 'on-hold' ) ) {
			return false;
		}
		$last_renewal = $subscription->get_last_order( 'all', [ 'renewal' ] );
		return is_a( $last_renewal, 'WC_Order' )
			&& $last_renewal->has_status( [ 'failed', 'pending' ] )
			&& self::order_can_be_paid( $last_renewal );
	}

	/**
	 * The status modifier for a subscription: on hold reads as a warning, since
	 * the reader has lost access until it is resolved.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return string The `newspack-ui__status` modifier, or an empty string for neutral.
	 */
	public static function get_subscription_status_modifier( $subscription ): string {
		return $subscription->has_status( 'on-hold' ) ? 'warning' : self::get_status_modifier( $subscription->get_status() );
	}

	/**
	 * The status label for a subscription, naming a failed renewal payment as such.
	 *
	 * @param \WC_Subscription $subscription   The subscription.
	 * @param bool|null        $payment_failed Whether its renewal payment failed, when the caller already knows.
	 * @return string
	 */
	public static function get_subscription_status_label( $subscription, ?bool $payment_failed = null ): string {
		if ( $payment_failed ?? self::subscription_payment_failed( $subscription ) ) {
			return __( 'Payment failed', 'newspack-plugin' );
		}
		return \wcs_get_subscription_status_name( $subscription->get_status() );
	}

	/**
	 * Render a subscription's status as an icon and its label.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 */
	public static function render_subscription_status( $subscription ): void {
		$payment_failed = self::subscription_payment_failed( $subscription );
		$icon           = self::get_status_icon( $payment_failed ? 'failed' : $subscription->get_status() );
		self::print_status( self::get_subscription_status_modifier( $subscription ), $icon, self::get_subscription_status_label( $subscription, $payment_failed ) );
	}

	/**
	 * The status label for an order: a completed order reads as paid.
	 *
	 * @param \WC_Order $order The order.
	 * @return string
	 */
	public static function get_order_status_label( $order ): string {
		$status = $order->get_status();
		return 'completed' === $status ? __( 'Paid', 'newspack-plugin' ) : \wc_get_order_status_name( $status );
	}

	/**
	 * How the order header names a subscription, led by "your": a donation by how
	 * often it renews, anything else by its product.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return string
	 */
	public static function get_subscription_reference( $subscription ): string {
		if ( WooCommerce_Update_Payment_Notice::is_donation_subscription( $subscription ) ) {
			$period = 1 === (int) $subscription->get_billing_interval() ? $subscription->get_billing_period() : '';
			switch ( $period ) {
				case 'day':
					return __( 'your daily donation', 'newspack-plugin' );
				case 'week':
					return __( 'your weekly donation', 'newspack-plugin' );
				case 'month':
					return __( 'your monthly donation', 'newspack-plugin' );
				case 'year':
					return __( 'your annual donation', 'newspack-plugin' );
				default:
					return __( 'your recurring donation', 'newspack-plugin' );
			}
		}
		$items   = array_values( $subscription->get_items() );
		$product = empty( $items ) ? null : $items[0]->get_product();
		if ( $product ) {
			/* translators: %s: the subscription's product name, such as "Digital Subscription". */
			return sprintf( __( 'your %s', 'newspack-plugin' ), $product->get_name() );
		}
		return __( 'your subscription', 'newspack-plugin' );
	}

	/**
	 * The lines the order header adds under the order date, one per subscription the
	 * order pays for: what the payment was for, linking to that subscription's page.
	 *
	 * @param \WC_Order $order The order.
	 * @return string[] Each line's markup, escaped.
	 */
	public static function get_order_subscription_lines( $order ): array {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return [];
		}
		$is_renewal = function_exists( 'wcs_order_contains_renewal' ) && \wcs_order_contains_renewal( $order );
		$lines      = [];
		foreach ( \wcs_get_subscriptions_for_order( $order, [ 'order_type' => [ 'parent', 'renewal', 'switch' ] ] ) as $subscription ) {
			if ( (int) $subscription->get_parent_id() === (int) $order->get_id() ) {
				/* translators: %s: a link to the subscription, such as "your monthly donation" or "your Premium Subscription". */
				$format = __( 'First payment for %s', 'newspack-plugin' );
			} elseif ( $is_renewal ) {
				/* translators: %s: a link to the subscription, such as "your monthly donation" or "your Premium Subscription". */
				$format = __( 'Renewal for %s', 'newspack-plugin' );
			} else {
				/* translators: %s: a link to the subscription, such as "your monthly donation" or "your Premium Subscription". */
				$format = __( 'Payment for %s', 'newspack-plugin' );
			}
			$link    = sprintf(
				'<a class="newspack-ui__link" href="%1$s">%2$s</a>',
				\esc_url( $subscription->get_view_order_url() ),
				\esc_html( self::get_subscription_reference( $subscription ) )
			);
			$lines[] = sprintf( \esc_html( $format ), $link );
		}
		return $lines;
	}

	/**
	 * Whether the reader can pay for an order from My Account: WooCommerce's own
	 * Pay condition, after plugins have filtered the order actions.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool
	 */
	public static function order_can_be_paid( $order ): bool {
		return isset( \wc_get_account_orders_actions( $order )['pay'] );
	}

	/**
	 * Render an order's status and, when the order can be paid, a "Pay now" link after it.
	 *
	 * @param \WC_Order $order   The order.
	 * @param string    $label   Status label to show.
	 * @param bool      $can_pay Whether to add the "Pay now" link.
	 */
	public static function render_order_status( $order, string $label, bool $can_pay ): void {
		self::render_status( $order->get_status(), $label );
		if ( ! $can_pay ) {
			return;
		}
		$amount = html_entity_decode( \wp_strip_all_tags( \wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES, 'UTF-8' );
		?>
		<span class="newspack-my-account__order-pay"><span aria-hidden="true">&middot;</span> <a class="newspack-ui__link pay" href="<?php echo \esc_url( $order->get_checkout_payment_url() ); ?>" aria-label="<?php /* translators: 1: the amount due, 2: the order number. */ echo \esc_attr( sprintf( __( 'Pay now, %1$s for order #%2$s', 'newspack-plugin' ), $amount, $order->get_order_number() ) ); ?>"><?php \esc_html_e( 'Pay now', 'newspack-plugin' ); ?></a></span>
		<?php
	}

	/**
	 * Render the status column of the Orders table.
	 *
	 * @param \WC_Order $order The order.
	 */
	public static function render_order_status_column( $order ): void {
		self::render_order_status( $order, self::get_order_status_label( $order ), self::order_can_be_paid( $order ) );
	}

	/**
	 * Render the order number cell in the Orders table: WooCommerce's link, plus the
	 * chevron the stacked phone layout shows at the end of each row.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function render_order_number_column( $order ): void {
		?>
		<a href="<?php echo \esc_url( $order->get_view_order_url() ); ?>" aria-label="<?php /* translators: %s: the order number. */ echo \esc_attr( sprintf( __( 'View order number %s', 'newspack-plugin' ), $order->get_order_number() ) ); ?>">
			<?php echo \esc_html( _x( '#', 'hash before order number', 'newspack-plugin' ) . $order->get_order_number() ); ?>
			<?php Newspack_UI_Icons::print_svg( 'chevronRight' ); ?>
		</a>
		<?php
	}

	/**
	 * Drop the actions column from the Orders table. The order number links to the
	 * order, whose page lists the order's actions; "Pay now" sits in the status cell.
	 *
	 * @param array $columns Orders table columns.
	 * @return array Orders table columns.
	 */
	public static function orders_columns( array $columns ): array {
		unset( $columns['order-actions'] );
		return $columns;
	}
}
My_Account_UI_V1::init();
