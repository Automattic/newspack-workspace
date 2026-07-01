<?php
/**
 * Service Provider: ActiveCampaign Implementation
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack\Newsletters\Send_Lists;
use Newspack\Newsletters\Send_List;

/**
 * ActiveCampaign ESP Class.
 */
final class Newspack_Newsletters_Active_Campaign extends \Newspack_Newsletters_Service_Provider {

	/**
	 * Default timeout, in seconds, for ActiveCampaign API requests.
	 *
	 * @var int
	 */
	const DEFAULT_REQUEST_TIMEOUT = 45;

	/**
	 * Timeout, in seconds, for best-effort cleanup requests (deleting a
	 * previously-created campaign before a new send). ActiveCampaign
	 * occasionally stops responding to calls that reference a particular
	 * campaign; when that happens the campaign_list/campaign_delete cleanup must
	 * fail fast instead of consuming the whole request budget and stranding the
	 * send behind a stuck campaign it was only trying to tidy up.
	 *
	 * This bound is also reused for the safety-critical status check in
	 * get_campaign_dispatch_state(): a fast failure there is desirable, since a
	 * slow/unresponsive ActiveCampaign correctly routes to the fail-safe
	 * "unverified" branch that blocks the resend. Don't lower it on the
	 * assumption it only affects disposable cleanup calls.
	 *
	 * @var int
	 */
	const CLEANUP_REQUEST_TIMEOUT = 15;

	/**
	 * Dispatch states for a stored campaign, returned by
	 * get_campaign_dispatch_state(): a confirmed fresh draft that is safe to
	 * (re)send, a campaign that has already been dispatched and must never be
	 * resent, or an indeterminate state that needs manual review before resending.
	 */
	const CAMPAIGN_DRAFT              = 'draft';
	const CAMPAIGN_ALREADY_DISPATCHED = 'dispatched';
	const CAMPAIGN_NEEDS_REVIEW       = 'needs_review';

	/**
	 * ActiveCampaign V1 campaign status codes (the `status` field on a campaign).
	 * See https://www.activecampaign.com/api/example.php?call=campaign_status.
	 */
	const STATUS_DRAFT     = '0';
	const STATUS_SCHEDULED = '1';
	const STATUS_SENDING   = '2';
	const STATUS_PAUSED    = '3';
	const STATUS_STOPPED   = '4';
	const STATUS_COMPLETED = '5';
	const STATUS_DISABLED  = '6';

	/**
	 * Campaign statuses that can be safely deleted (not mid- or post-send).
	 */
	const DELETABLE_STATUSES = [ self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_DISABLED ];

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	public $name = 'ActiveCampaign';

	/**
	 * Cached fields.
	 *
	 * @var array
	 */
	private $fields = null;

	/**
	 * Cached lists.
	 *
	 * @var array
	 */
	private $lists = null;

	/**
	 * Cached segments.
	 *
	 * @var array
	 */
	private $segments = null;

	/**
	 * Cached contact data.
	 *
	 * @var array
	 */
	private $contact_data = [];

	/**
	 * Whether the provider has support to tags and tags based Subscription Lists.
	 *
	 * @var boolean
	 */
	public static $support_local_lists = true;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->service    = 'active_campaign';
		$this->controller = new Newspack_Newsletters_Active_Campaign_Controller( $this );

		add_action( 'updated_post_meta', [ $this, 'save' ], 10, 4 );
		add_action( 'wp_trash_post', [ $this, 'trash' ], 10, 1 );

		add_action( 'newspack_newsletters_subscription_lists_metabox_after_tag', [ $this, 'lists_metabox_notice' ] );

		parent::__construct( $this );
	}

	/**
	 * Get configuration for conditional tag support.
	 *
	 * @return array
	 */
	public static function get_conditional_tag_support() {
		return [
			'support_url' => 'https://help.activecampaign.com/hc/en-us/articles/220358207-Use-Conditional-Content',
			'example'     => [
				'before' => '%IF in_array(\'Interested in cameras\', $TAGS)%',
				'after'  => '%/IF%',
			],
		];
	}

	/**
	 * ActiveCampaign personalization-tag dictionary for the editor autocomplete.
	 *
	 * Sourced from: https://help.activecampaign.com/hc/en-us/articles/220709307-Personalization-Tags-overview
	 *
	 * @return array
	 */
	public static function get_merge_tags() {
		return [
			'label'          => __( 'personalization tag', 'newspack-newsletters' ),
			'trigger_prefix' => '',
			'tags'           => [
				/* Contact personalization. */
				[
					'tag'   => '%EMAIL%',
					'label' => __( "Inserts the contact's email address.", 'newspack-newsletters' ),
				],
				[
					'tag'      => '%FIRSTNAME%',
					'label'    => __( "Inserts the contact's first name.", 'newspack-newsletters' ),
					'keywords' => [ 'first name' ],
				],
				[
					'tag'      => '%LASTNAME%',
					'label'    => __( "Inserts the contact's last name.", 'newspack-newsletters' ),
					'keywords' => [ 'last name' ],
				],
				[
					'tag'      => '%FULLNAME%',
					'label'    => __( "Inserts the contact's full name.", 'newspack-newsletters' ),
					'keywords' => [ 'full name' ],
				],
				[
					'tag'      => '%PHONE%',
					'label'    => __( "Inserts the contact's phone number.", 'newspack-newsletters' ),
					'keywords' => [ 'telephone' ],
				],
				[
					'tag'      => '%ORGANIZATION%',
					'label'    => __( "Inserts the contact's organization name.", 'newspack-newsletters' ),
					'keywords' => [ 'company', 'organization' ],
				],
				/* Subscription metadata. */
				[
					'tag'   => '%SUBDATE%',
					'label' => __( 'Inserts the date the contact subscribed to the list.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%SUBTIME%',
					'label' => __( 'Inserts the time the contact subscribed to the list.', 'newspack-newsletters' ),
				],
				/* Campaign links. */
				[
					'tag'   => '%UNSUBSCRIBELINK%',
					'label' => __( 'Inserts the unsubscribe link required in every email.', 'newspack-newsletters' ),
				],
				[
					'tag'      => '%WEBCOPY%',
					'label'    => __( 'Inserts a link to view this campaign in a web browser (web copy link).', 'newspack-newsletters' ),
					'keywords' => [ 'web copy', 'view in browser' ],
				],
				[
					'tag'   => '%WEBCOPY-NOSOCIAL%',
					'label' => __( 'Inserts a web copy link without social-share links.', 'newspack-newsletters' ),
				],
				[
					'tag'      => '%UPDATELINK%',
					'label'    => __( "Inserts a link to the contact's subscription preferences page.", 'newspack-newsletters' ),
					'keywords' => [ 'preferences', 'update profile' ],
				],
				[
					'tag'      => '%FORWARD2FRIEND%',
					'label'    => __( 'Inserts a link that lets the contact forward this email to a friend.', 'newspack-newsletters' ),
					'keywords' => [ 'forward' ],
				],
				/* Deals (most recent deal). */
				[
					'tag'   => '%DEAL_OWNER_FIRST_NAME%',
					'label' => __( "Inserts the deal owner's first name.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_OWNER_LAST_NAME%',
					'label' => __( "Inserts the deal owner's last name.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_OWNER_EMAIL%',
					'label' => __( "Inserts the deal owner's email address.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_TITLE%',
					'label' => __( 'Inserts the deal title.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_DESCRIPTION%',
					'label' => __( 'Inserts the deal description.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_VALUE%',
					'label' => __( 'Inserts the deal value.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_STATUS%',
					'label' => __( 'Inserts the deal status.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_PIPELINE%',
					'label' => __( 'Inserts the deal pipeline.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_STAGE%',
					'label' => __( 'Inserts the deal stage.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_CREATED_DATE%',
					'label' => __( 'Inserts the deal created date.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%DEAL_UPDATED_DATE%',
					'label' => __( 'Inserts the deal last-updated date.', 'newspack-newsletters' ),
				],
				/* Account fields. */
				[
					'tag'      => '%ACCT_NAME%',
					'label'    => __( 'Inserts the account name.', 'newspack-newsletters' ),
					'keywords' => [ 'account' ],
				],
				[
					'tag'   => '%ACCT_URL%',
					'label' => __( 'Inserts the account URL.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%ACCT_CREATED_DATE%',
					'label' => __( 'Inserts the account created date.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%ACCT_UPDATED_DATE%',
					'label' => __( 'Inserts the account last-updated date.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%ACCT_ADDRESS_1%',
					'label' => __( 'Inserts the account address line 1.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%ACCT_ADDRESS_2%',
					'label' => __( 'Inserts the account address line 2.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%ACCT_CITY%',
					'label' => __( 'Inserts the account city.', 'newspack-newsletters' ),
				],
				[
					'tag'      => '%ACCT_STATE_PROVIDENCE%',
					'label'    => __( 'Inserts the account state or province.', 'newspack-newsletters' ),
					'keywords' => [ 'state', 'province' ],
				],
				[
					'tag'      => '%ACCT_POSTAL_CODE%',
					'label'    => __( 'Inserts the account postal code.', 'newspack-newsletters' ),
					'keywords' => [ 'zip', 'postcode' ],
				],
				[
					'tag'   => '%ACCT_NUMBER_OF_EMPLOYEES%',
					'label' => __( 'Inserts the account number of employees.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%ACCT_ANNUAL_REVENUE%',
					'label' => __( 'Inserts the account annual revenue.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%ACCT_INDUSTRY_VERTICAL%',
					'label' => __( 'Inserts the account industry or vertical.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '%ACCT_COUNTRY%',
					'label' => __( 'Inserts the account country.', 'newspack-newsletters' ),
				],
				/* Social share. */
				[
					'tag'      => '%SOCIALSHARE%',
					'label'    => __( 'Inserts the full set of social-share submit links.', 'newspack-newsletters' ),
					'keywords' => [ 'social' ],
				],
				[
					'tag'      => '%SOCIAL-FACEBOOK-LIKE%',
					'label'    => __( 'Inserts a Facebook Like button.', 'newspack-newsletters' ),
					'keywords' => [ 'facebook' ],
				],
				[
					'tag'      => '%SOCIALSHARE-FACEBOOK%',
					'label'    => __( 'Inserts a Share on Facebook link/icon.', 'newspack-newsletters' ),
					'keywords' => [ 'facebook' ],
				],
				[
					'tag'      => '%SOCIALSHARE-FACEBOOK-LINK%',
					'label'    => __( 'Inserts a Share on Facebook link (no icon).', 'newspack-newsletters' ),
					'keywords' => [ 'facebook' ],
				],
				[
					'tag'      => '%SOCIALSHARE-TWITTER%',
					'label'    => __( 'Inserts a Share on X (formerly Twitter) link/icon.', 'newspack-newsletters' ),
					'keywords' => [ 'x', 'twitter' ],
				],
				[
					'tag'      => '%SOCIALSHARE-TWITTER-LINK%',
					'label'    => __( 'Inserts a Share on X (formerly Twitter) link (no icon).', 'newspack-newsletters' ),
					'keywords' => [ 'x', 'twitter' ],
				],
				[
					'tag'      => '%SOCIALSHARE-DIGG%',
					'label'    => __( 'Inserts a Share on Digg link/icon.', 'newspack-newsletters' ),
					'keywords' => [ 'digg' ],
				],
				[
					'tag'      => '%SOCIALSHARE-DIGG-LINK%',
					'label'    => __( 'Inserts a Share on Digg link (no icon).', 'newspack-newsletters' ),
					'keywords' => [ 'digg' ],
				],
				[
					'tag'      => '%SOCIALSHARE-REDDIT%',
					'label'    => __( 'Inserts a Share on Reddit link/icon.', 'newspack-newsletters' ),
					'keywords' => [ 'reddit' ],
				],
				[
					'tag'      => '%SOCIALSHARE-REDDIT-LINK%',
					'label'    => __( 'Inserts a Share on Reddit link (no icon).', 'newspack-newsletters' ),
					'keywords' => [ 'reddit' ],
				],
				[
					'tag'      => '%SOCIALSHARE-DELICIOUS%',
					'label'    => __( 'Inserts a Share on del.icio.us link/icon.', 'newspack-newsletters' ),
					'keywords' => [ 'delicious' ],
				],
				[
					'tag'      => '%SOCIALSHARE-DELICIOUS-LINK%',
					'label'    => __( 'Inserts a Share on del.icio.us link (no icon).', 'newspack-newsletters' ),
					'keywords' => [ 'delicious' ],
				],
				[
					'tag'      => '%SOCIALSHARE-GPLUS%',
					'label'    => __( 'Inserts a Share on Google+ link/icon.', 'newspack-newsletters' ),
					'keywords' => [ 'google plus', 'gplus' ],
				],
				[
					'tag'      => '%SOCIALSHARE-GPLUS-LINK%',
					'label'    => __( 'Inserts a Share on Google+ link (no icon).', 'newspack-newsletters' ),
					'keywords' => [ 'google plus', 'gplus' ],
				],
				[
					'tag'      => '%SOCIALSHARE-STUMBLEUPON%',
					'label'    => __( 'Inserts a Share on StumbleUpon link/icon.', 'newspack-newsletters' ),
					'keywords' => [ 'stumbleupon' ],
				],
				[
					'tag'      => '%SOCIALSHARE-STUMBLEUPON-LINK%',
					'label'    => __( 'Inserts a Share on StumbleUpon link (no icon).', 'newspack-newsletters' ),
					'keywords' => [ 'stumbleupon' ],
				],
				/* Date and time. */
				[
					'tag'      => '%TODAY%',
					'label'    => __( "Inserts today's date.", 'newspack-newsletters' ),
					'keywords' => [ 'date', 'today' ],
				],
				[
					'tag'      => '%TODAY+1%',
					'label'    => __( "Inserts today's date plus N days. Replace 1 with the number of days you want to add.", 'newspack-newsletters' ),
					'keywords' => [ 'date', 'tomorrow', 'future' ],
				],
				[
					'tag'      => '%TODAY-1%',
					'label'    => __( "Inserts today's date minus N days. Replace 1 with the number of days you want to subtract.", 'newspack-newsletters' ),
					'keywords' => [ 'date', 'yesterday', 'past' ],
				],
				/* Sender info. */
				[
					'tag'      => '%SENDER-INFO%',
					'label'    => __( 'Inserts the sender / list contact info.', 'newspack-newsletters' ),
					'keywords' => [ 'sender', 'address' ],
				],
				[
					'tag'      => '%SENDER-INFO-SINGLELINE%',
					'label'    => __( 'Inserts the sender / list contact info on a single line.', 'newspack-newsletters' ),
					'keywords' => [ 'sender', 'address' ],
				],
				[
					'tag'      => '%SENDER-LIST-REMINDER%',
					'label'    => __( "Inserts the list's permission reminder.", 'newspack-newsletters' ),
					'keywords' => [ 'reminder' ],
				],
				/* Contact and list metadata. */
				[
					'tag'      => '%CONTACTIP%',
					'label'    => __( "Inserts the contact's IP address.", 'newspack-newsletters' ),
					'keywords' => [ 'ip' ],
				],
				[
					'tag'      => '%LISTNAME%',
					'label'    => __( "Inserts the name of the contact's list.", 'newspack-newsletters' ),
					'keywords' => [ 'list' ],
				],
				[
					'tag'      => '%SUBSCRIBERID%',
					'label'    => __( "Inserts the contact's unique ID.", 'newspack-newsletters' ),
					'keywords' => [ 'id', 'subscriber' ],
				],
				[
					'tag'   => '%CAMPAIGNID%',
					'label' => __( "Inserts the campaign's unique ID.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '%MESSAGEID%',
					'label' => __( "Inserts the message's unique ID.", 'newspack-newsletters' ),
				],
			],
		];
	}

	/**
	 * Test the ActiveCampaign API connection.
	 *
	 * @return true|WP_Error True if the connection is successful, WP_Error otherwise.
	 */
	public function test_connection() {
		$result = $this->api_v3_request( 'users/me' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Perform v3 API request.
	 *
	 * @param string $resource Resource path.
	 * @param string $method   HTTP method.
	 * @param array  $options  Request options.
	 *
	 * @return object|WP_Error The API response body or WP_Error.
	 */
	public function api_v3_request( $resource, $method = 'GET', $options = [] ) {
		if ( ! $this->has_api_credentials() ) {
			return new \WP_Error(
				'newspack_newsletters_active_campaign_api_credentials_missing',
				__( 'Active Campaign API credentials are missing.', 'newspack-newsletters' )
			);
		}
		$credentials = $this->api_credentials();
		$api_path    = '/api/3/';
		$query       = isset( $options['query'] ) ? $options['query'] : [];
		$timeout     = isset( $options['timeout'] ) ? (int) $options['timeout'] : self::DEFAULT_REQUEST_TIMEOUT;
		unset( $options['timeout'] );
		$url         = add_query_arg(
			$query,
			rtrim( $credentials['url'], '/' ) . $api_path . $resource
		);
		$args        = [
			'method'  => $method,
			'timeout' => $timeout, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'api-token'    => $credentials['key'],
			],
		];
		$response    = wp_safe_remote_request( $url, $args + $options );
		if ( is_wp_error( $response ) ) {
			return $this->humanize_transport_error( $response );
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$response_message = wp_remote_retrieve_response_message( $response );

		// Treat HTTP 400 (Bad Request) as an error, not just 401+: a bare 400 with
		// no `errors` array would otherwise slip through as a valid response and
		// feed bad data into callers (including the dispatch-state reasoning).
		if ( 400 <= $response_code || ! empty( $response_body['errors'] ) ) {
			$errors = new WP_Error();
			if ( isset( $response_body['errors'] ) && is_array( $response_body['errors'] ) ) {
				foreach ( $response_body['errors'] as $error ) {
					$errors->add( $error['code'] ?? 'error', $error['title'] );
				}
			} elseif ( ! empty( $response_message ) ) {
				$errors->add( $response_code, $response_message );
			}

			return $errors;
		}
		return $response_body;
	}

	/**
	 * Translate a low-level HTTP transport failure into a publisher-friendly error.
	 *
	 * ActiveCampaign sometimes stops responding to calls that reference a
	 * particular campaign, which surfaces from wp_safe_remote_request as a cURL
	 * timeout (error 28, "0 bytes received"). That raw message is meaningless to
	 * a publisher, so timeouts are rephrased into actionable guidance. Every
	 * other transport error is returned unchanged so genuine failures keep their
	 * original, more specific message.
	 *
	 * The substring match is for the publisher-facing *message* only; it is not
	 * relied on for send-safety. get_campaign_dispatch_state() fails safe on
	 * ANY error, so a timeout that this match ever missed would still block a
	 * resend rather than slip through.
	 *
	 * The 'cURL error 28' token is locale-stable (cURL error numbers aren't
	 * translated), so the important case is always caught. The 'timed out'
	 * fallback is locale/transport-fragile (cron/CLI or non-English builds may
	 * phrase it differently); when it misses, the publisher sees the raw message.
	 * That is a residual UX gap only, never a safety issue.
	 *
	 * @param WP_Error $error A WP_Error returned by wp_safe_remote_request.
	 *
	 * @return WP_Error The original error, or a friendlier one for timeouts.
	 */
	private function humanize_transport_error( $error ) {
		$message = $error->get_error_message();
		if ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'cURL error 28' ) ) {
			return new \WP_Error(
				'newspack_newsletters_active_campaign_timeout',
				__( 'ActiveCampaign did not respond in time. This is usually a temporary issue on ActiveCampaign\'s end. Please wait a few minutes and try again; if the problem continues, try sending a fresh copy of the newsletter.', 'newspack-newsletters' ),
				// Preserve the original transport failure for logging and support
				// tooling; the publisher sees the friendly message above.
				[
					'original_error_code'    => $error->get_error_code(),
					'original_error_message' => $message,
					'original_error_data'    => $error->get_error_data(),
				]
			);
		}
		return $error;
	}

	/**
	 * Perform v1 API request.
	 *
	 * @param string $action  API Action.
	 * @param string $method  HTTP method.
	 * @param array  $options Request options.
	 *
	 * @return array|WP_Error The API response body or WP_Error.
	 */
	public function api_v1_request( $action, $method = 'GET', $options = [] ) {
		if ( ! $this->has_api_credentials() ) {
			return new \WP_Error(
				'newspack_newsletters_active_campaign_api_credentials_missing',
				__( 'ActiveCampaign API credentials are missing.', 'newspack-newsletters' )
			);
		}
		$credentials   = $this->api_credentials();
		$params        = [
			'api_key'    => $credentials['key'],
			'api_action' => $action,
			'api_output' => 'json',
		];
		$api_path      = '/admin/api.php';
		$options_query = [];
		if ( isset( $options['query'] ) ) {
			$options_query = $options['query'];
			unset( $options['query'] );
		}
		$timeout = isset( $options['timeout'] ) ? (int) $options['timeout'] : self::DEFAULT_REQUEST_TIMEOUT;
		unset( $options['timeout'] );
		$content_type = 'application/json';
		$url          = rtrim( $credentials['url'], '/' ) . $api_path;
		$body         = null;
		$params       = wp_parse_args( $options_query, $params );
		if ( 'POST' === $method ) {
			$content_type = 'application/x-www-form-urlencoded';
			$body         = wp_parse_args(
				isset( $options['body'] ) ? $options['body'] : [],
				$params
			);
		} else {
			$url = add_query_arg( $params, $url );
		}
		$args     = [
			'method'  => $method,
			'timeout' => $timeout, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			'headers' => [
				'Content-Type' => $content_type,
				'Accept'       => 'application/json',
				'API-TOKEN'    => $credentials['key'],
			],
			'body'    => $body,
		];
		$response = wp_safe_remote_request( $url, $args + $options );
		if ( is_wp_error( $response ) ) {
			return $this->humanize_transport_error( $response );
		}
		$body = json_decode( $response['body'], true );

		do_action(
			'newspack_log',
			'newspack_newsletters_active_campaign_api_v1_request',
			'API v1 Request',
			[
				'log_level' => 1,
				'file'      => 'newspack_newsletters_active_campaign_api_v1_request',
				'data'      => [
					'action'        => $action,
					'method'        => $method,
					'options'       => $options,
					'response_body' => $body,
				],
			]
		);

		if ( ! $body ) {
			return new \WP_Error(
				'newspack_newsletters_active_campaign_api_error',
				! empty( $response['response']['message'] ) ? $response['response']['message'] : __( 'An error occurred while communicating with ActiveCampaign.', 'newspack-newsletters' )
			);
		}

		if ( 1 !== $body['result_code'] ) {
			$message = ! empty( $body['result_message'] ) ? $body['result_message'] : __( 'An error occurred while communicating with ActiveCampaign.', 'newspack-newsletters' );
			return new \WP_Error(
				'newspack_newsletters_active_campaign_api_error',
				$message
			);
		}
		return $body;
	}

	/**
	 * Get API credentials for service provider.
	 *
	 * @return array Stored API credentials for the service provider.
	 */
	public function api_credentials() {
		return [
			'url' => get_option( 'newspack_newsletters_active_campaign_url' ),
			'key' => get_option( 'newspack_newsletters_active_campaign_key' ),
		];
	}

	/**
	 * Check if provider has all necessary credentials set.
	 *
	 * @return Boolean Result.
	 */
	public function has_api_credentials() {
		$credentials = $this->api_credentials();
		return ! empty( $credentials['url'] ) && ! empty( $credentials['key'] );
	}

	/**
	 * Retrieve the ESP's tag ID from its name
	 *
	 * @param string  $tag_name The tag.
	 * @param boolean $create_if_not_found Whether to create a new tag if not found. Default to true.
	 * @param string  $list_id The List ID. Not needed for Active Campaign.
	 * @return int|WP_Error The tag ID on success. WP_Error on failure.
	 */
	public function get_tag_id( $tag_name, $create_if_not_found = true, $list_id = null ) {
		$tag_name = (string) $tag_name;
		$search   = $this->api_v3_request(
			'tags',
			'GET',
			[
				'query' => [
					'search' => $tag_name,
				],
			]
		);

		if ( ! empty( $search['tags'] ) ) {
			foreach ( $search['tags'] as $found_tag ) {
				if ( ! empty( $found_tag['tag'] ) && strtolower( $tag_name ) === strtolower( $found_tag['tag'] ) ) {
					return (int) $found_tag['id'];
				}
			}
		}

		// Tag was not found.
		if ( ! $create_if_not_found ) {
			return new WP_Error(
				'newspack_newsletter_tag_not_found'
			);
		}

		$created = $this->create_tag( $tag_name );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return (int) $created['id'];
	}

	/**

	 * Retrieve the ESP's tag name from its ID
	 *
	 * @param int    $tag_id The tag ID.
	 * @param string $list_id The List ID.
	 * @return string|WP_Error The tag name on success. WP_Error on failure.
	 */
	public function get_tag_by_id( $tag_id, $list_id = null ) {
		$search = $this->api_v3_request(
			sprintf( 'tags/%d', $tag_id )
		);
		if ( ! empty( $search['tag'] ) && ! empty( $search['tag']['tag'] ) ) {
			return $search['tag']['tag'];
		}
		return new WP_Error(
			'newspack_newsletter_tag_not_found'
		);
	}

	/**
	 * Get the IDs of the tags associated with a contact.
	 *
	 * @param string $email The contact email.
	 * @return array|WP_Error The tag IDs on success. WP_Error on failure.
	 */
	public function get_contact_tags_ids( $email ) {
		$contact_data = $this->get_contact_data( $email );
		if ( is_wp_error( $contact_data ) ) {
			return $contact_data;
		}
		$result = $this->api_v3_request(
			sprintf( 'contacts/%d/contactTags', $contact_data['id'] ),
			'GET'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_values(
			array_map(
				function ( $tag ) {
					return (int) $tag['tag'];
				},
				$result['contactTags']
			)
		);
	}

	/**
	 * Create a Tag on the provider
	 *
	 * @param string $tag The Tag name.
	 * @param string $list_id The List ID. Not needed for Active Campaign.
	 * @return array|WP_Error The tag representation with at least 'id' and 'name' keys on succes. WP_Error on failure.
	 */
	public function create_tag( $tag, $list_id = null ) {
		$tag_info = [
			'tag' => [
				'tag'         => $tag,
				'tagType'     => 'contact',
				'description' => 'Created by Newspack Newsletters to manage subscription lists',
			],
		];

		$created = $this->api_v3_request(
			'tags',
			'POST',
			[
				'body' => wp_json_encode( $tag_info ),
			]
		);
		if ( is_array( $created ) && ! empty( $created['tag'] ) && ! empty( $created['tag']['id'] ) ) {
			$created['tag']['name'] = $created['tag']['tag'];
			return $created['tag'];
		}
		return new WP_Error(
			'newspack_newsletters_error_creating_tag',
			! empty( $created['error'] ) ? $created['error'] : ''
		);
	}

	/**
	 * Updates a Tag name on the provider
	 *
	 * @param string|int $tag_id The tag ID.
	 * @param string     $tag The Tag new name.
	 * @param string     $list_id The List ID. Not needed for Active Campaign.
	 * @return array|WP_Error The tag representation with at least 'id' and 'name' keys on succes. WP_Error on failure.
	 */
	public function update_tag( $tag_id, $tag, $list_id = null ) {
		$tag_info = [
			'tag' => [
				'tag'         => $tag,
				'tagType'     => 'contact',
				'description' => 'Created by Newspack Newsletters to manage subscription lists',
			],
		];

		$created = $this->api_v3_request(
			sprintf( 'tags/%d', $tag_id ),
			'PUT',
			[
				'body' => wp_json_encode( $tag_info ),
			]
		);
		if ( is_array( $created ) && ! empty( $created['tag'] ) && ! empty( $created['tag']['id'] ) ) {
			$created['tag']['name'] = $created['tag']['tag'];
			return $created['tag'];
		}
		return new WP_Error(
			'newspack_newsletters_error_updating_tag',
			! empty( $created['error'] ) ? $created['error'] : ''
		);
	}

	/**
	 * Add a tag to a contact
	 *
	 * @param string     $email The contact email.
	 * @param string|int $tag The tag ID.
	 * @param string     $list_id The List ID. Not needed for Active Campaign.
	 * @return true|WP_Error
	 */
	public function add_tag_to_contact( $email, $tag, $list_id = null ) {
		$existing_contact = $this->get_contact_data( $email );
		if ( is_wp_error( $existing_contact ) ) {
			return $existing_contact;
		}

		$contact_tag = [
			'contactTag' => [
				'contact' => (int) $existing_contact['id'],
				'tag'     => $tag,
			],
		];

		$created = $this->api_v3_request(
			'contactTags',
			'POST',
			[
				'body' => wp_json_encode( $contact_tag ),
			]
		);

		if ( is_wp_error( $created ) ) {
			$created = [ 'message' => $created->get_error_message() ];
		}

		if ( is_array( $created ) && ! empty( $created['contactTag'] ) ) {
			return true;
		}

		return new WP_Error(
			'newspack_newsletter_error_adding_tag_to_contact',
			! empty( $created['message'] ) ? $created['message'] : ''
		);
	}

	/**
	 * Remove a tag from a contact
	 *
	 * @param string     $email The contact email.
	 * @param string|int $tag The tag ID.
	 * @param string     $list_id The List ID. Not needed for Active Campaign.
	 * @return true|WP_Error
	 */
	public function remove_tag_from_contact( $email, $tag, $list_id = null ) {
		$existing_contact = $this->get_contact_data( $email );
		if ( is_wp_error( $existing_contact ) ) {
			return $existing_contact;
		}

		$contact_tag_id = $this->get_contact_tag_id( $email, $tag );

		if ( is_wp_error( $contact_tag_id ) ) {
			return $contact_tag_id;
		}

		$deleted = $this->api_v3_request(
			sprintf( 'contactTags/%d', $contact_tag_id ),
			'DELETE'
		);

		if ( is_array( $deleted ) && empty( $deleted ) ) {
			return true;
		}

		return new WP_Error(
			'newspack_newsletter_error_removing_tag_from_contact',
			! empty( $deleted['message'] ) ? $deleted['message'] : ''
		);
	}

	/**
	 * Get the ContactTag relationship ID from the provider
	 *
	 * @param string $email The contact email.
	 * @param int    $tag_id The Tag ID retrieved with get_tag_id.
	 * @return int|WP_Error The ID on success. WP_Error on failure.
	 */
	private function get_contact_tag_id( $email, $tag_id ) {
		$existing_contact = $this->get_contact_data( $email );
		if ( is_wp_error( $existing_contact ) ) {
			return $existing_contact;
		}

		$contact_tags = $this->api_v3_request(
			sprintf( 'contacts/%d/contactTags', (int) $existing_contact['id'] ),
			'GET'
		);

		if ( is_array( $contact_tags ) && ! empty( $contact_tags['contactTags'] ) ) {
			foreach ( $contact_tags['contactTags'] as $contact_tag ) {
				if ( (int) $tag_id === (int) $contact_tag['tag'] ) {
					return (int) $contact_tag['id'];
				}
			}
		}

		return new WP_Error(
			'newspack_newsletter_error_fetching_contact_tags'
		);
	}

	/**
	 * Set the API credentials for the service provider.
	 *
	 * @param array $credentials API credentials.
	 */
	public function set_api_credentials( $credentials ) {
		if ( empty( $credentials['url'] ) || empty( $credentials['key'] ) ) {
			return new WP_Error(
				'newspack_newsletters_invalid_keys',
				__( 'Please input ActiveCampaign API URL and Key.', 'newspack-newsletters' )
			);
		} else {
			$updated_url = update_option( 'newspack_newsletters_active_campaign_url', $credentials['url'] );
			$updated_key = update_option( 'newspack_newsletters_active_campaign_key', $credentials['key'] );
			do_action( 'newspack_newsletters_provider_credentials_changed', 'active_campaign' );
			return $updated_url && $updated_key;
		}
	}

	/**
	 * Get lists.
	 *
	 * @param array $args Query args to pass to the lists_lists endpoint.
	 *                    For supported args, see: https://www.activecampaign.com/api/example.php?call=list_list.
	 *
	 * @return array|WP_Error List of existing lists or error.
	 */
	public function get_lists( $args = [] ) {
		if ( null !== $this->lists ) {
			if ( ! empty( $args['ids'] ) ) {
				return array_values(
					array_filter(
						$this->lists,
						function ( $list ) use ( $args ) {
							return Send_Lists::matches_id( $args['ids'], $list['id'] );
						}
					)
				);
			}
			if ( ! empty( $args['filters[name]'] ) ) {
				return array_values(
					array_filter(
						$this->lists,
						function ( $list ) use ( $args ) {
							return Send_Lists::matches_search( $args['filters[name]'], [ $list['name'] ] );
						}
					)
				);
			}
			return $this->lists;
		}
		if ( empty( $args['ids'] ) && empty( $args['filters[name]'] ) ) {
			$args['ids'] = 'all';
		}
		$lists = $this->api_v1_request( 'list_list', 'GET', [ 'query' => $args ] );
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}
		// Remove result metadata.
		unset( $lists['result_code'] );
		unset( $lists['result_message'] );
		unset( $lists['result_output'] );

		if ( ! empty( $args['ids'] ) && 'all' === $args['ids'] ) {
			$this->lists = array_values( $lists );
		}
		return array_values( $lists );
	}

	/**
	 * Get all applicable lists and segments as Send_List objects.
	 *
	 * @param array   $args Array of search args. See Send_Lists::get_default_args() for supported params and default values.
	 * @param boolean $to_array If true, convert Send_List objects to arrays before returning.
	 *
	 * @return Send_List[]|array|WP_Error Array of Send_List objects or arrays on success, or WP_Error object on failure.
	 */
	public function get_send_lists( $args = [], $to_array = false ) {
		$send_lists = [];
		if ( empty( $args['type'] ) || 'list' === $args['type'] ) {
			$list_args = [
				'limit' => ! empty( $args['limit'] ) ? intval( $args['limit'] ) : 100,
			];

			// Search by IDs.
			if ( ! empty( $args['ids'] ) ) {
				$list_args['ids'] = implode( ',', $args['ids'] );
			}

			// Search by name.
			if ( ! empty( $args['search'] ) ) {
				if ( is_array( $args['search'] ) ) {
					return new WP_Error(
						'newspack_newsletters_active_campaign_fetch_send_lists',
						__( 'ActiveCampaign supports searching by a single search term only.', 'newspack-newsletters' )
					);
				}
				$list_args['filters[name]'] = $args['search'];
			}

			$lists = $this->get_lists( $list_args );
			if ( is_wp_error( $lists ) ) {
				return $lists;
			}
			foreach ( $lists as $list ) {
				$send_lists[] = new Send_List(
					[
						'provider'    => $this->service,
						'type'        => 'list',
						'id'          => $list['id'],
						'name'        => $list['name'],
						'entity_type' => 'list',
						'count'       => $list['subscriber_count'] ?? 0,
					]
				);
			}
		}

		if ( empty( $args['type'] ) || 'sublist' === $args['type'] ) {
			$segment_args = [];
			if ( ! empty( $args['ids'] ) ) {
				$segment_args['ids'] = $args['ids'];
			}
			if ( ! empty( $args['search'] ) ) {
				$segment_args['search'] = $args['search'];
			}
			$segments = $this->get_segments( $segment_args );
			if ( is_wp_error( $segments ) ) {
				return $segments;
			}
			foreach ( $segments as $segment ) {
				$segment_name = ! empty( $segment['attributes']['name'] ) ?
					$segment['attributes']['name'] :
					sprintf(
						// Translators: %s is the segment ID.
						__( 'Untitled %s', 'newspack-newsletters' ),
						$segment['id']
					);
				$send_lists[] = new Send_List(
					[
						'provider'    => $this->service,
						'type'        => 'sublist',
						'id'          => $segment['id'],
						'parent_id'   => $args['parent_id'] ?? null,
						'name'        => $segment_name,
						'entity_type' => 'segment',
						'count'       => $segment['attributes']['counts']['last_active_total']['count'] ?? null,
					]
				);
			}
		}

		// Convert to arrays if requested.
		if ( $to_array ) {
			$send_lists = array_map(
				function ( $list ) {
					return $list->to_array();
				},
				$send_lists
			);
		}
		return $send_lists;
	}

	/**
	 * Get segments.
	 *
	 * @param array $args Array of search args.
	 *
	 * @return array|WP_Error List os existing segments or error.
	 */
	public function get_segments( $args = [] ) {
		if ( null !== $this->segments ) {
			if ( ! empty( $args['ids'] ) ) {
				$filtered = array_values(
					array_filter(
						$this->segments,
						function ( $segment ) use ( $args ) {
							return Send_Lists::matches_id( $args['ids'], $segment['id'] );
						}
					)
				);
				return array_slice( $filtered, 0, $args['limit'] ?? count( $filtered ) );
			}
			if ( ! empty( $args['search'] ) ) {
				$filtered = array_values(
					array_filter(
						$this->segments,
						function ( $segment ) use ( $args ) {
							return Send_Lists::matches_search( $args['search'], [ $segment['attributes']['name'] ] );
						}
					)
				);
				return array_slice( $filtered, 0, $args['limit'] ?? count( $filtered ) );
			}
			return $this->segments;
		}

		$query_args               = $args;
		$query_args['page_size']  = $args['limit'] ?? 500;
		$query_args['page']       = 1;
		$result = $this->api_v3_request(
			'audiences',
			'GET',
			[
				'query' => $query_args,
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$segments = $result['data'];
		if ( isset( $args['limit'] ) ) {
			return $segments;
		}

		// If not passed a limit, get all the segments.
		$total = $result['meta']['page']['total'];
		while ( $total > $query_args['page_size'] * $query_args['page'] ) {
			$query_args['page'] = $query_args['page'] + 1;
			$result = $this->api_v3_request(
				'audiences',
				'GET',
				[
					'query' => $query_args,
				]
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$segments = array_merge( $segments, $result['data'] );
		}

		$this->segments = $segments;
		if ( ! empty( $args['ids'] ) || ! empty( $args['search'] ) ) {
			return $this->get_segments( $args );
		}

		return $this->segments;
	}

	/**
	 * List method not used in this ESP, but required by parent class.
	 *
	 * @param string $post_id The post ID.
	 * @param string $list_id The list ID.
	 */
	public function list( $post_id, $list_id ) {
		return null;
	}

	/**
	 * Get the address ID to be used for the campaign. Default to 0, which will use AC default address.
	 *
	 * @return int
	 */
	private function get_address_id() {
		/**
		 * Specifies the ActiveCampaign address ID to use for campaigns.
		 * If not set, ActiveCampaign's default address will be used.
		 * Find your address ID in ActiveCampaign under Settings > Addresses.
		 *
		 * @constant NEWSPACK_NEWSLETTERS_ACTIVE_CAMPAIGN_ADDRESS_ID
		 * @type     int
		 * @default  0 (uses ActiveCampaign default address)
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NEWSLETTERS_ACTIVE_CAMPAIGN_ADDRESS_ID', 1 );
		 */
		if ( ! defined( 'NEWSPACK_NEWSLETTERS_ACTIVE_CAMPAIGN_ADDRESS_ID' ) ) {
			return 0;
		}
		return NEWSPACK_NEWSLETTERS_ACTIVE_CAMPAIGN_ADDRESS_ID;
	}

	/**
	 * Given legacy newsletterData, extract sender and send-to info.
	 *
	 * @param array $newsletter_data Newsletter data from the ESP.
	 * @return array {
	 *    Extracted sender and send-to info. All keys are optional and will be
	 *    returned only if found in the campaign data.
	 *
	 *    @type string $senderName Sender name.
	 *    @type string $senderEmail Sender email.
	 *    @type string $list_id List ID.
	 *    @type string $sublist_id Sublist ID.
	 * }
	 */
	public function extract_campaign_info( $newsletter_data ) {
		$campaign_info = [];

		// Sender info.
		if ( ! empty( $newsletter_data['from_name'] ) ) {
			$campaign_info['senderName'] = $newsletter_data['from_name'];
		}
		if ( ! empty( $newsletter_data['from_email'] ) ) {
			$campaign_info['senderEmail'] = $newsletter_data['from_email'];
		}

		// List.
		if ( ! empty( $newsletter_data['list_id'] ) ) {
			$campaign_info['list_id'] = $newsletter_data['list_id'];
		}

		// Segment.
		if ( ! empty( $newsletter_data['segment_id'] ) ) {
			$campaign_info['sublist_id'] = $newsletter_data['segment_id'];
		}

		return $campaign_info;
	}

	/**
	 * Retrieve a campaign.
	 *
	 * @param int  $post_id    Numeric ID of the Newsletter post.
	 * @param bool $skip_sync Whether to skip syncing the campaign.
	 * @throws Exception Error message.

	 * @return array|WP_Error API Response or error.
	 */
	public function retrieve( $post_id, $skip_sync = false ) {
		try {
			if ( ! $this->has_api_credentials() ) {
				throw new Exception( esc_html__( 'Missing or invalid ActiveCampign credentials.', 'newspack-newsletters' ) );
			}

			$campaign_id     = get_post_meta( $post_id, 'ac_campaign_id', true );
			$send_list_id    = get_post_meta( $post_id, 'send_list_id', true );
			$send_sublist_id = get_post_meta( $post_id, 'send_sublist_id', true );
			$newsletter_data = [
				'campaign'    => true, // Satisfy the JS API.
				'campaign_id' => $campaign_id,
			];

			// Handle legacy send-to meta.
			if ( ! $send_list_id ) {
				$legacy_list_id = get_post_meta( $post_id, 'ac_list_id', true );
				if ( $legacy_list_id ) {
					$newsletter_data['send_list_id'] = $legacy_list_id;
					$send_list_id               = $legacy_list_id;
				}
			}
			if ( ! $send_sublist_id ) {
				$legacy_sublist_id = get_post_meta( $post_id, 'ac_segment_id', true );
				if ( $legacy_sublist_id ) {
					$newsletter_data['send_sublist_id'] = $legacy_sublist_id;
					$send_sublist_id               = $legacy_sublist_id;
				}
			}
			$send_lists = $this->get_send_lists( // Get first 10 top-level send lists for autocomplete.
				[
					'ids'  => $send_list_id ? [ $send_list_id ] : null, // If we have a selected list, make sure to fetch it.
					'type' => 'list',
				],
				true
			);
			if ( is_wp_error( $send_lists ) ) {
				throw new Exception( wp_kses_post( $send_lists->get_error_message() ) );
			}
			$newsletter_data['lists'] = $send_lists;
			$send_sublists = $send_list_id || $send_sublist_id ?
				$this->get_send_lists(
					[
						'ids'       => [ $send_sublist_id ], // If we have a selected sublist, make sure to fetch it. Otherwise, we'll populate sublists later.
						'parent_id' => $send_list_id,
						'type'      => 'sublist',
					],
					true
				) :
				[];
			if ( is_wp_error( $send_sublists ) ) {
				throw new Exception( wp_kses_post( $send_sublists->get_error_message() ) );
			}
			$newsletter_data['sublists'] = $send_sublists;

			if ( $campaign_id ) {
				$newsletter_data['link'] = sprintf(
					'https://%s.activehosted.com/app/campaigns/%d',
					explode( '.', str_replace( 'https://', '', $this->api_credentials()['url'] ) )[0],
					$campaign_id
				);
			}

			// Handle legacy sender meta.
			$from_name   = get_post_meta( $post_id, 'senderName', true );
			$from_email  = get_post_meta( $post_id, 'senderEmail', true );
			if ( ! $from_name ) {
				$legacy_from_name = get_post_meta( $post_id, 'ac_from_name', true );
				if ( $legacy_from_name ) {
					$newsletter_data['senderName'] = $legacy_from_name;
				}
			}
			if ( ! $from_email ) {
				$legacy_from_email = get_post_meta( $post_id, 'ac_from_email', true );
				if ( $legacy_from_email ) {
					$newsletter_data['senderEmail'] = $legacy_from_email;
				}
			}

			if ( ! $campaign_id && true !== $skip_sync ) {
				$sync_result = $this->sync( get_post( $post_id ) );
				if ( is_wp_error( $sync_result ) ) {
					throw new Exception( $sync_result->get_error_message() );
				}
				$newsletter_data = wp_parse_args(
					$sync_result,
					$newsletter_data
				);
			}
			return $newsletter_data;
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_active_campaign_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Sender method not used in this ESP, but required by parent class.
	 *
	 * @param string $post_id    Numeric ID of the campaign.
	 * @param string $from_name  Sender name.
	 * @param string $from_email Sender email address.
	 */
	public function sender( $post_id, $from_name, $from_email ) {
		return null;
	}

	/**
	 * Send test email or emails.
	 *
	 * @param integer $post_id Numeric ID of the Newsletter post.
	 * @param array   $emails Array of email addresses to send to.
	 * @return array|WP_Error API Response or error.
	 */
	public function test( $post_id, $emails ) {
		if ( ! $this->has_api_credentials() ) {
			return new \WP_Error(
				'newspack_newsletters_active_campaign_api_credentials_missing',
				__( 'ActiveCampaign API credentials are missing.', 'newspack-newsletters' )
			);
		}
		/** Clear existing test campaigns for this post. */
		$test_campaigns = get_post_meta( $post_id, 'ac_test_campaign', false );
		if ( ! empty( $test_campaigns ) ) {
			foreach ( $test_campaigns as $test_campaign_id ) {
				$delete_res = $this->delete_campaign( $test_campaign_id, true );
				if ( ! is_wp_error( $delete_res ) ) {
					delete_post_meta( $post_id, 'ac_test_campaign', $test_campaign_id );
				}
			}
		}
		$post        = get_post( $post_id );
		$sync_result = $this->sync( $post );
		if ( is_wp_error( $sync_result ) ) {
			return $sync_result;
		}
		/** Create disposable campaign for sending a test. */
		$campaign_name = sprintf( 'Test for %s', $this->get_campaign_name( $post ) );
		$campaign      = $this->create_campaign( get_post( $post_id ), $campaign_name, true );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		add_post_meta( $post_id, 'ac_test_campaign', $campaign['id'] );

		/** Get the latest message ID from the temporary campaign. */
		$campaign_data = $this->api_v1_request(
			'campaign_list',
			'GET',
			[
				'query' => [
					'action' => 'test',
					'ids'    => $campaign['id'],
				],
			]
		);
		if ( is_wp_error( $campaign_data ) ) {
			return $campaign_data;
		}
		$campaign_messages = explode( ',', $campaign_data[0]['messageslist'] );
		$message_id        = ! empty( $campaign_messages ) ? reset( $campaign_messages ) : 0;

		$test_result = $this->api_v1_request(
			'campaign_send',
			'GET',
			[
				'query' => [
					'type'       => 'html',
					'action'     => 'test',
					'campaignid' => $campaign['id'],
					'messageid'  => $message_id,
					'email'      => implode( ',', $emails ),
				],
			]
		);
		if ( is_wp_error( $test_result ) ) {
			return new WP_Error(
				'newspack_newsletters_active_campaign_test',
				sprintf( 'Sending test campaign failed: %s', $test_result->get_error_message() )
			);
		}
		return [
			'message' => sprintf(
				// translators: %s are comma-separated emails.
				__( 'ActiveCampaign test message sent successfully to %s.', 'newspack-newsletters' ),
				implode( ', ', $emails )
			),
			'result'  => $test_result,
		];
	}

	/**
	 * Synchronize post with corresponding ESP campaign.
	 *
	 * @param WP_Post $post Post to synchronize.
	 *
	 * @return array|WP_Error Campaign data or error.
	 */
	public function sync( $post ) {
		if ( ! $this->has_api_credentials() ) {
			return new \WP_Error(
				'newspack_newsletters_active_campaign_api_credentials_missing',
				__( 'ActiveCampaign API credentials are missing.', 'newspack-newsletters' )
			);
		}
		if ( empty( $post->post_title ) ) {
			return new WP_Error(
				'newspack_newsletter_error',
				__( 'The newsletter subject cannot be empty.', 'newspack-newsletters' )
			);
		}

		// Clear prior error messages.
		$transient_name = $this->get_transient_name( $post->ID );
		delete_transient( $transient_name );

		$from_name    = get_post_meta( $post->ID, 'senderName', true );
		$from_email   = get_post_meta( $post->ID, 'senderEmail', true );
		$send_list_id = get_post_meta( $post->ID, 'send_list_id', true );
		$message_id   = get_post_meta( $post->ID, 'ac_message_id', true );

		$renderer = new Newspack_Newsletters_Renderer();
		$content  = $renderer->retrieve_email_html( $post );

		$message_action = 'message_add';
		$message_data   = [];
		$sync_data = [
			'campaign' => true, // Satisfy JS API.
		];

		if ( $message_id ) {
			$message = $this->api_v1_request( 'message_view', 'GET', [ 'query' => [ 'id' => $message_id ] ] );
			if ( is_wp_error( $message ) ) {
				return $message;
			}
			$message_action     = 'message_edit';
			$message_data['id'] = $message['id'];

			// If sender data is not available locally, update from ESP.
			if ( ! $from_name || ! $from_email ) {
				$sync_data['senderName']  = $message['fromname'];
				$sync_data['senderEmail'] = $message['fromemail'];
			}
		} else {
			// Validate required meta if campaign and message are not yet created.
			if ( empty( $from_name ) || empty( $from_email ) ) {
				return new \WP_Error(
					'newspack_newsletters_active_campaign_invalid_sender',
					__( 'Please input sender name and email address.', 'newspack-newsletters' )
				);
			}
			if ( empty( $send_list_id ) ) {
				return new \WP_Error(
					'newspack_newsletters_active_campaign_invalid_list',
					__( 'Please select a list.', 'newspack-newsletters' )
				);
			}
		}

		$message_data = wp_parse_args(
			[
				'format'                   => 'html',
				'htmlconstructor'          => 'editor',
				'html'                     => $content,
				'p[' . $send_list_id . ']' => 1,
				'fromemail'                => $from_email,
				'fromname'                 => $from_name,
				'subject'                  => html_entity_decode( $post->post_title ),
			],
			$message_data
		);

		$message = $this->api_v1_request( $message_action, 'POST', [ 'body' => $message_data ] );
		if ( is_wp_error( $message ) ) {
			return $message;
		}

		update_post_meta( $post->ID, 'ac_message_id', $message['id'] );
		$sync_data['message_id'] = $message['id'];

		// Retrieve and store campaign data.
		$data = $this->retrieve( $post->ID, true );
		if ( is_wp_error( $data ) ) {
			set_transient( $transient_name, __( 'ActiveCampaign sync error: ', 'newspack-newsletters' ) . $data->get_error_message(), 45 );
			return $data;
		} else {
			$data = array_merge( $data, $sync_data );
		}

		return $sync_data;
	}

	/**
	 * Create a campaign for the given post.
	 *
	 * @param WP_Post $post          Post to create campaign for.
	 * @param string  $campaign_name Optional custom title for this campaign.
	 * @param bool    $is_test       Whether this campaign is a disposable test send. Test sends deliver via `action=test` with explicit recipient emails, so the campaign's segmentid is never used — segment validation is skipped to let publishers test newsletters that reference a deleted-but-still-saved segment.
	 *
	 * @return array|WP_Error Campaign data or error.
	 */
	private function create_campaign( $post, $campaign_name = '', $is_test = false ) {
		$sync_result = $this->sync( $post );
		if ( is_wp_error( $sync_result ) ) {
			return $sync_result;
		}

		$message = $this->api_v1_request( 'message_view', 'GET', [ 'query' => [ 'id' => $sync_result['message_id'] ] ] );
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		if ( empty( $message['html'] ) ) {
			return new \WP_Error(
				'newspack_newsletters_active_campaign_message_html_missing',
				__( 'Error creating campaign: Message HTML is missing. Campaign not sent.', 'newspack-newsletters' )
			);
		}

		$from_name       = get_post_meta( $post->ID, 'senderName', true );
		$from_email      = get_post_meta( $post->ID, 'senderEmail', true );
		$send_list_id    = get_post_meta( $post->ID, 'send_list_id', true );
		$send_sublist_id = get_post_meta( $post->ID, 'send_sublist_id', true );

		// A configured-but-unresolvable segment must NOT silently fall through
		// to "no segment" — AC interprets segmentid=0 as "send to the entire
		// parent audience", and sent email cannot be unsent. Verify the segment
		// resolves before submitting; only an explicitly unset send_sublist_id
		// (null or '' — no segment ever picked) is treated as an intentional
		// whole-list send. A literal "0" is treated as configured-but-invalid
		// rather than as "no segment", since AC segment IDs are positive
		// integers and zero is the sentinel for the whole-audience case.
		$has_configured_segment = ! $is_test && null !== $send_sublist_id && '' !== $send_sublist_id;
		if ( $has_configured_segment ) {
			// Note: AC segments are global per account, not scoped to a parent
			// list. `get_send_lists()` accepts `parent_id` but only echoes it
			// back on the returned `Send_List` — it does NOT filter the AC
			// `audiences` lookup. A segment that belongs to a different list
			// will therefore still resolve here. Cross-list mismatches are an
			// inherent AC limitation rather than something this guard can
			// catch.
			$segment_check = $this->get_send_lists(
				[
					'type'      => 'sublist',
					'ids'       => [ $send_sublist_id ],
					'parent_id' => $send_list_id,
				]
			);
			if ( is_wp_error( $segment_check ) ) {
				return new \WP_Error(
					'newspack_newsletters_active_campaign_segment_lookup_failed',
					sprintf(
						// Translators: %s is the upstream error message from ActiveCampaign.
						__( 'Could not verify the selected segment with ActiveCampaign (%s). Sending was aborted to avoid sending to the entire audience.', 'newspack-newsletters' ),
						$segment_check->get_error_message()
					)
				);
			}
			if ( empty( $segment_check ) ) {
				return new \WP_Error(
					'newspack_newsletters_active_campaign_segment_not_found',
					__( 'The selected segment could not be found in ActiveCampaign. Sending was aborted to avoid sending to the entire audience. Please re-select a segment and try again.', 'newspack-newsletters' )
				);
			}
		}

		$is_public = get_post_meta( $post->ID, 'is_public', true );
		if ( empty( $campaign_name ) ) {
			$campaign_name = $this->get_campaign_name( $post );
		}
		$campaign_data = [
			'type'                                  => 'single',
			'status'                                => 0, // 0 = Draft; 1 = Scheduled.
			'public'                                => (int) $is_public,
			'name'                                  => $campaign_name,
			'fromname'                              => $from_name,
			'fromemail'                             => $from_email,
			'segmentid'                             => $has_configured_segment ? $send_sublist_id : 0, // 0 = No segment (intentional whole-list send, or a test send where the segment is irrelevant because delivery is via `action=test` with explicit recipients).
			'p[' . $send_list_id . ']'              => $send_list_id,
			'm[' . $sync_result['message_id'] . ']' => 100, // 100 = 100% of contacts will receive this.
			'addressid'                             => $this->get_address_id(),
		];
		/**
		 * Disables link click tracking in ActiveCampaign campaigns.
		 * When enabled, ActiveCampaign will not track which links
		 * subscribers click in your emails.
		 *
		 * @constant NEWSPACK_NEWSLETTERS_AC_DISABLE_LINK_TRACKING
		 * @type     bool
		 * @default  Link tracking enabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NEWSLETTERS_AC_DISABLE_LINK_TRACKING', true );
		 */
		if ( defined( 'NEWSPACK_NEWSLETTERS_AC_DISABLE_LINK_TRACKING' ) && NEWSPACK_NEWSLETTERS_AC_DISABLE_LINK_TRACKING ) {
			$campaign_data['tracklinks'] = 'none';
		}
		return $this->api_v1_request( 'campaign_create', 'POST', [ 'body' => $campaign_data ] );
	}

	/**
	 * Delete a campaign.
	 *
	 * @param int  $campaign_id The Campaign ID.
	 * @param bool $force       Whether to delete the campaign regardless of its status.
	 *
	 * @return array|WP_Error API response data or error.
	 */
	private function delete_campaign( $campaign_id, $force = false ) {
		// Deleting a previous campaign is best-effort cleanup, never the operation
		// the publisher is waiting on, so it must fail fast: a campaign stuck in
		// ActiveCampaign would otherwise hang this call for the full request
		// timeout and strand the new send behind it.
		//
		// The status look-up only exists to enforce the deletable-status guard,
		// so it is skipped entirely when forcing — saving a round-trip (the
		// callers that pass $force already know the campaign is disposable).
		if ( true !== $force ) {
			$campaigns = $this->api_v1_request(
				'campaign_list',
				'GET',
				[
					'query'   => [ 'ids' => $campaign_id ],
					'timeout' => self::CLEANUP_REQUEST_TIMEOUT,
				]
			);
			if ( is_wp_error( $campaigns ) ) {
				return $campaigns;
			}
			$status = isset( $campaigns[0]['status'] ) ? (string) $campaigns[0]['status'] : '';
			if ( ! in_array( $status, self::DELETABLE_STATUSES, true ) ) {
				return new \WP_Error(
					'newspack_newsletters_active_campaign_campaign_not_deletable',
					__( 'Campaign is not deletable.', 'newspack-newsletters' )
				);
			}
		}
		return $this->api_v1_request(
			'campaign_delete',
			'GET',
			[
				'query'   => [ 'id' => $campaign_id ],
				'timeout' => self::CLEANUP_REQUEST_TIMEOUT,
			]
		);
	}

	/**
	 * Update ESP campaign after refreshing the email HTML, which is triggered by post save.
	 *
	 * @param int   $meta_id Numeric ID of the meta field being updated.
	 * @param int   $post_id The post ID for the meta field being updated.
	 * @param mixed $meta_key The meta key being updated.
	 */
	public function save( $meta_id, $post_id, $meta_key ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( Newspack_Newsletters::EMAIL_HTML_META !== $meta_key ) {
			return;
		}
		// Layouts share the email editor (so the bundle, MJML refresh, and
		// editor chrome all load) but must never create or update an ESP
		// campaign — the post type is the boundary.
		if ( $this->is_layout_post( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! Newspack_Newsletters_Editor::is_editing_email( $post_id ) ) {
			return;
		}
		if ( 'trash' === $post->post_status ) {
			return;
		}
		$this->sync( $post );
	}

	/**
	 * Determine whether a stored campaign has already been dispatched, so send()
	 * can avoid sending the same newsletter twice.
	 *
	 * A send attempt can time out *after* ActiveCampaign has begun dispatching the
	 * campaign, so the campaign's own status — not the success of our last
	 * request — is the source of truth for whether the newsletter already went
	 * out. Only a confirmed draft is safe to recreate and (re)send.
	 *
	 * @param string $campaign_id The ActiveCampaign campaign ID.
	 *
	 * @return string|WP_Error One of the CAMPAIGN_* dispatch states, or a WP_Error
	 *                         when the status could not be verified (a timeout),
	 *                         in which case the caller must not resend.
	 */
	private function get_campaign_dispatch_state( $campaign_id ) {
		$campaigns = $this->api_v1_request(
			'campaign_list',
			'GET',
			[
				'query'   => [ 'ids' => $campaign_id ],
				'timeout' => self::CLEANUP_REQUEST_TIMEOUT,
			]
		);
		if ( is_wp_error( $campaigns ) ) {
			// ANY error (timeout, HTTP 5xx, auth, malformed) means we cannot
			// confirm whether the campaign already started sending, so fail safe
			// and refuse to resend rather than risk a duplicate send to the full
			// list. A campaign that genuinely no longer exists does NOT arrive
			// here: ActiveCampaign returns it as a successful empty list, handled
			// below. The error code is deliberately not inspected — an HTTP 5xx
			// during an AC incident (exactly when this matters) carries the
			// generic api_error code, not the timeout code, and must still block.
			return new \WP_Error(
				'newspack_newsletters_active_campaign_unverified_campaign',
				__( 'Newspack could not confirm with ActiveCampaign whether this newsletter had already started sending, so it was not resent (to avoid a duplicate). Please wait a few minutes and try again, or check the campaign status in ActiveCampaign.', 'newspack-newsletters' )
			);
		}
		// A successful response with no campaign means it no longer exists in
		// ActiveCampaign: nothing was dispatched, so it is safe to recreate.
		//
		// This is the only branch that defaults toward recreate-and-send on an
		// absent row, and its safety rests on an ActiveCampaign behavior: AC is
		// documented never to return an empty *success* for a campaign that was
		// dispatched and then deleted (it returns result_code=0, which lands in
		// the is_wp_error() fail-safe branch above). If that assumption ever
		// broke, the cost would be a duplicate send to the full list, so do not
		// widen this default without re-verifying that AC behavior.
		$status = isset( $campaigns[0]['status'] ) ? (string) $campaigns[0]['status'] : self::STATUS_DRAFT;
		switch ( $status ) {
			case self::STATUS_DRAFT: // Never dispatched.
				return self::CAMPAIGN_DRAFT;
			case self::STATUS_SCHEDULED:
			case self::STATUS_SENDING:
			case self::STATUS_COMPLETED:
				return self::CAMPAIGN_ALREADY_DISPATCHED;
			default:
				// Paused (3) and stopped (4) are campaigns that were sending and
				// got halted, so they have likely already delivered to part of
				// the list; an unknown status is equally indeterminate. None are
				// safe to recreate-and-resend, so route them to manual review.
				//
				// Disabled (6) reaches this default too. It is intentionally NOT
				// treated as a fresh draft here even though it appears in
				// DELETABLE_STATUSES: "deletable" only means the cleanup path may
				// remove it, not that its dispatch state is known to be safe to
				// resend. A disabled campaign is genuinely indeterminate, so it
				// belongs in needs-review, not the recreate-and-send path.
				return self::CAMPAIGN_NEEDS_REVIEW;
		}
	}

	/**
	 * Send a campaign.
	 *
	 * @param WP_Post $post Post to send.
	 *
	 * @return true|WP_Error True if the campaign was sent or error if failed.
	 */
	public function send( $post ) {
		$post_id = $post->ID;

		// A campaign created by a previous attempt may already have been
		// dispatched even if that attempt ended in a timeout: ActiveCampaign can
		// begin sending before the HTTP response returns. So before doing
		// anything destructive, verify the stored campaign's state. Never delete,
		// recreate, or re-trigger a campaign we cannot confirm is still a fresh
		// draft, or the newsletter could go out twice.
		$campaign_id = get_post_meta( $post_id, 'ac_campaign_id', true );
		if ( $campaign_id ) {
			$dispatch_state = $this->get_campaign_dispatch_state( $campaign_id );
			if ( is_wp_error( $dispatch_state ) ) {
				return $dispatch_state;
			}
			if ( self::CAMPAIGN_ALREADY_DISPATCHED === $dispatch_state ) {
				// A prior attempt already started the send; its response was just
				// lost. Treat as success so it is marked sent, not resent.
				return true;
			}
			if ( self::CAMPAIGN_NEEDS_REVIEW === $dispatch_state ) {
				return new \WP_Error(
					'newspack_newsletters_active_campaign_send_needs_review',
					__( "This newsletter's ActiveCampaign campaign is in an unexpected state (paused or stopped) and may have already partially sent. To avoid sending it twice, Newspack did not resend it. Please review the campaign in ActiveCampaign before trying again.", 'newspack-newsletters' )
				);
			}
			// Confirmed draft: safe to clean up and recreate below.
			$this->delete_campaign( $campaign_id, true );
		}
		/** Clean up existing test campaigns. */
		$test_campaigns = get_post_meta( $post_id, 'ac_test_campaign', false );
		if ( ! empty( $test_campaigns ) ) {
			foreach ( $test_campaigns as $test_campaign_id ) {
				$delete_res = $this->delete_campaign( $test_campaign_id, true );
				if ( ! is_wp_error( $delete_res ) ) {
					delete_post_meta( $post_id, 'ac_test_campaign', $test_campaign_id );
				}
			}
		}
		/** Create new campaign for sending. */
		$campaign = $this->create_campaign( $post );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		update_post_meta( $post_id, 'ac_campaign_id', $campaign['id'] );
		$campaign_id = $campaign['id'];
		// See https://www.activecampaign.com/api/example.php?call=campaign_status.
		$send_result = $this->api_v1_request(
			'campaign_status',
			'GET',
			[
				'query' => [
					'id'     => $campaign_id,
					'status' => 1, // 0 = draft, 1 = scheduled, 2 = sending, 3 = paused, 4 = stopped, 5 = completed.
					'sdate'  => '', // Empty means send immediately.
				],
			]
		);
		if ( is_wp_error( $send_result ) ) {
			return $send_result;
		}

		return true;
	}

	/**
	 * After Newsletter post is deleted, clean up by deleting corresponding ESP campaign.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 */
	public function trash( $post_id ) {
		if ( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT !== get_post_type( $post_id ) ) {
			return;
		}
		/** Clean up existing test campaigns. */
		$test_campaigns = get_post_meta( $post_id, 'ac_test_campaign', false );
		if ( ! empty( $test_campaigns ) ) {
			foreach ( $test_campaigns as $test_campaign_id ) {
				$delete_res = $this->delete_campaign( $test_campaign_id, true );
				if ( ! is_wp_error( $delete_res ) ) {
					delete_post_meta( $post_id, 'ac_test_campaign', $test_campaign_id );
				}
			}
		}
		$campaign_id = get_post_meta( $post_id, 'ac_campaign_id', true );
		$message_id  = get_post_meta( $post_id, 'ac_message_id', true );
		if ( $campaign_id ) {
			$this->delete_campaign( $campaign_id );
		}
		if ( $message_id ) {
			$message = $this->api_v1_request( 'message_view', 'GET', [ 'query' => [ 'id' => $message_id ] ] );
			if ( ! is_wp_error( $message ) ) {
				$this->api_v1_request( 'campaign_delete', 'GET', [ 'query' => [ 'id' => $message_id ] ] );
			}
		}
	}

	/**
	 * Get data type for a given field.
	 *
	 * @param string $field_name The field name.
	 *
	 * @return int Data type ID.
	 */
	private static function get_metadata_type( $field_name ) {
		$date_fields = [
			'Registration Date',
			'Last Payment Date',
			'Next Payment Date',
			'Current Subscription End Date',
			'Current Subscription Start Date',
		];

		foreach ( $date_fields as $date_field ) {
			if ( str_contains( $field_name, $date_field ) ) {
				return 'date';
			}
		}
		return 'text';
	}

	/**
	 * Add contact to a list or update an existing contact.
	 *
	 * @param array        $contact      {
	 *          Contact data.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 *    @type string[] $tags     Contact tags. Optional.
	 * }
	 * @param string|false $list_id      List to add the contact to.
	 *
	 * @return array|WP_Error Contact data if the contact was added or error if failed.
	 */
	public function add_contact( $contact, $list_id = false ) {
		if ( ! isset( $contact['metadata'] ) ) {
			$contact['metadata'] = [];
		}
		$action  = 'contact_add';
		$email   = trim( strtolower( $contact['email'] ) );
		$payload = [
			'email' => $email,
		];
		$has_list_id = false !== $list_id;
		if ( $has_list_id ) {
			$payload[ 'p[' . $list_id . ']' ]      = $list_id;
			$payload[ 'status[' . $list_id . ']' ] = 1;
		}
		if ( isset( $contact['name'] ) && ! empty( $contact['name'] ) ) {
			$name_fragments = explode( ' ', $contact['name'], 2 );
			$payload        = array_merge(
				$payload,
				[
					'first_name' => $name_fragments[0],
					'last_name'  => isset( $name_fragments[1] ) ? $name_fragments[1] : '',
				]
			);
		}
		/** Register metadata fields. */
		if ( ! empty( $contact['metadata'] ) ) {
			$existing_fields = $this->get_all_contact_fields();
			foreach ( $contact['metadata'] as $field_title => $value ) {
				$field_perstag = strtoupper( str_replace( '-', '_', sanitize_title( $field_title ) ) );
				/** For optimization, don't add the field if it already exists. */
				if ( is_wp_error( $existing_fields ) || false === array_search( $field_perstag, array_column( $existing_fields, 'perstag' ) ) ) {
					$field_res = $this->api_v3_request(
						'fields',
						'POST',
						[
							'body' => wp_json_encode(
								[
									'field' => [
										'title'   => $field_title,
										'type'    => self::get_metadata_type( $field_title ),
										'perstag' => $field_perstag,
										'visible' => 1,
									],
								]
							),
						]
					);
					if ( \is_wp_error( $field_res ) ) {
						return $field_res;
					}
					/** Set list relation. */
					$this->api_v3_request(
						'fieldRels',
						'POST',
						[
							'body' => wp_json_encode(
								[
									'fieldRel' => [
										'field' => $field_res['field']['id'],
										'relid' => 0,
									],
								]
							),
						]
					);
				}
				$payload[ 'field[%' . $field_perstag . '%,0]' ] = (string) $value; // Per ESP documentation, "leave 0 as is".
			}
		}

		$contact_data          = $this->get_contact_data( $email );
		$existing_email        = isset( $contact['existing_contact_data']['email'] ) ? trim( strtolower( $contact['existing_contact_data']['email'] ) ) : '';
		$existing_contact_data = $this->get_contact_data( $existing_email );
		if ( ! is_wp_error( $contact_data ) || ! is_wp_error( $existing_contact_data ) ) {
			$action               = 'contact_edit';
			$payload['id']        = is_wp_error( $contact_data ) ? $existing_contact_data['id'] : $contact_data['id'];
			$payload['overwrite'] = 0;
			// For email changes, if the email address exists, but is different from the one we're trying to upsert, delete the existing contact.
			if ( ! is_wp_error( $contact_data ) && ! is_wp_error( $existing_contact_data ) && $existing_email !== $email ) {
				$is_email_change = isset( $contact['is_email_change'] ) && $contact['is_email_change'];
				if ( $is_email_change ) {
					$delete_res = $this->delete_contact( $existing_email );
					if ( is_wp_error( $delete_res ) ) {
						Newspack_Newsletters_Logger::log( 'Error deleting existing contact during upsert: ' . $delete_res->get_error_message() );
					}
				}
			}
		}
		$result = $this->api_v1_request(
			$action,
			'POST',
			[
				'body' => $payload,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// On success, clear cached contact data to make sure we get updated data next time we need.
		$this->clear_contact_data( $email );

		return [ 'id' => $result['subscriber_id'] ];
	}

	/**
	 * Delete contact from all lists given its email.
	 *
	 * @param string $email Email address.
	 *
	 * @return bool|WP_Error True if the contact was deleted, error if failed.
	 */
	public function delete_contact( $email ) {
		$contact = $this->get_contact_data( $email );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$result = $this->api_v1_request( 'contact_delete', 'GET', [ 'query' => [ 'id' => $contact['id'] ] ] );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Get the lists a contact is subscribed to.
	 *
	 * @param string $email The contact email.
	 *
	 * @return string[] Contact subscribed lists IDs.
	 */
	public function get_contact_lists( $email ) {
		$contact = $this->get_contact_data( $email );
		if ( is_wp_error( $contact ) ) {
			return [];
		}
		$contact_lists = $this->api_v3_request( 'contacts/' . $contact['id'] . '/contactLists' );
		if ( is_wp_error( $contact_lists ) || ! isset( $contact_lists['contactLists'] ) ) {
			return [];
		}
		$lists = [];
		foreach ( $contact_lists['contactLists'] as $list ) {
			if ( isset( $list['status'] ) && 1 === absint( $list['status'] ) ) {
				$lists[] = $list['list'];
			}
		}
		return $lists;
	}

	/**
	 * Update a contact lists subscription.
	 *
	 * @param string   $email           Contact email address.
	 * @param string[] $lists_to_add    Array of list IDs to subscribe the contact to.
	 * @param string[] $lists_to_remove Array of list IDs to remove the contact from.
	 *
	 * @return true|WP_Error True if the contact was updated or error.
	 */
	public function update_contact_lists( $email, $lists_to_add = [], $lists_to_remove = [] ) {
		$existing_contact = $this->get_contact_data( $email );
		if ( is_wp_error( $existing_contact ) ) {
			/** Create contact */
			// Call Newspack_Newsletters_Contacts's method (not the provider's directly),
			// so the appropriate hooks are called.
			$contact_data = Newspack_Newsletters_Contacts::upsert( [ 'email' => $email ] );
			if ( is_wp_error( $contact_data ) ) {
				return $contact_data;
			}
			$contact_id = $contact_data['id'];
		} else {
			$contact_id = $existing_contact['id'];
			/** Set status to "2" (unsubscribed) for lists to remove. */
			foreach ( $lists_to_remove as $list ) {
				$result = $this->api_v3_request(
					'contactLists',
					'POST',
					[
						'body' => wp_json_encode(
							[
								'contactList' => [
									'list'    => $list,
									'contact' => $contact_id,
									'status'  => 2,
								],
							]
						),
					]
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		/** Set status to "1" (subscribed) for lists to add. */
		foreach ( $lists_to_add as $list ) {
			$result = $this->api_v3_request(
				'contactLists',
				'POST',
				[
					'body' => wp_json_encode(
						[
							'contactList' => [
								'list'     => $list,
								'contact'  => $contact_id,
								'status'   => 1,
								'sourceid' => 4,
							],
						]
					),
				]
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Get the list of contact metadata fields.
	 *
	 * @param number $offset Offset for pagination.
	 */
	private function fetch_contact_fields( $offset ) {
		return $this->api_v3_request(
			'fields',
			'GET',
			[
				'query' => [
					'limit'  => 100,
					'offset' => $offset,
				],
			]
		);
	}

	/**
	 * Get the list of all available contact metadata fields.
	 *
	 * @param number $offset Offset for pagination.
	 */
	private function get_all_contact_fields( $offset = 0 ) {
		$response = $this->fetch_contact_fields( $offset );
		if ( \is_wp_error( $response ) ) {
			return $response;
		}
		$result     = $response['fields'];
		$new_offset = count( $result ) + $offset;
		if ( $new_offset < $response['meta']['total'] ) {
			$fields = $this->get_all_contact_fields( $new_offset );
			if ( \is_wp_error( $fields ) ) {
				return $fields;
			}
			$result = array_merge( $result, $fields );
		}
		return $result;
	}

	/**
	 * Get contact data by email.
	 *
	 * @param string $email Email address.
	 * @param bool   $return_details Fetch full contact data.
	 *
	 * @return array|WP_Error Response or error if contact was not found.
	 */
	public function get_contact_data( $email, $return_details = false ) {
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'newspack_newsletters', __( 'Invalid email address.' ) );
		}
		if ( isset( $this->contact_data[ $email ] ) ) {
			$result = $this->contact_data[ $email ];
		} else {
			$result                       = $this->api_v3_request( 'contacts', 'GET', [ 'query' => [ 'email' => urlencode( $email ) ] ] );
			$this->contact_data[ $email ] = $result;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! isset( $result['contacts'], $result['contacts'][0] ) ) {
			return new WP_Error( 'newspack_newsletters', __( 'No contact data found.' ) );
		}
		$contact_data = $result['contacts'][0];
		if ( $return_details ) {
			$contact_fields = $this->get_all_contact_fields();
			if ( \is_wp_error( $contact_fields ) ) {
				return $contact_fields;
			}
			$fields_perstag_by_id = array_reduce(
				$contact_fields,
				function ( $acc, $field ) {
					$acc[ $field['id'] ] = $field['perstag'];
					return $acc;
				},
				[]
			);
			$contact_result       = $this->api_v3_request( 'contacts/' . $contact_data['id'], 'GET' );
			if ( \is_wp_error( $contact_result ) ) {
				return $contact_result;
			}
			$contact_fields           = array_reduce(
				$contact_result['fieldValues'],
				function ( $acc, $field ) use ( $fields_perstag_by_id ) {
					if ( isset( $field['value'] ) && isset( $fields_perstag_by_id[ $field['field'] ] ) ) {
						$acc[ $fields_perstag_by_id[ $field['field'] ] ] = $field['value'];
					}
					return $acc;
				},
				[]
			);
			$contact_data['metadata'] = $contact_fields;
		}
		return $contact_data;
	}

	/**
	 * Clears cached Contact data
	 *
	 * @param string $email The contact email.
	 * @return void
	 */
	public function clear_contact_data( $email ) {
		if ( isset( $this->contact_data[ $email ] ) ) {
			unset( $this->contact_data[ $email ] );
		}
	}

	/**
	 * Get the provider specific labels
	 *
	 * This allows us to make reference to provider specific features in the way the user is used to see them in the provider's UI
	 *
	 * @param mixed $context The context in which the labels are being applied.
	 * @return array
	 */
	public static function get_labels( $context = '' ) {
		return array_merge(
			parent::get_labels(),
			[
				'name'                   => 'Active Campaign',
				'list_explanation'       => __( 'Active Campaign List', 'newspack-newsletters' ),
				'local_list_explanation' => __( 'Active Campaign Tag', 'newspack-newsletters' ),
				'list'                   => __( 'list', 'newspack-newsletters' ), // "list" in lower case singular format.
				'lists'                  => __( 'lists', 'newspack-newsletters' ), // "list" in lower case plural format.
				'sublist'                => __( 'segment', 'newspack-newsletters' ), // Sublist entities in lowercase singular format.
				'List'                   => __( 'List', 'newspack-newsletters' ), // "list" in uppercase case singular format.
				'Lists'                  => __( 'Lists', 'newspack-newsletters' ), // "list" in uppercase case plural format.
				'Sublist'                => __( 'Segments', 'newspack-newsletters' ), // Sublist entities in uppercase singular format.
			]
		);
	}

	/**
	 * Add a notice to the Subscription Lists metabox letting the user know that they have to manually create the Segment
	 *
	 * @param array $settings The List settings.
	 * @return void
	 */
	public function lists_metabox_notice( $settings ) {
		if ( $settings['tag_name'] ) {
			?>
			<p class="subscription-list-warning">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %1$s and %2$s are opening and closing link tag to Active Campaign documentation. */
						__( 'Note for Active Campaign: You need to manually create a segment using the above tag to be able to send campaigns to this list. %1$sLearn more%2$s', 'newspack-newsletters' ),
						'<a href="https://help.activecampaign.com/hc/en-us/articles/221483407-How-to-create-segments-in-ActiveCampaign" target="_blank">',
						'</a>'
					),
					[
						'a' => [
							'href'   => [],
							'target' => [],
						],
					]
				);
				?>
			</p>
			<?php
		}
	}

	/**
	 * Get usage report.
	 */
	public function get_usage_report() {
		$ac_usage_reports = new Newspack_Newsletters_Active_Campaign_Usage_Reports();
		return $ac_usage_reports->get_usage_report();
	}

	/**
	 * Object cache group for the integrations field schema and per-field option lists.
	 */
	const INTEGRATIONS_CACHE_GROUP = 'newspack_newsletters_active_campaign';

	/**
	 * Get contact fields for Newspack integrations.
	 *
	 * @param string|null $list_id The List ID (unused — ActiveCampaign contact fields are global).
	 * @return array|WP_Error
	 */
	public function get_contact_fields_for_integrations( $list_id = null ) {
		$cache_key     = $this->integrations_cache_key( 'fields' );
		$cached_fields = wp_cache_get( $cache_key, self::INTEGRATIONS_CACHE_GROUP );
		if ( false !== $cached_fields ) {
			return $cached_fields;
		}
		$all_fields = $this->get_all_contact_fields();
		if ( is_wp_error( $all_fields ) ) {
			return $all_fields;
		}
		$fields = [];
		foreach ( $all_fields as $field ) {
			$mapped = $this->map_contact_field_to_integration_schema( $field );
			if ( null !== $mapped ) {
				$fields[] = $mapped;
			}
		}
		wp_cache_set( $cache_key, $fields, self::INTEGRATIONS_CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $fields;
	}

	/**
	 * Build a cache key for integrations data, namespaced by the configured AC account URL.
	 *
	 * Two sites sharing an object cache backend can be configured against different AC accounts;
	 * a flat global key would let one site's cached schema leak into the other.
	 *
	 * @param string $suffix Per-call key suffix (e.g. 'fields', 'options:34').
	 * @return string
	 */
	private function integrations_cache_key( $suffix ) {
		$credentials = $this->api_credentials();
		if ( ! empty( $credentials['url'] ) ) {
			$account_hash = substr( md5( (string) $credentials['url'] ), 0, 12 );
		} else {
			// Defensive fallback for a code path that bypasses credential checks; salt with
			// the blog id so two unconfigured sites sharing an object cache don't collide.
			$account_hash = 'noaccount-' . get_current_blog_id();
		}
		return $account_hash . ':' . $suffix;
	}

	/**
	 * Map an ActiveCampaign contact field to the Newspack integrations schema.
	 *
	 * AC types eligible for access-rule / segmentation defaults: text, textarea, date, datetime,
	 * dropdown, radio, listbox, checkbox, multiselect. Hidden and NULL-typed fields are exposed
	 * but not promoted by default.
	 *
	 * Matching function depends on selection cardinality. Per AC's Contact Custom Fields API
	 * Guide, dropdown / radio / listbox are single-selection types (their stored value is the
	 * raw chosen option), so 'default' (strict equality) matching is correct. Checkbox and
	 * multiselect are multi-selection types: AC stores the chosen options with a `||` delimiter
	 * (e.g. `||Option A||Option C||`), which `default` matching cannot resolve — those types
	 * use 'list__in', and the consumer's parse_list_value() recognizes the delimiter.
	 *
	 * @param array $field Raw field from the ActiveCampaign v3 /fields endpoint.
	 * @return array|null Mapped field, or null if no usable identifier is available.
	 */
	private function map_contact_field_to_integration_schema( $field ) {
		$perstag = isset( $field['perstag'] ) ? (string) $field['perstag'] : '';
		if ( '' === $perstag ) {
			return null;
		}

		$type                       = isset( $field['type'] ) ? $field['type'] : 'text';
		$single_select_enum_types   = [ 'dropdown', 'radio', 'listbox' ];
		$multi_select_enum_types    = [ 'checkbox', 'multiselect' ];
		$enumerated_types           = array_merge( $single_select_enum_types, $multi_select_enum_types );
		$eligible_types             = array_merge( [ 'text', 'textarea', 'date', 'datetime' ], $enumerated_types );
		$is_promoted_by_default     = in_array( $type, $eligible_types, true );
		$is_multi_select            = in_array( $type, $multi_select_enum_types, true );

		$options = [];
		if ( in_array( $type, $enumerated_types, true ) && ! empty( $field['id'] ) ) {
			$options = $this->fetch_field_options( $field['id'] );
		}

		return [
			'key'                 => $perstag,
			'name'                => ! empty( $field['title'] ) ? $field['title'] : $perstag,
			'value_type'          => 'string',
			'matching_function'   => $is_multi_select ? 'list__in' : 'default',
			'options'             => $options,
			'description'         => ! empty( $field['descript'] ) ? $field['descript'] : '',
			'is_access_rule'      => $is_promoted_by_default,
			'is_segment_criteria' => $is_promoted_by_default,
		];
	}

	/**
	 * Fetch the option list for an enumerated ActiveCampaign field.
	 *
	 * Cached per field with a longer TTL than the parent schema (1h vs 5min). Option lists
	 * change much less often than the field roster, and a misaligned TTL means the parent
	 * cache miss only re-fetches options for fields whose own per-field cache has lapsed.
	 * Tradeoff: an option-label edit in AC can take up to 1h to surface in the admin UI.
	 *
	 * @param int|string $field_id The AC field ID.
	 * @return array Array of [ 'value' => ..., 'label' => ... ] pairs (empty on failure).
	 */
	private function fetch_field_options( $field_id ) {
		$cache_key = $this->integrations_cache_key( 'options:' . $field_id );
		$cached    = wp_cache_get( $cache_key, self::INTEGRATIONS_CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}
		$response = $this->api_v3_request( 'fields/' . rawurlencode( (string) $field_id ) . '/options', 'GET' );
		if ( is_wp_error( $response ) ) {
			Newspack_Newsletters_Logger::log(
				sprintf(
					'ActiveCampaign: failed to fetch options for field %s: %s',
					$field_id,
					$response->get_error_message()
				)
			);
			return [];
		}
		if ( empty( $response['fieldOptions'] ) ) {
			wp_cache_set( $cache_key, [], self::INTEGRATIONS_CACHE_GROUP, HOUR_IN_SECONDS );
			return [];
		}
		$options = [];
		foreach ( $response['fieldOptions'] as $option ) {
			if ( ! isset( $option['value'] ) ) {
				continue;
			}
			$options[] = [
				'value' => $option['value'],
				'label' => isset( $option['label'] ) ? $option['label'] : $option['value'],
			];
		}
		wp_cache_set( $cache_key, $options, self::INTEGRATIONS_CACHE_GROUP, HOUR_IN_SECONDS );
		return $options;
	}
}
