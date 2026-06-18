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
	 * third-party, no donor merge field is configured, or the ESP is unsupported.
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
		if ( ! self::is_first_party_url( $url ) ) {
			return $url;
		}
		// Read the option directly rather than via Newspack_Popups_Settings::get_setting(),
		// which builds the whole settings array (including a WP_Query over all pages)
		// on every call — wasteful here since this filter fires once per newsletter link.
		$donor_merge_field = get_option( 'newspack_popups_mc_donor_merge_field', Newspack_Popups_Settings::DEFAULT_DONOR_MERGE_FIELD );
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
		$url = add_query_arg( self::DONOR_SEGMENT_QUERY_PARAM, $merge_tag, $url );
		// add_query_arg() URL-encodes the value, but ESPs substitute only the raw
		// merge-tag syntax: Mailchimp leaves the percent-encoded form (%2A%7C...%7C%2A)
		// untouched, as verified against a live send, so the tag would never resolve.
		// Restore the raw tag so the ESP substitutes the recipient's value at send
		// time. An unsubstituted literal is ignored client-side, so this stays fail-safe.
		return str_replace( urlencode( $merge_tag ), $merge_tag, $url );
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
