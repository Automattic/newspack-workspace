<?php
/**
 * Service Provider: Campaign Monitor Implementation
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack\Newsletters\Send_Lists;
use Newspack\Newsletters\Send_List;

// Increase default timeout for 3rd-party API requests to 30s.
define( 'CS_REST_CALL_TIMEOUT', 30 );

/**
 * Main Newspack Newsletters Class for Campaign Monitor ESP.
 */
final class Newspack_Newsletters_Campaign_Monitor extends \Newspack_Newsletters_Service_Provider {

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	public $name = 'Campaign Monitor';

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
	 * Class constructor.
	 */
	public function __construct() {
		$this->service    = 'campaign_monitor';
		$this->controller = new Newspack_Newsletters_Campaign_Monitor_Controller( $this );

		add_action( 'updated_post_meta', [ $this, 'save' ], 10, 4 );

		parent::__construct( $this );
	}

	/**
	 * Get configuration for conditional tag support.
	 *
	 * @return array
	 */
	public static function get_conditional_tag_support() {
		return [
			'support_url' => 'https://www.campaignmonitor.com/create/dynamic-content/',
			'example'     => [
				'before' => '[ifmemberof:"My list|VIP segment"]',
				'after'  => '[endif]',
			],
		];
	}

	/**
	 * Get API credentials for service provider.
	 *
	 * @return Object Stored API credentials for the service provider.
	 */
	public function api_credentials() {
		return [
			'api_key'   => get_option( 'newspack_newsletters_campaign_monitor_api_key', '' ),
			'client_id' => get_option( 'newspack_newsletters_campaign_monitor_client_id', '' ),
		];
	}

	/**
	 * Check if provider has all necessary credentials set.
	 *
	 * @return Boolean Result.
	 */
	public function has_api_credentials() {
		return ! empty( $this->api_key() ) && ! empty( $this->client_id() );
	}

	/**
	 * Get API key for service provider.
	 *
	 * @return String Stored API key for the service provider.
	 */
	public function api_key() {
		$credentials = self::api_credentials();
		return $credentials['api_key'];
	}

	/**
	 * Get Access Token key for service provider.
	 *
	 * @return String Stored Access Token key for the service provider.
	 */
	public function client_id() {
		$credentials = self::api_credentials();
		return $credentials['client_id'];
	}

	/**
	 * Get sender info.
	 */

	/**
	 * Set the API credentials for the service provider.
	 *
	 * @param object $credentials API credentials.
	 */
	public function set_api_credentials( $credentials ) {
		if ( empty( $credentials['api_key'] ) || empty( $credentials['client_id'] ) ) {
			return new WP_Error(
				'newspack_newsletters_invalid_keys',
				__( 'Please input Campaign Monitor API key and Client ID.', 'newspack-newsletters' )
			);
		} else {
			$update_api_key   = update_option( 'newspack_newsletters_campaign_monitor_api_key', $credentials['api_key'] );
			$update_client_id = update_option( 'newspack_newsletters_campaign_monitor_client_id', $credentials['client_id'] );
			return $update_api_key && $update_client_id;
		}
	}

	/**
	 * Get lists for a client iD.
	 *
	 * @return array|WP_Error Array of lists, or error.
	 */
	public function get_lists() {
		if ( null !== $this->lists ) {
			return $this->lists;
		}

		$api_key   = $this->api_key();
		$client_id = $this->client_id();

		if ( ! $api_key ) {
			return new WP_Error(
				'newspack_newsletters_missing_api_key',
				__( 'No Campaign Monitor API key available.', 'newspack-newsletters' )
			);
		}
		if ( ! $client_id ) {
			return new WP_Error(
				'newspack_newsletters_missing_client_id',
				__( 'No Campaign Monitor Client ID available.', 'newspack-newsletters' )
			);
		}

		$cm_clients = new CS_REST_Clients( $client_id, [ 'api_key' => $api_key ] );
		$lists      = $cm_clients->get_lists();

		// If the request failed, throw an error.
		if ( ! $lists->was_successful() ) {
			return new WP_Error(
				'newspack_newsletters_campaign_monitor_error',
				__( 'Could not retrieve Campaign Monitor list info. Please check your API key and client ID.', 'newspack-newsletters' )
			);
		}

		$lists = array_map(
			function ( $item ) {
				return [
					'id'   => $item->ListID, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'name' => $item->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				];
			},
			$lists->response
		);

		$this->lists = $lists;
		return $this->lists;
	}

	/**
	 * Get segments for a client iD.
	 *
	 * @return array|WP_Error Array of segments, or error.
	 */
	public function get_segments() {
		if ( null !== $this->segments ) {
			return $this->segments;
		}

		$api_key   = $this->api_key();
		$client_id = $this->client_id();

		if ( ! $api_key ) {
			return new WP_Error(
				'newspack_newsletters_missing_api_key',
				__( 'No Campaign Monitor API key available.', 'newspack-newsletters' )
			);
		}
		if ( ! $client_id ) {
			return new WP_Error(
				'newspack_newsletters_missing_client_id',
				__( 'No Campaign Monitor Client ID available.', 'newspack-newsletters' )
			);
		}

		$cm_clients = new CS_REST_Clients( $client_id, [ 'api_key' => $api_key ] );
		$segments   = $cm_clients->get_segments();

		// If the request failed, throw an error.
		if ( ! $segments->was_successful() ) {
			return new WP_Error(
				'newspack_newsletters_campaign_monitor_error',
				__( 'Could not retrieve Campaign Monitor segment info. Please check your API key and client ID.', 'newspack-newsletters' )
			);
		}

		$segments = array_map(
			function ( $item ) {
				return [
					'id'        => $item->SegmentID, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'name'      => $item->Title, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'parent_id' => $item->ListID, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				];
			},
			$segments->response
		);

		$this->segments = $segments;
		return $this->segments;
	}

	/**
	 * Get all applicable lists and segments as Send_List objects.
	 * Note that in CM, campaigns can be sent to either lists or segments, not both,
	 * so both entity types should be treated as top-level send lists.
	 *
	 * @param array   $args Array of search args. See Send_Lists::get_default_args() for supported params and default values.
	 * @param boolean $to_array If true, convert Send_List objects to arrays before returning.
	 *
	 * @return Send_List[]|array|WP_Error Array of Send_List objects or arrays on success, or WP_Error object on failure.
	 */
	public function get_send_lists( $args = [], $to_array = false ) {
		$api_key   = $this->api_key();
		$client_id = $this->client_id();

		if ( ! $api_key ) {
			return new WP_Error(
				'newspack_newsletters_missing_api_key',
				__( 'No Campaign Monitor API key available.', 'newspack-newsletters' )
			);
		}
		if ( ! $client_id ) {
			return new WP_Error(
				'newspack_newsletters_missing_client_id',
				__( 'No Campaign Monitor Client ID available.', 'newspack-newsletters' )
			);
		}

		$lists = $this->get_lists();
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}
		$send_lists = array_map(
			function( $list ) use ( $api_key ) {
				$config = [
					'provider'    => $this->service,
					'type'        => 'list',
					'id'          => $list['id'],
					'name'        => $list['name'],
					'entity_type' => 'list',
				];

				$list_details = new CS_REST_Lists( $list['id'], [ 'api_key' => $api_key ] );
				$list_stats   = $list_details->get_stats();
				if ( ! empty( $list_stats->response->TotalActiveSubscribers ) ) {
					$config['count'] = $list_stats->response->TotalActiveSubscribers;
				}

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
					'type'        => 'list', // In CM, segments and lists have the same hierarchy.
					'id'          => $segment_id,
					'name'        => $segment['name'],
					'entity_type' => 'segment',
				];

				return new Send_List( $config );
			},
			$segments
		);
		$send_lists    = array_merge( $send_lists, $send_segments );
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
	 * Retrieve campaign details.
	 *
	 * @param integer $post_id Numeric ID of the Newsletter post.
	 * @return object|WP_Error API Response or error.
	 * @throws Exception Error message.
	 */
	public function retrieve( $post_id ) {
		try {
			if ( ! $this->has_api_credentials() ) {
				throw new Exception( esc_html__( 'Missing or invalid Campaign Monitor credentials.', 'newspack-newsletters' ) );
			}
			$send_list_id    = get_post_meta( $post_id, 'send_list_id', true );
			$send_lists      = $this->get_send_lists( // Get first 10 top-level send lists for autocomplete.
				[
					'ids'  => $send_list_id ? [ $send_list_id ] : null, // If we have a selected list, make sure to fetch it.
					'type' => 'list',
				],
				true
			);
			if ( is_wp_error( $send_lists ) ) {
				throw new Exception( wp_kses_post( $send_lists->get_error_message() ) );
			}
			$newsletter_data = [
				'campaign'                          => true, // Satisfy the JS API.
				'supports_multiple_test_recipients' => true,
				'lists'                             => $send_lists,
			];

			// Handle legacy sender meta.
			$from_name   = get_post_meta( $post_id, 'senderName', true );
			$from_email  = get_post_meta( $post_id, 'senderEmail', true );
			if ( ! $from_name ) {
				$legacy_from_name = get_post_meta( $post_id, 'cm_from_name', true );
				if ( $legacy_from_name ) {
					$newsletter_data['senderName'] = $legacy_from_name;
				}
			}
			if ( ! $from_email ) {
				$legacy_from_email = get_post_meta( $post_id, 'cm_from_email', true );
				if ( $legacy_from_email ) {
					$newsletter_data['senderEmail'] = $legacy_from_email;
				}
			}

			// Handle legacy send-to meta.
			if ( ! $send_list_id ) {
				$legacy_list_id = get_post_meta( $post_id, 'cm_list_id', true ) ?? get_post_meta( $post_id, 'cm_segment_id', true );
				if ( $legacy_list_id ) {
					$newsletter_data['send_list_id'] = $legacy_list_id;
				}
			}

			return $newsletter_data;
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_campaign_monitor_error',
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
		try {
			$api_key   = $this->api_key();
			$client_id = $this->client_id();

			if ( ! $api_key ) {
				return new WP_Error(
					'newspack_newsletters_missing_api_key',
					__( 'No Campaign Monitor API key available.', 'newspack-newsletters' )
				);
			}
			if ( ! $client_id ) {
				return new WP_Error(
					'newspack_newsletters_missing_client_id',
					__( 'No Campaign Monitor Client ID available.', 'newspack-newsletters' )
				);
			}

			$cm_campaigns = new CS_REST_Campaigns( null, [ 'api_key' => $api_key ] );
			$args         = $this->format_campaign_args( $post_id );

			// Create a temporary test campaign and get the ID from the response.
			$test_campaign = $cm_campaigns->create( $client_id, $args );

			if ( ! $test_campaign->was_successful() ) {
				return new WP_Error(
					'newspack_newsletters_campaign_monitor_error',
					__( 'Failed sending Campaign Monitor test campaign: ', 'newspack-newsletters' ) . $test_campaign->response->Message
				);
			}

			// Use the temporary test campaign ID to send a preview.
			$preview          = new CS_REST_Campaigns( $test_campaign->response, [ 'api_key' => $api_key ] );
			$preview_response = $preview->send_preview( $emails );

			// After sending a preview, delete the temporary test campaign. We must do this because the API doesn't support updating campaigns.
			$deleted  = $preview->delete();
			$response = [
				'result'  => $preview_response->response,
				'message' => sprintf(
					// translators: Message after successful test email.
					__( 'Campaign Monitor test sent successfully to %s.', 'newspack-newsletters' ),
					implode( ', ', $emails )
				),
			];
			return \rest_ensure_response( $response );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_campaign_monitor_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Creates an array of arguments to pass to the Campaign Monitor API.
	 *
	 * @param integer $post_id Post ID for the newsletter.
	 * @return object Args for sending a campaign or campaign preview.
	 */
	public function format_campaign_args( $post_id ) {
		$public_id = get_post_meta( $post_id, Newspack_Newsletters::PUBLIC_POST_ID_META, true );

		// If we don't have a public ID, generate one and save it.
		if ( empty( $public_id ) ) {
			$public_id = wp_generate_password( 20, false, false );
			update_post_meta( $post_id, Newspack_Newsletters::PUBLIC_POST_ID_META, $public_id );
		}

		$args = [
			'Subject'   => html_entity_decode( get_the_title( $post_id ) ),
			'Name'      => html_entity_decode( get_the_title( $post_id ) ) . ' ' . gmdate( 'h:i:s A' ), // Name must be unique.
			'FromName'  => get_post_meta( $post_id, 'senderName', true ),
			'FromEmail' => get_post_meta( $post_id, 'senderEmail', true ),
			'ReplyTo'   => get_post_meta( $post_id, 'senderEmail', true ),
			'HtmlUrl'   => rest_url(
				$this::BASE_NAMESPACE . $this->service . '/' . $public_id . '/content'
			),
		];

		$send_list_id = get_post_meta( $post_id, 'send_list_id', true );
		if ( $send_list_id ) {
			$send_list = $this->get_send_lists( [ 'ids' => $send_list_id ] );
			if ( ! empty( $send_list[0] ) ) {
				$send_mode = $send_list[0]->get_entity_type();
				if ( 'list' === $send_mode ) {
					$args['ListIDs'] = [ $send_list_id ];
				} elseif ( 'segment' === $send_mode ) {
					$args['SegmentIDs'] = [ $send_list_id ];
				}
			}
		}

		return $args;
	}

	/**
	 * Get rendered HTML content of post for the campaign.
	 *
	 * @param string $public_id Alphanumeric public ID for the newseltter post.
	 * @return object|WP_Error API Response or error.
	 */
	public function content( $public_id ) {
		$posts  = get_posts(
			[
				'fields'      => 'ID',
				'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'meta_key'    => Newspack_Newsletters::PUBLIC_POST_ID_META,
				'meta_value'  => $public_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post_status' => 'any',
			]
		);
		$post_id = reset( $posts );
		if ( ! $post_id || ! Newspack_Newsletters::validate_newsletter_id( $post_id ) ) {
			return new WP_Error(
				'newspack_newsletters_not_found',
				__( 'Newsletter not found.', 'newspack-newsletters' )
			);
		}

		$post     = get_post( $post_id );
		$renderer = new Newspack_Newsletters_Renderer();
		$html     = $renderer->retrieve_email_html( $post );

		return $html;
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

		$api_key   = $this->api_key();
		$client_id = $this->client_id();

		if ( ! $api_key ) {
			return new WP_Error(
				'newspack_newsletter_error',
				__( 'No Campaign Monitor API key available.', 'newspack-newsletters' )
			);
		}
		if ( empty( $post->post_title ) ) {
			return new WP_Error(
				'newspack_newsletter_error',
				__( 'The newsletter subject cannot be empty.', 'newspack-newsletters' )
			);
		}
		if ( ! $client_id ) {
			return new WP_Error(
				'newspack_newsletter_error',
				__( 'No Campaign Monitor Client ID available.', 'newspack-newsletters' )
			);
		}

		$cm_campaigns = new CS_REST_Campaigns( null, [ 'api_key' => $api_key ] );
		$args         = $this->format_campaign_args( $post_id );

		// Set the current user's email address as the email to receive sent confirmation.
		$current_user       = wp_get_current_user();
		$confirmation_email = $current_user->user_email;

		// Create a draft campaign and get the ID from the response.
		$new_campaign = $cm_campaigns->create( $client_id, $args );

		if ( ! $new_campaign->was_successful() ) {
			return new WP_Error(
				'newspack_newsletter_error',
				__( 'Failed creating Campaign Monitor campaign: ', 'newspack-newsletters' ) . $new_campaign->response->Message
			);
		}

		try {
			// Send the draft campaign.
			$campaign_to_send = new CS_REST_Campaigns( $new_campaign->response, [ 'api_key' => $api_key ] );
			$campaign_to_send->send(
				[
					'ConfirmationEmail' => $confirmation_email,
					'SendDate'          => 'Immediately',
				]
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_campaign_monitor_error',
				$e->getMessage()
			);
		}

		return true;
	}

	/**
	 * Convenience method to retrieve the Campaign Monitor campaign ID for a post.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 * @return string Campaign Monitor campaign ID.
	 */
	public function retrieve_campaign_id( $post_id ) {
		return get_post_meta( $post_id, 'cm_campaign_id', true );
	}

	/**
	 * Convenience method to retrieve the Campaign Monitor send mode for a post.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 * @return string Campaign Monitor send mode
	 */
	public function retrieve_send_mode( $post_id ) {
		return get_post_meta( $post_id, 'cm_send_mode', true );
	}

	/**
	 * Convenience method to retrieve the Campaign Monitor list ID for a post.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 * @return string Campaign Monitor list ID.
	 */
	public function retrieve_list_id( $post_id ) {
		return get_post_meta( $post_id, 'cm_list_id', true );
	}

	/**
	 * Convenience method to retrieve the Campaign Monitor segment ID for a post.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 * @return string Campaign Monitor segment ID.
	 */
	public function retrieve_segment_id( $post_id ) {
		return get_post_meta( $post_id, 'cm_segment_id', true );
	}

	/**
	 * Convenience method to retrieve the Campaign Monitor From Name for a post.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 * @return string Campaign Monitor from name.
	 */
	public function retrieve_from_name( $post_id ) {
		return get_post_meta( $post_id, 'cm_from_name', true );
	}

	/**
	 * Convenience method to retrieve the Campaign Monitor From Email for a post.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 * @return string Campaign Monitor from email.
	 */
	public function retrieve_from_email( $post_id ) {
		return get_post_meta( $post_id, 'cm_from_email', true );
	}

	/**
	 * Validate newsletter data required to send a campaign.
	 * Throws an error if data is missing or invalid.
	 *
	 * @param Object $data Newsletter data to validate.
	 * @param String $preferred_error Preset error to use instead of generic errors.
	 * @throws Exception Error message.
	 * @return Ojbect Validated data.
	 */
	public function validate( $data, $preferred_error = null ) {
		if ( ! $data ) {
			if ( $preferred_error ) {
				// If passed an error, throw that.
				throw new Exception( esc_html( $preferred_error ) );
			} else {
				// Otherwise, throw the generic error.
				throw new Exception( esc_html__( 'Campaign Monitor error: Missing required campaign data.', 'newspack-newsletters' ) );
			}
		}

		if ( empty( $data['send_mode'] ) || empty( $data['from_name'] ) || empty( $data['from_email'] ) ) {
			// If passed an error, throw that.
			if ( $preferred_error && ! Newspack_Newsletters::debug_mode() ) {
				throw new Exception( esc_html( $preferred_error ) );
			}

			// Otherwise, throw the generic error.
			throw new Exception( esc_html__( 'Campaign Monitor error: Missing campaign sender data.', 'newspack-newsletters' ) );
		}

		if ( 'list' === $data['send_mode'] && empty( $data['list_id'] ) ) {
			// If passed an error, throw that.
			if ( $preferred_error && ! Newspack_Newsletters::debug_mode() ) {
				throw new Exception( esc_html( $preferred_error ) );
			}

			// Otherwise, throw the generic error.
			throw new Exception( esc_html__( 'Campaign Monitor error: Must select a list if sending in list mode.', 'newspack-newsletters' ) );
		}

		if ( 'segment' === $data['send_mode'] && empty( $data['segment_id'] ) ) {
			// If passed an error, throw that.
			if ( $preferred_error && ! Newspack_Newsletters::debug_mode() ) {
				throw new Exception( esc_html( $preferred_error ) );
			}

			// Otherwise, throw the generic error.
			throw new Exception( esc_html__( 'Campaign Monitor error: Must select a segment if sending in segment mode.', 'newspack-newsletters' ) );
		}

		return $data;
	}

	/**
	 * Update ESP campaign after refreshing the email HTML, which is triggered by post save.
	 *
	 * @param int   $meta_id Numeric ID of the meta field being updated.
	 * @param int   $post_id The post ID for the meta field being updated.
	 * @param mixed $meta_key The meta key being updated.
	 */
	public function save( $meta_id, $post_id, $meta_key ) {
		$this->retrieve( $post_id );
		return $post_id;
	}

	/**
	 * List not used in this ESP, because campaigns are generated on send.
	 *
	 * @param string $post_id Campaign Id.
	 * @param string $list_id ID of the list.
	 * @return object|WP_Error API Response or error.
	 */
	public function list( $post_id, $list_id ) {
		return null;
	}

	/**
	 * Sender not used in this ESP, because campaigns are generated on send.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 * @param string $from_name Sender name.
	 * @param string $reply_to Reply to email address.
	 * @return object|WP_Error API Response or error.
	 */
	public function sender( $post_id, $from_name, $reply_to ) {
		return null;
	}

	/**
	 * Sync not used in this ESP, because campaigns are generated on send.
	 *
	 * @param WP_POST $post Post to synchronize.
	 * @return object|null API Response or error.
	 */
	public function sync( $post ) {
		return null;
	}

	/**
	 * Trash not used in this ESP, because campaigns are generated on send.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 */
	public function trash( $post_id ) {
		return null;
	}

	/**
	 * Add contact to a list.
	 *
	 * @param array  $contact      {
	 *    Contact data.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 * }
	 * @param string $list_id      List to add the contact to.
	 *
	 * @return array|WP_Error Contact data if it was added, or error otherwise.
	 */
	public function add_contact( $contact, $list_id = false ) {
		if ( false === $list_id ) {
			return new WP_Error( 'newspack_newsletters_constant_contact_list_id', __( 'Missing list id.' ) );
		}
		try {
			$api_key   = $this->api_key();
			$client_id = $this->client_id();
			if ( $api_key && $client_id ) {
				$cm_subscribers   = new CS_REST_Subscribers( $list_id, [ 'api_key' => $api_key ] );
				$email_address    = $contact['email'];
				$found_subscriber = $cm_subscribers->get( $email_address, true );
				$update_payload   = [
					'EmailAddress'   => $email_address,
					'CustomFields'   => [],
					'ConsentToTrack' => 'yes',
					'Resubscribe'    => true,
				];

				if ( isset( $contact['name'] ) ) {
					$update_payload['Name'] = $contact['name'];
				}

				// Get custom fields (metadata) to create them if needed.
				$cm_list            = new CS_REST_Lists( $list_id, [ 'api_key' => $api_key ] );
				$custom_fields_keys = array_map(
					function ( $field ) {
						return $field->FieldName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					},
					$cm_list->get_custom_fields()->response
				);
				if ( isset( $contact['metadata'] ) && is_array( $contact['metadata'] && ! empty( $contact['metadata'] ) ) ) {
					foreach ( $contact['metadata'] as $key => $value ) {
						$update_payload['CustomFields'][] = [
							'Key'   => $key,
							'Value' => (string) $value,
						];
						if ( ! in_array( $key, $custom_fields_keys ) ) {
							$cm_list->create_custom_field(
								[
									'FieldName' => $key,
									'DataType'  => CS_REST_CUSTOM_FIELD_TYPE_TEXT,
								]
							);
						}
					}
				}

				if ( 200 === $found_subscriber->http_status_code ) {
					$result = $cm_subscribers->update( $email_address, $update_payload );
				} else {
					$result = $cm_subscribers->add( $update_payload );
				}
				return $result;
			}
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'newspack_newsletters_campaign_monitor_api_error',
				$e->getMessage()
			);
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
				'name'  => 'Campaign Monitor',
				'list'  => __( 'list or segment', 'newspack-newsletters' ), // "list" in lower case singular format.
				'lists' => __( 'lists or segments', 'newspack-newsletters' ), // "list" in lower case plural format.
				'List'  => __( 'List or Segment', 'newspack-newsletters' ), // "list" in uppercase case singular format.
				'Lists' => __( 'Lists or Segments', 'newspack-newsletters' ), // "list" in uppercase case plural format.
			]
		);
	}

	/**
	 * Get usage data for yesterday.
	 *
	 * @return Newspack_Newsletters_Service_Provider_Usage_Report|WP_Error
	 */
	public function get_usage_report() {
		return Newspack_Newsletters_Campaign_Monitor_Usage_Reports::get_report();
	}
}
