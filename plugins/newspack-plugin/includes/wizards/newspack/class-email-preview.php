<?php
/**
 * Email preview rendering for the Settings → Emails screen.
 *
 * Renders a publisher-facing preview of a transactional email by substituting
 * known template tokens with realistic sample values. Used by the unified
 * email management UI to display a per-card thumbnail.
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\Emails;
use Newspack\Logger;
use Newspack\WooCommerce_Emails;
use Newspack_Newsletters;

defined( 'ABSPATH' ) || exit;

/**
 * Email Preview Class.
 */
class Email_Preview {

	/**
	 * Get the rendered HTML for an email post, with sample token values substituted.
	 *
	 * Falls back to the registered template file's HTML when the post's saved
	 * EMAIL_HTML_META is empty (i.e. the email has never been customized).
	 *
	 * @param int $post_id ID of the email post.
	 *
	 * @return string|false Rendered HTML, or false if the email can't be resolved.
	 */
	public static function get_preview_html( int $post_id ) {
		if ( ! self::is_supported() ) {
			return false;
		}

		$html = self::get_source_html( $post_id );
		if ( empty( $html ) ) {
			return false;
		}

		return self::apply_sample_substitutions( $html, $post_id );
	}

	/**
	 * Get the source HTML for an email post.
	 *
	 * Reads the saved EMAIL_HTML_META first; falls back to the registered
	 * template's default email_html when that meta is empty.
	 *
	 * @param int $post_id ID of the email post.
	 *
	 * @return string Source HTML, or empty string if unavailable.
	 */
	private static function get_source_html( int $post_id ): string {
		$html = get_post_meta( $post_id, Newspack_Newsletters::EMAIL_HTML_META, true );
		if ( ! empty( $html ) ) {
			return $html;
		}

		// Fallback: look up the registered template for this post type and use its default HTML.
		$type = get_post_meta( $post_id, Emails::EMAIL_CONFIG_NAME_META, true );
		if ( empty( $type ) ) {
			return '';
		}

		// Look up the registered template and include it to get the default HTML.
		// The template file returns an array with an 'email_html' key.
		$configs = apply_filters( 'newspack_email_configs', [] );
		if ( ! isset( $configs[ $type ], $configs[ $type ]['template'] ) ) {
			return '';
		}

		$template_path = $configs[ $type ]['template'];

		// `$template_path` comes through the `newspack_email_configs`
		// filter. A malicious or buggy third-party hook could supply any
		// readable file path on the server (LFI) or — worse — point at an
		// attacker-uploaded `.php` file (RCE via top-level side effects on
		// include). `is_readable` doesn't gate against either. Constrain
		// the path to within the plugin tree and require a `.php`
		// extension before including. realpath() also resolves any
		// `../` traversal away from the plugin root.
		//
		// Assumption: the jail boundary is `realpath( dirname(
		// NEWSPACK_PLUGIN_FILE ) )` — the plugin's own resolved tree. On
		// installs where the plugin (or a sibling plugin a third-party
		// `newspack_email_configs` callback legitimately points at) lives
		// behind a symlink — devkit/composer-installer or some Atomic
		// layouts — the resolved target may fall outside this tree and be
		// rejected. That errs CLOSED (no LFI/RCE), at the cost of a silent
		// empty preview for those edge templates; acceptable given the
		// security posture. See the rejected-path test in
		// tests/unit-tests/email-preview.php.
		$resolved = realpath( $template_path );
		$plugin_root = realpath( dirname( NEWSPACK_PLUGIN_FILE ) );
		if (
			false === $resolved
			|| false === $plugin_root
			|| 0 !== strpos( $resolved, $plugin_root . DIRECTORY_SEPARATOR )
			|| '.php' !== substr( $resolved, -4 )
		) {
			return '';
		}

		$template_data = include $resolved;
		if ( ! is_array( $template_data ) || empty( $template_data['email_html'] ) ) {
			return '';
		}

		return $template_data['email_html'];
	}

	/**
	 * Apply sample-value substitutions to email HTML.
	 *
	 * Site/branding tokens use the publisher's real site config (so the preview
	 * reflects their actual branding). Reader/transaction tokens use stable
	 * fake values. Action URLs are replaced with anchor placeholders so
	 * preview iframes don't trigger live navigation.
	 *
	 * @param string $html    Source HTML containing *TOKEN* placeholders.
	 * @param int    $post_id The email post being previewed (passed to the filter).
	 *
	 * @return string HTML with tokens substituted.
	 */
	private static function apply_sample_substitutions( string $html, int $post_id = 0 ): string {
		$substitutions = self::get_sample_substitutions();

		/**
		 * Filters the sample substitution map used for email previews.
		 *
		 * The map is structured as three sub-arrays keyed by escaping context:
		 * - 'html': Tokens rendered as visible text (escaped with esc_html()).
		 * - 'url':  Tokens used in href/src attributes (escaped with esc_url()).
		 * - 'raw':  Tokens containing pre-escaped HTML (not escaped again).
		 *
		 * @param array $substitutions Structured map of `*TOKEN*` => sample value.
		 * @param int   $post_id       The email post being previewed (0 if unknown).
		 */
		$substitutions = apply_filters( 'newspack_email_preview_substitutions', $substitutions, $post_id );

		// Validate the filtered value — fall back to defaults if a filter broke the structure.
		if (
			! is_array( $substitutions )
			|| ! isset( $substitutions['html'], $substitutions['url'], $substitutions['raw'] )
			|| ! is_array( $substitutions['html'] )
			|| ! is_array( $substitutions['url'] )
			|| ! is_array( $substitutions['raw'] )
		) {
			$substitutions = self::get_sample_substitutions();
		}

		// Escape HTML-text tokens.
		$html_map = array_map( 'esc_html', $substitutions['html'] );
		// Escape URL tokens.
		$url_map = array_map( 'esc_url', $substitutions['url'] );
		// Raw tokens carry pre-escaped markup from our own builder, but the
		// `newspack_email_preview_substitutions` filter can replace the whole
		// `raw` bucket. A misbehaving third-party callback could hand back a
		// non-string value or unsanitized HTML, which strtr would drop into
		// the iframe srcDoc verbatim. Coerce each value to a string and run
		// it through wp_kses_post() so the sandbox isn't the only thing
		// containing post-filter raw markup. Our own raw values
		// (`<a href="mailto:…">`, `<strong>…</strong>`) survive wp_kses_post
		// unchanged.
		$raw_map = array_map(
			static fn( $value ) => wp_kses_post( is_string( $value ) ? $value : '' ),
			$substitutions['raw']
		);

		return strtr( $html, array_merge( $html_map, $url_map, $raw_map ) );
	}

	/**
	 * Get the substitution map of email-template tokens to sample values.
	 *
	 * Returns a three-key array grouped by escaping context:
	 * - 'html': Tokens rendered as visible text (escaped with esc_html() at call site).
	 * - 'url':  Tokens used in href/src attributes (escaped with esc_url() at call site).
	 * - 'raw':  Tokens containing pre-escaped HTML markup (not escaped again).
	 *
	 * @return array{ html: array<string, string>, url: array<string, string>, raw: array<string, string> }
	 */
	public static function get_sample_substitutions(): array {
		$site_logo_url   = wp_get_attachment_url( get_theme_mod( 'custom_logo' ) );
		$site_title      = get_bloginfo( 'name' );
		$site_url        = get_bloginfo( 'wpurl' );
		$reply_to_email  = Emails::get_reply_to_email();
		$site_address    = self::get_site_address();
		// *SITE_CONTACT* lives in the 'raw' bucket (pre-escaped HTML, NOT
		// re-escaped at strtr-time), so the values interpolated here MUST
		// be escaped at construction time. get_bloginfo('name') and the
		// WC store address are admin-controlled strings — if an admin
		// (or a role-elevation supply-chain compromise) sets the site
		// title to a `<script>` or malformed tag, the unescaped version
		// would inject into the iframe's srcDoc. Iframe sandbox blocks
		// script execution today, but breaks rendering and would be
		// immediately exploitable if `allow-scripts` were ever added.
		$site_contact = $site_address
			? sprintf( '<strong>%s</strong> — %s', esc_html( $site_title ), esc_html( $site_address ) )
			: esc_html( $site_title );

		return [
			// Tokens rendered as visible text inside HTML — escaped with esc_html().
			'html' => [
				// Site / branding — real values from the publisher's config.
				'*SITE_TITLE*'            => $site_title,
				'*SITE_ADDRESS*'          => $site_address,

				// Reader identity — stable sample values.
				'*BILLING_FIRST_NAME*'    => __( 'Sample', 'newspack-plugin' ),
				'*BILLING_LAST_NAME*'     => __( 'Reader', 'newspack-plugin' ),
				'*BILLING_NAME*'          => __( 'Sample Reader', 'newspack-plugin' ),
				'*PENDING_EMAIL_ADDRESS*' => 'sample.reader@example.com',

				// Transaction / subscription details.
				'*AMOUNT*'                => '$25.00',
				'*PAYMENT_METHOD*'        => __( 'Visa ending in 4242', 'newspack-plugin' ),
				'*PRODUCT_NAME*'          => __( 'Monthly Membership', 'newspack-plugin' ),
				'*BILLING_FREQUENCY*'     => __( 'monthly', 'newspack-plugin' ),
				'*DATE*'                  => wp_date( get_option( 'date_format', 'F j, Y' ) ),
				'*CANCELLATION_TITLE*'    => __( 'Subscription Cancelled', 'newspack-plugin' ),
				'*CANCELLATION_TYPE*'     => __( 'subscription', 'newspack-plugin' ),
				// CTA button label — used by the cancellation template
				// (includes/templates/reader-revenue-emails/cancellation.php).
				'*BUTTON_TEXT*'           => __( 'Manage subscription', 'newspack-plugin' ),

				// Card expiry warning details.
				'*CARD_LAST_4*'           => '4242',
				'*EXPIRY_DATE*'           => '12/2026',
				'*RENEWAL_DATE*'          => wp_date( get_option( 'date_format', 'F j, Y' ), strtotime( '+30 days' ) ),

				// OTP code — stable sample value.
				'*MAGIC_LINK_OTP*'        => '123456',
			],

			// Tokens used in href/src attributes — escaped with esc_url().
			'url'  => [
				'*SITE_URL*'               => $site_url,
				'*SUBSCRIPTION_URL*'       => '#',
				'*SITE_LOGO*'              => $site_logo_url ? $site_logo_url : '',
				'*ACCOUNT_URL*'            => '#',
				'*CANCELLATION_URL*'       => '#',
				'*EMAIL_CANCELLATION_URL*' => '#',
				'*EMAIL_VERIFICATION_URL*' => '#',
				'*VERIFICATION_URL*'       => '#',
				'*RECEIPT_URL*'            => '#',
				'*MAGIC_LINK_URL*'         => '#',
				'*PASSWORD_RESET_LINK*'    => '#',
				'*SET_PASSWORD_LINK*'      => '#',
				'*DELETION_LINK*'          => '#',
				'*WP_LOGIN_URL*'           => '#',
				'*UPDATE_PAYMENT_URL*'     => '#',
			],

			// Tokens containing pre-escaped HTML — NOT escaped again.
			'raw'  => [
				'*CONTACT_EMAIL*' => sprintf( '<a href="%s">%s</a>', esc_url( 'mailto:' . $reply_to_email ), esc_html( $reply_to_email ) ),
				'*SITE_CONTACT*'  => $site_contact,
			],
		];
	}

	/**
	 * Get the site's store address as a formatted string.
	 *
	 * Mirrors the logic in Emails::get_email_payload() so the preview
	 * shows the same address format the real email would use.
	 *
	 * @return string Formatted site address, or empty string.
	 */
	private static function get_site_address(): string {
		if ( class_exists( 'WC' ) ) {
			$base_address  = WC()->countries->get_base_address();
			$base_city     = WC()->countries->get_base_city();
			$base_postcode = WC()->countries->get_base_postcode();
		} else {
			$base_address  = get_option( 'woocommerce_store_address', '' );
			$base_city     = get_option( 'woocommerce_store_city', '' );
			$base_postcode = get_option( 'woocommerce_store_postcode', '' );
		}

		if ( ! $base_address ) {
			return '';
		}

		if ( ! $base_city && ! $base_postcode ) {
			return $base_address;
		}

		return sprintf(
			/* translators: 1: street address, 2: city, 3: postcode. */
			__( '%1$s, %2$s %3$s', 'newspack-plugin' ),
			$base_address,
			$base_city,
			$base_postcode
		);
	}

	/**
	 * Whether a numeric `woo_email` post maps to a WC email that the wizard
	 * currently surfaces.
	 *
	 * Reverse-resolves the post → WC_Email class name via the WC posts
	 * manager, then confirms a `source='woocommerce'` entry in
	 * {@see Emails::get_email_configs()} carries that class. Returns false
	 * when WC isn't loaded, the post doesn't reverse-resolve, or no surfaced
	 * config matches — i.e. a stale/orphan template post.
	 *
	 * @param int $post_id The woo_email post ID.
	 * @return bool
	 */
	private static function woo_email_post_is_surfaced( int $post_id ): bool {
		$manager_class = 'Automattic\\WooCommerce\\Internal\\EmailEditor\\WCTransactionalEmails\\WCTransactionalEmailPostsManager';
		if ( ! class_exists( $manager_class ) ) {
			return false;
		}

		$email_class_name = $manager_class::get_instance()->get_email_type_class_name_from_post_id( $post_id );
		if ( empty( $email_class_name ) ) {
			return false;
		}

		foreach ( Emails::get_email_configs() as $config ) {
			if (
				'woocommerce' === ( $config['source'] ?? '' )
				&& ( $config['wc_email_class'] ?? '' ) === $email_class_name
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get rendered preview HTML for a WooCommerce block-editor email template.
	 *
	 * Tries the block-editor render path first (BlockEmailRenderer) which
	 * produces HTML styled with the site's theme.json colors and logo — the
	 * same output the block email editor preview shows. Falls back to
	 * EmailPreview::render() (legacy WC template with woocommerce_email_*
	 * option colors) if the block path is unavailable or fails.
	 *
	 * @param int $post_id ID of the woo_email post.
	 *
	 * @return string|false Rendered HTML, or false if unavailable.
	 */
	private static function get_wc_preview_html( int $post_id ) {
		$manager_class = 'Automattic\\WooCommerce\\Internal\\EmailEditor\\WCTransactionalEmails\\WCTransactionalEmailPostsManager';
		$preview_class = 'Automattic\\WooCommerce\\Internal\\Admin\\EmailPreview\\EmailPreview';

		if ( ! class_exists( $manager_class ) || ! class_exists( $preview_class ) ) {
			return false;
		}

		// Reverse-lookup: post ID → email class name (e.g. 'WC_Email_New_Order').
		$email_class_name = $manager_class::get_instance()->get_email_type_class_name_from_post_id( $post_id );
		if ( empty( $email_class_name ) ) {
			return false;
		}

		// Check transient cache (block render is ~180 ms per email).
		$cache_key = self::get_wc_preview_cache_key( $post_id );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		// Set up the WC_Email with a dummy order/user so both render paths
		// have realistic sample data to work with.
		try {
			$preview = $preview_class::instance();
			$preview->set_email_type( $email_class_name );
		} catch ( \Throwable $e ) {
			return false;
		}

		// Try the block-editor render path (themed, with site logo + theme colors).
		$block_html = self::try_block_render( $preview, $post_id, $email_class_name );
		if ( ! empty( $block_html ) ) {
			set_transient( $cache_key, $block_html, HOUR_IN_SECONDS );
			return $block_html;
		}

		// Fallback: legacy render via EmailPreview (WC default styling).
		// NOT cached under $cache_key: caching the classic result here would
		// mask block-path recovery for up to an hour — a single transient
		// block failure (e.g. mid-WC-update) would pin the classic render
		// even after the block path comes back online. Classic render is
		// fast (~30-50 ms), so re-rendering each request is cheap and lets
		// the block path retry. Mirrors get_wc_classic_preview_html, which
		// also doesn't cache.
		try {
			return $preview->render();
		} catch ( \Throwable $e ) {
			Logger::log(
				"WC EmailPreview::render() legacy fallback threw for woo_email post $post_id ($email_class_name): " . $e->getMessage(),
				'NEWSPACK-EMAILS',
				'warning'
			);
			return false;
		}
	}

	/**
	 * Build the transient cache key for a WC email preview.
	 *
	 * Includes the post's modified date AND a short branding fingerprint.
	 * The modified date catches template edits; the fingerprint catches
	 * branding changes (theme color/logo, WC email option colors) that the
	 * block render bakes in but that don't bump post_modified — without it a
	 * branding change would serve a stale thumbnail until the TTL expires.
	 * Old transients expire via TTL.
	 *
	 * @param int $post_id The woo_email post ID.
	 * @return string Transient key (well within the 172-char option-name limit).
	 */
	private static function get_wc_preview_cache_key( int $post_id ): string {
		$post     = get_post( $post_id );
		$modified = $post ? strtotime( $post->post_modified_gmt ) : 0;

		// 8 hex chars of an md5 over the branding inputs the block render
		// reflects. Cheap to compute and collision-resistant enough for a
		// cache-busting fingerprint (not a security boundary).
		$fingerprint = substr(
			md5(
				(string) wp_json_encode(
					[
						get_theme_mod( 'custom_logo' ),
						get_option( 'woocommerce_email_base_color' ),
						get_option( 'woocommerce_email_background_color' ),
						get_option( 'woocommerce_email_body_background_color' ),
						get_option( 'woocommerce_email_text_color' ),
					]
				)
			),
			0,
			8
		);

		return 'newspack_wc_email_preview_' . $post_id . '_' . $modified . '_' . $fingerprint;
	}

	/**
	 * Attempt to render a WC email through the block-editor pipeline.
	 *
	 * Uses BlockEmailRenderer::maybe_render_block_email() which renders
	 * through the email-editor package's Renderer — applying theme.json
	 * global styles, CSS inlining, and personalization tag replacement.
	 *
	 * @param object $preview          WC EmailPreview instance (with email type already set).
	 * @param int    $post_id          The woo_email post ID being previewed.
	 * @param string $email_class_name The WC_Email class name (for logging).
	 *
	 * @return string|null Rendered HTML, or null if the block path is unavailable.
	 */
	private static function try_block_render( $preview, int $post_id, string $email_class_name ): ?string {
		$renderer_class = 'Automattic\\WooCommerce\\Internal\\EmailEditor\\BlockEmailRenderer';

		if ( ! class_exists( $renderer_class ) || ! function_exists( 'wc_get_container' ) ) {
			Logger::log(
				"BlockEmailRenderer not available for woo_email post $post_id ($email_class_name); using legacy preview.",
				'NEWSPACK-EMAILS',
				'warning'
			);
			return null;
		}

		// Track whether set_up_filters() ran to completion. If it threw
		// mid-way, clean_up_filters() may not be safe to call (it could
		// reference state that set_up_filters never initialized). The
		// flag gates the cleanup so we don't risk a second throw bubbling
		// past this method's catch.
		$filters_set_up = false;
		try {
			$preview->set_up_filters();
			$filters_set_up = true;
			$renderer = wc_get_container()->get( $renderer_class );
			$html     = $renderer->maybe_render_block_email( $preview->get_email() );
			$preview->clean_up_filters();

			if ( empty( $html ) ) {
				Logger::log(
					"BlockEmailRenderer returned empty for woo_email post $post_id ($email_class_name); using legacy preview.",
					'NEWSPACK-EMAILS',
					'warning'
				);
				return null;
			}

			return $html;
		} catch ( \Throwable $e ) {
			if ( $filters_set_up ) {
				// Wrap the cleanup in its own try/catch — a second throw
				// from clean_up_filters() would otherwise escape past this
				// method's catch and bubble up to api_get_preview as a
				// 500 fatal with no graceful fallback to legacy preview.
				try {
					$preview->clean_up_filters();
				} catch ( \Throwable $cleanup_e ) {
					Logger::log(
						'clean_up_filters() also threw during BlockEmailRenderer recovery: ' . $cleanup_e->getMessage(),
						'NEWSPACK-EMAILS',
						'warning'
					);
				}
			}
			Logger::log(
				'BlockEmailRenderer threw ' . get_class( $e ) . " for woo_email post $post_id ($email_class_name): " . $e->getMessage() . '; using legacy preview.',
				'NEWSPACK-EMAILS',
				'warning'
			);
			return null;
		}
	}

	/**
	 * Is email preview supported on this install?
	 *
	 * Requires Newspack Newsletters (the same dependency that gates
	 * email management in general).
	 *
	 * @return bool
	 */
	private static function is_supported(): bool {
		return class_exists( 'Newspack_Newsletters' );
	}

	/**
	 * Initialize the class. Hooked from class-newspack.php inclusion.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
	}

	/**
	 * Register the email-preview REST endpoint.
	 *
	 * Accepts two identifier shapes:
	 * - Numeric post ID (Newspack emails, WC block-editor template posts).
	 * - `wc:{email_id}` strings (WC classic-template emails with no
	 *   block-editor template post — e.g. WC Subscriptions emails).
	 *
	 * @codeCoverageIgnore
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'wizard/newspack-settings/emails/(?P<id>\d+|wc:[\w-]+)/preview',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_preview' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						// Mirror the URL path regex as an arg-level guard so a
						// malformed id supplied out-of-band (body/query) is
						// rejected before api_get_preview runs, not just one in
						// the URL segment.
						'validate_callback' => static function ( $value ) {
							return 1 === preg_match( '/^(\d+|wc:[\w-]+)$/', (string) $value );
						},
					],
				],
			]
		);
	}

	/**
	 * REST handler: return preview HTML for an email.
	 *
	 * Handles two identifier shapes:
	 * - Numeric: resolves to a post (newspack_rr_email or woo_email).
	 * - `wc:{email_id}`: routes to a WC email render path. If a
	 *   block-editor template post exists for the email, uses the block
	 *   render (themed + cached); otherwise falls back to WC's legacy
	 *   classic render.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_get_preview( $request ) {
		$id = (string) $request->get_param( 'id' );

		// WC classic / block-template path (e.g. "wc:customer_payment_retry").
		if ( str_starts_with( $id, 'wc:' ) ) {
			$wc_email_id = substr( $id, 3 );

			// Validate against the unified email config schema before any
			// resolution. The id must map to a config that's
			// source='woocommerce' — otherwise reject. The slice 1
			// unified-config refactor removed the parallel registry
			// lookup the legacy implementation used; `get_email_configs()`
			// is the single source of truth.
			$configs   = Emails::get_email_configs();
			$wc_config = $configs[ $wc_email_id ] ?? null;
			if ( ! $wc_config || 'woocommerce' !== ( $wc_config['source'] ?? '' ) ) {
				return new \WP_Error(
					'newspack_email_preview_not_found',
					__( 'Email not found.', 'newspack-plugin' ),
					[ 'status' => 404 ]
				);
			}

			// Prefer the block-editor render path when a template post
			// exists (themed + cached). Otherwise fall back to WC's
			// legacy classic render for emails without a template post
			// (WC Subscriptions, etc.).
			$template_post_id = Emails_Section::get_wc_email_template_post_id( $wc_email_id );
			if ( $template_post_id ) {
				// `get_wc_email_template_post_id` is a raw WC option read
				// — it doesn't verify the referenced post still exists or
				// is in an editable status. If the publisher trashed the
				// template post but the WC posts-manager option still
				// references the trashed ID, we'd render the trashed
				// content. Mirror the numeric branch's allowlist guard.
				$template_post = get_post( $template_post_id );
				if (
					$template_post
					&& 'woo_email' === $template_post->post_type
					&& in_array( $template_post->post_status, [ 'publish', 'draft', 'pending' ], true )
				) {
					$html = self::get_wc_preview_html( $template_post_id );
				} else {
					$html = self::get_wc_classic_preview_html( $wc_email_id );
				}
			} else {
				$html = self::get_wc_classic_preview_html( $wc_email_id );
			}

			if ( false === $html || empty( $html ) ) {
				return new \WP_Error(
					'newspack_email_preview_unavailable',
					__( 'Email preview is unavailable.', 'newspack-plugin' ),
					[ 'status' => 500 ]
				);
			}

			return rest_ensure_response(
				[
					'html' => $html,
					'id'   => $id,
				]
			);
		}

		// Numeric post ID path (Newspack emails, WC block-editor template posts).
		$post_id = absint( $id );
		if ( ! $post_id ) {
			return new \WP_Error(
				'newspack_email_preview_not_found',
				__( 'Email not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'newspack_email_preview_not_found',
				__( 'Email not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		// Don't preview trashed or auto-draft posts — they shouldn't be
		// reachable from the wizard's surfaced list, and previewing them
		// leaks their stored HTML through the endpoint. `manage_options`
		// gates this so the leak is admin-only, but the allowlist keeps
		// the endpoint scoped to publish-track statuses anyway.
		if ( ! in_array( $post->post_status, [ 'publish', 'draft', 'pending' ], true ) ) {
			return new \WP_Error(
				'newspack_email_preview_not_found',
				__( 'Email not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'woo_email' === $post->post_type ) {
			// Reverse-resolve the post → WC email class and confirm a
			// currently-surfaced source='woocommerce' config carries it
			// before rendering. Without this, a stale/orphan woo_email post
			// the wizard no longer surfaces would still render through this
			// endpoint (the `wc:` branch already validates against
			// get_email_configs(); this brings the numeric branch in line).
			if ( ! self::woo_email_post_is_surfaced( $post_id ) ) {
				return new \WP_Error(
					'newspack_email_preview_not_found',
					__( 'Email not found.', 'newspack-plugin' ),
					[ 'status' => 404 ]
				);
			}
			$html = self::get_wc_preview_html( $post_id );
		} elseif ( Emails::POST_TYPE === $post->post_type ) {
			$html = self::get_preview_html( $post_id );
		} else {
			return new \WP_Error(
				'newspack_email_preview_not_found',
				__( 'Email not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		if ( false === $html || empty( $html ) ) {
			return new \WP_Error(
				'newspack_email_preview_unavailable',
				__( 'Email preview is unavailable.', 'newspack-plugin' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response(
			[
				'html' => $html,
				'id'   => $post_id,
			]
		);
	}

	/**
	 * Render a WC classic-template email via WC's legacy EmailPreview.
	 *
	 * Used for WC emails that have no block-editor template post (e.g.
	 * WC Subs emails). The rendered HTML uses `woocommerce_email_*` option
	 * colors which a separate style-sync slice keeps in sync with the
	 * site's brand.
	 *
	 * Not cached — classic render is fast (~30–50 ms) and
	 * `woocommerce_email_*` options change without a post_modified
	 * timestamp to key against. The lazy-loading IntersectionObserver in
	 * the frontend limits concurrency in practice.
	 *
	 * @param string $wc_email_id The WC_Email ID (e.g. 'customer_payment_retry').
	 *
	 * @return string|false Rendered HTML, or false if unavailable.
	 */
	public static function get_wc_classic_preview_html( string $wc_email_id ) {
		$preview_class = 'Automattic\\WooCommerce\\Internal\\Admin\\EmailPreview\\EmailPreview';

		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( $preview_class ) ) {
			return false;
		}

		// Resolve email ID → class name via the slice 2a memoized helper
		// instead of re-walking the mailer here. The helper already
		// memoizes the slug→instance map per request, so this honors that
		// cache instead of paying a fresh mailer init on every preview.
		$wc_email = WooCommerce_Emails::get_wc_email_by_id( $wc_email_id );
		if ( ! $wc_email ) {
			return false;
		}
		$wc_email_class = get_class( $wc_email );

		// Wrap the preview instantiation + render in a try so a third-party
		// hook (e.g. `woocommerce_email_setup_locale`) throwing inside
		// WC's EmailPreview chain degrades to the logged-false return
		// instead of bubbling up to api_get_preview as a 500.
		try {
			$preview = $preview_class::instance();
			$preview->set_email_type( $wc_email_class );
			return $preview->render();
		} catch ( \Throwable $e ) {
			Logger::log(
				"WC EmailPreview::render() failed for '$wc_email_id' ($wc_email_class): " . $e->getMessage(),
				'NEWSPACK-EMAILS',
				'warning'
			);
			return false;
		}
	}

	/**
	 * Permissions check for the preview endpoint. Mirrors other Newspack email endpoints.
	 *
	 * @codeCoverageIgnore
	 * @return bool|\WP_Error
	 */
	public static function api_permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}
}


Email_Preview::init();
