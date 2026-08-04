<?php
/**
 * Customisable emails.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Reader-revenue related emails.
 */
class Emails {
	const POST_TYPE              = 'newspack_rr_email'; // "Reader Revenue" emails, for legacy reasons.
	const EMAIL_CONFIG_NAME_META = 'newspack_email_type'; // "type" for legacy reasons.

	/**
	 * Request-scoped cache for `get_email_configs()`. Null = uncached.
	 *
	 * @var array|null
	 */
	private static $email_configs_cache = null;

	/**
	 * Reset the `get_email_configs()` request-scoped cache. Call after
	 * mutating the `newspack_email_configs` filter mid-request (rare in
	 * production; tests use it to assert per-filter-callback behavior
	 * without bleeding state across assertions).
	 *
	 * @return void
	 */
	public static function reset_email_configs_cache(): void {
		self::$email_configs_cache = null;
	}

	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'init', [ __CLASS__, 'register_sender_settings' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_api_endpoints' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );
		add_filter( 'newspack_newsletters_email_editor_cpts', [ __CLASS__, 'register_email_cpt_with_email_editor' ] );
		add_filter( 'newspack_newsletters_allowed_editor_actions', [ __CLASS__, 'register_scripts_enqueue_with_email_editor' ] );
		add_action( 'update_option_theme_mods_' . ( wp_get_theme()->parent() ? get_stylesheet() : get_template() ), [ __CLASS__, 'maybe_update_email_templates' ], 10, 2 );
		add_action( 'admin_head', [ __CLASS__, 'inject_dynamic_email_template_styles' ] );
	}

	/**
	 * Register the custom post type as edited-as-email.
	 *
	 * @param array $email_editor_cpts Post type which should be edited as emails.
	 * @codeCoverageIgnore
	 */
	public static function register_email_cpt_with_email_editor( $email_editor_cpts ) {
		$email_editor_cpts[] = self::POST_TYPE;
		return $email_editor_cpts;
	}

	/**
	 * Register the editor scripts as allowed when editing email.
	 *
	 * @param array $allowed_actions Actions allowed when enqueuing assets for the block editor.
	 * @codeCoverageIgnore
	 */
	public static function register_scripts_enqueue_with_email_editor( $allowed_actions ) {
		$allowed_actions[] = __CLASS__ . '::enqueue_block_editor_assets';
		return $allowed_actions;
	}

	/**
	 * Register the custom post type for emails.
	 *
	 * @codeCoverageIgnore
	 */
	public static function register_cpt() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$labels = [
			'name'                     => _x( 'Newspack Emails', 'post type general name', 'newspack-plugin' ),
			'singular_name'            => _x( 'Newspack Email', 'post type singular name', 'newspack-plugin' ),
			'menu_name'                => _x( 'Newspack Emails', 'admin menu', 'newspack-plugin' ),
			'name_admin_bar'           => _x( 'Newspack Email', 'add new on admin bar', 'newspack-plugin' ),
			'add_new'                  => _x( 'Add New', 'popup', 'newspack-plugin' ),
			'add_new_item'             => __( 'Add New Newspack Email', 'newspack-plugin' ),
			'new_item'                 => __( 'New Newspack Email', 'newspack-plugin' ),
			'edit_item'                => __( 'Edit Newspack Email', 'newspack-plugin' ),
			'view_item'                => __( 'View Newspack Email', 'newspack-plugin' ),
			'all_items'                => __( 'All Newspack Emails', 'newspack-plugin' ),
			'search_items'             => __( 'Search Newspack Emails', 'newspack-plugin' ),
			'parent_item_colon'        => __( 'Parent Newspack Emails:', 'newspack-plugin' ),
			'not_found'                => __( 'No Newspack Emails found.', 'newspack-plugin' ),
			'not_found_in_trash'       => __( 'No Newspack Emails found in Trash.', 'newspack-plugin' ),
			'items_list'               => __( 'Newspack Emails list', 'newspack-plugin' ),
			'item_published'           => __( 'Newspack Email published', 'newspack-plugin' ),
			'item_published_privately' => __( 'Newspack Email published privately', 'newspack-plugin' ),
			'item_reverted_to_draft'   => __( 'Newspack Email reverted to draft', 'newspack-plugin' ),
			'item_scheduled'           => __( 'Newspack Email scheduled', 'newspack-plugin' ),
			'item_updated'             => __( 'Newspack Email updated', 'newspack-plugin' ),
		];

		\register_post_type(
			self::POST_TYPE,
			[
				'public'       => false,
				'labels'       => $labels,
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'supports'     => [ 'editor', 'title', 'custom-fields' ],
				'taxonomies'   => [],
			]
		);
	}

	/**
	 * Load up common JS/CSS for newsletter editor.
	 *
	 * @codeCoverageIgnore
	 */
	public static function enqueue_block_editor_assets() {
		if ( get_post_type() !== self::POST_TYPE ) {
			return;
		}
		Newspack::load_common_assets();
		$handle = 'revenue-email-editor';
		\wp_register_script(
			$handle,
			Newspack::plugin_url() . '/dist/other-scripts/emails.js',
			[],
			Newspack::asset_version( 'other-scripts/emails' ),
			true
		);
		\wp_localize_script(
			$handle,
			'newspack_emails',
			[
				'current_user_email'     => wp_get_current_user()->user_email,
				'configs'                => self::get_email_configs(),
				'email_config_name_meta' => self::EMAIL_CONFIG_NAME_META,
				'from_name'              => self::get_from_name(),
				'from_email'             => self::get_from_email(),
				'reply_to_email'         => self::get_reply_to_email(),
			]
		);
		wp_enqueue_script( $handle );

		\wp_register_style(
			$handle,
			Newspack::plugin_url() . '/dist/other-scripts/emails.css',
			[],
			Newspack::asset_version( 'other-scripts/emails' )
		);
		\wp_style_add_data( $handle, 'rtl', 'replace' );
		\wp_enqueue_style( $handle );
	}

	/**
	 * Register custom fields.
	 *
	 * @codeCoverageIgnore
	 */
	public static function register_meta() {
		\register_meta(
			'post',
			self::EMAIL_CONFIG_NAME_META,
			[
				'object_subtype' => self::POST_TYPE,
				'show_in_rest'   => true,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);
	}

	/**
	 * Get email payload by config name.
	 *
	 * @param string $config_name Name of email config.
	 * @param array  $placeholders Placeholders to replace in email.
	 */
	public static function get_email_payload( $config_name, $placeholders = [] ) {
		$email_config   = self::get_email_config_by_type( $config_name );
		$html           = $email_config['html_payload'];
		$reply_to_email = $email_config['reply_to_email'];
		$site_address   = '';

		if ( class_exists( 'WC' ) ) {
			$base_address  = WC()->countries->get_base_address();
			$base_city     = WC()->countries->get_base_city();
			$base_postcode = WC()->countries->get_base_postcode();
		} else {
			$base_address  = get_option( 'woocommerce_store_address', '' );
			$base_city     = get_option( 'woocommerce_store_city', '' );
			$base_postcode = get_option( 'woocommerce_store_postcode', '' );
		}

		if ( $base_address ) {
			if ( ! $base_city && ! $base_postcode ) {
				$site_address = $base_address;
			} else {
				$site_address = sprintf(
					// translators: formatted store address where 1 is street address, 2 is city, and 3 is postcode.
					__( '%1$s, %2$s %3$s', 'newspack-plugin' ),
					$base_address,
					$base_city,
					$base_postcode
				);
			}
		}

		if ( $site_address ) {
			$site_contact = sprintf(
				/* Translators: 1: site title 2: site base address. */
				__( '%1$s — %2$s', 'newspack-plugin' ),
				'<strong>' . get_bloginfo( 'name' ) . '</strong>',
				$site_address
			);
		} else {
			$site_contact = get_bloginfo( 'name' );
		}

		$placeholders = array_merge(
			[
				[
					'template' => '*CONTACT_EMAIL*',
					'value'    => sprintf( '<a href="mailto:%s">%s</a>', $reply_to_email, $reply_to_email ),
				],
				[
					'template' => '*SITE_ADDRESS*',
					'value'    => $site_address,
				],
				[
					'template' => '*SITE_CONTACT*',
					'value'    => $site_contact,
				],
				[
					'template' => '*SITE_LOGO*',
					'value'    => esc_url( wp_get_attachment_url( get_theme_mod( 'custom_logo' ) ) ),
				],
				[
					'template' => '*SITE_TITLE*',
					'value'    => get_bloginfo( 'name' ),
				],
				[
					'template' => '*SITE_URL*',
					'value'    => get_bloginfo( 'wpurl' ),
				],
			],
			$placeholders
		);
		foreach ( $placeholders as $value ) {
			$html = str_replace(
				$value['template'],
				$value['value'],
				$html
			);
		}
		return $html;
	}

	/**
	 * Send an HTML email.
	 *
	 * AUTO-SEND path — triggered by site events (registration, payment,
	 * subscription renewal, etc.). Requires the email's underlying
	 * post to be in `publish` status: an inactive/draft email must
	 * not fire on triggered events. For the deliberate admin
	 * preview operation, see {@see self::send_test_email()} —
	 * NPPD-1547 split the two entry points because the shared
	 * `'publish' === status` guard incidentally blocked test-send
	 * for inactive emails, conflating auto-trigger gating with
	 * admin-deliberate-action semantics.
	 *
	 * @param string|int $config_name Email config name (string), or
	 *                                Email post ID (int) for the
	 *                                post-id path shared with
	 *                                send_test_email.
	 * @param string     $to          Recipient's email address.
	 * @param array      $placeholders Dynamic content substitutions.
	 * @return bool wp_mail() result, or false if any prerequisite failed.
	 */
	public static function send_email( $config_name, $to, $placeholders = [] ) {
		if ( ! self::supports_emails() ) {
			return false;
		}

		// Normalize numeric-string post IDs to int BEFORE the
		// gettype()-based branching below. Without this, a caller
		// passing a digit-only string (e.g. from $_POST, post meta,
		// or a cast-naive integration) would be routed to the
		// string-name branch and silently fail because
		// get_email_config_by_type() looks up type names, not IDs.
		// Only digit-only strings are normalized — sender-name-style
		// strings stay strings.
		if ( is_string( $config_name ) && '' !== $config_name && ctype_digit( $config_name ) ) {
			$config_name = (int) $config_name;
		}

		self::maybe_run_ras_acc_template_migration();

		// Switch locale around the FULL send operation, not just
		// dispatch. get_email_config_by_type's lazy-create path
		// requires a template PHP file with __() calls; the locale
		// active at that moment determines what gets persisted to
		// the email post. Restoring symmetry via try/finally
		// guarantees the locale is restored even if a wp_mail filter
		// throws.
		$switched_locale = \switch_to_locale( \get_user_locale( \wp_get_current_user() ) );
		try {
			if ( 'string' === gettype( $config_name ) ) {
				// String path: looked up by type name. Used by every
				// production auto-trigger (RAS verification, magic
				// links, payment receipts, etc.). No HTML-payload check
				// — the string path uses template files, not stored
				// post content.
				$email_config = self::get_email_config_by_type( $config_name );
				if ( ! $to || ! $email_config || 'publish' !== $email_config['status'] ) {
					return false;
				}
			} elseif ( 'integer' === gettype( $config_name ) ) {
				// Post-id path: shared with send_test_email() via
				// validate_send_prerequisites(). Auto-send additionally
				// requires 'publish' status; test-send does not.
				$resolved = self::validate_send_prerequisites( $config_name, $to );
				if ( is_wp_error( $resolved ) ) {
					return false;
				}
				if ( 'publish' !== $resolved['config']['status'] ) {
					return false;
				}
				$email_config = $resolved['config'];
				$config_name  = $resolved['name'];
			} else {
				return false;
			}

			return self::dispatch_email( $email_config, $config_name, $to, $placeholders );
		} finally {
			if ( $switched_locale ) {
				\restore_previous_locale();
			}
		}
	}

	/**
	 * Run the v1 RAS-ACC email-templates migration once per request if
	 * it hasn't already completed. Extracted from `send_email()` so
	 * `send_test_email()` runs the same migration — both entry points
	 * are reachable from a fresh-install admin's first interaction
	 * with the email system, and the migration is harmless to run on
	 * any path (it's option-gated and idempotent).
	 *
	 * Trashing un-modified templates forces them to be regenerated
	 * from the current source on the next read, picking up RAS-ACC
	 * template updates without overwriting publisher edits.
	 */
	private static function maybe_run_ras_acc_template_migration() {
		if ( 'v1' === get_option( 'newspack_email_templates_migrated', '' ) ) {
			return;
		}
		$migrated  = true;
		$templates = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			]
		);

		foreach ( $templates as $template ) {
			$publish_date       = get_the_date( 'Y-m-d H:i:s', $template->ID );
			$last_modified_date = get_the_modified_date( 'Y-m-d H:i:s', $template->ID );

			// Template has not been modified, so trash the post so we can trigger a template update.
			if ( $publish_date === $last_modified_date ) {
				if ( ! wp_trash_post( $template->ID ) ) {
					// Flag the migration as failed so we can trigger another attempt later.
					$migrated = false;
				}
			}
		}

		if ( $migrated ) {
			update_option( 'newspack_email_templates_migrated', 'v1' );
		}
	}

	/**
	 * Send a test email for the given post.
	 *
	 * TEST-SEND path — the deliberate admin operation of previewing
	 * an email's actual rendered output. Distinct from the auto-send
	 * path ({@see self::send_email()}) which fires on triggered
	 * events and requires the email to be in `publish` status.
	 *
	 * NPPD-1547: before this split, test-send went through send_email()
	 * and inherited its `'publish' === status` guard, blocking
	 * test-send for inactive emails. That conflation was incidental
	 * — git blame shows the guard was introduced for the auto-send
	 * path with no test-send-specific design intent. Splitting the
	 * entry points makes the distinction explicit and means the
	 * test-send code path skips ONLY the draft/publish gate,
	 * retaining every other prerequisite plus an explicit trash
	 * exclusion (a trashed post is intentionally removed, not
	 * "inactive").
	 *
	 * Includes its own `current_user_can( 'manage_options' )` check
	 * even though the only current caller (api_send_test_email) is
	 * already behind a REST permission_callback. send_test_email is
	 * `public static` and the surface invites future internal
	 * callers; locking the cap check inside the entry point
	 * guarantees no path bypasses it.
	 *
	 * @param int    $post_id Email post id.
	 * @param string $to      Recipient email address.
	 * @return true|WP_Error True on successful dispatch; WP_Error
	 *                       with appropriate status code on failure.
	 */
	public static function send_test_email( $post_id, $to ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_emails_forbidden',
				esc_html__( 'You cannot send test emails.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}

		self::maybe_run_ras_acc_template_migration();

		$switched_locale = \switch_to_locale( \get_user_locale( \wp_get_current_user() ) );
		try {
			$resolved = self::validate_send_prerequisites( $post_id, $to );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			// Trash exclusion. The status-gate skip is for draft/pending
			// (the "inactive but still being edited" case); a trashed
			// post is intentionally removed from publication and
			// shouldn't be sendable even by an admin's deliberate
			// action. Restoring from trash is a one-click admin
			// operation if the publisher really wants to test-send
			// a trashed post.
			if ( 'trash' === ( $resolved['config']['status'] ?? '' ) ) {
				return new \WP_Error(
					'newspack_emails_post_trashed',
					esc_html__( 'Cannot test-send a trashed email. Restore the email first.', 'newspack-plugin' ),
					[ 'status' => 409 ]
				);
			}

			$sent = self::dispatch_email( $resolved['config'], $resolved['name'], $to, [] );
			if ( ! $sent ) {
				return new \WP_Error(
					'newspack_emails_test_dispatch_failed',
					esc_html__( 'Test email could not be dispatched. Check your mail configuration.', 'newspack-plugin' ),
					[ 'status' => 500 ]
				);
			}
			return true;
		} finally {
			if ( $switched_locale ) {
				\restore_previous_locale();
			}
		}
	}

	/**
	 * Whether a value is shaped like a positive integer post id: an int,
	 * or a non-empty digit-only string. Deliberately stricter than
	 * is_numeric()/absint(), both of which accept '1.5', '1e3',
	 * '1.7e308', '-5' and silently coerce them to a different (or wrong)
	 * id. Shared by validate_send_prerequisites() (direct-PHP guard) and
	 * the REST route's validate_callback (raw-input guard, runs before
	 * absint sanitization can mask a malformed value).
	 *
	 * @param mixed $value Raw post-id value.
	 * @return bool True when $value is an int or digit-only string > 0.
	 */
	private static function is_positive_int_shaped( $value ) {
		$is_int_shaped = is_int( $value )
			|| ( is_string( $value ) && '' !== $value && ctype_digit( $value ) );
		return $is_int_shaped && (int) $value > 0;
	}

	/**
	 * REST `validate_callback` for the test-send `post_id` arg. Runs on
	 * the RAW request value before the `absint` sanitize_callback, so a
	 * malformed shape ('1.5', '1e3', '-5') is rejected with the same
	 * `newspack_emails_invalid_post_id` error the direct-PHP path
	 * returns, instead of being coerced into a valid-but-wrong id.
	 *
	 * @param mixed $value Raw param value.
	 * @return true|WP_Error True when valid, WP_Error otherwise.
	 */
	public static function validate_test_send_post_id( $value ) {
		if ( ! self::is_positive_int_shaped( $value ) ) {
			return new \WP_Error(
				'newspack_emails_invalid_post_id',
				esc_html__( 'A valid email post ID is required.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * Validate the shared prerequisites for sending an email via the
	 * post-id path. Used by both send_email()'s post-id branch and
	 * send_test_email() so future shared guards (rate limiting, etc.)
	 * land in one place.
	 *
	 * Checks (in order, cheapest first):
	 * - $post_id is a positive integer
	 * - $to is non-empty AND a valid email per is_email()
	 * - Newspack Newsletters plugin is active
	 * - Post resolves to a config with HTML payload
	 * - Resolved config_name (EMAIL_CONFIG_NAME_META) is non-empty
	 *
	 * DOES NOT check post status — the caller layers its own status
	 * check on top when appropriate. send_email() requires
	 * `'publish'`; send_test_email() excludes only `'trash'`.
	 *
	 * Error codes use the `newspack_emails_*` prefix consistently so
	 * downstream consumers can group/filter on the namespace.
	 *
	 * @param int    $post_id Email post id.
	 * @param string $to      Recipient email address.
	 * @return array|WP_Error On success, [ 'config' => array,
	 *                        'name' => string ]. On failure, WP_Error
	 *                        with `status` data field set to an
	 *                        appropriate HTTP code.
	 */
	private static function validate_send_prerequisites( $post_id, $to ) {
		// Caller-input checks first (cheap, common failure modes).
		// is_numeric() is too loose — accepts '1.5', '1e3', '1.7e308'
		// which then (int)-cast to 1, 1000, or PHP_INT_MAX silently,
		// potentially resolving the wrong post. Require an actual
		// integer-shaped value (int type OR a digit-only string),
		// then normalize to int so downstream calls operate on a
		// well-typed positive integer.
		if ( ! self::is_positive_int_shaped( $post_id ) ) {
			return new \WP_Error(
				'newspack_emails_invalid_post_id',
				esc_html__( 'A valid email post ID is required.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$post_id = (int) $post_id;
		if ( empty( $to ) ) {
			return new \WP_Error(
				'newspack_emails_empty_recipient',
				esc_html__( 'A recipient is required.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		if ( ! is_email( $to ) ) {
			return new \WP_Error(
				'newspack_emails_invalid_recipient',
				esc_html__( 'Recipient must be a valid email address.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		// Infrastructure prereq: Newspack Newsletters provides the
		// email-editor post type + meta keys. 412 (Precondition
		// Failed) more accurately reflects "configuration prereq
		// not satisfied" than 500 (server fault) — monitoring that
		// pages on 5xx no longer fires for known-config states.
		if ( ! self::supports_emails() ) {
			return new \WP_Error(
				'newspack_emails_unsupported',
				esc_html__( 'Email sending requires the Newspack Newsletters plugin to be active.', 'newspack-plugin' ),
				[ 'status' => 412 ]
			);
		}
		// `serialize_email( null, $post_id )` returns false when the
		// post doesn't exist OR when its EMAIL_HTML_META is missing.
		// Distinguish via get_post() so the error codes (and HTTP
		// status) point the publisher at the actionable cause.
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error(
				'newspack_emails_post_missing',
				esc_html__( 'Email post does not exist.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}
		// Require the Newspack email CPT explicitly. serialize_email() keys
		// off post meta (EMAIL_CONFIG_NAME_META / EMAIL_HTML_META) that a
		// non-email post could also carry, so gate on post_type — otherwise
		// a matching-meta post of another type could reach the test-send
		// dispatch path.
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return new \WP_Error(
				'newspack_emails_wrong_post_type',
				esc_html__( 'The provided post is not a Newspack email.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$email_config = self::serialize_email( null, $post_id );
		if ( ! $email_config ) {
			return new \WP_Error(
				'newspack_emails_html_payload_missing',
				esc_html__( 'Email has no saved content. Open the email in the editor and save before sending.', 'newspack-plugin' ),
				[ 'status' => 422 ]
			);
		}
		$config_name = (string) \get_post_meta( $post_id, self::EMAIL_CONFIG_NAME_META, true );
		// EMAIL_CONFIG_NAME_META missing/empty would propagate through
		// dispatch_email → get_email_payload('') → get_email_config_by_type('')
		// returning false, producing a blank-bodied email. Now reachable
		// post-NPPD-1547 because send_test_email skips the publish gate
		// (drafts are likelier to have incomplete meta). Fail fast
		// instead of dispatching empty content.
		if ( '' === $config_name ) {
			return new \WP_Error(
				'newspack_emails_config_name_missing',
				esc_html__( 'Email is not associated with a known config type. Cannot dispatch.', 'newspack-plugin' ),
				[ 'status' => 422 ]
			);
		}
		// Beyond non-empty, require the config name to be a REGISTERED type.
		// An unknown/stale name (e.g. a renamed provider type left on an old
		// draft) would otherwise reach dispatch and re-resolve to nothing
		// downstream, producing a blank-bodied email. Fail fast instead.
		if ( ! isset( self::get_email_configs()[ $config_name ] ) ) {
			return new \WP_Error(
				'newspack_emails_config_name_unknown',
				esc_html__( 'Email is associated with an unregistered config type. Cannot dispatch.', 'newspack-plugin' ),
				[ 'status' => 422 ]
			);
		}
		return [
			'config' => $email_config,
			'name'   => $config_name,
		];
	}

	/**
	 * Dispatch an email via wp_mail() — locale-switching, header
	 * construction, payload substitution, logging. Shared between
	 * send_email() and send_test_email() so the wp_mail() call
	 * surface is identical between auto-send and test-send.
	 *
	 * Callers are responsible for prerequisite validation before
	 * reaching here.
	 *
	 * @param array  $email_config Serialized email config.
	 * @param string $config_name  Resolved config name (logger context).
	 * @param string $to           Recipient.
	 * @param array  $placeholders Dynamic content substitutions.
	 * @return bool wp_mail() result.
	 */
	private static function dispatch_email( $email_config, $config_name, $to, $placeholders ) {
		// Locale-switching is performed by the caller (send_email /
		// send_test_email) around the FULL send operation, including
		// config resolution — get_email_config_by_type's lazy-create
		// path requires a template PHP file with __() calls, and the
		// locale active at that moment determines what gets persisted
		// to the email post. Moving the switch into dispatch_email
		// would mean first-time-template-creation runs under site
		// default locale instead of the admin's.

		$email_content_type = function() {
			return 'text/html';
		};

		$headers = [
			sprintf( 'From: %s <%s>', $email_config['from_name'], $email_config['from_email'] ),
		];
		if ( $email_config['from_email'] !== $email_config['reply_to_email'] ) {
			$headers[] = sprintf( 'Reply-To: %s <%s>', $email_config['from_name'], $email_config['reply_to_email'] );
		}

		// try/finally so the wp_mail_content_type filter is removed
		// even if wp_mail() (or a filter it triggers) throws —
		// otherwise the html-content-type filter persists for every
		// subsequent wp_mail() call in the same request, silently
		// converting plain-text mails (password resets, WP core
		// notifications) to html.
		add_filter( 'wp_mail_content_type', $email_content_type );
		try {
			$email_send_result = wp_mail( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
				$to,
				$email_config['subject'],
				self::get_email_payload( $config_name, $placeholders ),
				$headers
			);
		} finally {
			remove_filter( 'wp_mail_content_type', $email_content_type );
		}

		// Log dispatch outcome, not the attempt. The old
		// unconditional "Sending..." log produced misleading
		// success-shaped lines for wp_mail failures and routinely
		// led incident investigation down the wrong path
		// (concluding dispatch succeeded when wp_mail returned
		// false). Distinguishing success/failure in the log
		// matches the WP_Error contract callers see.
		if ( $email_send_result ) {
			Logger::log( 'Sent "' . $config_name . '" email to: ' . $to );
		} else {
			Logger::log( 'Failed to send "' . $config_name . '" email to: ' . $to );
		}

		return $email_send_result;
	}

	/**
	 * Load a template of an email.
	 *
	 * @param string $type Email type.
	 */
	private static function load_email_template( $type ) {
		$configs = self::get_email_configs();

		if ( ! isset( $configs[ $type ], $configs[ $type ]['template'] ) ) {
			return false;
		}
		return require $configs[ $type ]['template'];
	}

	/**
	 * Does this instance support emails?
	 */
	public static function supports_emails() {
		if ( ! class_exists( 'Newspack_Newsletters' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Can email of a particular type be sent?
	 *
	 * @param string $type Type of the email.
	 */
	public static function can_send_email( $type ) {
		if ( ! self::supports_emails() ) {
			return false;
		}
		$email_config = self::get_email_config_by_type( $type );
		if ( ! $email_config || 'publish' !== $email_config['status'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Default values for email config fields shared across all providers.
	 *
	 * `chip` intentionally omitted — derived from `category` in
	 * `apply_config_defaults()` because the category→chip mapping is 1:1
	 * for every Newspack-side provider, and a global default of
	 * 'auth-account' would silently misclassify any reader-revenue config
	 * that didn't override it.
	 *
	 * `recommended` defaults to false (conservative): a third-party config
	 * that doesn't opt in shouldn't surface with a "Recommended" treatment.
	 */
	const EMAIL_CONFIG_DEFAULTS = [
		'trigger_description' => '',
		'recipient'           => 'reader',
		'recommended'         => false,
	];

	/**
	 * Fill in default values for any email config field a provider omitted.
	 *
	 * @param array $config Single email config entry as registered via the
	 *                      `newspack_email_configs` filter.
	 * @return array Config with shared defaults applied for missing fields.
	 */
	public static function apply_config_defaults( array $config ): array {
		$merged = array_merge( self::EMAIL_CONFIG_DEFAULTS, $config );
		// Derive chip from category if not explicitly set. Category →
		// chip mapping is 1:1 for every Newspack-side provider, so
		// providers don't need to declare both.
		if ( ! isset( $merged['chip'] ) ) {
			$merged['chip'] = ( 'reader-revenue' === ( $merged['category'] ?? '' ) )
				? 'reader-revenue'
				: 'auth-account';
		}
		return $merged;
	}

	/**
	 * Get all email configs.
	 *
	 * Returns the merged config set from the `newspack_email_configs`
	 * filter with shared defaults applied to each entry. Public so
	 * downstream consumers (e.g. the wizard response builder) can read
	 * the unified set without re-running the filter.
	 *
	 * @return array<string, array{
	 *     name?:                string,
	 *     category?:            string,
	 *     label?:               string,
	 *     description?:         string,
	 *     template?:            string,
	 *     editor_notice?:       string,
	 *     from_name?:           string,
	 *     from_email?:          string,
	 *     reply_to_email?:      string,
	 *     available_placeholders?: array,
	 *     trigger_description: string,
	 *     recipient:           'reader'|'admin',
	 *     recommended:         bool,
	 *     chip:                'auth-account'|'reader-revenue',
	 *     source?:             'newspack'|'woocommerce',
	 * }> Configs keyed by type. The four fields without `?` are guaranteed
	 *    by `apply_config_defaults()`; the rest are provider-specified and
	 *    may be absent.
	 */
	public static function get_email_configs() {
		// Request-scoped memoization. This method is called repeatedly per
		// request — directly by the wizard's response builder, and
		// transitively by serialize_email/get_email_config_by_type once
		// per email row. Each call would otherwise re-run apply_filters
		// across every registered provider plus apply_config_defaults on
		// every entry. Tests that mutate filter callbacks mid-test can
		// invalidate the cache via reset_email_configs_cache().
		if ( null !== self::$email_configs_cache ) {
			return self::$email_configs_cache;
		}

		$configs = apply_filters( 'newspack_email_configs', [] );
		if ( ! is_array( $configs ) ) {
			self::$email_configs_cache = [];
			return self::$email_configs_cache;
		}
		foreach ( $configs as $type => $config ) {
			// Defensive: a third-party filter callback can return a non-array
			// value at a key (e.g. ['mytype' => 'string']). apply_config_defaults
			// is typed array, so an unguarded call would throw TypeError and
			// take down every consumer of get_email_configs. Drop the bad row
			// silently rather than fatal the whole endpoint.
			if ( ! is_array( $config ) ) {
				unset( $configs[ $type ] );
				continue;
			}
			$configs[ $type ] = self::apply_config_defaults( $config );
		}
		self::$email_configs_cache = $configs;
		return self::$email_configs_cache;
	}

	/**
	 * Serialize an email config.
	 *
	 * @param string $type Type of the email.
	 * @param int    $post_id Email post id.
	 *
	 * @return array|false The serialized email config or false if not available or supported.
	 */
	private static function serialize_email( $type = null, $post_id = 0 ) {
		if ( ! self::supports_emails() ) {
			return false;
		}

		if ( null !== $type ) {
			$configs = self::get_email_configs();
			if ( ! isset( $configs[ $type ] ) ) {
				return false;
			}
			$email_config = $configs[ $type ];
		} else {
			// Fallback config for the null-type branch. Apply the shared
			// defaults so the serialized output shape stays uniform.
			$email_config = self::apply_config_defaults(
				[
					'label'       => '',
					'description' => '',
					'category'    => '',
				]
			);
		}
		$html_payload = get_post_meta( $post_id, \Newspack_Newsletters::EMAIL_HTML_META, true );
		if ( ! $html_payload || empty( $html_payload ) ) {
			return false;
		}
		$edit_link = '';
		$post_link = get_edit_post_link( $post_id, '' );
		if ( $post_link ) {
			// Make the edit link relative.
			$edit_link = str_replace( site_url(), '', $post_link );
		}
		$serialized_email = [
			'type'                => $type,
			// `category`, `label`, and `description` are provider-specified
			// and NOT among the keys `apply_config_defaults()` guarantees, so
			// a third-party config can omit them. Reading them unguarded would
			// raise an undefined-key warning under PHP 8.3; coalesce to ''.
			'category'            => $email_config['category'] ?? '',
			'label'               => $email_config['label'] ?? '',
			'description'         => $email_config['description'] ?? '',
			'post_id'             => $post_id,
			'edit_link'           => $edit_link,
			'subject'             => get_the_title( $post_id ),
			'from_name'           => isset( $email_config['from_name'] ) ? $email_config['from_name'] : self::get_from_name(),
			'from_email'          => isset( $email_config['from_email'] ) ? $email_config['from_email'] : self::get_from_email(),
			'reply_to_email'      => isset( $email_config['reply_to_email'] ) ? $email_config['reply_to_email'] : self::get_reply_to_email(),
			'status'              => get_post_status( $post_id ),
			'html_payload'        => $html_payload,
			'trigger_description' => $email_config['trigger_description'],
			'recipient'           => $email_config['recipient'],
			'recommended'         => $email_config['recommended'],
			'chip'                => $email_config['chip'],
			'source'              => isset( $email_config['source'] ) ? $email_config['source'] : 'newspack',
		];

		return $serialized_email;
	}

	/**
	 * Get the from email address used to send all transactional emails.
	 * We avoid use of the `wp_mail_from` hook because we only want to
	 * set the email address for Newspack emails, not all emails sent via wp_mail.
	 *
	 * @return string Email address used as the sender for Newspack emails.
	 */
	public static function get_from_email() {
		// Get the site domain and get rid of www.
		$sitename   = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$from_email = 'no-reply@';

		if ( null !== $sitename ) {
			if ( 'www.' === substr( $sitename, 0, 4 ) ) {
				$sitename = substr( $sitename, 4 );
			}
			$from_email .= $sitename;
		}
		$from_email = self::saved_sender_setting_or_default( 'sender_email_address', $from_email );
		return apply_filters( 'newspack_from_email', $from_email );
	}

	/**
	 * Get the "reply-to" email address used to send all transactional emails.
	 *
	 * @return string
	 */
	public static function get_reply_to_email() {
		$reply_to_email = get_bloginfo( 'admin_email' );
		$reply_to_email = self::saved_sender_setting_or_default( 'contact_email_address', $reply_to_email );
		return apply_filters( 'newspack_reply_to_email', $reply_to_email );
	}

	/**
	 * Get the from from name used to send all transactional emails.
	 * We avoid use of the `wp_mail_from_name` hook because we only want
	 * to set the name for Newspack emails, not all emails sent via wp_mail.
	 *
	 * @return string Name used as the sender for Newspack emails.
	 */
	public static function get_from_name() {
		$from_name = get_bloginfo( 'name' );
		$from_name = self::saved_sender_setting_or_default( 'sender_name', $from_name );
		return apply_filters( 'newspack_from_name', $from_name );
	}

	/**
	 * Resolve a saved transactional-email sender/contact setting,
	 * falling back to a derived default when unset or empty.
	 *
	 * The saved override is honored REGARDLESS of Reader Activation
	 * state. The Emails > Settings modal surfaces and writes these
	 * settings whether or not RA is enabled, so silently ignoring a
	 * saved value when RA is off (the previous behavior) would discard
	 * an explicit publisher choice and send with the derived default.
	 * RA visibility rules are kept separate from sender resolution.
	 *
	 * An empty stored value means "use the derived default" — this is
	 * the modal's revert-to-default signal — so empty never overrides.
	 *
	 * @param string $key     Option key suffix (e.g. 'sender_name'),
	 *                        appended to Reader_Activation::OPTIONS_PREFIX.
	 * @param string $default Derived default to use when unset/empty.
	 * @return string Saved value when non-empty, otherwise $default.
	 */
	private static function saved_sender_setting_or_default( $key, $default ) {
		$saved = get_option( Reader_Activation::OPTIONS_PREFIX . $key, '' );
		if ( is_string( $saved ) && '' !== $saved ) {
			return $saved;
		}
		return $default;
	}

	/**
	 * Register option-layer sanitization for the three transactional
	 * sender/contact settings, so EVERY writer inherits the same guard:
	 * the Emails > Settings route, the legacy Audience-Management route,
	 * and any future REST/cron writer. `register_setting()` attaches a
	 * `sanitize_option_{$option}` filter that `update_option()` always
	 * runs, so route-level validation can't be bypassed at the option.
	 *
	 * Hooked on `init` (not `admin_init`) so the guard is attached on
	 * every request type — a cron or REST write must not slip past an
	 * admin-only filter.
	 *
	 * The route keeps its own inline `is_email()` for a friendly inline
	 * error; this is the integrity backstop beneath it.
	 *
	 * @return void
	 */
	public static function register_sender_settings() {
		// sender_name flows into the mail `From:` header.
		// sanitize_text_field strips CR/LF — load-bearing header-injection
		// defense. Empty is preserved (the revert-to-default signal).
		register_setting(
			'newspack_emails',
			Reader_Activation::OPTIONS_PREFIX . 'sender_name',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			]
		);
		// register_setting's sanitize_callback receives only $value (it
		// adds the filter with accepted_args = 1), so each email option
		// gets a dedicated callback that knows its own key — needed to
		// look up the last-good stored value on the invalid-coerce path.
		register_setting(
			'newspack_emails',
			Reader_Activation::OPTIONS_PREFIX . 'sender_email_address',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_sender_email_address_option' ],
			]
		);
		register_setting(
			'newspack_emails',
			Reader_Activation::OPTIONS_PREFIX . 'contact_email_address',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_contact_email_address_option' ],
			]
		);
	}

	/**
	 * Option-layer sanitizer for the sender email address.
	 *
	 * @param mixed $value Incoming option value.
	 * @return string Sanitized value.
	 */
	public static function sanitize_sender_email_address_option( $value ) {
		return self::sanitize_email_setting( $value, Reader_Activation::OPTIONS_PREFIX . 'sender_email_address' );
	}

	/**
	 * Option-layer sanitizer for the contact (reply-to) email address.
	 *
	 * @param mixed $value Incoming option value.
	 * @return string Sanitized value.
	 */
	public static function sanitize_contact_email_address_option( $value ) {
		return self::sanitize_email_setting( $value, Reader_Activation::OPTIONS_PREFIX . 'contact_email_address' );
	}

	/**
	 * Shared email-setting sanitizer. Empty is never blocked or coerced
	 * — it is the revert-to-derived-default signal. A non-empty value
	 * that fails `is_email()` (e.g. a legacy/cron writer bypassing the
	 * route's guard) is coerced to the LAST-GOOD stored value rather
	 * than to empty, so an invalid write can't silently wipe a good
	 * saved address down to the derived default.
	 *
	 * @param mixed  $value  Incoming option value.
	 * @param string $option Full option name (to read the prior value).
	 * @return string Sanitized value.
	 */
	private static function sanitize_email_setting( $value, $option ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		if ( is_email( $value ) ) {
			return sanitize_email( $value );
		}
		$existing = get_option( $option, '' );
		if ( is_string( $existing ) && '' !== $existing ) {
			return $existing;
		}
		return '';
	}

	/**
	 * Get the email for a specific type.
	 * If the email does not exist, it will be created based on default template.
	 *
	 * @param string $type Type of the email.
	 *
	 * @return array|false The serialized email config or false if not available or supported.
	 */
	public static function get_email_config_by_type( $type ) {
		$emails_query = new \WP_Query(
			[
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'meta_key'       => self::EMAIL_CONFIG_NAME_META,
				'meta_value'     => $type, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		if ( $emails_query->post ) {
			return self::serialize_email( $type, $emails_query->post->ID );
		} elseif ( ! function_exists( 'is_user_logged_in' ) ) {
			/** Only attempt to create the email post if wp-includes/pluggable.php is loaded. */
			return false;
		} else {
			// Make sure newsletters color palette is updated with latest theme colors.
			if ( self::supports_emails() && method_exists( '\Newspack_Newsletters', 'update_color_palette' ) ) {
				$theme_colors = newspack_get_theme_colors();
				\Newspack_Newsletters::update_color_palette(
					[
						'primary'             => $theme_colors['primary_color'],
						'primary-text'        => $theme_colors['primary_text_color'],
						'primary-variation'   => $theme_colors['primary_variation'],
						'secondary'           => $theme_colors['secondary_color'],
						'secondary-text'      => $theme_colors['secondary_text_color'],
						'secondary-variation' => $theme_colors['secondary_variation'],
					]
				);
			}

			$email_post_data = self::load_email_template( $type );
			if ( ! $email_post_data ) {
				Logger::error( 'Error: could not retrieve template for type: ' . $type );
				return false;
			}
			$email_post_data['post_status'] = 'publish';
			$email_post_data['post_type']   = self::POST_TYPE;
			$email_post_data['meta_input']  = [
				self::EMAIL_CONFIG_NAME_META           => $type,
				\Newspack_Newsletters::EMAIL_HTML_META => $email_post_data['email_html'],
				'font_body'                            => 'Arial, Helvetica, sans-serif',
				'font_header'                          => 'Arial, Helvetica, sans-serif',
			];
			$post_id                        = wp_insert_post( $email_post_data );
			Logger::log( sprintf( 'Creating email of type %s (id: %s).', $type, $post_id ) );
			return self::serialize_email(
				$type,
				$post_id
			);
		}
	}

	/**
	 * Get the emails per-purpose.
	 *
	 * @param string[] $config_names Configuration names of the emails.
	 * @param bool     $get_full_configs Whether to get the full configs or just the minimum amount of data.
	 */
	public static function get_emails( $config_names = [], $get_full_configs = true ) {
		$emails = [];
		if ( ! self::supports_emails() ) {
			return $emails;
		}
		$configs = self::get_email_configs();
		foreach ( $configs as $type => $email_config ) {
			if ( ! empty( $config_names ) && ! in_array( $type, $config_names, true ) ) {
				continue;
			}
			$found_config = self::get_email_config_by_type( $type );
			if ( $found_config ) {
				if ( false == $get_full_configs ) {
					unset( $found_config['html_payload'] );
				}
				$emails[ $type ] = $found_config;
			}
		}
		return $emails;
	}

	/**
	 * Send a test email.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function api_send_test_email( $request ) {
		// All validation (post_id, recipient empty/format, supports
		// emails, post resolves, HTML payload present, config name
		// present) lives in send_test_email →
		// validate_send_prerequisites. The handler is a thin
		// pass-through so the REST and direct-PHP entry points
		// produce identical error codes for identical inputs.
		$result = self::send_test_email(
			$request->get_param( 'post_id' ),
			$request->get_param( 'recipient' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return \rest_ensure_response( [] );
	}

	/**
	 * Register the endpoints needed for the wizard screens.
	 */
	public static function register_api_endpoints() {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/newspack-emails/test',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_send_test_email' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				'args'                => [
					'recipient' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'post_id'   => [
						'required'          => true,
						// validate_callback runs on the RAW value before
						// sanitize, so a malformed shape is rejected rather
						// than coerced by absint into a valid-but-wrong id.
						'validate_callback' => [ __CLASS__, 'validate_test_send_post_id' ],
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Check capabilities for using API.
	 *
	 * @codeCoverageIgnore
	 * @param WP_REST_Request $request API request object.
	 * @return bool|WP_Error
	 */
	public static function api_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-plugin' ),
				[
					'status' => 403,
				]
			);
		}
		return true;
	}

	/**
	 * Get a password reset URL.
	 *
	 * @param WP_User $user WP user object.
	 * @param string  $key Reset key.
	 */
	public static function get_password_reset_url( $user, $key ) {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return add_query_arg(
				[
					'key' => $key,
					'id'  => $user->ID,
				],
				wc_get_account_endpoint_url( 'lost-password' )
			);
		}
		return add_query_arg(
			[
				'action' => 'rp',
				'key'    => $key,
				'login'  => rawurlencode( $user->user_login ),
			],
			wp_lostpassword_url()
		);
	}

	/**
	 * Trigger an update to all email template posts when theme color is updated in customizer.
	 * This is to force an update of dynamic properties such as theme colors.
	 *
	 * @param string|array $previous_value previous option value.
	 * @param string|array $updated_value  updated option value.
	 *
	 * @return void
	 */
	public static function maybe_update_email_templates( $previous_value, $updated_value ) {
		// Do nothing if newsletters is not active.
		if ( ! self::supports_emails() ) {
			return;
		}

		// Check for theme mod color settings in case a non-newspack theme is installed.
		if ( ! isset( $previous_value['primary_color_hex'], $updated_value['primary_color_hex'] ) ) {
			return;
		}

		if ( ( $previous_value['primary_color_hex'] !== $updated_value['primary_color_hex'] ) || ( $previous_value['secondary_color_hex'] !== $updated_value['secondary_color_hex'] ) ) {
			// Update the newsletters color palette.
			$updated = \Newspack_Newsletters::update_color_palette(
				[
					'primary'             => $updated_value['primary_color_hex'],
					'primary-text'        => newspack_get_color_contrast( $updated_value['primary_color_hex'] ),
					'primary-variation'   => newspack_adjust_brightness( $updated_value['primary_color_hex'], -40 ),
					'secondary'           => $updated_value['secondary_color_hex'],
					'secondary-text'      => newspack_get_color_contrast( $updated_value['secondary_color_hex'] ),
					'secondary-variation' => newspack_adjust_brightness( $updated_value['secondary_color_hex'], -40 ),

				]
			);

			if ( ! $updated ) {
				Logger::error( 'There was an error updating the newsletters color palette.' );
			}

			// Trigger an update of all email templates to regenerate HTML.
			$templates = get_posts(
				[
					'post_type'      => self::POST_TYPE,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				]
			);

			foreach ( $templates as $template ) {
				// Find/replace the old hex values with the new ones in the rendered email HTML.
				$email_html = get_post_meta( $template->ID, \Newspack_Newsletters::EMAIL_HTML_META, true );
				$email_html = str_replace(
					[
						$previous_value['primary_color_hex'],
						newspack_get_color_contrast( $previous_value['primary_color_hex'] ),
						newspack_adjust_brightness( $previous_value['primary_color_hex'], -40 ),
						$previous_value['secondary_color_hex'],
						newspack_get_color_contrast( $previous_value['secondary_color_hex'] ),
						newspack_adjust_brightness( $previous_value['secondary_color_hex'], -40 ),
					],
					[
						$updated_value['primary_color_hex'],
						newspack_get_color_contrast( $updated_value['primary_color_hex'] ),
						newspack_adjust_brightness( $updated_value['primary_color_hex'], -40 ),
						$updated_value['secondary_color_hex'],
						newspack_get_color_contrast( $updated_value['secondary_color_hex'] ),
						newspack_adjust_brightness( $updated_value['secondary_color_hex'], -40 ),
					],
					$email_html
				);
				update_post_meta( $template->ID, \Newspack_Newsletters::EMAIL_HTML_META, $email_html );

				wp_update_post( [ 'ID' => $template->ID ] );
			}
		}
	}

	/**
	 * Inject dynamic email template styles for dynamic text colors in the editor.
	 *
	 * @return void
	 */
	public static function inject_dynamic_email_template_styles() {
		if ( get_post_type() !== self::POST_TYPE ) {
			return;
		}

		[ 'primary_text_color' => $primary_text_color ] = newspack_get_theme_colors();

		?>
		<style type="text/css">
			.<?php echo esc_html( self::POST_TYPE ); ?>-has-primary-text-color,
			.<?php echo esc_html( self::POST_TYPE ); ?>-has-primary-text-color a {
				color: <?php echo esc_attr( $primary_text_color ); ?> !important;
			}

			.is-style-filled-primary-text li {
				background: transparent !important;
			}

			.is-style-filled-primary-text li svg {
				color: <?php echo esc_attr( $primary_text_color ); ?> !important;
			}
		</style>
		<?php
	}
}

Emails::init();
