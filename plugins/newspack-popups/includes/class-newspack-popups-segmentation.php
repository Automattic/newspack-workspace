<?php
/**
 * Newspack Segmentation Plugin
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use DrewM\MailChimp\MailChimp;

/**
 * Main Newspack Segmentation Plugin Class.
 */
final class Newspack_Popups_Segmentation {
	/**
	 * The single instance of the class.
	 *
	 * @var Newspack_Popups_Segmentation
	 */
	protected static $instance = null;

	/**
	 * Name of the option to store segments under.
	 */
	const SEGMENTS_OPTION_NAME = 'newspack_popups_segments';

	/**
	 * Query param appended to newsletter links carrying the reader's donor
	 * status. Its value is the ESP merge tag for the configured donor merge
	 * field (e.g. Mailchimp's *|HUB-MEMBER|*), which the ESP substitutes with
	 * the recipient's actual value at send time. The view script reads the
	 * substituted value on the inbound click to flag the reader as a donor for
	 * segmentation — no login required.
	 *
	 * This is an unsigned, reader-visible, forgeable signal: it must only ever
	 * drive prompt segmentation, never content access. Restricted content stays
	 * behind the HMAC-signed newsletter pass (see Newspack\Newsletters_Access).
	 */
	const DONOR_SEGMENT_QUERY_PARAM = 'np_seg_donor';

	/**
	 * Query param appended to newsletter links carrying the reader's account ID.
	 * Its value is the ESP merge tag for the synced Account field, substituted
	 * per recipient at send time. On arrival the ID resolves to the reader's
	 * last-known matched segments for the browsing session.
	 *
	 * Unsigned, forgeable, and enumerable: drives prompt segmentation only —
	 * never content access, analytics identity, or reader-profile writes.
	 *
	 * Emitted for every supported ESP, including ActiveCampaign's `%FIELD%`
	 * syntax (unsafe when unsubstituted, NPPM-3032), because
	 * handle_account_param() always redirects the param away before any output.
	 */
	const ACCOUNT_QUERY_PARAM = 'np_account';

	/**
	 * Cookie handing the resolved segment IDs to the view script, which moves
	 * them into sessionStorage and deletes the cookie on first read. The name
	 * must not start with `wp`, `wordpress`, or `comment_author` — Batcache
	 * skips page cache for requests carrying such cookies.
	 */
	const CARRIED_SEGMENTS_COOKIE = 'np_carried_segments';

	/**
	 * Cookie value asserting the account matched no segments, distinct from an
	 * absent cookie (no handoff). Cannot be an empty string: setcookie() sends a
	 * deletion for an empty value regardless of `expires`, so the assertion
	 * would never reach the browser. Never collides with a real segment ID
	 * (positive-integer term IDs). Mirrored in carried-segments.js — keep in sync.
	 */
	const CARRIED_SEGMENTS_NONE = 'none';

	/**
	 * Installed version number of the custom table.
	 */
	const TABLE_VERSION = '1.0';

	/**
	 * Option name for the installed version number of the custom table.
	 */
	const TABLE_VERSION_OPTION = '_newspack_popups_table_versions';

	/**
	 * Main Newspack Segmentation Plugin Instance.
	 * Ensures only one instance of Newspack Segmentation Plugin Instance is loaded or can be loaded.
	 *
	 * @return Newspack Segmentation Plugin Instance - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ __CLASS__, 'check_update_version' ] );

		// Remove legacy pruning CRON job.
		add_action( 'init', [ __CLASS__, 'cron_deactivate' ] );

		// Handle Mailchimp merge tag functionality.
		if (
			method_exists( '\Newspack_Newsletters', 'service_provider' ) &&
			'mailchimp' === \Newspack_Newsletters::service_provider() &&
			method_exists( '\Newspack\Data_Events', 'register_handler' ) &&
			method_exists( '\Newspack\Reader_Data', 'update_newsletter_subscribed_lists' )
		) {
			\Newspack\Data_Events::register_handler( [ __CLASS__, 'reader_logged_in' ], 'reader_logged_in' );
		}

		// Append the donor-status segment param to newsletter links so readers
		// arriving from a newsletter are segmented as donors without a login.
		// The handler self-guards on the donor merge field being configured and
		// the ESP being supported, so it's cheap to register unconditionally.
		add_filter( 'newspack_newsletters_process_link', [ __CLASS__, 'append_donor_segment_param' ], 30, 3 );

		// Append the reader's account ID to newsletter links. Self-guards on the
		// Account field being synced, so it's cheap to register unconditionally.
		add_filter( 'newspack_newsletters_process_link', [ __CLASS__, 'append_account_param' ], 30, 3 );

		// Strip unsubstituted donor merge tags from inbound URLs. Newsletters
		// already delivered carry tags this plugin can no longer stop emitting, and
		// an unresolved `%FIELD%` is a malformed percent-escape that crashes
		// consumers which decode query params strictly — see NPPM-3032. Priority 1
		// so it runs before redirect_canonical() and before any HTML is generated.
		add_action( 'template_redirect', [ __CLASS__, 'scrub_unsubstituted_donor_param' ], 1 );

		// Resolve an inbound account ID to carried segments and redirect the param
		// away. Priority 1: before redirect_canonical() and any HTML output.
		add_action( 'template_redirect', [ __CLASS__, 'handle_account_param' ], 1 );
	}

	/**
	 * Clear the cron job when this plugin is deactivated.
	 */
	public static function cron_deactivate() {
		wp_clear_scheduled_hook( 'newspack_popups_segmentation_data_prune' );
	}

	/**
	 * Permission callback for the API calls.
	 */
	public static function is_admin_user() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Checks if the custom table has been created and is up-to-date.
	 * See: https://codex.wordpress.org/Creating_Tables_with_Plugins
	 */
	public static function check_update_version() {
		$current_version = get_option( self::TABLE_VERSION_OPTION, false );

		if ( self::TABLE_VERSION !== $current_version ) {
			update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
		}
	}

	/**
	 * Get all configured segments.
	 *
	 * @param boolean $include_inactive If true, fetch both inactive and active segments. If false, only fetch active segments.
	 *
	 * @return array Array of segments.
	 */
	public static function get_segments( $include_inactive = true ) {
		return Newspack_Segments_Model::get_segments( $include_inactive );
	}

	/**
	 * Get a single segment by ID.
	 *
	 * @param string $id A segment id.
	 * @return object|null The single segment object with matching ID, or null.
	 */
	public static function get_segment( $id ) {
		return Newspack_Segments_Model::get_segment( $id );
	}

	/**
	 * Get segment IDs.
	 */
	public static function get_segment_ids() {
		return Newspack_Segments_Model::get_segment_ids();
	}

	/**
	 * Create a segment.
	 *
	 * @param object $segment A segment.
	 * @deprecated
	 */
	public static function create_segment( $segment ) {
		return Newspack_Segments_Model::create_segment( $segment );
	}

	/**
	 * Delete a segment.
	 *
	 * @param string $id A segment id.
	 */
	public static function delete_segment( $id ) {
		return Newspack_Segments_Model::delete_segment( $id );
	}

	/**
	 * Update a segment.
	 *
	 * @param object $segment A segment.
	 */
	public static function update_segment( $segment ) {
		return Newspack_Segments_Model::update_segment( $segment );
	}

	/**
	 * Sort all segments by relative priority.
	 *
	 * @param array $segment_ids Array of segment IDs, in order of desired priority.
	 * @return array Array of sorted segments.
	 * @deprecated
	 */
	public static function sort_segments( $segment_ids ) {
		return Newspack_Segments_Model::sort_segments( $segment_ids );
	}

	/**
	 * Validate an array of segment IDs against the existing segment IDs in the options table.
	 * When re-sorting segments, the IDs passed should all exist, albeit in a different order,
	 * so if there are any differences, validation will fail.
	 *
	 * @param array $segment_ids Array of segment IDs to validate.
	 * @param array $segments    Array of existing segments to validate against.
	 * @return boolean Whether $segment_ids is valid.
	 * @deprecated
	 */
	public static function validate_segment_ids( $segment_ids, $segments ) {
		return Newspack_Segments_Model::validate_segment_ids( $segment_ids, $segments );
	}

	/**
	 * Reindex segment priorities based on current position in array.
	 *
	 * @param object $segments Array of segments.
	 * @deprecated
	 */
	public static function reindex_segments( $segments ) {
		return Newspack_Segments_Model::reindex_segments( $segments );
	}

	/**
	 * Filter callback: append the donor-status segment param to first-party
	 * newsletter links.
	 *
	 * The appended value is the ESP merge tag for the configured donor merge
	 * field; the ESP substitutes the recipient's value at send time so the
	 * inbound click carries e.g. `?np_seg_donor=true`. Skips when the Newsletters
	 * tracking helper is unavailable, the post isn't a newsletter (ad links are
	 * proxied separately and wouldn't forward the param), the link is
	 * third-party, no donor merge field is configured, the ESP is unsupported, or
	 * the ESP's tag syntax can't survive in a URL (see is_url_safe_merge_tag()).
	 *
	 * @param string        $url          Processed URL (may already carry other params).
	 * @param string        $original_url Original URL before processing.
	 * @param \WP_Post|null $post         Newsletter post object, or null.
	 *
	 * @return string
	 */
	public static function append_donor_segment_param( $url, $original_url, $post ) {
		// Guard on the method, not just the class: the Tracking\Utils class predates
		// get_merge_tag(), so an older newspack-newsletters can satisfy a class_exists()
		// check while lacking the method — calling it would fatal mid-render, breaking
		// every newsletter on the site. method_exists() also returns false when the
		// class is absent, so this covers both the missing-class and version-skew cases.
		if ( ! method_exists( '\Newspack_Newsletters\Tracking\Utils', 'get_merge_tag' ) ) {
			return $url;
		}
		if ( ! self::is_newsletter_post( $post ) ) {
			return $url;
		}
		// Mailchimp's process_link() (priority 10) can hand back a bare merge-tag
		// placeholder as the whole URL (e.g. *|UNSUB|*), which is host-less and so
		// reads as first-party. Decorating it breaks the expanded link. Anchored
		// match: a real URL carrying a merge tag in a query value still passes.
		if ( self::is_unsubstituted_merge_tag( $url ) ) {
			return $url;
		}
		if ( ! self::is_first_party_url( $url ) ) {
			return $url;
		}
		// Read the option directly rather than via Newspack_Popups_Settings::get_setting(),
		// which builds the whole settings array (including a WP_Query over all pages)
		// on every call — wasteful here since this filter fires once per newsletter link.
		// No default: DEFAULT_DONOR_MERGE_FIELD ('DONAT') is a *name fragment* for the
		// substring matching below, not an ESP merge tag, and it only ever applied to
		// Mailchimp. Falling back to it here decorated links on every site that had
		// never configured the setting, with a tag no ESP resolves — see NPPM-3032.
		// This feature is opt-in: without an explicitly configured field, do nothing.
		$donor_merge_field = get_option( 'newspack_popups_mc_donor_merge_field', '' );
		// This setting is a comma-delimited list of name fragments used for substring
		// matching at login (see reader_logged_in()). Building a query-param merge tag
		// instead needs a single exact ESP merge tag — a multi-value list can't map to
		// one — so use the first entry. The value must be the exact merge tag (not a
		// display label or partial name) for the ESP to substitute it.
		$donor_merge_field = trim( explode( ',', (string) $donor_merge_field )[0] );
		if ( empty( $donor_merge_field ) ) {
			return $url;
		}
		$merge_tag = \Newspack_Newsletters\Tracking\Utils::get_merge_tag( $donor_merge_field );
		if ( empty( $merge_tag ) ) {
			return $url;
		}
		if ( ! self::is_url_safe_merge_tag( $merge_tag ) ) {
			return $url;
		}
		$url = add_query_arg( self::DONOR_SEGMENT_QUERY_PARAM, $merge_tag, $url );
		// add_query_arg() URL-encodes the value, but ESPs substitute only the raw
		// merge-tag syntax: Mailchimp leaves the percent-encoded form (%2A%7C...%7C%2A)
		// untouched, as verified against a live send, so the tag would never resolve.
		// Restore the raw tag so the ESP substitutes the recipient's value at send
		// time. An unsubstituted literal is ignored client-side, so this stays fail-safe.
		return str_replace( urlencode( $merge_tag ), $merge_tag, $url );
	}

	/**
	 * Filter callback: append the reader's account-ID merge tag to first-party
	 * newsletter links. Skips when the required helpers are unavailable, the
	 * post isn't a newsletter, the link is third-party, or the Account field
	 * has no resolvable ESP tag (i.e. it isn't synced there).
	 *
	 * @param string        $url          Processed URL (may already carry other params).
	 * @param string        $original_url Original URL before processing.
	 * @param \WP_Post|null $post         Newsletter post object, or null.
	 *
	 * @return string
	 */
	public static function append_account_param( $url, $original_url, $post ) {
		// method_exists() covers both a missing plugin and version skew; a fatal
		// here would break newsletter rendering.
		if ( ! method_exists( '\Newspack_Newsletters\Tracking\Utils', 'get_merge_tag' ) ) {
			return $url;
		}
		if ( ! method_exists( '\Newspack\Reader_Activation\Sync\Metadata', 'get_key' ) ) {
			return $url;
		}
		if ( ! self::is_newsletter_post( $post ) ) {
			return $url;
		}
		// Mailchimp's process_link() (priority 10) can hand back a bare merge-tag
		// placeholder as the whole URL (e.g. *|UNSUB|*), which is host-less and so
		// reads as first-party. Decorating it breaks the expanded link. Anchored
		// match: a real URL carrying a merge tag in a query value still passes.
		if ( self::is_unsubstituted_merge_tag( $url ) ) {
			return $url;
		}
		if ( ! self::is_first_party_url( $url ) ) {
			return $url;
		}

		$field_name = self::get_account_field_name();
		if ( '' === $field_name ) {
			return $url;
		}

		$tag_name = self::get_esp_field_tag_name( $field_name, $post );
		if ( '' === $tag_name ) {
			return $url;
		}

		$merge_tag = \Newspack_Newsletters\Tracking\Utils::get_merge_tag( $tag_name );
		if ( empty( $merge_tag ) ) {
			return $url;
		}

		// No is_url_safe_merge_tag() guard, unlike the donor handler: an
		// unsubstituted np_account is always redirected away before output.
		return self::append_raw_query_param( $url, self::ACCOUNT_QUERY_PARAM, $merge_tag );
	}

	/**
	 * Append a query parameter with its value left raw. Not add_query_arg():
	 * that reparses the URL and urlencode_deep()s values already in it,
	 * re-encoding another handler's raw merge tag into a form no ESP
	 * substitutes. The param is inserted before any `#fragment`.
	 *
	 * The value is not escaped, so it must not contain `&`, `=`, or `#`. ESP
	 * merge-tag syntaxes use none of them.
	 *
	 * @param string $url   URL, possibly with a query string and/or fragment.
	 * @param string $param Parameter name.
	 * @param string $value Raw parameter value; must not contain `&`, `=`, or `#`.
	 *
	 * @return string
	 */
	private static function append_raw_query_param( $url, $param, $value ) {
		$fragment = '';
		$hash_pos = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos );
			$url      = substr( $url, 0, $hash_pos );
		}
		$separator = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $separator . $param . '=' . $value . $fragment;
	}

	/**
	 * The prefixed ESP field name carrying the reader's account ID. The raw key
	 * is 'Account' in the v1 metadata schema and 'account' in legacy, and the
	 * prefix is configurable, so resolve through Metadata::get_key().
	 *
	 * @return string Prefixed field name, or '' when unresolvable.
	 */
	private static function get_account_field_name() {
		foreach ( [ 'Account', 'account' ] as $raw_key ) {
			$key = \Newspack\Reader_Activation\Sync\Metadata::get_key( $raw_key );
			if ( ! empty( $key ) ) {
				return (string) $key;
			}
		}
		return '';
	}

	/**
	 * The connected ESP's merge-tag name for a synced field. Not derivable from
	 * the field name: ActiveCampaign perstags can be renamed, and Mailchimp
	 * assigns tags per audience — hence the newsletter's send list.
	 *
	 * @param string   $field_name Prefixed ESP field name.
	 * @param \WP_Post $post       Newsletter post.
	 *
	 * @return string Tag name, or '' when unresolvable.
	 */
	private static function get_esp_field_tag_name( $field_name, $post ) {
		if ( ! method_exists( '\Newspack_Newsletters', 'get_service_provider' ) ) {
			return '';
		}
		$provider = \Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) || ! method_exists( $provider, 'get_field_merge_tag_name' ) ) {
			return '';
		}
		$list_id = (string) get_post_meta( $post->ID, 'send_list_id', true );
		return (string) $provider->get_field_merge_tag_name( $field_name, '' === $list_id ? null : $list_id );
	}

	/**
	 * Whether a merge tag can be placed raw in a URL without corrupting it.
	 *
	 * The tag is written to the query string unencoded so the ESP can substitute
	 * it, which means its literal characters must be valid there. ActiveCampaign's
	 * `%FIELD%` syntax is not: `%DO` is not a well-formed `%XX` escape, so any
	 * consumer that percent-decodes query params throws on it. Jetpack Instant
	 * Search does exactly that on every page load with no try/catch, so the
	 * exception blanks the page (NPPM-3032).
	 *
	 * This is not limited to a misconfigured field. A tag survives into the live
	 * URL whenever the ESP doesn't substitute it — an unknown field, a recipient
	 * missing the value, a forwarded or previewed email — so for a provider whose
	 * delimiter is `%` the broken URL is a routine outcome, not an edge case.
	 * Skipping the param costs that provider donor segmentation on newsletter
	 * clicks; emitting it risks breaking the page the reader landed on.
	 *
	 * Any `%` is rejected, rather than only malformed `%XX` shapes: a hex-shaped
	 * escape can still be invalid UTF-8 — `decodeURIComponent( '%FF' )` and
	 * `decodeURIComponent( '%C0%AF' )` both throw — so shape alone is not enough,
	 * and validating decoded UTF-8 here would be a lot of machinery to license a
	 * character no supported ESP needs. ActiveCampaign's `%FIELD%` delimiters are
	 * rejected wholesale regardless, and a `%` inside a Mailchimp / Constant
	 * Contact / Campaign Monitor field name is not a real merge field.
	 *
	 * @param string $merge_tag Raw ESP merge tag, e.g. '*|DONOR|*' or '%DONOR%'.
	 *
	 * @return bool
	 */
	private static function is_url_safe_merge_tag( $merge_tag ) {
		return false === strpos( (string) $merge_tag, '%' );
	}

	/**
	 * Whether a query-param value is still raw ESP merge-tag syntax — i.e. the
	 * sending service never replaced it with the recipient's value.
	 *
	 * Mirrors isUnsubstitutedMergeTag() in src/criteria/default/donation.js —
	 * keep the two in sync. The JS side uses this to ignore the value for
	 * segmentation; this side uses it to decide the param is safe to drop.
	 *
	 * Matching the bare-bracket Campaign Monitor form would be risky for an
	 * arbitrary query value, but `np_seg_donor` only ever carries a donor-status
	 * value (e.g. `true`, `monthly`, `$50.00`) — never a `[…]`-wrapped string.
	 *
	 * @param string $value Decoded query-param value.
	 *
	 * @return bool
	 */
	public static function is_unsubstituted_merge_tag( $value ) {
		$patterns = [
			'/^\*\|[^|]+\|\*$/',  // Mailchimp.
			'/^\[\[[^\]]+\]\]$/', // Constant Contact.
			'/^%[^%]+%$/',        // ActiveCampaign.
			'/^\[[^\][]+\]$/',    // Campaign Monitor.
		];
		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Action callback: redirect away any inbound URL still carrying an
	 * unsubstituted donor merge tag.
	 *
	 * Newsletters sent before the tag stopped being emitted are already in
	 * inboxes, so their links keep arriving with e.g. `?np_seg_donor=%DONAT%`.
	 * That value is a malformed percent-escape: `decodeURIComponent( '%DONAT%' )`
	 * throws, and Jetpack Instant Search decodes every query param on load with
	 * no try/catch, so the exception blanks the page (NPPM-3032). Redirecting
	 * before any output means no such consumer ever sees the param.
	 *
	 * Dropping the value costs nothing: an unsubstituted tag is not a donor
	 * signal, and the criteria script already ignores it (see
	 * isUnsubstitutedMergeTag() in donation.js). Substituted values — the ones
	 * that actually segment — are left alone, as is every other query param.
	 */
	public static function scrub_unsubstituted_donor_param() {
		// Redirecting a POST would discard its body, and a redirect is only
		// meaningful for a document request in a browser.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		// Reading a URL param to decide whether the URL itself is malformed; there
		// is no form submission or state change here to nonce-verify.
		if ( ! isset( $_GET[ self::DONOR_SEGMENT_QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		// Match against BOTH the raw query value and the percent-decoded one. PHP
		// decodes $_GET before this runs, so a tag whose field name begins with a hex
		// pair — `%CAFE%`, `%ABCD%` — arrives already mangled (`%CA` becomes one byte)
		// and no longer looks like a `%…%` tag, yet the raw URL still throws in a
		// strict client-side decoder (decodeURIComponent) and blanks the page. The raw
		// REQUEST_URI query preserves the literal `%XX` so the check can catch it. The
		// decoded value is still checked too, so a merge tag whose delimiters were
		// percent-encoded in transit (e.g. `%2A%7C…%7C%2A` for Mailchimp) is not
		// missed. See NPPM-3032.
		$raw_value     = self::get_raw_query_param( $request_uri, self::DONOR_SEGMENT_QUERY_PARAM );
		$decoded_value = sanitize_text_field( wp_unslash( $_GET[ self::DONOR_SEGMENT_QUERY_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::is_unsubstituted_merge_tag( $raw_value ) && ! self::is_unsubstituted_merge_tag( $decoded_value ) ) {
			return;
		}
		$clean_url = remove_query_arg(
			self::DONOR_SEGMENT_QUERY_PARAM,
			$request_uri
		);
		// Temporary: the param is a property of this one link, not of the page, so
		// nothing should cache the mapping permanently.
		wp_safe_redirect( $clean_url, 302 );
		exit;
	}

	/**
	 * Read a single query parameter's value from a URL *without* percent-decoding
	 * it, so a malformed escape (e.g. `%CAFE%`) survives intact for inspection.
	 *
	 * `$_GET`, parse_str() and wp_parse_args() all percent-decode, which is exactly
	 * what must be avoided when deciding whether a value is a raw merge tag that
	 * would break client-side decoding — see scrub_unsubstituted_donor_param().
	 *
	 * @param string $url   URL or path carrying a query string.
	 * @param string $param Parameter name to read.
	 *
	 * @return string Raw (still-encoded) value, or '' when the param is absent.
	 */
	private static function get_raw_query_param( $url, $param ) {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return '';
		}
		foreach ( explode( '&', $query ) as $pair ) {
			$parts = explode( '=', $pair, 2 );
			if ( $param === $parts[0] ) {
				return isset( $parts[1] ) ? $parts[1] : '';
			}
		}
		return '';
	}

	/**
	 * A reader's last-known matching segment IDs, filtered to the site's active
	 * segments. The snapshot is client-asserted and only as fresh as the
	 * reader's last visit; no age cap by design.
	 *
	 * @param int $account_id WordPress user ID from the inbound param.
	 *
	 * @return string[] Active segment IDs as strings.
	 */
	public static function get_carried_segments_for_account( int $account_id ): array {
		if ( $account_id < 1 || ! method_exists( '\Newspack\Reader_Data', 'get_matched_segments' ) ) {
			return [];
		}

		$snapshot = \Newspack\Reader_Data::get_matched_segments( $account_id );
		if ( empty( $snapshot ) || ! is_array( $snapshot ) ) {
			return [];
		}

		// Client-asserted input: drop shapes strval() below would mishandle.
		$snapshot = array_filter(
			$snapshot,
			function ( $value ) {
				return is_int( $value ) || is_string( $value );
			}
		);

		$active = [];
		foreach ( self::get_segments( false ) as $segment ) {
			if ( ! empty( $segment['id'] ) ) {
				$active[] = (string) $segment['id'];
			}
		}

		return array_values( array_intersect( array_map( 'strval', $snapshot ), $active ) );
	}

	/**
	 * The setcookie() options for the carried-segments cookie. Extracted so
	 * tests can reach these values — the setcookie() call itself never runs
	 * under PHPUnit, where headers are already sent.
	 *
	 * @return array setcookie() `$options` argument.
	 */
	private static function get_carried_segments_cookie_options(): array {
		return [
			'expires'  => 0,
			'path'     => '/',
			'secure'   => is_ssl(),
			// Readable by the view script; a segmentation hint, not a credential.
			'httponly' => false,
			'samesite' => 'Lax',
		];
	}

	/**
	 * The cookie value for a resolved segment set. Never an empty string:
	 * setcookie() sends a deletion for an empty value regardless of `expires`,
	 * so "matches nothing" would never reach the browser. An empty set becomes
	 * the CARRIED_SEGMENTS_NONE sentinel instead.
	 *
	 * @param string[] $segment_ids Active segment IDs; empty asserts no matches.
	 *
	 * @return string Comma-joined IDs, or CARRIED_SEGMENTS_NONE.
	 */
	private static function get_carried_segments_cookie_value( array $segment_ids ): string {
		return empty( $segment_ids ) ? self::CARRIED_SEGMENTS_NONE : implode( ',', $segment_ids );
	}

	/**
	 * Hand the resolved segment IDs to the view script in a session cookie.
	 * Every call is authoritative: an empty set overwrites a previous arrival's
	 * segments with the "matches nothing" sentinel rather than deleting the
	 * cookie. Callers must skip values that never passed the account-ID gate —
	 * those assert nothing about the reader.
	 *
	 * @param string[] $segment_ids Active segment IDs; empty asserts no matches.
	 */
	private static function set_carried_segments_cookie( $segment_ids ) {
		$value = self::get_carried_segments_cookie_value( $segment_ids );
		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie(
				self::CARRIED_SEGMENTS_COOKIE,
				$value,
				self::get_carried_segments_cookie_options()
			);
		}
		// Mirror in $_COOKIE so same-request readers (and tests, where headers are
		// already sent) see the same value a browser would receive.
		$_COOKIE[ self::CARRIED_SEGMENTS_COOKIE ] = $value; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Action callback: resolve an inbound account ID to carried segments, hand
	 * them off in a cookie, and redirect to the clean URL.
	 *
	 * The redirect is unconditional whenever the param is present: it keeps the
	 * landing page a shared cacheable URL, and it is what makes ActiveCampaign's
	 * `%FIELD%` syntax safe to emit at all (NPPM-3032) — the param never
	 * survives into a rendered page. Only a plain positive integer is accepted;
	 * that one rule rejects every unsubstituted merge-tag shape. The cookie
	 * handoff is conditional on that gate, the redirect is not.
	 */
	public static function handle_account_param() {
		// Redirecting a POST would discard its body.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		// Reading a URL param to decide where to redirect; there is no form
		// submission or state change here to nonce-verify.
		if ( ! isset( $_GET[ self::ACCOUNT_QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_GET[ self::ACCOUNT_QUERY_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 1 === preg_match( '/^[1-9][0-9]*$/', $raw ) ) {
			// Gate passed, so the value asserts something real — including "no
			// segments" when the resolved set is empty.
			self::set_carried_segments_cookie( self::get_carried_segments_for_account( (int) $raw ) );
		}

		// This redirect may carry a per-reader Set-Cookie and must never be stored.
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		nocache_headers();

		$clean_url = remove_query_arg(
			self::ACCOUNT_QUERY_PARAM,
			esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		);
		// Temporary: the param is a property of this one link, not of the page.
		wp_safe_redirect( $clean_url, 302 );
		exit;
	}

	/**
	 * Whether the given post is a Newspack newsletter.
	 *
	 * @param \WP_Post|null $post Post object.
	 *
	 * @return bool
	 */
	private static function is_newsletter_post( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( ! defined( '\Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT' ) ) {
			return false;
		}
		return \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT === $post->post_type;
	}

	/**
	 * Whether the given URL points to this site, by host comparison.
	 *
	 * The donor flag is appended only to first-party links: pushing it onto
	 * third-party URLs would leak the reader's donor status into external logs,
	 * analytics, and Referer headers for no benefit, since only this site reads
	 * the param. Relative URLs are first-party by definition.
	 *
	 * @param string $url URL to test.
	 *
	 * @return bool
	 */
	private static function is_first_party_url( $url ) {
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $url_host ) ) {
			return true;
		}
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		return strcasecmp( $url_host, (string) $site_host ) === 0;
	}

	/**
	 * Check if a Mailchimp merge field value should be considered as a positive donor indicator.
	 *
	 * @param mixed $field_value The merge field value to check.
	 * @return bool Whether the value indicates the contact is a donor.
	 */
	public static function is_donor_merge_field_value( $field_value ) {
		$falsy_values = [ 'no', 'none', 'false', '0', '' ];
		return ! in_array( strtolower( (string) $field_value ), $falsy_values, true );
	}

	/**
	 * When a reader logs in and the connected ESP is Mailchimp, check their donation status.
	 * If they have a non-empty value in a merge field which matches the newspack_popups_mc_donor_merge_field
	 * setting, then they should be segmented as a donor.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 */
	public static function reader_logged_in( $timestamp, $data ) {
		// See newspack-newsletters/includes/class-newspack-newsletters.php:827.
		$api_key = \get_option( 'newspack_mailchimp_api_key', false );

		if ( ! $api_key ) {
			return;
		}

		try {
			$mailchimp = new Mailchimp( $api_key );
		} catch ( \Exception $th ) {
			return;
		}

		$user_id = $data['user_id'];
		$email   = $data['email'];

		$contacts = $mailchimp->get(
			'search-members',
			[
				'fields' => [ 'members.email_address', 'members.merge_fields' ],
				'query'  => $email,
			]
		);

		if ( isset( $contacts['exact_matches']['members'][0] ) ) {
			$contact           = $contacts['exact_matches']['members'][0];
			$merge_fields      = $contact['merge_fields'];
			$donor_merge_field = Newspack_Popups_Settings::get_setting( 'newspack_popups_mc_donor_merge_field' );

			foreach ( $merge_fields as $field_name => $field_value ) {
				if ( false !== strpos( $field_name, $donor_merge_field ) && self::is_donor_merge_field_value( $field_value ) ) {
					if ( method_exists( '\Newspack\Logger', 'log' ) ) {
						\Newspack\Logger::log(
							sprintf(
								'Setting reader %d with email %s as a donor due to Mailchimp merge tag match.',
								$user_id,
								$email
							),
							'NEWSPACK-POPUPS'
						);
					}
					\Newspack\Reader_Data::set_is_donor( time(), [ 'user_id' => $user_id ] );
				}
			}
		}
	}
}
Newspack_Popups_Segmentation::instance();
