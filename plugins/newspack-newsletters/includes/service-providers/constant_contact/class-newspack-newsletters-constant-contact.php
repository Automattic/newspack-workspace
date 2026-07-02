<?php
/**
 * Service Provider: Constant Contact Implementation
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack\Newsletters\Send_Lists;
use Newspack\Newsletters\Send_List;

/**
 * Main Newspack Newsletters Class for Constant Contact ESP.
 */
final class Newspack_Newsletters_Constant_Contact extends \Newspack_Newsletters_Service_Provider {

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	public $name = 'Contant Constact';

	/**
	 * Cached instance of the CC SDK.
	 *
	 * @var Newspack_Newsletters_Constant_Contact_SDK
	 */
	private $cc = null;

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
		$this->service    = 'constant_contact';
		$this->controller = new Newspack_Newsletters_Constant_Contact_Controller( $this );
		$credentials      = $this->api_credentials();

		add_action( 'admin_init', [ $this, 'oauth_callback' ] );
		add_action( 'update_option_newspack_newsletters_constant_contact_api_key', [ $this, 'clear_tokens' ], 10, 2 );
		add_action( 'update_option_newspack_newsletters_constant_contact_api_secret', [ $this, 'clear_tokens' ], 10, 2 );
		add_action( 'updated_post_meta', [ $this, 'save' ], 10, 4 );
		add_action( 'wp_trash_post', [ $this, 'trash' ], 10, 1 );

		parent::__construct( $this );
	}

	/**
	 * Get API credentials for service provider.
	 *
	 * @return Object Stored API credentials for the service provider.
	 */
	public function api_credentials() {
		return [
			'api_key'       => get_option( 'newspack_newsletters_constant_contact_api_key', '' ),
			'api_secret'    => get_option( 'newspack_newsletters_constant_contact_api_secret', '' ),
			'access_token'  => get_option( 'newspack_newsletters_constant_contact_api_access_token', '' ),
			'refresh_token' => get_option( 'newspack_newsletters_constant_contact_api_refresh_token', '' ),
		];
	}

	/**
	 * Check if provider has all necessary credentials set.
	 *
	 * @return Boolean Result.
	 */
	public function has_api_credentials() {
		return ! empty( $this->api_key() ) && ! empty( $this->api_secret() );
	}

	/**
	 * Get or create a cached instance of the Constant Contact SDK.
	 */
	public function get_sdk() {
		if ( $this->cc ) {
			return $this->cc;
		}
		$credentials = $this->api_credentials();
		$this->cc    = new Newspack_Newsletters_Constant_Contact_SDK(
			$credentials['api_key'],
			$credentials['api_secret'],
			$credentials['access_token']
		);
		return $this->cc;
	}

	/**
	 * Build the OAuth authorisation URL without calling the live API.
	 * Decoupled from `verify_token()` so admin surfaces can render the
	 * "Authorize" button (which requires a session-current
	 * `wp_create_nonce`) without paying for a `validate_token` round trip.
	 *
	 * @return string
	 */
	public function get_oauth_auth_url() {
		try {
			$redirect_uri = $this->get_oauth_redirect_uri();
			$cc           = $this->get_sdk();
			return $cc->get_auth_code_url( wp_create_nonce( 'constant_contact_oauth2' ), $redirect_uri );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Verify service provider connection.
	 *
	 * @param boolean $refresh Whether to attempt connection refresh.
	 *
	 * @return array
	 */
	public function verify_token( $refresh = true ) {
		// Initialise outside the try so the catch has a safe shape if SDK setup throws.
		$response = [
			'error'    => null,
			'valid'    => false,
			'auth_url' => '',
		];
		try {
			$redirect_uri         = $this->get_oauth_redirect_uri();
			$cc                   = $this->get_sdk();
			$response['auth_url'] = $cc->get_auth_code_url( wp_create_nonce( 'constant_contact_oauth2' ), $redirect_uri );
			// If we have a valid access token, we're connected.
			if ( $cc->validate_token() ) {
				$response['valid'] = true;
				return $response;
			}

			// If we have a refresh token, we can get a new access token.
			$credentials = $this->api_credentials();
			if ( $refresh && ! empty( $credentials['refresh_token'] ) ) {
				$token             = $cc->refresh_token( $credentials['refresh_token'] );
				$response['valid'] = $this->set_access_token( $token->access_token, $token->refresh_token );
				return $response;
			}
			return $response;
		} catch ( Exception $e ) {
			return $response;
		}
	}

	/**
	 * Check if is connected to service provider.
	 *
	 * @return Boolean
	 */
	public function has_valid_connection() {
		return $this->verify_token( false )['valid'];
	}

	/**
	 * Get OAuth Redirect URI.
	 *
	 * @return string OAuth Redirect URI.
	 */
	private function get_oauth_redirect_uri() {
		return add_query_arg(
			'cc_oauth2',
			1,
			admin_url( 'index.php' )
		);
	}

	/**
	 * Authorization code callback
	 */
	public function oauth_callback() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		if ( ! isset( $_GET['cc_oauth2'] ) ) {
			return;
		}
		if (
			! isset( $_GET['state'] ) ||
			! wp_verify_nonce( sanitize_text_field( $_GET['state'] ), 'constant_contact_oauth2' ) ||
			! isset( $_GET['code'] )
		) {
			return;
		}

		if ( isset( $_GET['error_description'] ) ) {
			wp_die( esc_html( sanitize_text_field( $_GET['error_description'] ) ) );
		}

		$redirect_uri = $this->get_oauth_redirect_uri();
		$code         = sanitize_text_field( $_GET['code'] );

		$connected = $this->connect( $redirect_uri, $code );
		if ( ! $connected ) {
			wp_die( esc_html__( 'Could not connect to Constant Contact.', 'newspack-newsletters' ) );
		}
		?>
		<script type="text/javascript">
			window.close();
			if(window.opener && window.opener.verify) {
				window.opener.verify();
			}
		</script>
		<?php
		wp_die( 'OK', '', 200 );
	}

	/**
	 * Connect using authorization code.
	 *
	 * @param string $redirect_uri Redirect URI.
	 * @param string $code         Authorization code.
	 *
	 * @return boolean Whether we are connected.
	 */
	private function connect( $redirect_uri, $code ) {
		$cc    = $this->get_sdk();
		$token = $cc->get_access_token( $redirect_uri, $code );
		if ( ! $token || ! isset( $token->access_token ) ) {
			return false;
		}
		try {
			return $this->set_access_token(
				$token->access_token,
				isset( $token->refresh_token ) ? $token->refresh_token : ''
			);
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get API key for service provider.
	 *
	 * @return String Stored API key for the service provider.
	 */
	public function api_key() {
		$credentials = $this->api_credentials();
		return $credentials['api_key'];
	}

	/**
	 * Get API secret for service provider.
	 *
	 * @return String Stored API secret for the service provider.
	 */
	public function api_secret() {
		$credentials = $this->api_credentials();
		return $credentials['api_secret'];
	}

	/**
	 * Get Access Token key for service provider.
	 *
	 * @return String Stored Access Token key for the service provider.
	 */
	public function access_token() {
		$credentials = $this->api_credentials();
		return $credentials['access_token'];
	}

	/**
	 * Set the API credentials for the service provider.
	 *
	 * @param object $credentials API credentials.
	 *
	 * @return Boolean Whether any API credential was updated.
	 */
	public function set_api_credentials( $credentials ) {
		if ( empty( $credentials['api_key'] ) || empty( $credentials['api_secret'] ) ) {
			return new WP_Error(
				'newspack_newsletters_invalid_keys',
				__( 'Please input Constant Contact API key and secret.', 'newspack-newsletters' )
			);
		} else {
			$update_api_key    = update_option( 'newspack_newsletters_constant_contact_api_key', $credentials['api_key'] );
			$update_api_secret = update_option( 'newspack_newsletters_constant_contact_api_secret', $credentials['api_secret'] );
			return $update_api_key || $update_api_secret;
		}
	}

	/**
	 * Clear API tokens when API key or secret changes.
	 *
	 * @param string $old_api_value Old API value.
	 * @param string $new_api_value New API value.
	 */
	public function clear_tokens( $old_api_value, $new_api_value ) {
		if ( $old_api_value !== $new_api_value ) {
			delete_option( 'newspack_newsletters_constant_contact_api_access_token' );
			delete_option( 'newspack_newsletters_constant_contact_api_refresh_token	' );
		}
	}

	/**
	 * Set acccess and refresh tokens.
	 *
	 * @param string $access_token  Access token.
	 * @param string $refresh_token Refresh token.
	 *
	 * @return Boolean Whether values were updated.
	 *
	 * @throws Exception Error message.
	 */
	private function set_access_token( $access_token = '', $refresh_token = '' ) {
		if ( empty( $access_token ) ) {
			throw new Exception(
				esc_html__( 'Access token is required.', 'newspack-newsletter' )
			);
		}
		$update_access_token  = update_option( 'newspack_newsletters_constant_contact_api_access_token', $access_token );
		$update_refresh_token = update_option( 'newspack_newsletters_constant_contact_api_refresh_token', $refresh_token );

		/**
		 * Provider credentials persisted — listeners (e.g. Settings_REST's
		 * OAuth cache) bust their caches so the next read reflects the change.
		 *
		 * @param string $provider_slug The provider whose credentials changed.
		 */
		do_action( 'newspack_newsletters_provider_credentials_changed', 'constant_contact' );

		return $update_access_token;
	}

	/**
	 * Set list for a campaign.
	 *
	 * A campaign can not use segments and lists at the same time, so we also unset all segments.
	 *
	 * @param string $post_id Campaign Id.
	 * @param string $list_id ID of the list.
	 * @return object|WP_Error API API Response or error.
	 */
	public function list( $post_id, $list_id ) {
		if ( ! $this->has_valid_connection() ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				__( 'Unable to connect to Constant Contact API', 'newspack-newsletters' )
			);
		}
		try {
			$cc_campaign_id = $this->retrieve_campaign_id( $post_id );
			$cc             = $this->get_sdk();

			$campaign = $cc->get_campaign( $cc_campaign_id );
			$activity = $campaign->activity;

			if ( ! in_array( $list_id, $activity->contact_list_ids, true ) ) {
				$activity->contact_list_ids[] = $list_id;
			}

			$activity->segment_ids = [];
			$cc->update_campaign_activity( $activity->campaign_activity_id, $activity );

			return \rest_ensure_response( $this->retrieve( $post_id ) );

		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Unset list for a campaign.
	 *
	 * @param string $post_id Campaign Id.
	 * @param string $list_id ID of the list.
	 * @return object|WP_Error API API Response or error.
	 */
	public function unset_list( $post_id, $list_id ) {
		if ( ! $this->has_valid_connection() ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				__( 'Unable to connect to Constant Contact API', 'newspack-newsletters' )
			);
		}
		try {
			$cc_campaign_id = $this->retrieve_campaign_id( $post_id );
			$cc             = $this->get_sdk();

			$campaign = $cc->get_campaign( $cc_campaign_id );
			$activity = $campaign->activity;

			$activity->contact_list_ids = array_diff( $activity->contact_list_ids, [ $list_id ] );

			$cc->update_campaign_activity( $activity->campaign_activity_id, $activity );

			return \rest_ensure_response( $this->retrieve( $post_id ) );

		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Set segment for a campaign.
	 *
	 * A campaign can not use segments and lists at the same time, so we also unset all lists.
	 *
	 * @param string $post_id Campaign Id.
	 * @param string $segment_id ID of the segment.
	 * @return object|WP_Error API API Response or error.
	 */
	public function set_segment( $post_id, $segment_id ) {
		if ( ! $this->has_valid_connection() ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				__( 'Unable to connect to Constant Contact API', 'newspack-newsletters' )
			);
		}
		try {
			$cc_campaign_id = $this->retrieve_campaign_id( $post_id );
			$cc             = $this->get_sdk();

			$campaign = $cc->get_campaign( $cc_campaign_id );
			$activity = $campaign->activity;

			if ( ! in_array( $segment_id, $activity->segment_ids, true ) ) {
				$activity->segment_ids      = [ $segment_id ];
				$activity->contact_list_ids = [];
			}

			$cc->update_campaign_activity( $activity->campaign_activity_id, $activity );

			return \rest_ensure_response( $this->retrieve( $post_id ) );

		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Unset segment for a campaign.
	 *
	 * @param string $post_id Campaign Id.
	 * @return object|WP_Error API API Response or error.
	 */
	public function unset_segment( $post_id ) {
		if ( ! $this->has_valid_connection() ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				__( 'Unable to connect to Constant Contact API', 'newspack-newsletters' )
			);
		}
		try {
			$cc_campaign_id = $this->retrieve_campaign_id( $post_id );
			$cc             = $this->get_sdk();

			$campaign = $cc->get_campaign( $cc_campaign_id );
			$activity = $campaign->activity;

			$activity->segment_ids = [];

			$cc->update_campaign_activity( $activity->campaign_activity_id, $activity );

			return \rest_ensure_response( $this->retrieve( $post_id ) );

		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Wrapper for fetching campaign from CC API.
	 *
	 * @param string $cc_campaign_id Campaign ID.
	 * @return object|WP_Error API Response or error.
	 */
	private function fetch_synced_campaign( $cc_campaign_id ) {
		try {
			$cc       = $this->get_sdk();
			$campaign = $cc->get_campaign( $cc_campaign_id );
			return $campaign;
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Get the campaign link.
	 *
	 * @param object $campaign Campaign object.
	 *
	 * @return string Campaign link.
	 */
	private static function get_campaign_link( $campaign ) {
		if ( empty( $campaign->campaign_activities ) ) {
			return '';
		}
		$activity_index = array_search( 'primary_email', array_column( $campaign->campaign_activities, 'role' ) );
		if ( false === $activity_index ) {
			return '';
		}
		$activity = $campaign->campaign_activities[ $activity_index ];
		return sprintf( 'https://app.constantcontact.com/pages/ace/v1#/%s', $activity->campaign_activity_id );
	}

	/**
	 * Given a campaign object from the ESP or legacy newsletterData, extract sender and send-to info.
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
		if ( empty( $newsletter_data['campaign'] ) ) {
			return $campaign_info;
		}

		// Convert stdClass object to an array.
		$campaign = json_decode( wp_json_encode( $newsletter_data['campaign'] ), true );

		// Sender info.
		if ( ! empty( $campaign['activity']['from_name'] ) ) {
			$campaign_info['senderName'] = $campaign['activity']['from_name'];
		}
		if ( ! empty( $campaign['activity']['from_email'] ) ) {
			$campaign_info['senderEmail'] = $campaign['activity']['from_email'];
		}

		// List.
		if ( ! empty( $campaign['activity']['contact_list_ids'][0] ) ) {
			$campaign_info['list_id'] = $campaign['activity']['contact_list_ids'][0];
		}

		// Segment. CC campaigns can be sent to either lists or segments, so if a segment is set it'll override the list.
		if ( ! empty( $campaign['activity']['segment_ids'][0] ) ) {
			$campaign_info['list_id'] = $campaign['activity']['segment_ids'][0];
		}

		return $campaign_info;
	}

	/**
	 * Retrieve a campaign.
	 *
	 * @param integer $post_id Numeric ID of the Newsletter post.
	 * @return object|WP_Error API Response or error.
	 * @throws Exception Error message.
	 */
	public function retrieve( $post_id ) {
		try {
			if ( ! $this->has_api_credentials() ) {
				throw new Exception( esc_html__( 'Missing or invalid Constant Contact credentials.', 'newspack-newsletters' ) );
			}
			if ( ! $this->has_valid_connection() ) {
				throw new Exception( esc_html__( 'Unable to connect to Constant Contact API. Please try authorizing your connection or obtaining new credentials.', 'newspack-newsletters' ) );
			}

			$cc_campaign_id = get_post_meta( $post_id, 'cc_campaign_id', true );
			if ( ! $cc_campaign_id ) {
				$campaign = $this->sync( get_post( $post_id ) );
				if ( is_wp_error( $campaign ) ) {
					throw new Exception( wp_kses_post( $campaign->get_error_message() ) );
				}
				$cc_campaign_id = $campaign->campaign_id;
			} else {
				Newspack_Newsletters_Logger::log( 'Retrieving campaign ' . $cc_campaign_id . ' for post ID ' . $post_id );
				$campaign = $this->fetch_synced_campaign( $cc_campaign_id );

				// If we couldn't get the campaign, delete the cc_campaign_id so it gets recreated on the next sync.
				if ( is_wp_error( $campaign ) ) {
					delete_post_meta( $post_id, 'cc_campaign_id' );
					throw new Exception( wp_kses_post( $campaign->get_error_message() ) );
				}
			}

			$campaign_info   = $this->extract_campaign_info( [ 'campaign' => $campaign ] );
			$list_id         = $campaign_info['list_id'] ?? null;
			$send_list_id    = get_post_meta( $post_id, 'send_list_id', true );
			$newsletter_data = [
				'campaign'              => $campaign,
				'campaign_id'           => $cc_campaign_id,
				'link'                  => $this->get_campaign_link( $campaign ),
				'allowed_sender_emails' => $this->get_verified_email_addresses(), // Get allowed email addresses for sender UI.
				'email_settings_url'    => 'https://app.constantcontact.com/pages/myaccount/settings/emails',
			];

			// Reconcile campaign settings with info fetched from the ESP for a true two-way sync.
			if ( ! empty( $campaign_info['senderName'] ) && $campaign_info['senderName'] !== get_post_meta( $post_id, 'senderName', true ) ) {
				$newsletter_data['senderName'] = $campaign_info['senderName']; // If campaign has different sender info set, update ours.
			}
			if ( ! empty( $campaign_info['senderEmail'] ) && $campaign_info['senderEmail'] !== get_post_meta( $post_id, 'senderEmail', true ) ) {
				$newsletter_data['senderEmail'] = $campaign_info['senderEmail']; // If campaign has different sender info set, update ours.
			}
			if ( $list_id && $list_id !== $send_list_id ) {
				$newsletter_data['send_list_id'] = strval( $list_id ); // If campaign has different list or segment set, update ours.
				$send_list_id                    = $newsletter_data['send_list_id'];
			}

			// Prefetch send list info if we have a selected list and/or sublist.
			$send_lists = $this->get_send_lists(
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

			return $newsletter_data;
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Set sender data.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 * @param string $from_name Sender name.
	 * @param string $reply_to Reply to email address.
	 * @return object|WP_Error API Response or error.
	 */
	public function sender( $post_id, $from_name, $reply_to ) {
		if ( ! $this->has_valid_connection() ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				__( 'Unable to connect to Constant Contact API', 'newspack-newsletters' )
			);
		}
		try {
			$post           = get_post( $post_id );
			$cc_campaign_id = $this->retrieve_campaign_id( $post_id );
			$cc             = $this->get_sdk();
			$renderer       = new Newspack_Newsletters_Renderer();
			$content        = $renderer->retrieve_email_html( $post );

			$campaign = $cc->get_campaign( $cc_campaign_id );

			$activity = [
				'format_type'      => 5,
				'email_content'    => $content,
				'subject'          => html_entity_decode( $post->post_title ),
				'contact_list_ids' => $campaign->activity->contact_list_ids,
				'from_name'        => $from_name,
				'from_email'       => $reply_to,
				'reply_to_email'   => $reply_to,
			];

			$cc->update_campaign_activity( $campaign->activity->campaign_activity_id, $activity );

			return \rest_ensure_response( $this->retrieve( $post_id ) );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Send test email or emails.
	 *
	 * @param integer $post_id Numeric ID of the Newsletter post.
	 * @param array   $emails Array of email addresses to send to.
	 * @return object|WP_Error API Response or error.
	 */
	public function test( $post_id, $emails ) {
		if ( ! $this->has_valid_connection() ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				__( 'Unable to connect to Constant Contact API', 'newspack-newsletters' )
			);
		}
		try {
			$cc   = $this->get_sdk();
			$data = $this->retrieve( $post_id );

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$activity = $data['campaign']->activity;
			$result   = $cc->test_campaign( $activity->campaign_activity_id, $emails );

			$data['result']  = $result;
			$data['message'] = sprintf(
			// translators: Message after successful test email.
				__( 'Constant Contact test sent successfully to %s.', 'newspack-newsletters' ),
				implode( ', ', $emails )
			);

			return \rest_ensure_response( $data );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Get all of the verified email addresses associated with the CC account.
	 * See: https://developer.constantcontact.com/api_reference/index.html#!/Account_Services/retrieveEmailAddresses.
	 */
	public function get_verified_email_addresses() {
		$cc              = $this->get_sdk();
		$email_addresses = (array) $cc->get_email_addresses( [ 'confirm_status' => 'CONFIRMED' ] );

		return array_map(
			function( $email ) {
				return $email->email_address;
			},
			$email_addresses
		);
	}

	/**
	 * Get a payload for syncing post data to the ESP campaign.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 *
	 * @return array|WP_Error Payload for syncing or error.
	 */
	public function get_sync_payload( $post ) {
		$cc              = $this->get_sdk();
		$renderer        = new Newspack_Newsletters_Renderer();
		$content         = $renderer->retrieve_email_html( $post );
		$auto_draft_html = '<html><body>[[trackingImage]]<p>Auto draft</p></body></html>';
		$account_info    = $cc->get_account_info();
		$sender_name     = get_post_meta( $post->ID, 'senderName', true );
		$sender_email    = get_post_meta( $post->ID, 'senderEmail', true );

		// If we don't have a sender name or email, set default values.
		if ( ! $sender_name && $account_info->organization_name ) {
			$sender_name = $account_info->organization_name;
		} elseif ( ! $sender_name && $account_info->first_name && $account_info->last_name ) {
			$sender_name = $account_info->first_name . ' ' . $account_info->last_name;
		}

		$verified_email_addresses = $this->get_verified_email_addresses();
		if ( empty( $verified_email_addresses ) ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				__( 'There are no verified email addresses in the Constant Contact account.', 'newspack-newsletters' )
			);
		}
		if ( ! $sender_email ) {
			$sender_email = $verified_email_addresses[0];
		}
		if ( ! in_array( $sender_email, $verified_email_addresses, true ) ) {
			return new WP_Error(
				'newspack_newsletters_constant_contact_error',
				__( 'Sender email must be a verified Constant Contact account email address.', 'newspack-newsletters' )
			);
		}
		$payload = [
			'format_type'    => 5, // https://v3.developer.constantcontact.com/api_guide/email_campaigns_overview.html#collapse-format-types .
			'html_content'   => empty( $content ) ? $auto_draft_html : $content,
			'subject'        => $post->post_title,
			'from_name'      => $sender_name ?? __( 'Sender Name', 'newspack-newsletters' ),
			'from_email'     => $sender_email,
			'reply_to_email' => $sender_email,
		];
		if ( $account_info->physical_address ) {
			$payload['physical_address_in_footer'] = $account_info->physical_address;
		}

		// Sync send-to selections.
		$send_list_id = get_post_meta( $post->ID, 'send_list_id', true );
		// Only an explicitly unset send_list_id (null or '' — no list/segment
		// ever picked) is treated as "no recipient configured" and left for
		// CC's API to reject downstream. Any other value, including a literal
		// "0", is treated as configured-and-must-resolve so a garbage value
		// can't quietly produce an upstream error that's harder to debug than
		// a clear, local abort.
		if ( null !== $send_list_id && '' !== $send_list_id ) {
			$send_lists = $this->get_send_lists( [ 'ids' => $send_list_id ] );
			// CC's recipient selector requires a non-empty `contact_list_ids`
			// or `segment_ids` array; if neither is set, the upstream
			// `create_campaign` call rejects the payload. So the silent
			// whole-list failure mode that hit AC and MC does not apply here.
			// This guard exists for parity with the other providers — it
			// converts an opaque upstream rejection into a clear, local
			// abort that names the actual cause.
			if ( is_wp_error( $send_lists ) ) {
				return new WP_Error(
					'newspack_newsletters_constant_contact_send_list_lookup_failed',
					sprintf(
						// Translators: %s is the upstream error message from Constant Contact.
						__( 'Could not verify the selected list or segment with Constant Contact (%s). Sending was aborted; please re-select a list or segment and try again.', 'newspack-newsletters' ),
						$send_lists->get_error_message()
					)
				);
			}
			if ( empty( $send_lists[0] ) ) {
				return new WP_Error(
					'newspack_newsletters_constant_contact_send_list_not_found',
					__( 'The selected list or segment could not be found in Constant Contact. Sending was aborted; please re-select a list or segment and try again.', 'newspack-newsletters' )
				);
			}
			$send_list   = $send_lists[0];
			$entity_type = $send_list->get_entity_type();
			if ( 'list' === $entity_type ) {
				$payload['contact_list_ids'] = [ $send_list->get_id() ];
			} elseif ( 'segment' === $entity_type ) {
				$payload['segment_ids'] = [ $send_list->get_id() ];
			} else {
				return new WP_Error(
					'newspack_newsletters_constant_contact_send_list_unknown_type',
					sprintf(
						// Translators: %s is the unrecognized recipient entity type.
						__( 'Unrecognized Constant Contact recipient type "%s". Sending was aborted; please re-select a list or segment and try again.', 'newspack-newsletters' ),
						$entity_type
					)
				);
			}
		}

		return $payload;
	}

	/**
	 * Synchronize post with corresponding ESP campaign.
	 *
	 * @param WP_Post $post Post to synchronize.
	 *
	 * @return object|WP_Error API Response or error.
	 *
	 * @throws Exception Error message.
	 */
	public function sync( $post ) {
		// Clear prior error messages.
		$transient_name = $this->get_transient_name( $post->ID );
		delete_transient( $transient_name );

		try {
			$api_key = $this->api_key();
			if ( ! $api_key ) {
				throw new Exception(
					__( 'No Constant Contact API key available.', 'newspack-newsletters' )
				);
			}
			if ( empty( $post->post_title ) ) {
				throw new Exception(
					__( 'The newsletter subject cannot be empty.', 'newspack-newsletters' )
				);
			}

			if ( ! $this->has_valid_connection() ) {
				return;
			}

			$cc              = $this->get_sdk();
			$cc_campaign_id  = get_post_meta( $post->ID, 'cc_campaign_id', true );
			$payload         = $this->get_sync_payload( $post );

			if ( is_wp_error( $payload ) ) {
				throw new Exception( esc_html( $payload->get_error_message() ) );
			}

			/**
			 * Filter the metadata payload sent to CC when syncing.
			 *
			 * Allows custom tracking codes to be sent.
			 *
			 * @param array  $payload        CC payload.
			 * @param object $post           Post object.
			 * @param string $cc_campaign_id CC campaign ID, if defined.
			 */
			$payload = apply_filters( 'newspack_newsletters_cc_payload_sync', $payload, $post, $cc_campaign_id );

			// If we have any errors in the payload, throw an exception.
			if ( is_wp_error( $payload ) ) {
				throw new Exception( esc_html( $payload->get_error_message() ) );
			}

			if ( $cc_campaign_id ) {
				$campaign = $cc->get_campaign( $cc_campaign_id );

				// Constant Constact only allow updates on DRAFT or SENT status.
				if ( ! in_array( $campaign->current_status, [ 'DRAFT', 'SENT' ], true ) ) {
					throw new Exception(
						__( 'The newsletter campaign must have a DRAFT or SENT status.', 'newspack-newsletters' )
					);
				}

				$cc->update_campaign_activity( $campaign->activity->campaign_activity_id, $payload );

				// Update campaign name.
				$campaign_name = $this->get_campaign_name( $post );
				if ( $campaign->name !== $campaign_name ) {
					$cc->update_campaign_name( $cc_campaign_id, $campaign_name );
				}

				$campaign_result = $cc->get_campaign( $cc_campaign_id );
			} else {
				$campaign = [
					'name'                      => $this->get_campaign_name( $post ),
					'email_campaign_activities' => [ $payload ],
				];

				$campaign_result = $cc->create_campaign( $campaign );
			}
			update_post_meta( $post->ID, 'cc_campaign_id', $campaign_result->campaign_id );

			return $campaign_result;
		} catch ( Exception $e ) {
			set_transient( $transient_name, 'Constant Contact campaign sync error: ' . wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), 45 );
			return new WP_Error( 'newspack_newsletters_constant_contact_error', $e->getMessage() );
		}
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
		if ( ! Newspack_Newsletters_Editor::is_editing_email( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( 'trash' === $post->post_status ) {
			return;
		}
		$this->sync( $post );
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

		try {
			$sync_result = $this->sync( $post );
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletters_error', $e->getMessage() );
		}

		if ( ! $sync_result ) {
			return new WP_Error(
				'newspack_newsletters_error',
				__( 'Unable to synchronize with Constant Contact.', 'newspack-newsletters' )
			);
		}

		$cc_campaign_id = get_post_meta( $post_id, 'cc_campaign_id', true );
		if ( ! $cc_campaign_id ) {
			return new WP_Error(
				'newspack_newsletters_error',
				__( 'Constant Contact campaign ID not found.', 'newspack-newsletters' )
			);
		}

		try {
			$cc = $this->get_sdk();
			$cc->create_schedule( $sync_result->activity->campaign_activity_id );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_error',
				$e->getMessage()
			);
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
		$cc_campaign_id = get_post_meta( $post_id, 'cc_campaign_id', true );
		if ( ! $cc_campaign_id ) {
			return;
		}

		$api_key = $this->api_key();
		if ( ! $api_key ) {
			return;
		}

		try {
			if ( ! $this->verify_token() ) {
				return;
			}

			$cc = $this->get_sdk();

			$campaign = $cc->get_campaign( $cc_campaign_id );
			if ( $campaign && 'DRAFT' === $campaign->current_status ) {
				$result = $cc->delete_campaign( $cc_campaign_id );
				delete_post_meta( $post_id, 'cc_campaign_id', $cc_campaign_id );
			}
		} catch ( Exception $e ) {
			return; // Fail silently.
		}
	}

	/**
	 * Convenience method to retrieve the Constant Contact campaign ID for a post or throw an error.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 * @return string Constant Contact campaign ID.
	 * @throws Exception Error message.
	 */
	public function retrieve_campaign_id( $post_id ) {
		$cc_campaign_id = get_post_meta( $post_id, 'cc_campaign_id', true );
		if ( ! $cc_campaign_id ) {
			throw new Exception( esc_html__( 'Constant Contact campaign ID not found.', 'newspack-newsletters' ) );
		}
		return $cc_campaign_id;
	}

	/**
	 * Get lists.
	 *
	 * @return array|WP_Error List of existing lists or error.
	 */
	public function get_lists() {
		if ( null !== $this->lists ) {
			return $this->lists;
		}
		try {
			$cc = $this->get_sdk();
			if ( ! $this->lists ) {
				$this->lists = array_map(
					function ( $list ) {
						return [
							'id'               => $list->list_id,
							'name'             => $list->name,
							'membership_count' => $list->membership_count,
						];
					},
					$cc->get_contact_lists()
				);
			}

			return $this->lists;
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletters_error', $e->getMessage() );
		}
	}

	/**
	 * Get segments.
	 *
	 * @return array|WP_Error List of existing segments or error.
	 */
	public function get_segments() {
		if ( null !== $this->segments ) {
			return $this->segments;
		}
		try {
			$cc = $this->get_sdk();
			if ( ! $this->segments ) {
				$this->segments = array_map(
					function ( $segment ) {
						return [
							'id'   => $segment->segment_id,
							'name' => $segment->name,
						];
					},
					$cc->get_segments()
				);
			}

			return $this->segments;
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletters_error', $e->getMessage() );
		}
	}

	/**
	 * Get all applicable lists and segments as Send_List objects.
	 * Note that in CC, campaigns can be sent to either lists or segments, not both,
	 * so both entity types should be treated as top-level send lists.
	 *
	 * @param array   $args Array of search args. See Send_Lists::get_default_args() for supported params and default values.
	 * @param boolean $to_array If true, convert Send_List objects to arrays before returning.
	 *
	 * @return Send_List[]|array|WP_Error Array of Send_List objects or arrays on success, or WP_Error object on failure.
	 */
	public function get_send_lists( $args = [], $to_array = false ) {
		$lists = $this->get_lists();
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}
		$send_lists = array_map(
			function( $list ) {
				$config = [
					'provider'    => $this->service,
					'type'        => 'list',
					'id'          => $list['id'],
					'name'        => $list['name'],
					'entity_type' => 'list',
					'count'       => $list['membership_count'],
					'edit_link'   => 'https://app.constantcontact.com/pages/contacts/ui#contacts/' . $list['id'],
				];

				return new Send_List( $config );
			},
			$lists
		);
		$segments = $this->get_segments();
		if ( is_wp_error( $segments ) ) {
			return $segments;
		}
		$send_segments = array_map(
			function( $segment ) {
				$segment_id = (string) $segment['id'];
				$config      = [
					'provider'    => $this->service,
					'type'        => 'list', // In CC, segments and lists have the same hierarchy.
					'id'          => $segment_id,
					'name'        => $segment['name'],
					'entity_type' => 'segment',
					'edit_link'   => "https://app.constantcontact.com/pages/contacts/ui#segments/$segment_id/preview",
				];

				return new Send_List( $config );
			},
			$segments
		);
		$send_lists     = array_merge( $send_lists, $send_segments );
		$filtered_lists = $send_lists;
		if ( ! empty( $args['ids'] ) ) {
			$ids           = ! is_array( $args['ids'] ) ? [ $args['ids'] ] : $args['ids'];
			$filtered_lists = array_values(
				array_filter(
					$send_lists,
					function ( $list ) use ( $ids ) {
						return Send_Lists::matches_id( $ids, $list->get_id() );
					}
				)
			);
		}
		if ( ! empty( $args['search'] ) ) {
			$search        = ! is_array( $args['search'] ) ? [ $args['search'] ] : $args['search'];
			$filtered_lists = array_values(
				array_filter(
					$send_lists,
					function ( $list ) use ( $search ) {
						return Send_Lists::matches_search(
							$search,
							[
								$list->get_id(),
								$list->get_name(),
								$list->get_entity_type(),
							]
						);
					}
				)
			);
		}

		if ( ! empty( $args['limit'] ) ) {
			$filtered_lists = array_slice( $filtered_lists, 0, $args['limit'] );
		}

		// Convert to arrays if requested.
		if ( $to_array ) {
			$filtered_lists = array_map(
				function ( $list ) {
					return $list->to_array();
				},
				$filtered_lists
			);
		}
		return $filtered_lists;
	}

	/**
	 * Add contact to a list or update an existing contact.
	 *
	 * @param array        $contact      {
	 *      Contact data.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 * }
	 * @param string|false $list_id      List to add the contact to.
	 *
	 * @return array|WP_Error Contact data if the contact was added or error if failed.
	 */
	public function add_contact( $contact, $list_id = false ) {
		$cc   = $this->get_sdk();
		$data = [];
		if ( $list_id ) {
			$data['list_ids'] = [ $list_id ];
		}
		if ( isset( $contact['name'] ) ) {
			$name_fragments     = explode( ' ', $contact['name'], 2 );
			$data['first_name'] = $name_fragments[0];
			if ( isset( $name_fragments[1] ) ) {
				$data['last_name'] = $name_fragments[1];
			}
		}
		if ( isset( $contact['metadata'] ) ) {
			$data['custom_fields'] = [];
			foreach ( $contact['metadata'] as $key => $value ) {
				// Update request will fail if metadata contains an empty value, so we set these to '-'.
				$data['custom_fields'][ strval( $key ) ] = empty( $value ) ? '-' : strval( $value );
			}
		}

		$email = trim( strtolower( $contact['email'] ) );
		if ( isset( $contact['existing_contact_data']['email_address']->address ) ) {
			$existing_email = trim( strtolower( $contact['existing_contact_data']['email_address']->address ) );
			if ( $existing_email->address !== $email ) {
				$data['email_address'] = $email;
				$email                 = $existing_email;
			}
		}
		$result = $cc->upsert_contact( $email, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return get_object_vars( $result );
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
		$cc      = $this->get_sdk();
		$contact = $cc->get_contact( $email );
		if ( ! $contact || is_wp_error( $contact ) ) {
			return new WP_Error(
				'newspack_newsletters_error',
				__( 'Contact not found.', 'newspack-newsletters' )
			);
		}
		return get_object_vars( $contact );
	}

	/**
	 * Get the lists a contact is subscribed to.
	 *
	 * @param string $email The contact email.
	 *
	 * @return string[] Contact subscribed lists IDs.
	 */
	public function get_contact_lists( $email ) {
		$contact_lists = [];
		$contact_data = self::get_contact_data( $email );
		if ( is_wp_error( $contact_data ) ) {
			return $contact_lists;
		}
		if ( ! empty( $contact_data['list_memberships'] ) ) {
			$contact_lists = array_merge( $contact_lists, $contact_data['list_memberships'] );
		}
		if ( ! empty( $contact_data['taggings'] ) ) {
			$contact_lists = array_merge( $contact_lists, $contact_data['taggings'] );
		}

		return $contact_lists;
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
		$cc           = $this->get_sdk();
		$contact_data = $this->get_contact_data( $email );
		if ( is_wp_error( $contact_data ) ) {
			/** Create contact */
			// Call Newspack_Newsletters_Contacts's method (not the provider's directly),
			// so the appropriate hooks are called.
			$contact_data = Newspack_Newsletters_Contacts::upsert( [ 'email' => $email ] );
			if ( is_wp_error( $contact_data ) ) {
				return $contact_data;
			}
		}

		// Remove lists or tags from contact.
		if ( $lists_to_remove ) {
			$result = $cc->remove_contacts_from_lists( [ $contact_data['contact_id'] ], $lists_to_remove );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Add lists or tags to contact.
		if ( $lists_to_add ) {
			$new_contact_data['list_ids'] = $lists_to_add;
			$result = $cc->upsert_contact( $email, $new_contact_data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
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
				'name'                   => 'Constant Contact',
				'list_explanation'       => __( 'Constant Contact List', 'newspack-newsletters' ),
				'local_list_explanation' => __( 'Constant Contact Tag', 'newspack-newsletters' ),
				'list'                   => __( 'list or segment', 'newspack-newsletters' ), // "list" in lower case singular format.
				'lists'                  => __( 'lists or segments', 'newspack-newsletters' ), // "list" in lower case plural format.
				'List'                   => __( 'List or Segment', 'newspack-newsletters' ), // "list" in uppercase case singular format.
				'Lists'                  => __( 'Lists or Segments', 'newspack-newsletters' ), // "list" in uppercase case plural format.
			]
		);
	}

	/**
	 * Retrieve the ESP's tag ID from its name
	 *
	 * @param string  $tag_name The tag.
	 * @param boolean $create_if_not_found Whether to create a new tag if not found. Default to true.
	 * @param string  $list_id The List ID.
	 * @return int|WP_Error The tag ID on success. WP_Error on failure.
	 */
	public function get_tag_id( $tag_name, $create_if_not_found = true, $list_id = null ) {
		$cc  = $this->get_sdk();
		$tag = $cc->get_tag_by_name( $tag_name );
		if ( is_wp_error( $tag ) && $create_if_not_found ) {
			$tag = $this->create_tag( $tag_name );
		}
		if ( is_wp_error( $tag ) ) {
			return $tag;
		}
		return $tag['id'];
	}

	/**
	 * Retrieve the ESP's tag name from its ID
	 *
	 * @param string|int $tag_id The tag ID.
	 * @param string     $list_id The List ID.
	 * @return string|WP_Error The tag name on success. WP_Error on failure.
	 */
	public function get_tag_by_id( $tag_id, $list_id = null ) {
		$cc  = $this->get_sdk();
		$tag = $cc->get_tag_by_id( $tag_id );
		if ( is_wp_error( $tag ) ) {
			return $tag;
		}
		return $tag->name;
	}

	/**
	 * Create a Tag on the provider
	 *
	 * @param string $tag The Tag name.
	 * @param string $list_id The List ID.
	 * @return array|WP_Error The tag representation with at least 'id' and 'name' keys on succes. WP_Error on failure.
	 */
	public function create_tag( $tag, $list_id = null ) {
		$cc  = $this->get_sdk();
		$tag = $cc->create_tag( $tag );
		if ( is_wp_error( $tag ) ) {
			return $tag;
		}
		// Also create a Segment grouping users tagged with this tag.
		$cc->create_tag_segment( $tag->tag_id, $tag->name );
		return [
			'id'   => $tag->tag_id,
			'name' => $tag->name,
		];
	}

	/**
	 * Updates a Tag name on the provider
	 *
	 * @param string|int $tag_id The tag ID.
	 * @param string     $tag The Tag new name.
	 * @param string     $list_id The List ID.
	 * @return array|WP_Error The tag representation with at least 'id' and 'name' keys on succes. WP_Error on failure.
	 */
	public function update_tag( $tag_id, $tag, $list_id = null ) {
		$cc  = $this->get_sdk();
		$tag = $cc->update_tag( $tag_id, $tag );
		if ( is_wp_error( $tag ) ) {
			return $tag;
		}
		return [
			'id'   => $tag->tag_id,
			'name' => $tag->name,
		];
	}

	/**
	 * Add a tag to a contact
	 *
	 * @param string     $email The contact email.
	 * @param string|int $tag The tag ID.
	 * @param string     $list_id The List ID.
	 * @return true|WP_Error
	 */
	public function add_tag_to_contact( $email, $tag, $list_id = null ) {
		$tags = $this->get_contact_tags_ids( $email );
		if ( is_wp_error( $tags ) ) {
			$tags = [];
		}
		if ( in_array( $tag, $tags, true ) ) {
			return true;
		}
		$new_tags = array_merge( $tags, [ $tag ] );
		$cc       = $this->get_sdk();
		return $cc->upsert_contact( $email, [ 'taggings' => $new_tags ] );
	}

	/**
	 * Remove a tag from a contact
	 *
	 * @param string     $email The contact email.
	 * @param string|int $tag The tag ID.
	 * @param string     $list_id The List ID.
	 * @return true|WP_Error
	 */
	public function remove_tag_from_contact( $email, $tag, $list_id = null ) {
		$tags     = $this->get_contact_tags_ids( $email );
		$new_tags = array_diff( $tags, [ $tag ] );
		if ( count( $new_tags ) === count( $tags ) ) {
			return true;
		}
		$cc = $this->get_sdk();
		return $cc->upsert_contact( $email, [ 'taggings' => $new_tags ] );
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
		return $contact_data['taggings'] ?? [];
	}

		/**
		 * Get usage report.
		 */
	public function get_usage_report() {
		$ac_usage_reports = new Newspack_Newsletters_Constant_Contact_Usage_Reports();
		return $ac_usage_reports->get_usage_report();
	}

	/**
	 * Get configuration for conditional tag support.
	 *
	 * @return array
	 */
	public static function get_conditional_tag_support() {
		return [
			'support_url' => '',
			'example'     => [
				'before' => '',
				'after'  => '',
			],
		];
	}
}
