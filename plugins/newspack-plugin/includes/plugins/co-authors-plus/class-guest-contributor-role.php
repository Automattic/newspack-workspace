<?php
/**
 * Guest_Contributor_Role class.
 * https://wordpress.org/plugins/co-authors-plus
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_User;

/**
 * This class implements an alternative for the Guest Authors feature of Co-Authors Plus.
 *
 * The Non Editing Contributor role behaves similarly to the Guest Authors feature, but it's a custom role that can be assigned to users.
 *
 * This role can also be assigned to users who have other roles, so they can be assigned as co-authors of a post without having the capability to edit posts.
 * This is done via a custom UI in the user profile.
 *
 * CAP's Guest Authors feature will be disabled by default if there are no Guest Authors in the site. If you want to force enabling it, add the NEWSPACK_ENABLE_CAP_GUEST_AUTHORS constant to your wp-config.php file.
 */
class Guest_Contributor_Role {
	/**
	 * Custom capability name.
	 */
	const ASSIGNABLE_TO_POSTS_CAPABILITY_NAME = 'edit_cap_posts';

	/**
	 * Custom role name for users who are assignable as post authors but aren't allowed to edit posts.
	 */
	const CONTRIBUTOR_NO_EDIT_ROLE_NAME = 'contributor_no_edit';

	/**
	 * Option name to mark the version of the settings. If the implementation details
	 * change, the expected option value should be updated to trigger a reset of the settings.
	 */
	const SETTINGS_VERSION_OPTION_NAME = 'newspack_coauthors_plus_settings_version';

	/**
	 * The option where we store if the site has CAP's guest authors.
	 */
	const SITE_HAS_GUEST_AUTHORS_OPTION_NAME = 'newspack_check_site_has_cap_guest_authors';

	/**
	 * Log code for outbound-mail-guard suppression events (Logger::newspack_log()).
	 * The `mode` key in the log data discriminates the three cases sharing it.
	 */
	const MAIL_GUARD_LOG_CODE = 'newspack_guest_author_mail_suppressed';

	/**
	 * Initialize hooks and filters.
	 */
	public static function initialize() {
		add_filter( 'coauthors_edit_author_cap', [ __CLASS__, 'coauthors_edit_author_cap' ] );
		add_action( 'template_redirect', [ __CLASS__, 'prevent_myaccount_update' ] );
		add_action( 'newspack_before_delete_account', [ __CLASS__, 'before_delete_account' ] );

		add_action( 'init', [ __CLASS__, 'early_init' ], 5 );
		add_action( 'init', [ __CLASS__, 'setup_custom_role_and_capability' ] );

		// Do not allow guest authors to login.
		\add_filter( 'wp_authenticate_user', [ __CLASS__, 'wp_authenticate_user' ], 10, 2 );

		// Modify the user profile and user creation forms.
		\add_action( 'admin_footer', [ __CLASS__, 'admin_footer' ] );
		\add_filter( 'user_profile_update_errors', [ __CLASS__, 'user_profile_update_errors' ], 10, 3 );
		\add_action( 'admin_print_scripts-user-new.php', [ __CLASS__, 'admin_footer' ] );
		\add_action( 'admin_print_scripts-user-edit.php', [ __CLASS__, 'admin_footer' ] );

		\add_filter( 'option_default_role', [ __CLASS__, 'create_user_default_role' ] );
		\add_filter( 'option_cme_capabilities_add_user_multi_roles', [ __CLASS__, 'cme_capabilities_add_user_multi_roles' ] );

		// Disable some features from the user profile.
		\add_filter( 'show_password_fields', [ __CLASS__, 'disable_feature' ], 10, 2 );
		\add_filter( 'wp_is_application_passwords_available_for_user', [ __CLASS__, 'disable_feature' ], 10, 2 );
		\add_filter( 'allow_password_reset', [ __CLASS__, 'disable_feature' ], 10, 2 );
		\add_filter( 'woocommerce_current_user_can_edit_customer_meta_fields', [ __CLASS__, 'disable_feature' ], 10, 2 );

		\add_filter( 'rest_user_query', [ __CLASS__, 'filter_rest_user_query' ], 10, 2 );

		// Only if Members plugin is not active, because it has its own UI for roles.
		if ( ! class_exists( 'Members_Plugin' ) ) {
			// Add UI to the user profile to assign the custom role.
			add_action( 'edit_user_profile', [ __CLASS__, 'edit_user_profile' ] );
			add_action( 'wp_update_user', [ __CLASS__, 'edit_user_profile_update' ] );
		}

		// Hide author email on the frontend, if it's a placeholder email.
		\add_filter( 'theme_mod_show_author_email', [ __CLASS__, 'should_display_author_email' ] );
		\add_filter( 'newspack_show_coauthor_email', [ __CLASS__, 'should_display_coauthor_email' ], 10, 2 );

		// Never send email to generated placeholder addresses — they bounce and
		// can get the site's outbound email blocked (e.g. comment notifications
		// to Guest Contributors created without a real email).
		self::register_mail_guard();

		// Make sure we check again if the site has guest authors every hour.
		$re_check_guest_authors = 'newspack_re_check_guest_authors';
		if ( ! \wp_next_scheduled( $re_check_guest_authors ) ) {
			\wp_schedule_event( time(), 'hourly', $re_check_guest_authors );
		}
		add_action( $re_check_guest_authors, [ __CLASS__, 'clear_site_has_cap_guest_authors_check' ] );

		// Make Guest Contributors available for the Author List and Profile blocks.
		\add_filter( 'newspack_blocks_authors_roles', [ __CLASS__, 'add_guest_contributor_to_authors_blocks' ] );
	}

	/**
	 * Runs early in the init hook to make sure it runs before Co-Authors Plus initialization.
	 *
	 * @return void
	 */
	public static function early_init() {
		/**
		 * Enables Co-Authors Plus native guest author functionality instead
		 * of Newspack's custom guest contributor role system.
		 *
		 * @constant NEWSPACK_ENABLE_CAP_GUEST_AUTHORS
		 * @type     bool
		 * @default  CAP guest authors use contributor role
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_ENABLE_CAP_GUEST_AUTHORS', true );
		 */
		if ( defined( 'NEWSPACK_ENABLE_CAP_GUEST_AUTHORS' ) && NEWSPACK_ENABLE_CAP_GUEST_AUTHORS ) {
			return;
		}
		if ( ! self::site_has_cap_guest_authors() ) {
			add_filter( 'coauthors_guest_authors_enabled', '__return_false' );
			add_action( 'admin_menu', [ __CLASS__, 'guest_author_menu_replacement' ] );
		}
	}

	/**
	 * Clear the option that stores if the site has CAP's guest authors.
	 * This will enforce a new check in the next request.
	 * This will make sure we update the option if all guest authors are deleted.
	 */
	public static function clear_site_has_cap_guest_authors_check() {
		if ( self::site_has_cap_guest_authors() ) {
			delete_option( self::SITE_HAS_GUEST_AUTHORS_OPTION_NAME );
		}
	}

	/**
	 * Checks if the site has any guest authors. Will check it once in the database and store the result in an option.
	 *
	 * @return bool
	 */
	private static function site_has_cap_guest_authors() {
		$response = get_option( self::SITE_HAS_GUEST_AUTHORS_OPTION_NAME );

		// Only check in the database once.
		if ( false === $response ) {
			$query = new \WP_Query(
				[
					'post_type'      => 'guest-author',
					'posts_per_page' => 1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				]
			);
			$response = $query->have_posts() ? 'yes' : 'no';
			add_option( self::SITE_HAS_GUEST_AUTHORS_OPTION_NAME, $response, '', true );
		}

		return 'yes' === $response;
	}

	/**
	 * Override the capability required to assign a user as a co-author.
	 *
	 * @param string $edit_cap Capability required for a user to be assigned as a co-author.
	 */
	public static function coauthors_edit_author_cap( $edit_cap ) {
		return self::ASSIGNABLE_TO_POSTS_CAPABILITY_NAME;
	}

	/**
	 * Determines whether a user is only a "guest author", meaning it only has the custom role and no other role.
	 *
	 * In this case, the user won't be able to login and will have some features removed from their profile.
	 *
	 * Users who have more than one role other than non_edit_contributor are still able to login and a have a full profile.
	 *
	 * @param WP_User $user The user to check.
	 * @return bool
	 */
	private static function is_guest_author( WP_User $user ) {
		return 1 === count( $user->roles ) && self::CONTRIBUTOR_NO_EDIT_ROLE_NAME === current( $user->roles );
	}

	/**
	 * Determines whether a user has the guest contributor role.
	 *
	 * @param WP_User $user The user to check.
	 * @return bool
	 */
	private static function has_guest_contributor_role( WP_User $user ) {
		return in_array( self::CONTRIBUTOR_NO_EDIT_ROLE_NAME, $user->roles, true );
	}

	/**
	 * Prevent users from updating their account details in My Account, if they have the custom role.
	 */
	public static function prevent_myaccount_update() {
		$action = filter_input( INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( empty( $action ) || 'save_account_details' !== $action ) {
			return;
		}

		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$user = \get_user_by( 'id', $user_id );

		$is_contributor_no_edit = in_array( self::CONTRIBUTOR_NO_EDIT_ROLE_NAME, $user->roles );
		if ( $is_contributor_no_edit ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				/* translators: %s is the custom role name. */
				\wc_add_notice( sprintf( __( 'Can\'t update details of a "%s" user.', 'newspack-plugin' ), self::CONTRIBUTOR_NO_EDIT_ROLE_NAME ), 'error' );
			}
			return;
		}
	}

	/**
	 * Prevents the Delete Account email to be sent and display an error message to the user
	 *
	 * @param int $user_id The user ID trying to delete the account.
	 * @return void
	 */
	public static function before_delete_account( $user_id ) {
		if ( user_can( $user_id, self::ASSIGNABLE_TO_POSTS_CAPABILITY_NAME ) ) {
			\wp_safe_redirect(
				\add_query_arg(
					[
						'message'  => __( 'It looks like you are an author on this site. Please contact a site adminstrator to get your account deactivated.', 'newspack-plugin' ),
						'is_error' => true,
					],
					\remove_query_arg( WooCommerce_My_Account::DELETE_ACCOUNT_URL_PARAM )
				)
			);
			exit;
		}
	}

	/**
	 * Create the custom role and then add custom capability.
	 */
	public static function setup_custom_role_and_capability() {
		$current_settings_version = '2';
		if ( \get_option( self::SETTINGS_VERSION_OPTION_NAME ) === $current_settings_version ) {
			return;
		}

		// Update the custom role.
		remove_role( self::CONTRIBUTOR_NO_EDIT_ROLE_NAME );
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role
			self::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			__( 'Guest Contributor', 'newspack-plugin' ),
			[
				self::ASSIGNABLE_TO_POSTS_CAPABILITY_NAME => true,
			]
		);

		$wp_roles = wp_roles();
		foreach ( $wp_roles->roles as $role_name => $role ) {
			$role = $wp_roles->get_role( $role_name );
			if ( $role->has_cap( 'edit_posts' ) || $role_name === self::CONTRIBUTOR_NO_EDIT_ROLE_NAME ) {
				$role->add_cap( self::ASSIGNABLE_TO_POSTS_CAPABILITY_NAME );
			}
		}

		\update_option( self::SETTINGS_VERSION_OPTION_NAME, $current_settings_version );
	}

	/**
	 * Get dummy email domain.
	 */
	public static function get_dummy_email_domain() {
		/**
		 * Filters the domain used for generated Guest Contributor placeholder
		 * email addresses.
		 *
		 * This domain is also treated as unroutable by the outbound-mail guard:
		 * wp_mail() recipients on it are stripped, and mail addressed only to it
		 * is suppressed. Point it at a reserved, undeliverable domain (RFC 2606)
		 * only — on a real domain, mail to every address on it would be silently
		 * dropped site-wide.
		 *
		 * @param string $domain Placeholder email domain. Default 'example.com'.
		 */
		return \apply_filters( 'newspack_guest_author_email_domain', 'example.com' );
	}

	/**
	 * Is a dummy email address?
	 *
	 * The match is end-anchored so addresses on domains that merely contain
	 * the dummy domain as a prefix (e.g. user@example.company.com) are not
	 * mistaken for placeholders.
	 *
	 * @param string $email_address Email address to check.
	 */
	public static function is_dummy_email_address( $email_address ) {
		$suffix = strtolower( '@' . self::get_dummy_email_domain() );
		return str_ends_with( strtolower( trim( (string) $email_address ) ), $suffix );
	}

	/**
	 * Whether the outbound-mail guard should be active for an environment type.
	 *
	 * On local and development environments outbound mail terminates in a
	 * capture tool (e.g. MailHog) instead of bouncing, so suppression would
	 * only hide mail developers are trying to inspect. Everywhere else —
	 * including staging, which delivers real mail — the guard is active.
	 *
	 * Note: a production site whose WP_ENVIRONMENT_TYPE is set to 'local' or
	 * 'development' loses this protection.
	 *
	 * @param string $environment_type Environment type, as returned by wp_get_environment_type().
	 *
	 * @return bool
	 */
	public static function is_mail_guard_active_for_environment( string $environment_type ): bool {
		return ! in_array( $environment_type, [ 'local', 'development' ], true );
	}

	/**
	 * Register the outbound-mail guard filters. Registration is unconditional;
	 * whether anything is suppressed is decided per send by
	 * is_mail_guard_active(), so the activity filter stays reachable from any
	 * plugin, not only code that runs before this class loads.
	 *
	 * The short-circuit registers at priority 1 so it runs before mailer
	 * plugins' own pre_wp_mail callbacks. That protects the all-placeholder
	 * case against callbacks that honor a non-null incoming value (the
	 * well-behaved majority); a callback that ignores the incoming value can
	 * still dispatch and overwrite the result regardless of priority.
	 */
	public static function register_mail_guard(): void {
		\add_filter( 'pre_wp_mail', [ __CLASS__, 'short_circuit_dummy_only_email' ], 1, 2 );
		\add_filter( 'wp_mail', [ __CLASS__, 'remove_dummy_email_recipients' ], 10, 1 );
	}

	/**
	 * Whether the outbound-mail guard should suppress anything right now.
	 * Evaluated on every send, so the filter below can be hooked from any
	 * plugin, theme, or must-use plugin at any point before mail goes out.
	 * wp_get_environment_type() memoizes, so the per-call cost is negligible.
	 *
	 * @return bool
	 */
	public static function is_mail_guard_active(): bool {
		/**
		 * Filters whether the outbound-mail guard suppresses placeholder mail.
		 *
		 * Defaults to active everywhere except local and development
		 * environments (see is_mail_guard_active_for_environment()). Because
		 * this runs per send, any plugin can hook it — to force the guard back
		 * on for a site whose declared environment type would switch it off,
		 * or to switch it off for testing. The workspace's Docker mu-plugin
		 * disables it on dev containers so captured mail stays inspectable.
		 *
		 * @param bool $active Whether the guard suppresses placeholder mail.
		 */
		return (bool) \apply_filters( 'newspack_guest_author_mail_guard_active', self::is_mail_guard_active_for_environment( \wp_get_environment_type() ) );
	}

	/**
	 * Short-circuit wp_mail() when every recipient is a generated placeholder
	 * address. Returning true reports the email as sent without dispatching
	 * anything, so callers behave as if delivery succeeded.
	 *
	 * Core applies the 'wp_mail' filter before this one, so the recipient
	 * list seen here has already been stripped by
	 * remove_dummy_email_recipients() — which deliberately leaves an
	 * all-dummy list intact (absent Cc/Bcc) so it can be recognized and
	 * suppressed here.
	 *
	 * Scope notes: both guard callbacks no-op when is_mail_guard_active()
	 * says the guard is off. Mail whose headers carry Cc/Bcc recipients is
	 * never short-circuited — suppressing it would also suppress delivery to those
	 * (possibly real) header recipients. Dummy addresses inside Cc/Bcc headers
	 * are not scrubbed by this guard. Mailer plugins that take over delivery
	 * from their own pre_wp_mail callback still receive the stripped list —
	 * core applies the wp_mail filter before any pre_wp_mail callback. The
	 * priority-1 registration (see register_mail_guard()) protects the
	 * all-placeholder case only against callbacks that honor a non-null
	 * incoming value; one that ignores it can still dispatch regardless of
	 * priority. Plugins that replace the pluggable wp_mail() itself bypass
	 * both filters and this guard.
	 *
	 * @param null|bool $return Short-circuit return value.
	 * @param array     $atts   wp_mail() arguments.
	 *
	 * @return null|bool
	 */
	public static function short_circuit_dummy_only_email( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}
		if ( ! self::is_mail_guard_active() ) {
			return $return;
		}
		if ( empty( $atts['to'] ) ) {
			return $return;
		}
		if ( self::has_cc_or_bcc_headers( $atts['headers'] ?? '' ) ) {
			return $return;
		}
		$recipients = self::parse_recipients( $atts['to'] );
		if ( ! empty( $recipients ) && empty( self::remove_dummy_addresses( $recipients ) ) ) {
			Logger::newspack_log(
				self::MAIL_GUARD_LOG_CODE,
				'Suppressed an outbound email: every recipient is a generated placeholder address.',
				[
					'mode'       => 'suppressed_all',
					'count'      => count( $recipients ),
					'recipients' => array_slice( array_map( [ __CLASS__, 'extract_address' ], $recipients ), 0, 20 ),
				],
				'info'
			);
			return true;
		}
		return $return;
	}

	/**
	 * Remove generated placeholder addresses from a wp_mail() recipient list,
	 * so mixed recipient lists still reach their real recipients. The value
	 * (and its string-vs-array type) is left untouched unless a placeholder
	 * was actually removed.
	 *
	 * @param array $args wp_mail() arguments.
	 *
	 * @return array
	 */
	public static function remove_dummy_email_recipients( $args ) {
		if ( ! self::is_mail_guard_active() ) {
			return $args;
		}
		if ( empty( $args['to'] ) ) {
			return $args;
		}
		$recipients = self::parse_recipients( $args['to'] );
		$filtered   = self::remove_dummy_addresses( $recipients );
		if ( count( $filtered ) === count( $recipients ) ) {
			// Nothing removed — leave the value (and its type) untouched.
			return $args;
		}
		if ( ! empty( $filtered ) ) {
			$removed = array_values( array_map( [ __CLASS__, 'extract_address' ], array_diff( $recipients, $filtered ) ) );
			Logger::newspack_log(
				self::MAIL_GUARD_LOG_CODE,
				'Removed generated placeholder recipient(s) from an outbound email.',
				[
					'mode'       => 'stripped_mixed',
					'count'      => count( $removed ),
					'recipients' => array_slice( $removed, 0, 20 ),
				],
				'info'
			);
			$args['to'] = $filtered;
			return $args;
		}
		// Every recipient was a placeholder. Core applies this filter BEFORE
		// pre_wp_mail, so when the mail has no other (Cc/Bcc) recipients the
		// list must be left as-is for short_circuit_dummy_only_email() to
		// suppress the send; emptying it here would make wp_mail() error out
		// instead of reporting success. With Cc/Bcc present the mail proceeds
		// to those recipients only.
		if ( self::has_cc_or_bcc_headers( $args['headers'] ?? '' ) ) {
			Logger::newspack_log(
				self::MAIL_GUARD_LOG_CODE,
				'Removed a placeholder-only recipient list from an outbound email; delivering to its Cc/Bcc recipients only.',
				[
					'mode'       => 'stripped_to_cc_bcc',
					'count'      => count( $recipients ),
					'recipients' => array_slice( array_map( [ __CLASS__, 'extract_address' ], $recipients ), 0, 20 ),
				],
				'info'
			);
			$args['to'] = [];
		}
		return $args;
	}

	/**
	 * Whether a wp_mail() headers value carries Cc or Bcc recipients.
	 *
	 * Recipients are counted at the same level core parses them: the header
	 * value split on commas, empty tokens dropped. A header whose value is
	 * empty or all separators ("Cc:", "Cc: ,") carries no recipients and does
	 * not count — treating it as recipients would route an all-placeholder
	 * send past the short-circuit into a hard wp_mail() failure. A non-empty
	 * token that is not a valid address still counts: the caller explicitly
	 * addressed someone, and core's own failure behavior is the right feedback
	 * there.
	 *
	 * @param string|string[] $headers Headers, as a string or array of lines.
	 *
	 * @return bool
	 */
	private static function has_cc_or_bcc_headers( $headers ): bool {
		$lines = is_array( $headers ) ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^\s*b?cc\s*:(.*)$/i', (string) $line, $matches ) ) {
				continue;
			}
			foreach ( explode( ',', $matches[1] ) as $token ) {
				if ( '' !== trim( $token ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Normalize a wp_mail() recipient value into a clean list: split
	 * comma-separated strings, trim, and drop empty entries.
	 *
	 * @param string|string[] $to Recipients.
	 *
	 * @return string[]
	 */
	private static function parse_recipients( $to ): array {
		$recipients = is_array( $to ) ? $to : explode( ',', (string) $to );
		$recipients = array_map(
			function ( $recipient ) {
				return trim( (string) $recipient );
			},
			$recipients
		);
		return array_values(
			array_filter(
				$recipients,
				function ( $recipient ) {
					return '' !== $recipient;
				}
			)
		);
	}

	/**
	 * Filter dummy addresses out of a normalized recipient list. Entries may
	 * be bare addresses or "Name <address>".
	 *
	 * @param string[] $recipients Recipients.
	 *
	 * @return string[] Recipients that are not dummy addresses.
	 */
	private static function remove_dummy_addresses( array $recipients ): array {
		return array_values(
			array_filter(
				$recipients,
				function ( $recipient ) {
					return ! self::is_dummy_email_address( self::extract_address( $recipient ) );
				}
			)
		);
	}

	/**
	 * Extract the dispatch address from a recipient entry, which may be a bare
	 * address or "Name <address>". Uses the same greedy pattern as core's
	 * wp_mail() recipient parsing (wp-includes/pluggable.php), so the address
	 * judged here is the address core will actually dispatch to — even when a
	 * quoted display name itself contains angle brackets.
	 *
	 * @param string $recipient Recipient entry.
	 *
	 * @return string Bare email address.
	 */
	private static function extract_address( $recipient ): string {
		if ( preg_match( '/(.*)<(.+)>/', (string) $recipient, $matches ) ) {
			return trim( $matches[2] );
		}
		return trim( (string) $recipient );
	}

	/**
	 * Create a placeholder/dummy email address.
	 *
	 * @param WP_User|string $user_or_name The user, or just the name.
	 */
	public static function get_dummy_email_address( $user_or_name ) {
		$email_domain = self::get_dummy_email_domain();
		// Strip @ from the login — it may contain @ from legacy migrations, which produces
		// a double-@ dummy email that sanitize_email() mangles into a non-detectable format.
		if ( is_string( $user_or_name ) ) {
			$login = str_replace( '@', '', $user_or_name );
			return $login . '@' . $email_domain;
		}
		$login = str_replace( '@', '', $user_or_name->user_login );
		return $login . '@' . $email_domain;
	}

	/**
	 * Filters user validation to allow empty emails for guest authors
	 *
	 * When creating a new user, also automatically generate a username from the display name.
	 *
	 * @param WP_Error $errors WP_Error object (passed by reference).
	 * @param bool     $update Whether this is a user update.
	 * @param stdClass $user   User object (passed by reference).
	 * @return WP_Error
	 */
	public static function user_profile_update_errors( $errors, $update, $user ) {

		if ( ! isset( $user->role ) || self::CONTRIBUTOR_NO_EDIT_ROLE_NAME !== $user->role ) {
			return $errors;
		}

		if ( ! empty( $errors->errors['empty_email'] ) ) {
			$errors->remove( 'empty_email' );
		}

		if ( ! empty( $errors->errors['user_login'] ) ) {
			$errors->remove( 'user_login' );
		}

		// Since WordPress 7.0.3, edit_user() validates the submitted email at
		// assignment, so an empty field adds invalid_email before this action
		// fires. Clear it only when the submission is genuinely empty, judged
		// on the raw value: input that only sanitization would empty (stray
		// markup, an address pasted with angle brackets, a non-string
		// payload) keeps failing validation like any other malformed entry.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every edit_user() caller verifies a nonce first (user-new.php, user-edit.php, profile.php, wp_ajax_add_user()); the value is only compared with the empty string, never stored or output.
		$is_empty_email = isset( $_POST['email'] ) && is_string( $_POST['email'] ) && '' === trim( wp_unslash( $_POST['email'] ) );
		if ( $is_empty_email && ! empty( $errors->errors['invalid_email'] ) ) {
			$errors->remove( 'invalid_email' );
		}

		// We still don't want users with duplicate emails.
		if ( ! empty( $errors->errors['email_exists'] ) ) {
			return $errors;
		}

		if ( ! $update ) {
			// For guest authors, the form is modified via JS and we get the display name in the username field.
			// Get the original display name from POST data (before WordPress sanitizes it).
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification happens in wp-admin/user-new.php before this hook.
			$original_display_name = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : $user->user_login;

			// Generate sanitized username from the original display name.
			$user->user_login = self::generate_username( $original_display_name );

			// Set display name to the sanitized value (preserves accents but removes HTML/scripts).
			$user->display_name = $original_display_name;
		}

		if ( empty( $user->user_email ) ) {
			// Create a placeholder email address to avoid any issues with empty emails.
			$user->user_email = self::get_dummy_email_address( $user );
		}

		return $errors;
	}

	/**
	 * Generates a unique username from a display name.
	 *
	 * @param string $display_name The user's display name.
	 * @return string
	 */
	public static function generate_username( $display_name ) {
		$username = \sanitize_user( $display_name, true );
		$username = \sanitize_title( $username );

		while ( \username_exists( $username ) ) {
			$username = $username . '-' . \wp_rand( 1, 100 );
		}

		return $username;
	}

	/**
	 * Enqueues the JS that modifies the user profile and user creation forms.
	 *
	 * @return void
	 */
	public static function admin_footer() {
		global $pagenow;
		if ( ! in_array( $pagenow, [ 'user-edit.php','user-new.php' ] ) ) {
			return;
		}
		\wp_enqueue_script(
			'newspack-co-authors-plus',
			Newspack::plugin_url() . '/dist/other-scripts/co-authors-plus.js',
			[ 'jquery' ],
			Newspack::asset_version( 'other-scripts/co-authors-plus' ),
			true
		);

		wp_localize_script(
			'newspack-co-authors-plus',
			'guestAuthorRole',
			[
				'role'             => self::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
				'displayNameLabel' => __( 'Display name', 'newspack-plugin' ),
				'screen'           => $pagenow === 'user-new.php' ? 'new' : 'edit',
			]
		);
	}

	/**
	 * A generic callback applied to filters that check if a user has access to a feature, or if a certain field should be displayed in its profile.
	 *
	 * These callbacks pass the return of the check as the first argument ant the user or user ID as the second.
	 *
	 * @param bool        $result The result of the check.
	 * @param int|WP_User $user A user ID or user object.
	 * @return bool
	 */
	public static function disable_feature( $result, $user ) {
		if ( is_int( $user ) ) {
			$user = \get_user_by( 'id', $user );
		}

		if ( ! is_a( $user, 'WP_User' ) ) {
			return $result;
		}

		if ( self::is_guest_author( $user ) ) {
			return false;
		}

		return $result;
	}

	/**
	 * Filters user authentication to prevent guest authors from logging in.
	 *
	 * @param WP_Error|WP_User $user The logged in user or login error.
	 * @param string           $password The user's password.
	 * @return WP_Error|WP_User
	 */
	public static function wp_authenticate_user( $user, $password ) {
		if ( ! is_a( $user, 'WP_User' ) ) {
			return $user;
		}

		if ( self::is_guest_author( $user ) ) {
			return new \WP_Error( 'guest_authors_cannot_login', __( 'Guest Contributors cannot login.', 'newspack-plugin' ) );
		}

		return $user;
	}

	/**
	 * Adds a replacement Guest Authors menu item.
	 */
	public static function guest_author_menu_replacement() {
		add_submenu_page(
			'users.php',
			__( 'Guest Authors', 'newspack-plugin' ),
			__( 'Guest Authors', 'newspack-plugin' ),
			'list_users',
			'newspack-view-guest-authors',
			[ __CLASS__, 'render_guest_authors_replacement_page' ]
		);
	}

	/**
	 * Render the replacement Guest Authors page.
	 */
	public static function render_guest_authors_replacement_page() {
		?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'Guest Authors', 'newspack-plugin' ); ?></h1>

				<p><?php echo esc_html__( "Co-Authors-Plus' Guest Authors are disabled in this site. Use the Guest Contributor user role instead.", 'newspack-plugin' ); ?></p>
				<p><?php echo esc_html__( 'You can use one of the shortcuts below:', 'newspack-plugin' ); ?></p>

				<a href="<?php echo esc_url( admin_url( 'user-new.php?role=' . self::CONTRIBUTOR_NO_EDIT_ROLE_NAME ) ); ?>" class="page-title-action"><?php echo esc_html__( 'Create a new Guest Contributor', 'newspack-plugin' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'users.php?role=' . self::CONTRIBUTOR_NO_EDIT_ROLE_NAME ) ); ?>" class="page-title-action"><?php echo esc_html__( 'View all Guest Contributors', 'newspack-plugin' ); ?></a>
			</div>
		<?php
	}

	/**
	 * Save custom fields.
	 *
	 * @param int $user_id User ID.
	 */
	public static function edit_user_profile_update( $user_id ) {
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( self::is_guest_author( $user ) ) {
			return;
		}

		if ( ! empty( $_POST['newspack_cap_custom_cap_option'] ) ) {
			$user->add_role( self::CONTRIBUTOR_NO_EDIT_ROLE_NAME );
		} else {
			$user->remove_role( self::CONTRIBUTOR_NO_EDIT_ROLE_NAME );
		}
	}

	/**
	 * Add user profile fields.
	 *
	 * @param WP_User $user The current WP_User object.
	 */
	public static function edit_user_profile( $user ) {

		if ( self::is_guest_author( get_userdata( $user->ID ) ) ) { // For some reason $user is not the full user object.
			return;
		}
		$current_status = user_can( $user->ID, self::CONTRIBUTOR_NO_EDIT_ROLE_NAME );
		?>
		<div class="newspack-plugin-cap-options">

			<h2><?php echo esc_html__( 'Co-Authors Plus Options', 'newspack-plugin' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr class="user-newspack_cap_custom_cap_option-wrap">
					<th scope="row">
						<?php esc_html_e( 'Enable as coauthor', 'newspack-plugin' ); ?>
					</th>
					<td>
						<label for="newspack_cap_custom_cap_option">
							<input type="checkbox" name="newspack_cap_custom_cap_option" id="newspack_cap_custom_cap_option" value="1" <?php checked( $current_status ); ?> />
							<?php esc_html_e( 'Allow this user to be assigned as a co-author of a post.', 'newspack-plugin' ); ?>
						</label>
						<p class="description">
						<?php
							esc_html_e( 'If this option is checked, the "Guest Contributor" role will be added to the user (on top of their current role) and they will be able to be assigned as a co-author of a post even if they are not allowed to edit posts. For users with edit access, this option has no effect.', 'newspack-plugin' );
						?>
						</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Is creating a new user with the no-edit role?
	 */
	public static function is_adding_user_with_no_edit_role() {
		global $pagenow;
		return $pagenow === 'user-new.php' && isset( $_GET['role'] ) && $_GET['role'] === self::CONTRIBUTOR_NO_EDIT_ROLE_NAME; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Filter the `default_role` option value.
	 *
	 * @param string $default_role The default role slug.
	 */
	public static function create_user_default_role( $default_role ) {
		if ( self::is_adding_user_with_no_edit_role() ) {
			return self::CONTRIBUTOR_NO_EDIT_ROLE_NAME;
		}
		return $default_role;
	}

	/**
	 * Handle the `capability-manager-enhanced` plugin - disable multi-role UI if adding a new no-edit role user.
	 *
	 * @param bool $value Whether to enable the multi-role UI.
	 */
	public static function cme_capabilities_add_user_multi_roles( $value ) {
		if ( self::is_adding_user_with_no_edit_role() ) {
			return false;
		}
		return $value;
	}

	/**
	 * Hide the author email on the frontend if it's a placeholder email.
	 *
	 * @param bool $value Whether to show the author email.
	 */
	public static function should_display_author_email( $value ) {
		global $coauthors_plus;
		// This filter is onl used to occasionally hide the email, so if the value is already false, just return it.
		if ( ! $value ) {
			return $value;
		}

		$is_coauthors_plus_active = is_object( $coauthors_plus ) && method_exists( $coauthors_plus, 'search_authors' );

		if ( is_author() || ( is_singular() && ! $is_coauthors_plus_active ) ) {
			$author_id = get_the_author_meta( 'ID' );
			$user = get_userdata( $author_id );

			if ( $user && self::has_guest_contributor_role( $user ) && self::is_dummy_email_address( $user->user_email ) ) {
				return false;
			}
		}

		return $value;
	}

	/**
	 * Hide the author email on the frontend if it's a placeholder email.
	 *
	 * @param bool $value Whether to show the author email.
	 * @param int  $user_id The user ID.
	 */
	public static function should_display_coauthor_email( $value, $user_id ) {
		if ( ! $value ) {
			return $value;
		}

		$user = get_userdata( $user_id );
		if ( $user && self::has_guest_contributor_role( $user ) && self::is_dummy_email_address( $user->user_email ) ) {
			return false;
		}
		return $value;
	}

	/**
	 * Modify the REST API user query to include users with the custom capability.
	 *
	 * @param array           $args    The query arguments.
	 * @param WP_REST_Request $request The request object.
	 * @return array Modified query arguments.
	 */
	public static function filter_rest_user_query( $args, $request ) {
		if ( isset( $args['who'] ) && $args['who'] === 'authors' && current_user_can( 'list_users' ) ) {
			unset( $args['who'] );
			$args['capability__in'] = [ 'edit_posts', self::ASSIGNABLE_TO_POSTS_CAPABILITY_NAME ];
		}
		return $args;
	}

	/**
	 * Add Guest Contributor to the Author List block.
	 *
	 * @param array $roles The list of roles.
	 * @return array Modified list of roles.
	 */
	public static function add_guest_contributor_to_authors_blocks( $roles ) {
		$roles[] = [
			'slug'  => self::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			'label' => __( 'Guest Contributor', 'newspack-plugin' ),
		];
		return $roles;
	}
}
Guest_Contributor_Role::initialize();
