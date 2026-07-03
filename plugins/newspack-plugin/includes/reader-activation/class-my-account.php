<?php
/**
 * Newspack My Account core shell.
 *
 * Owns the My Account page, endpoints, page detection, URL generation, tab
 * registry, and content dispatch independently of WooCommerce. When
 * WooCommerce is active, every accessor delegates to WooCommerce so behavior
 * is unchanged; when it is absent, the shell runs natively.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * My_Account class.
 */
class My_Account {
	/**
	 * Option that stores the native account page ID (used only when WooCommerce
	 * is not active).
	 */
	const PAGE_ID_OPTION = 'newspack_my_account_page_id';

	/**
	 * Core endpoint slug constants.
	 */
	const ENDPOINT_EDIT_ACCOUNT   = 'edit-account';
	const ENDPOINT_DELETE_ACCOUNT = 'newspack-delete-account';

	/**
	 * Option storing the last-registered endpoint slug set (for flush detection).
	 */
	const ENDPOINTS_OPTION = 'newspack_my_account_endpoint_slugs';

	/**
	 * Transient prefix for one-time My Account notices (keyed by user ID).
	 */
	const NOTICE_TRANSIENT_PREFIX = 'newspack_my_account_notice_';

	/**
	 * Whether WooCommerce owns the My Account shell.
	 *
	 * @return bool
	 */
	public static function woocommerce_owns_shell(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_permalink' );
	}

	/**
	 * Add a My Account notice, using WooCommerce notices when available and
	 * falling back to Newspack UI snackbars on the native (no-WooCommerce) page.
	 *
	 * Shared so the WooCommerce and native paths — and integrations such as
	 * newspack-newsletters — emit notices through a single implementation.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type: 'success' | 'error' | 'notice'.
	 */
	public static function add_notice( string $message, string $type = 'success' ): void {
		if ( \function_exists( 'wc_add_notice' ) ) {
			\wc_add_notice( $message, $type );
			return;
		}
		if ( \class_exists( 'Newspack\Newspack_UI' ) ) {
			Newspack_UI::add_notice(
				$message,
				[
					'type'           => 'error' === $type ? 'error' : 'success',
					'corner'         => 'top-right',
					'autohide'       => true,
					'active_on_load' => true,
				]
			);
		}
	}

	/**
	 * Whether the current reader must verify their account before accessing
	 * account content (mirrors WooCommerce_My_Account::restrict_account_content).
	 *
	 * @return bool
	 */
	protected static function reader_must_verify(): bool {
		if ( defined( 'NEWSPACK_ALLOW_MY_ACCOUNT_ACCESS_WITHOUT_VERIFICATION' ) && NEWSPACK_ALLOW_MY_ACCOUNT_ACCESS_WITHOUT_VERIFICATION ) {
			return false;
		}
		if ( ! \class_exists( 'Newspack\WooCommerce_My_Account' ) ) {
			return false;
		}
		return \is_user_logged_in() && ! WooCommerce_My_Account::is_user_verified();
	}

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		\add_action( 'init', [ __CLASS__, 'register_shortcode' ] );
		// Defer the native-shell decision to `plugins_loaded` so the WooCommerce
		// class-existence check is reliable regardless of plugin load order
		// (newspack-plugin sorts before woocommerce, so WooCommerce may not be
		// loaded yet at this file's load time).
		\add_action( 'plugins_loaded', [ __CLASS__, 'maybe_register_native_hooks' ] );
	}

	/**
	 * Register the native shell hooks when WooCommerce is absent.
	 *
	 * Gated on Reader Activation being enabled so sites without it aren't given
	 * site-wide rewrite endpoints, query vars, or a rewrite flush for a feature
	 * that is effectively off (no native account page is provisioned when
	 * Reader Activation is disabled).
	 */
	public static function maybe_register_native_hooks(): void {
		if ( self::woocommerce_owns_shell() || ! Reader_Activation::is_enabled() ) {
			return;
		}
		\add_filter( 'page_template', [ __CLASS__, 'page_template' ], 11 );
		\add_action( 'init', [ __CLASS__, 'register_endpoints' ], 6 );
		\add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		// Priority 20 so it runs after auth/token handlers (e.g. Magic_Link's
		// process_token_request at 10), which redirect+exit on their own — the
		// dashboard redirect must not strip their query params.
		\add_action( 'template_redirect', [ __CLASS__, 'redirect_dashboard_to_account_details' ], 20 );
		\add_action( 'template_redirect', [ __CLASS__, 'handle_form_submissions' ] );
		\add_action( 'wp', [ __CLASS__, 'maybe_display_notice' ] );
		\add_action( 'admin_init', [ __CLASS__, 'maybe_provision_page' ] );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 11 );
		// Priority 11 so it runs after the theme's body_class filter (10) and
		// can replace `has-sidebar` with `no-sidebar` on the logged-out page.
		\add_filter( 'body_class', [ __CLASS__, 'add_body_class' ], 11 );
		\add_filter( 'show_admin_bar', [ __CLASS__, 'hide_admin_bar' ] ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected
	}

	/**
	 * Enqueue My Account styles on the native account page.
	 *
	 * Reuses the compiled My Account stylesheet (dist/my-account-v1.css). The
	 * WooCommerce path enqueues this via My_Account_UI_V1; when WooCommerce is
	 * absent the native shell must do it.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_account_page() ) {
			return;
		}
		\wp_enqueue_style(
			'newspack-my-account-v1',
			\Newspack\Newspack::plugin_url() . '/dist/my-account-v1.css',
			[ 'newspack-ui' ],
			NEWSPACK_PLUGIN_VERSION
		);

		// Logged-out readers see the inline auth form. Center it like the
		// WooCommerce account page does (its centering CSS is scoped to the
		// `.woocommerce-account` body class, which is absent without WooCommerce).
		if ( ! \is_user_logged_in() ) {
			\wp_add_inline_style(
				'newspack-my-account-v1',
				'.newspack-my-account--logged-out .newspack-reader-auth__inline-wrapper{margin-left:auto;margin-right:auto;max-width:var(--newspack-ui-modal-width-s)}'
			);
			return;
		}

		// The classic theme pins the content column to its article width, which
		// prevents the account content from expanding so its `margin: auto` can
		// center it. Free the content column on the native account page.
		\wp_add_inline_style(
			'newspack-my-account-v1',
			'.newspack-my-account--logged-in .main-content{width:100%;max-width:none}'
		);
		\wp_enqueue_script(
			'newspack-ui',
			\Newspack\Newspack::plugin_url() . '/dist/newspack-ui.js',
			[ 'wp-util' ],
			NEWSPACK_PLUGIN_VERSION,
			true
		);
		\wp_add_inline_script(
			'newspack-ui',
			"( function() {
				document.addEventListener( 'DOMContentLoaded', function() {
					var deleteButton = document.querySelector( '#delete-account .newspack-ui__button' );
					var modal = document.getElementById( 'newspack-my-account__delete-account' );
					if ( deleteButton && modal ) {
						deleteButton.addEventListener( 'click', function( e ) {
							e.preventDefault();
							modal.setAttribute( 'data-state', 'open' );
						} );
					}
				} );
			} )();"
		);
	}

	/**
	 * Add My Account body classes on the native account page.
	 *
	 * Mirrors My_Account_UI_V1::add_body_class() so the layout CSS (scoped to
	 * these classes) applies on the native path.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public static function add_body_class( array $classes ): array {
		if ( ! self::is_account_page() ) {
			return $classes;
		}
		if ( \is_user_logged_in() ) {
			$classes[] = 'newspack-ui';
		}
		$classes[] = 'newspack-my-account';
		$classes[] = 'newspack-my-account--v1';
		if ( ! \is_user_logged_in() ) {
			$classes[] = 'newspack-my-account--logged-out';
			// The logged-out template renders no sidebar; swap the theme's
			// `has-sidebar` class for `no-sidebar` so the content lays out
			// full-width and the auth form centers on the page.
			$classes = array_values( array_diff( $classes, [ 'has-sidebar' ] ) );
			if ( ! in_array( 'no-sidebar', $classes, true ) ) {
				$classes[] = 'no-sidebar';
			}
		} else {
			$classes[] = 'newspack-my-account--logged-in';
		}
		return $classes;
	}

	/**
	 * Hide the WordPress admin bar on the native My Account page for readers.
	 *
	 * Mirrors Reader_Activation::hide_admin_bar_for_readers(): administrators and
	 * editors keep the admin bar so they can still manage the site.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public static function hide_admin_bar( $show ): bool {
		if ( ! self::is_account_page() ) {
			return (bool) $show;
		}
		$user = \wp_get_current_user();
		if ( ! $user || ! $user->ID || ! Reader_Activation::is_user_reader( $user ) ) {
			return $show;
		}
		return false;
	}

	/**
	 * Ensure the native account page exists when running without WooCommerce.
	 *
	 * Self-healing: runs on admin_init, creates the page at most once (guarded
	 * by the stored option), and only when Reader Activation is enabled. Gated on
	 * `manage_options` so the write never fires on low-privilege admin-ajax
	 * requests or the hot admin path for non-administrators.
	 */
	public static function maybe_provision_page(): void {
		if ( ! Reader_Activation::is_enabled() || ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$page_id = (int) \get_option( self::PAGE_ID_OPTION, 0 );
		if ( $page_id && 'page' === \get_post_type( $page_id ) ) {
			return;
		}
		self::get_or_create_page();
	}

	/**
	 * Handle the native account-settings save (Woo absent).
	 *
	 * Updates the display name. The email address is intentionally not updated
	 * here: the native path has no email-change verification flow, and
	 * `WooCommerce_My_Account::edit_account_prevent_email_update()` resets
	 * `$_POST['account_email']` to the current address on every Reader Activation
	 * request, so the field is rendered read-only and any submitted value is
	 * ignored rather than silently dropped while reporting success.
	 *
	 * @return int Updated user ID, or 0 on failure / invalid nonce / unverified.
	 */
	public static function handle_save_account(): int {
		if ( ! \is_user_logged_in() || self::reader_must_verify() ) {
			return 0;
		}
		$nonce = isset( $_POST['newspack_my_account_save_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['newspack_my_account_save_nonce'] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, 'newspack_my_account_save' ) ) {
			return 0;
		}

		$user_id = \get_current_user_id();
		$args    = [ 'ID' => $user_id ];
		if ( isset( $_POST['account_display_name'] ) ) {
			$args['display_name'] = \sanitize_text_field( \wp_unslash( $_POST['account_display_name'] ) );
		}
		$result = \wp_update_user( $args );
		return \is_wp_error( $result ) ? 0 : $user_id;
	}

	/**
	 * Handle the native password change (Woo absent).
	 *
	 * @return bool True on success.
	 */
	public static function handle_password_change(): bool {
		if ( ! \is_user_logged_in() || self::reader_must_verify() ) {
			return false;
		}
		$nonce = isset( $_POST['newspack_my_account_password_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['newspack_my_account_password_nonce'] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, 'newspack_my_account_password' ) ) {
			return false;
		}
		$user  = \wp_get_current_user();
		$pass1 = isset( $_POST['password_1'] ) && \is_string( $_POST['password_1'] ) ? \wp_unslash( $_POST['password_1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.
		$pass2 = isset( $_POST['password_2'] ) && \is_string( $_POST['password_2'] ) ? \wp_unslash( $_POST['password_2'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.

		if ( '' === $pass1 || $pass1 !== $pass2 ) {
			self::set_notice( \__( 'Passwords do not match. Please try again.', 'newspack-plugin' ), 'error' );
			return false;
		}
		// Require the current password unless the reader has none yet. An explicit
		// `true ===` check keeps a WP_Error (returned for a non-reader) on the
		// safe side: it is treated as "has a password", so the current-password
		// requirement is enforced rather than skipped on error-truthiness.
		if ( true !== Reader_Activation::is_reader_without_password( $user ) ) {
			$current = isset( $_POST['current_password'] ) && \is_string( $_POST['current_password'] ) ? \wp_unslash( $_POST['current_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.
			if ( '' === $current || ! \wp_check_password( $current, $user->data->user_pass, $user->ID ) ) {
				self::set_notice( \__( 'Your current password is incorrect.', 'newspack-plugin' ), 'error' );
				return false;
			}
		}
		\wp_update_user(
			[
				'ID'        => $user->ID,
				'user_pass' => $pass1,
			]
		);
		self::set_notice( \__( 'Password updated.', 'newspack-plugin' ), 'success' );
		return true;
	}

	/**
	 * Store a one-time My Account notice for the current user (shown after redirect).
	 *
	 * @param string $message Notice message.
	 * @param string $type    'success' | 'error'.
	 */
	private static function set_notice( string $message, string $type = 'success' ): void {
		\set_transient(
			self::NOTICE_TRANSIENT_PREFIX . \get_current_user_id(),
			[
				'message' => $message,
				'type'    => $type,
			],
			\MINUTE_IN_SECONDS
		);
	}

	/**
	 * Route native form POSTs to their handlers on the `template_redirect` hook.
	 *
	 * The form `action` value is only used for routing; each target handler runs
	 * its own nonce verification before mutating any data.
	 */
	public static function handle_form_submissions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- routing only; the dispatched handlers verify their own nonces.
		if ( empty( $_POST['action'] ) || ! self::is_account_page() ) {
			return;
		}
		$action = \sanitize_text_field( \wp_unslash( $_POST['action'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( 'newspack_my_account_save_account' === $action ) {
			$saved = self::handle_save_account();
			self::set_notice(
				$saved ? \__( 'Account details changed successfully.', 'newspack-plugin' ) : \__( 'Something went wrong. Please try again.', 'newspack-plugin' ),
				$saved ? 'success' : 'error'
			);
			\wp_safe_redirect( self::get_endpoint_url( self::ENDPOINT_EDIT_ACCOUNT ) );
			exit;
		}
		if ( 'newspack_my_account_save_password' === $action ) {
			self::handle_password_change();
			\wp_safe_redirect( self::get_endpoint_url( self::ENDPOINT_EDIT_ACCOUNT ) );
			exit;
		}
		if ( 'newspack_my_account_request_delete' === $action ) {
			self::handle_delete_request();
		}
	}

	/**
	 * Display a one-time success/error notice after a profile save.
	 */
	public static function maybe_display_notice(): void {
		if ( ! \is_user_logged_in() || ! self::is_account_page() ) {
			return;
		}

		// Native equivalent of WooCommerce_My_Account::handle_messages(): show the
		// `?message=...&is_error=...` feedback used by the verify-account buttons.
		$message = filter_input( INPUT_GET, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $message ) {
			$is_error = (bool) filter_input( INPUT_GET, 'is_error', FILTER_VALIDATE_BOOLEAN );
			self::add_notice( $message, $is_error ? 'error' : 'success' );
		}

		$key    = self::NOTICE_TRANSIENT_PREFIX . \get_current_user_id();
		$stored = \get_transient( $key );
		if ( is_array( $stored ) ) {
			\delete_transient( $key );
			$msg = isset( $stored['message'] ) ? $stored['message'] : '';
			if ( $msg ) {
				self::add_notice( $msg, isset( $stored['type'] ) ? $stored['type'] : 'success' );
			}
		}
	}

	/**
	 * Redirect the base account page to the Account details endpoint.
	 *
	 * The dashboard has no standalone view; Account details is the default.
	 */
	public static function redirect_dashboard_to_account_details(): void {
		if ( ! \is_user_logged_in() || ! self::is_account_page() ) {
			return;
		}
		if ( '' !== self::get_current_endpoint() ) {
			return;
		}
		\wp_safe_redirect( self::get_endpoint_url( self::ENDPOINT_EDIT_ACCOUNT ) );
		exit;
	}

	/**
	 * Handle the native delete-account request (Woo absent): send the existing
	 * deletion email if available, else delete after confirmation.
	 */
	public static function handle_delete_request(): void {
		if ( ! \is_user_logged_in() ) {
			return;
		}
		$nonce = isset( $_POST['newspack_my_account_delete_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['newspack_my_account_delete_nonce'] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, 'newspack_my_account_delete' ) ) {
			return;
		}
		$user = \wp_get_current_user();
		if ( class_exists( 'Newspack\WooCommerce_My_Account' ) && method_exists( 'Newspack\WooCommerce_My_Account', 'send_delete_account_email' ) ) {
			WooCommerce_My_Account::send_delete_account_email( $user );
		}
		\wp_safe_redirect( self::get_endpoint_url( self::ENDPOINT_EDIT_ACCOUNT ) );
		exit;
	}

	/**
	 * Get the My Account page ID.
	 *
	 * Resolution order: WooCommerce account page when Woo is active, else the
	 * native Newspack account page.
	 *
	 * @return int Page ID, or 0 if none is set.
	 */
	public static function get_page_id(): int {
		if ( self::woocommerce_owns_shell() ) {
			return (int) \get_option( 'woocommerce_myaccount_page_id', 0 );
		}
		$page_id = (int) \get_option( self::PAGE_ID_OPTION, 0 );
		if ( ! $page_id ) {
			// Fall back to an existing WooCommerce account page if present.
			$page_id = (int) \get_option( 'woocommerce_myaccount_page_id', 0 );
		}
		return $page_id;
	}

	/**
	 * Get the native account page ID, creating the page if it does not exist.
	 *
	 * Only used when WooCommerce is not active.
	 *
	 * @return int Page ID.
	 */
	public static function get_or_create_page(): int {
		$page_id = (int) \get_option( self::PAGE_ID_OPTION, 0 );
		if ( $page_id && 'page' === \get_post_type( $page_id ) ) {
			return $page_id;
		}

		// Reuse an existing WooCommerce My Account page if present. This avoids
		// creating a duplicate "my-account-2" page when a WooCommerce account
		// page already occupies the "my-account" slug, and round-trips cleanly
		// if WooCommerce is reactivated (both resolve to the same page).
		$existing_id = (int) \get_option( 'woocommerce_myaccount_page_id', 0 );
		if ( $existing_id && 'page' === \get_post_type( $existing_id ) ) {
			\update_option( self::PAGE_ID_OPTION, $existing_id );
			return $existing_id;
		}

		// Reuse any page already sitting at the canonical "my-account" slug before
		// inserting a new one. This closes the check-then-act race where two
		// concurrent admin requests would each pass the option guard and insert a
		// duplicate page (the second orphaning the first).
		$existing_page = \get_page_by_path( 'my-account' );
		if ( $existing_page instanceof \WP_Post && 'page' === $existing_page->post_type ) {
			\update_option( self::PAGE_ID_OPTION, $existing_page->ID );
			return $existing_page->ID;
		}

		$page_id = \wp_insert_post(
			[
				'post_type'      => 'page',
				'post_title'     => \esc_html__( 'My Account', 'newspack-plugin' ),
				'post_content'   => '[newspack_my_account]',
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			]
		);

		if ( ! \is_wp_error( $page_id ) && $page_id ) {
			\update_option( self::PAGE_ID_OPTION, (int) $page_id );
			\update_post_meta( $page_id, 'newspack_hide_page_title', true );
			return (int) $page_id;
		}

		return 0;
	}

	/**
	 * Whether the current request is the My Account page (or one of its
	 * endpoints).
	 *
	 * @return bool
	 */
	public static function is_account_page(): bool {
		if ( self::woocommerce_owns_shell() && function_exists( 'is_account_page' ) ) {
			return \is_account_page();
		}
		$page_id = self::get_page_id();
		return $page_id && \is_page( $page_id );
	}

	/**
	 * Get the URL for a My Account endpoint.
	 *
	 * @param string $endpoint Endpoint slug. Empty string returns the base
	 *                         account page URL.
	 * @param string $value    Optional endpoint value (e.g. a subscription ID).
	 * @return string URL, or empty string if the page is not set.
	 */
	public static function get_endpoint_url( string $endpoint = '', string $value = '' ): string {
		if ( self::woocommerce_owns_shell() ) {
			if ( '' === $endpoint || 'dashboard' === $endpoint ) {
				return \wc_get_account_endpoint_url( 'dashboard' );
			}
			return \wc_get_endpoint_url( $endpoint, $value, \wc_get_page_permalink( 'myaccount' ) );
		}

		$page_id = self::get_page_id();
		if ( ! $page_id ) {
			return '';
		}
		$permalink = \get_permalink( $page_id );
		if ( ! $permalink || '' === $endpoint ) {
			return $permalink ? $permalink : '';
		}

		if ( \get_option( 'permalink_structure' ) ) {
			$url = \trailingslashit( $permalink ) . $endpoint;
			if ( '' !== $value ) {
				$url .= '/' . $value;
			}
			return \user_trailingslashit( $url );
		}
		return \add_query_arg( $endpoint, $value, $permalink );
	}

	/**
	 * Get the registered endpoint slugs => labels for the native shell.
	 *
	 * Core tabs plus any integration-declared endpoints. Filterable so
	 * integrations and sites can extend the set.
	 *
	 * @return array<string,string> slug => label.
	 */
	public static function get_endpoints(): array {
		$endpoints = [
			self::ENDPOINT_EDIT_ACCOUNT   => \__( 'Account details', 'newspack-plugin' ),
			self::ENDPOINT_DELETE_ACCOUNT => \__( 'Delete account', 'newspack-plugin' ),
		];
		/**
		 * Filters the My Account endpoint slugs => labels (native shell).
		 *
		 * @param array<string,string> $endpoints slug => label.
		 */
		return \apply_filters( 'newspack_my_account_endpoints', $endpoints );
	}

	/**
	 * Get the ordered set of navigation tabs (slug => label).
	 *
	 * Account details first, then integration endpoints, then logout last. The
	 * dashboard and the delete-account endpoint are intentionally excluded:
	 * the base account URL redirects to Account details, and account deletion
	 * lives within the Account details screen.
	 *
	 * @return array<string,string>
	 */
	public static function get_tabs(): array {
		if ( self::reader_must_verify() ) {
			/**
			 * Filters the ordered My Account navigation tabs.
			 *
			 * @param array<string,string> $tabs slug => label.
			 */
			return \apply_filters(
				'newspack_my_account_tabs',
				[
					self::ENDPOINT_EDIT_ACCOUNT => \__( 'Account details', 'newspack-plugin' ),
					'customer-logout'           => \__( 'Sign out', 'newspack-plugin' ),
				]
			);
		}
		$endpoints = self::get_endpoints();
		unset( $endpoints[ self::ENDPOINT_DELETE_ACCOUNT ] );
		$tabs = array_merge(
			$endpoints,
			[ 'customer-logout' => \__( 'Sign out', 'newspack-plugin' ) ]
		);
		/**
		 * Filters the ordered My Account navigation tabs.
		 *
		 * @param array<string,string> $tabs slug => label.
		 */
		return \apply_filters( 'newspack_my_account_tabs', $tabs );
	}

	/**
	 * Register rewrite endpoints for the native shell.
	 */
	public static function register_endpoints(): void {
		$slugs = array_keys( self::get_endpoints() );
		foreach ( $slugs as $slug ) {
			\add_rewrite_endpoint( $slug, EP_PAGES );
		}

		$current = $slugs;
		sort( $current );
		$previous = \get_option( self::ENDPOINTS_OPTION, [] );
		if ( ! is_array( $previous ) ) {
			$previous = [];
		}
		sort( $previous );
		if ( $current !== $previous ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- only fires when the slug set changes.
			\flush_rewrite_rules( false );
			\update_option( self::ENDPOINTS_OPTION, $current );
		}
	}

	/**
	 * Add the endpoint slugs to the public query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( array $vars ): array {
		foreach ( array_keys( self::get_endpoints() ) as $slug ) {
			$vars[] = $slug;
		}
		return $vars;
	}

	/**
	 * Get the current endpoint slug from the query, or '' for the dashboard.
	 *
	 * @return string
	 */
	public static function get_current_endpoint(): string {
		global $wp;
		foreach ( array_keys( self::get_endpoints() ) as $slug ) {
			if ( isset( $wp->query_vars[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Register the [newspack_my_account] shortcode.
	 */
	public static function register_shortcode(): void {
		\add_shortcode( 'newspack_my_account', [ __CLASS__, 'render_page' ] );

		// The native account page reuses the existing WooCommerce account page,
		// whose content is the `[woocommerce_my_account]` shortcode. When
		// WooCommerce is inactive that shortcode is unregistered and would render
		// as raw text, so alias it to the native renderer. This makes the page
		// render natively regardless of the active page template (e.g. when a
		// logged-out/cached copy bypasses the page_template override).
		if ( ! self::woocommerce_owns_shell() ) {
			\add_shortcode( 'woocommerce_my_account', [ __CLASS__, 'render_page' ] );
		}
	}

	/**
	 * Use the blank My Account page template on the native account page.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function page_template( $template ): string {
		if ( ! self::is_account_page() ) {
			return (string) $template;
		}
		if ( ! \is_user_logged_in() ) {
			// Logged-out readers get a stripped template: site header, the auth
			// form as content, and the site footer — no title, no sidebar.
			return NEWSPACK_ABSPATH . 'includes/plugins/woocommerce/my-account/templates/v1/login.php';
		}
		return NEWSPACK_ABSPATH . 'includes/plugins/woocommerce/my-account/templates/v1/my-account.php';
	}

	/**
	 * Render the My Account page body.
	 *
	 * Outputs the navigation and the content for the current endpoint. Used by
	 * the [newspack_my_account] shortcode and block when WooCommerce is absent.
	 *
	 * @return string Rendered HTML.
	 */
	public static function render_page(): string {
		if ( ! \is_user_logged_in() ) {
			// Mirror the WooCommerce account page: a logged-out visitor gets the
			// inline Reader Activation auth form (see login-form.php), not an
			// empty page.
			ob_start();
			echo '<div class="newspack-ui newspack-reader-auth__inline-wrapper">';
			Reader_Activation::render_auth_form( false );
			$footer = Reader_Activation::get_auth_footer();
			if ( $footer ) {
				echo '<p class="newspack-ui__font--xs">' . \wp_kses_post( $footer ) . '</p>';
			}
			echo '</div>';
			return ob_get_clean();
		}

		ob_start();
		echo '<div class="newspack-my-account newspack-ui">';
		echo '<div class="woocommerce">';
		self::render_navigation();
		echo '<div class="newspack-my-account__content woocommerce-MyAccount-content">';
		self::render_content();
		echo '</div>';
		echo '</div>';
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Render the native navigation menu.
	 *
	 * Mirrors the structure of templates/v1/navigation.php so the sidebar CSS
	 * (dist/my-account-v1.css) applies, using native tab data instead of
	 * WooCommerce menu functions.
	 */
	protected static function render_navigation(): void {
		$current = self::get_current_endpoint();
		$tabs    = self::get_tabs();
		$logout  = null;
		if ( isset( $tabs['customer-logout'] ) ) {
			$logout = $tabs['customer-logout'];
			unset( $tabs['customer-logout'] );
		}
		$site_logo = \get_site_icon_url( 96 );
		?>
		<nav class="woocommerce-MyAccount-navigation newspack-ui" aria-label="<?php \esc_attr_e( 'Account pages', 'newspack-plugin' ); ?>">
			<div class="newspack-my-account__navigation-header">
				<?php if ( ! empty( $site_logo ) ) : ?>
					<a class="newspack-my-account__site-logo" href="<?php echo \esc_url( \home_url( '/' ) ); ?>" title="<?php \esc_attr_e( 'Back to Homepage', 'newspack-plugin' ); ?>" aria-label="<?php \esc_attr_e( 'Back to Homepage', 'newspack-plugin' ); ?>">
						<img src="<?php echo \esc_url( $site_logo ); ?>" alt="" />
					</a>
				<?php endif; ?>
				<a href="<?php echo \esc_url( \home_url( '/' ) ); ?>" class="newspack-my-account__home-link newspack-ui__button newspack-ui__button--small newspack-ui__button--ghost-light">
					<?php Newspack_UI_Icons::print_svg( 'chevronLeft' ); ?>
					<?php \esc_html_e( 'Back to Homepage', 'newspack-plugin' ); ?>
				</a>
				<ul>
					<?php foreach ( $tabs as $newspack_tab_slug => $newspack_tab_label ) : ?>
						<?php $is_current = ( $newspack_tab_slug === $current ) || ( '' === $current && self::ENDPOINT_EDIT_ACCOUNT === $newspack_tab_slug ); ?>
						<li class="<?php echo \esc_attr( $is_current ? 'is-active' : '' ); ?>">
							<a href="<?php echo \esc_url( self::get_endpoint_url( $newspack_tab_slug ) ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?> class="newspack-ui__button newspack-ui__button--small <?php echo $is_current ? 'newspack-ui__button--accent' : 'newspack-ui__button--ghost'; ?>">
								<?php echo \esc_html( $newspack_tab_label ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php if ( $logout ) : ?>
			<div class="newspack-my-account__navigation-footer">
				<ul>
					<li class="woocommerce-MyAccount-navigation-link woocommerce-MyAccount-navigation-link--customer-logout">
						<a href="<?php echo \esc_url( \wp_logout_url( \home_url( '/' ) ) ); ?>" class="newspack-ui__button newspack-ui__button--small newspack-ui__button--ghost">
							<?php echo \esc_html( $logout ); ?>
							<?php Newspack_UI_Icons::print_svg( 'logout' ); ?>
						</a>
					</li>
				</ul>
			</div>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * Render the content for the current endpoint.
	 *
	 * Core endpoints render their own templates; the dashboard renders the
	 * default landing. Integration endpoints are rendered by the
	 * newspack_my_account_content action.
	 */
	public static function render_content(): void {
		if ( self::reader_must_verify() ) {
			include NEWSPACK_ABSPATH . 'includes/plugins/woocommerce/my-account/templates/verify.php';
			return;
		}

		$endpoint = self::get_current_endpoint();

		switch ( $endpoint ) {
			case self::ENDPOINT_EDIT_ACCOUNT:
				self::render_account_settings();
				break;
			case self::ENDPOINT_DELETE_ACCOUNT:
				self::render_delete_account();
				break;
			case '':
				self::render_dashboard();
				break;
		}

		/**
		 * Fires when rendering My Account content for an endpoint. Integrations
		 * hook this to render their tab body when their slug is current.
		 *
		 * @param string $endpoint Current endpoint slug ('' for dashboard).
		 */
		\do_action( 'newspack_my_account_content', $endpoint );
	}

	/**
	 * Render the dashboard landing. Stub; refined in a later task.
	 */
	protected static function render_dashboard(): void {
		echo '<p>' . \esc_html__( 'Welcome to your account.', 'newspack-plugin' ) . '</p>';
	}

	/**
	 * Render the native account settings form: editable display name, a
	 * read-only email field (the native path has no email-change verification
	 * flow), the password form, and the delete-account section.
	 */
	protected static function render_account_settings(): void {
		// When arriving from the account-deletion email link, show the confirmation form.
		if ( isset( $_GET[ WooCommerce_My_Account::DELETE_ACCOUNT_FORM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is verified in render_delete_confirmation().
			self::render_delete_confirmation();
			return;
		}

		$user = \wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}
		?>
		<section id="account-profile">
			<h4 class="newspack-ui__font--m newspack-ui__spacing-top--0"><?php \esc_html_e( 'Profile', 'newspack-plugin' ); ?></h4>
			<form class="woocommerce-EditAccountForm edit-profile newspack-my-account__settings-form" action="" method="post">
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="account_display_name"><?php \esc_html_e( 'Display name', 'newspack-plugin' ); ?></label>
					<input type="text" class="woocommerce-Input input-text" name="account_display_name" id="account_display_name" value="<?php echo \esc_attr( $user->display_name ); ?>" />
				</p>
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="account_email"><?php \esc_html_e( 'Email address', 'newspack-plugin' ); ?></label>
					<input type="email" class="woocommerce-Input input-text" name="account_email" id="account_email" value="<?php echo \esc_attr( $user->user_email ); ?>" autocomplete="email" readonly disabled />
					<span class="newspack-ui__font--xs"><?php \esc_html_e( 'Your email address cannot be changed here.', 'newspack-plugin' ); ?></span>
				</p>
				<?php
				/** Lets integrations add fields, mirroring the Woo template hook. */
				\do_action( 'newspack_woocommerce_edit_account_form_fields' );
				?>
				<p class="woocommerce-buttons-card">
					<?php \wp_nonce_field( 'newspack_my_account_save', 'newspack_my_account_save_nonce' ); ?>
					<input type="hidden" name="action" value="newspack_my_account_save_account" />
					<button type="submit" class="newspack-ui__button newspack-ui__button--primary"><?php \esc_html_e( 'Update profile', 'newspack-plugin' ); ?></button>
				</p>
			</form>
		</section>
		<?php
		$newspack_without_password = $user && true === Reader_Activation::is_reader_without_password( $user );
		?>
		<section id="account-password">
			<h4 class="newspack-ui__font--m"><?php \esc_html_e( 'Password', 'newspack-plugin' ); ?></h4>
			<form class="newspack-my-account__password-form" method="post" action="">
				<?php if ( ! $newspack_without_password ) : ?>
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="current_password"><?php \esc_html_e( 'Current password', 'newspack-plugin' ); ?>&nbsp;<span class="required">*</span></label>
					<input type="password" class="woocommerce-Input input-text" name="current_password" id="current_password" autocomplete="current-password" required />
				</p>
				<?php endif; ?>
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="password_1"><?php \esc_html_e( 'New password', 'newspack-plugin' ); ?>&nbsp;<span class="required">*</span></label>
					<input type="password" class="woocommerce-Input input-text" name="password_1" id="password_1" autocomplete="new-password" required />
				</p>
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="password_2"><?php \esc_html_e( 'Re-enter new password', 'newspack-plugin' ); ?>&nbsp;<span class="required">*</span></label>
					<input type="password" class="woocommerce-Input input-text" name="password_2" id="password_2" autocomplete="new-password" required />
				</p>
				<p class="woocommerce-buttons-card">
					<?php \wp_nonce_field( 'newspack_my_account_password', 'newspack_my_account_password_nonce' ); ?>
					<input type="hidden" name="action" value="newspack_my_account_save_password" />
					<button type="submit" class="newspack-ui__button newspack-ui__button--primary"><?php echo \esc_html( $newspack_without_password ? \__( 'Set password', 'newspack-plugin' ) : \__( 'Update password', 'newspack-plugin' ) ); ?></button>
				</p>
			</form>
		</section>
		<section id="delete-account">
			<h4 class="newspack-ui__font--m is-destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></h4>
			<p><?php \esc_html_e( 'Please note, account deletion is final, and there will be no way to restore your account.', 'newspack-plugin' ); ?></p>
			<p class="woocommerce-buttons-card">
				<a class="newspack-ui__button newspack-ui__button--destructive" href="<?php echo \esc_url( self::get_endpoint_url( self::ENDPOINT_DELETE_ACCOUNT ) ); ?>"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></a>
			</p>
		</section>
		<?php
		if ( \class_exists( 'Newspack\My_Account_UI_V1' ) && \method_exists( 'Newspack\My_Account_UI_V1', 'delete_account_modal' ) ) {
			My_Account_UI_V1::delete_account_modal();
		}
	}

	/**
	 * Render the account-deletion confirmation form (Woo absent).
	 *
	 * Reached when the reader clicks the link in the account-deletion email,
	 * which lands on the edit-account endpoint carrying the deletion nonce and
	 * token. Mirrors the safe happy-path of the Woo delete-account template but
	 * uses no WooCommerce functions. Submitting the form is processed by
	 * WooCommerce_My_Account::handle_delete_account() on template_redirect.
	 */
	protected static function render_delete_confirmation(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce is verified below before any output of the form.
		$nonce = isset( $_GET[ WooCommerce_My_Account::DELETE_ACCOUNT_FORM ] ) ? \sanitize_text_field( \wp_unslash( $_GET[ WooCommerce_My_Account::DELETE_ACCOUNT_FORM ] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, WooCommerce_My_Account::DELETE_ACCOUNT_FORM ) ) {
			echo '<p>' . \esc_html__( 'Invalid request.', 'newspack-plugin' ) . '</p>';
			return;
		}

		$token           = isset( $_GET['token'] ) ? \sanitize_text_field( \wp_unslash( $_GET['token'] ) ) : '';
		$transient_token = \get_transient( 'np_reader_account_delete_' . \get_current_user_id() );
		if ( ! $token || ! $transient_token || $token !== $transient_token ) {
			echo '<p>' . \esc_html__( 'Invalid request.', 'newspack-plugin' ) . '</p>';
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<section id="delete-account-confirm">
			<h4 class="newspack-ui__font--m is-destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></h4>
			<p><?php \esc_html_e( 'Confirm to delete your account permanently.', 'newspack-plugin' ); ?></p>
			<p><?php \esc_html_e( 'Deleting your account will also cancel any newsletter subscriptions and recurring payments.', 'newspack-plugin' ); ?></p>
			<p><strong><?php \esc_html_e( 'Caution, this action is irreversible!', 'newspack-plugin' ); ?></strong></p>
			<form method="post">
				<input type="hidden" name="<?php echo \esc_attr( WooCommerce_My_Account::DELETE_ACCOUNT_FORM ); ?>" value="<?php echo \esc_attr( $nonce ); ?>" />
				<input type="hidden" name="token" value="<?php echo \esc_attr( $token ); ?>" />
				<input type="hidden" name="confirm_delete" value="1" />
				<button type="submit" class="newspack-ui__button newspack-ui__button--destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * Render the native delete-account confirmation tab.
	 */
	protected static function render_delete_account(): void {
		?>
		<section id="delete-account-confirm">
			<h4 class="newspack-ui__font--m is-destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></h4>
			<p><?php \esc_html_e( 'This action is permanent. To confirm, submit the request below and follow the link we email you.', 'newspack-plugin' ); ?></p>
			<form method="post" action="">
				<?php \wp_nonce_field( 'newspack_my_account_delete', 'newspack_my_account_delete_nonce' ); ?>
				<input type="hidden" name="action" value="newspack_my_account_request_delete" />
				<button type="submit" class="newspack-ui__button newspack-ui__button--destructive"><?php \esc_html_e( 'Request account deletion', 'newspack-plugin' ); ?></button>
			</form>
		</section>
		<?php
	}
}

My_Account::init();
