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
		$url         = add_query_arg(
			$query,
			rtrim( $credentials['url'], '/' ) . $api_path . $resource
		);
		$args        = [
			'method'  => $method,
			'timeout' => 45, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'api-token'    => $credentials['key'],
			],
		];
		$response    = wp_safe_remote_request( $url, $args + $options );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$response_message = wp_remote_retrieve_response_message( $response );

		if ( 400 < $response_code || ! empty( $response_body['errors'] ) ) {
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
			'timeout' => 45, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			'headers' => [
				'Content-Type' => $content_type,
				'Accept'       => 'application/json',
				'API-TOKEN'    => $credentials['key'],
			],
			'body'    => $body,
		];
		$response = wp_safe_remote_request( $url, $args + $options );
		if ( is_wp_error( $response ) ) {
			return $response;
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
		$campaigns = $this->api_v1_request( 'campaign_list', 'GET', [ 'query' => [ 'ids' => $campaign_id ] ] );
		if ( is_wp_error( $campaigns ) ) {
			return $campaigns;
		}
		$deletable_statuses = [
			'0', // Draft.
			'1', // Scheduled.
			'6', // Disabled.
		];
		if ( true !== $force && ! in_array( $campaigns[0]['status'], $deletable_statuses ) ) {
			return new \WP_Error(
				'newspack_newsletters_active_campaign_campaign_not_deletable',
				__( 'Campaign is not deletable.', 'newspack-newsletters' )
			);
		}
		return $this->api_v1_request( 'campaign_delete', 'GET', [ 'query' => [ 'id' => $campaign_id ] ] );
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
	 * Send a campaign.
	 *
	 * @param WP_Post $post Post to send.
	 *
	 * @return true|WP_Error True if the campaign was sent or error if failed.
	 */
	public function send( $post ) {
		$post_id = $post->ID;

		$error = null;

		/** Clean up existing campaign. */
		$campaign_id = get_post_meta( $post_id, 'ac_campaign_id', true );
		if ( $campaign_id ) {
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
