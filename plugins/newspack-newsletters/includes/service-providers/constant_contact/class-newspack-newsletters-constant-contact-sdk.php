<?php
/**
 * Constant Contact Simple SDK
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Constant Contact Simple SDK for v3 API.
 */
final class Newspack_Newsletters_Constant_Contact_SDK {

	/**
	 * Base URI for API requests.
	 *
	 * @var string
	 */
	private $base_uri = 'https://api.cc.email/v3/';

	/**
	 * Authorization request URL.
	 *
	 * @var string
	 */
	private $authorization_url = 'https://authz.constantcontact.com/oauth2/default/v1/authorize';

	/**
	 * Base URI for Token requests.
	 *
	 * @var string
	 */
	private $token_base_uri = 'https://authz.constantcontact.com/oauth2/default/v1/token';

	/**
	 * Scope for API requests.
	 *
	 * @var string[]
	 */
	private $scope = [ 'offline_access', 'account_read', 'contact_data', 'campaign_data' ];

	/**
	 * API Key
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API Secret
	 *
	 * @var string
	 */
	private $api_secret;

	/**
	 * Access token
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Cache for "custom fields".
	 *
	 * @var array
	 */
	private $custom_fields;

	/**
	 * Request counter for rate limiting
	 *
	 * @var int
	 */
	private static $request_count = 0;

	/**
	 * Last request timestamp
	 *
	 * @var float
	 */
	private static $last_request_time = 0;

	/**
	 * Maximum requests per second
	 *
	 * @var int
	 */
	private static $max_requests_per_second = 4;

	/**
	 * Perform API requests.
	 *
	 * @param string $method  Request method.
	 * @param string $path    Request path.
	 * @param array  $options Request options to apply.
	 *
	 * @return object Request result.
	 *
	 * @throws Exception Error message.
	 */
	private function request( $method, $path, $options = [] ) {
		// Rate limiting logic.
		$current_time = microtime( true );
		if ( self::$request_count >= self::$max_requests_per_second ) {
			$time_since_last_request = $current_time - self::$last_request_time;
			if ( $time_since_last_request < 1 ) {
				// Sleep for the remaining time in the 1-second window.
				usleep( absint( ( 1 - $time_since_last_request ) * 1000000 ) );
				self::$request_count = 0;
			}
		}

		// Reset counter if more than 1 second has passed.
		if ( $current_time - self::$last_request_time >= 1 ) {
			self::$request_count = 0;
		}

		/** Remove "/v3/" coming from paging cursors. */
		if ( 0 === strpos( $path, '/v3' ) ) {
			$path = substr( $path, 4 );
		}
		$url = $this->base_uri . $path;
		if ( isset( $options['query'] ) ) {
			foreach ( $options['query'] as $key => $value ) {
				$options['query'][ $key ] = urlencode( $value );
			}
			$url = add_query_arg( $options['query'], $url );
			unset( $options['query'] );
		}
		$args = [
			'method'  => $method,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => $this->access_token ? 'Bearer ' . $this->access_token : '',
			],
		];
		try {
			// Update request counter and timestamp before making request.
			self::$request_count++;
			self::$last_request_time = microtime( true );

			$response = wp_safe_remote_request( $url, $args + $options );
			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}
			$body = json_decode( $response['body'] );
			if ( ! in_array( wp_remote_retrieve_response_code( $response ), [ 200, 201, 202, 204 ] ) ) { // phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar Constant Contact API response codes. See: https://developer.constantcontact.com/api_guide/glossary_responses.html
				if ( is_array( $body ) && isset( $body[0], $body[0]->error_message ) ) {
					throw new Exception( $body[0]->error_message );
				} elseif ( is_object( $body ) && isset( $body->error_message ) ) {
					throw new Exception( $body->error_message );
				} else {
					throw new Exception( wp_remote_retrieve_response_message( $response ) );
				}
			}
			return $body;
		} catch ( Exception $e ) {
			throw new Exception( 'Constant Contact: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Class constructor.
	 *
	 * @param string $api_key      Api Key.
	 * @param string $api_secret   Api Secret.
	 * @param string $access_token Access token.
	 *
	 * @throws Exception Error message.
	 */
	public function __construct( $api_key, $api_secret, $access_token = '' ) {
		if ( ! $api_key ) {
			throw new Exception( 'API key is required.' );
		}
		if ( ! $api_secret ) {
			throw new Exception( 'API secret is required.' );
		}
		$this->api_key    = $api_key;
		$this->api_secret = $api_secret;

		if ( $access_token ) {
			$this->access_token = $access_token;
		}
	}

	/**
	 * Get authorization code url
	 *
	 * @param string $nonce        Nonce.
	 * @param string $redirect_uri Redirect URI.
	 *
	 * @return string
	 */
	public function get_auth_code_url( $nonce, $redirect_uri = '' ) {
		return add_query_arg(
			[
				'response_type' => 'code',
				'state'         => $nonce,
				'client_id'     => $this->api_key,
				'redirect_uri'  => $redirect_uri,
				'scope'         => implode( ' ', $this->scope ),
			],
			$this->authorization_url
		);
	}

	/**
	 * Set access token
	 *
	 * @param string $access_token Access token.
	 */
	public function set_access_token( $access_token ) {
		$this->access_token = $access_token;
	}

	/**
	 * Parse JWT.
	 *
	 * @param string $jwt JWT.
	 *
	 * @return array Containing JWT payload.
	 */
	private static function parse_jwt( $jwt ) {
		$segments = explode( '.', $jwt );
		if ( count( $segments ) !== 3 ) {
			return false;
		}
		$data = json_decode( base64_decode( $segments[1] ), true );
		if ( ! $data ) {
			return false;
		}
		return $data;
	}

	/**
	 * Validate access token
	 *
	 * @param string $access_token Access token.
	 *
	 * @return bool Wether the token is valid or not.
	 */
	public function validate_token( $access_token = '' ) {
		$access_token = $access_token ? $access_token : $this->access_token;
		if ( ! $access_token ) {
			return false;
		}
		$data = self::parse_jwt( $access_token );
		if ( $data['exp'] < time() ) {
			return false;
		}
		return [] === array_diff( $this->scope, $data['scp'] ?? [] );
	}

	/**
	 * Get access token.
	 *
	 * @param string $redirect_uri Redirect URI.
	 * @param string $code         Authorization code.
	 *
	 * @return object Token data.
	 *
	 * @throws Exception Error message.
	 */
	public function get_access_token( $redirect_uri, $code ) {
		$credentials = base64_encode( $this->api_key . ':' . $this->api_secret );
		$query       = [
			'code'         => $code,
			'grant_type'   => 'authorization_code',
			'redirect_uri' => $redirect_uri,
		];
		$args        = [
			'headers' => [
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . $credentials,
			],
		];
		try {
			$response = wp_safe_remote_post( add_query_arg( $query, $this->token_base_uri ), $args );
			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}
			return json_decode( $response['body'] );
		} catch ( Exception $e ) {
			throw new Exception( 'Constant Contact: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Refresh access token.
	 *
	 * @param string $refresh_token Refresh token.
	 *
	 * @return object Token data.
	 *
	 * @throws Exception Error message.
	 */
	public function refresh_token( $refresh_token ) {
		$credentials = base64_encode( $this->api_key . ':' . $this->api_secret );
		$query       = [
			'refresh_token' => $refresh_token,
			'grant_type'    => 'refresh_token',
		];
		$args        = [
			'headers' => [
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . $credentials,
			],
		];
		try {
			$response = wp_safe_remote_post( add_query_arg( $query, $this->token_base_uri ), $args );
			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}
			return json_decode( $response['body'] );
		} catch ( Exception $e ) {
			throw new Exception( 'Constant Contact: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Get Account info
	 *
	 * @return object Account info.
	 */
	public function get_account_info() {
		return $this->request(
			'GET',
			'account/summary',
			[ 'query' => [ 'extra_fields' => 'physical_address' ] ]
		);
	}

	/**
	 * Get account email addresses
	 *
	 * @param array $args Array of query args.
	 *
	 * @return object Email addresses.
	 */
	public function get_email_addresses( $args = [] ) {
		return $this->request( 'GET', 'account/emails', [ 'query' => $args ] );
	}

	/**
	 * Get Contact Lists
	 *
	 * @return object Contact lists.
	 */
	public function get_contact_lists() {
		$args = [
			'include_count'            => 'true',
			'include_membership_count' => 'active',
			'limit'                    => 1000,
			'status'                   => 'active',
		];
		return $this->request(
			'GET',
			'contact_lists',
			[ 'query' => $args ]
		)->lists;
	}

	/**
	 * Get a Contact List by ID
	 *
	 * @param string $id Contact List ID.
	 *
	 * @return object Contact list.
	 */
	public function get_contact_list( $id ) {
		$args = [
			'include_membership_count' => 'active',
		];
		return $this->request(
			'GET',
			'contact_lists/' . $id,
			[ 'query' => $args ]
		);
	}

	/**
	 * Get segments
	 *
	 * @return array
	 */
	public function get_segments() {
		$args = [
			'limit'   => 1000,
			'sort_by' => 'date',
		];
		return $this->request(
			'GET',
			'segments',
			[ 'query' => $args ]
		)->segments;
	}

	/**
	 * Get a segment by ID
	 *
	 * @param string $id Segment ID.
	 *
	 * @return object Segment.
	 */
	public function get_segment( $id ) {
		return $this->request(
			'GET',
			'segments/' . $id
		);
	}

	/**
	 * Get v3 campaign UUID if matches v2 format.
	 *
	 * @param string $campaign_id Campaign ID.
	 *
	 * @return string Campaign ID.
	 */
	private function parse_campaign_id( $campaign_id ) {
		if (
			! preg_match(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-5][0-9a-f]{3}-[089ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
				$campaign_id
			)
		) {
			$ids_res = $this->request(
				'GET',
				'emails/campaign_id_xrefs',
				[ 'query' => [ 'v2_email_campaign_ids' => $campaign_id ] ]
			);
			if ( $ids_res->xrefs && $ids_res->xrefs[0] && $ids_res->xrefs[0]->campaign_id ) {
				$campaign_id = $ids_res->xrefs[0]->campaign_id;
			}
		}
		return $campaign_id;
	}

	/**
	 * Get campaign data from v2 or v3 API
	 *
	 * @param string $campaign_id Campaign id.
	 *
	 * @return object Campaign data.
	 */
	public function get_campaign( $campaign_id ) {
		$campaign           = $this->request( 'GET', 'emails/' . $this->parse_campaign_id( $campaign_id ) );
		$activities         = array_values(
			array_filter(
				$campaign->campaign_activities,
				function ( $activity ) {
					return 'primary_email' === $activity->role;
				}
			)
		);
		$activity_id        = $activities[0]->campaign_activity_id;
		$campaign->activity = $this->get_campaign_activity( $activity_id );

		return $campaign;
	}

	/**
	 * Get campaigns summaries
	 *
	 * @return object
	 */
	public function get_campaigns_summaries() {
		$campaigns = $this->request( 'GET', 'reports/summary_reports/email_campaign_summaries/' );
		return $campaigns;
	}

	/**
	 * Get campaign activity.
	 *
	 * @param string $campaign_activity_id Campaign Activity ID.
	 *
	 * @return object Campaign activity data.
	 */
	public function get_campaign_activity( $campaign_activity_id ) {
		return $this->request( 'GET', 'emails/activities/' . $campaign_activity_id );
	}

	/**
	 * Update campaign name.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param string $name        Campaign name.
	 *
	 * @return object Updated campaign data.
	 */
	public function update_campaign_name( $campaign_id, $name ) {
		return $this->request(
			'PATCH',
			'emails/' . $this->parse_campaign_id( $campaign_id ),
			[ 'body' => wp_json_encode( [ 'name' => $name ] ) ]
		);
	}

	/**
	 * Update campaign activity.
	 *
	 * @param string $campaign_activity_id Campaign Activity ID.
	 * @param string $data                 Campaign Activity Data.
	 *
	 * @return object Updated campaign activity data.
	 */
	public function update_campaign_activity( $campaign_activity_id, $data ) {
		return $this->request(
			'PUT',
			'emails/activities/' . $campaign_activity_id,
			[ 'body' => wp_json_encode( $data ) ]
		);
	}

	/**
	 * Create campaign
	 *
	 * @param array $data Campaign data.
	 *
	 * @return object Created campaign data.
	 */
	public function create_campaign( $data ) {
		$campaign = $this->request(
			'POST',
			'emails',
			[ 'body' => wp_json_encode( $data ) ]
		);
		return $this->get_campaign( $campaign->campaign_id );
	}

	/**
	 * Delete campaign
	 *
	 * @param string $campaign_id Campaign ID.
	 */
	public function delete_campaign( $campaign_id ) {
		$this->request( 'DELETE', 'emails/' . $this->parse_campaign_id( $campaign_id ) );
	}

	/**
	 * Test send email
	 *
	 * @param string   $campaign_activity_id Campaign Activity ID.
	 * @param string[] $emails               Email addresses.
	 */
	public function test_campaign( $campaign_activity_id, $emails ) {
		$this->request(
			'POST',
			'emails/activities/' . $campaign_activity_id . '/tests',
			[ 'body' => wp_json_encode( [ 'email_addresses' => $emails ] ) ]
		);
	}

	/**
	 * Create campaign schedule
	 *
	 * @param string $campaign_activity_id Campaign Activity ID.
	 * @param string $date                 ISO-8601 Formatted date or '0' for immediately.
	 */
	public function create_schedule( $campaign_activity_id, $date = '0' ) {
		$this->request(
			'POST',
			'emails/activities/' . $campaign_activity_id . '/schedules',
			[ 'body' => wp_json_encode( [ 'scheduled_date' => $date ] ) ]
		);
	}

	/**
	 * Get a contact
	 *
	 * @param string $email_address Email address.
	 *
	 * @return object|false Contact or false if not found.
	 */
	public function get_contact( $email_address ) {
		try {
			$res = $this->request(
				'GET',
				'contacts',
				[
					'query' => [
						'email'   => $email_address,
						'status'  => 'all',
						'include' => 'custom_fields,list_memberships,taggings',
					],
				]
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletter_error_get_contact', $e->getMessage() );
		}
		if ( empty( $res->contacts ) ) {
			return false;
		}
		if ( 1 !== count( $res->contacts ) ) {
			return false;
		}
		return $res->contacts[0];
	}

	/**
	 * Get contacts count for a specific query
	 *
	 * @param array $params The query params to be added to the request.
	 *
	 * @return ?int
	 */
	public function get_contacts_count( $params = [] ) {
		$res = $this->request(
			'GET',
			'contacts',
			[
				'query' => array_merge(
					[
						'status'        => 'all',
						'include_count' => true,
						'limit'         => 1,
					],
					$params
				),

			]
		);

		return $res->contacts_count ?? null;
	}

	/**
	 * Get all custom fields.
	 *
	 * @return object[] Custom fields.
	 */
	public function get_custom_fields() {
		if ( $this->custom_fields ) {
			return $this->custom_fields;
		}
		$fields = [];
		$path   = 'contact_custom_fields';
		while ( $path ) {
			$res    = $this->request( 'GET', $path );
			$fields = array_merge( $fields, $res->custom_fields );
			$path   = isset( $res->_links ) ? $res->_links->next->href : null;
		}
		$this->custom_fields = $fields;
		return $this->custom_fields;
	}

	/**
	 * Create or update a custom field if the type has changed.
	 *
	 * @param string $label Custom field label.
	 * @param string $type  Custom field type. Either 'string' or 'date', defaults
	 *                      to 'string'. Leave empty to not alter existing type.
	 *
	 * @return string Custom field ID.
	 */
	public function upsert_custom_field( $label, $type = '' ) {
		$custom_fields    = $this->get_custom_fields();
		$custom_field_idx = array_search( $label, array_column( $custom_fields, 'label' ) );
		if ( false !== $custom_field_idx ) {
			$custom_field = $custom_fields[ $custom_field_idx ];
			if ( empty( $type ) || $custom_field->type === $type ) {
				return $custom_field->custom_field_id;
			}
			$this->request(
				'PUT',
				'contact_custom_fields/' . $custom_field->custom_field_id,
				[ 'body' => wp_json_encode( [ 'type' => $type ] ) ]
			);
		} else {
			$custom_field = $this->request(
				'POST',
				'contact_custom_fields',
				[
					'body' => wp_json_encode(
						[
							'label' => $label,
							'type'  => empty( $type ) ? 'string' : $type,
						]
					),
				]
			);
		}
		return $custom_field->custom_field_id;
	}

	/**
	 * Remove one or more contacts from one or more lists.
	 *
	 * @param string[] $contact_ids Contact IDs.
	 * @param string[] $list_ids List IDs.
	 *
	 * @return array|WP_Error
	 */
	public function remove_contacts_from_lists( $contact_ids, $list_ids ) {
		$body = [
			'source'   => [
				'contact_ids' => $contact_ids,
			],
			'list_ids' => $list_ids,
		];
		try {
			$res = $this->request(
				'POST',
				'/activities/remove_list_memberships',
				[ 'body' => wp_json_encode( $body ) ]
			);
			return $res;
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletter_error_removing_contact_from_lists', $e->getMessage() );
		}
	}

	/**
	 * Create or update a contact
	 *
	 * @param string $email_address Email address.
	 * @param array  $data          {
	 *   Contact data.
	 *
	 *   @type string   $first_name    First name.
	 *   @type string   $last_name     Last name.
	 *   @type string[] $list_ids      List IDs to add the contact to.
	 *   @type string[] $custom_fields Custom field values keyed by their label.
	 * }
	 *
	 * @return WP_Error|object|false Created contact data or false.
	 */
	public function upsert_contact( $email_address, $data = [] ) {
		$contact = $this->get_contact( $email_address );
		$body    = [];
		if ( $contact && ! \is_wp_error( $contact ) ) {
			$body = [
				'email_address'    => isset( $data['email_address'] ) ?
					[
						'address'            => $data['email_address'],
						'permission_to_send' => 'implicit',
					] :
					get_object_vars( $contact->email_address ),
				'list_memberships' => $contact->list_memberships,
				'custom_fields'    => array_map( 'get_object_vars', $contact->custom_fields ),
				'update_source'    => 'Contact',
			];
		} else {
			$body = [
				'email_address' => [
					'address'            => $email_address,
					'permission_to_send' => 'implicit',
				],
				'create_source' => 'Contact',
			];
		}
		if ( ! empty( $data ) ) {
			if ( isset( $data['first_name'] ) ) {
				$body['first_name'] = $data['first_name'];
			}
			if ( isset( $data['last_name'] ) ) {
				$body['last_name'] = $data['last_name'];
			}
			if ( ! empty( $data['list_ids'] ) ) {
				if ( ! isset( $body['list_memberships'] ) ) {
					$body['list_memberships'] = [];
				}
				if ( is_string( $data['list_ids'] ) ) {
					$data['list_ids'] = [ $data['list_ids'] ];
				}
				$body['list_memberships'] = array_unique( array_merge( $body['list_memberships'], array_map( 'strval', $data['list_ids'] ) ), SORT_REGULAR );
			}
			if ( ! empty( $data['custom_fields'] ) ) {
				if ( ! isset( $body['custom_fields'] ) ) {
					$body['custom_fields'] = [];
				}
				$keys = array_keys( $data['custom_fields'] );
				foreach ( $keys as $key ) {
					$key_id  = $this->upsert_custom_field( $key );
					$key_idx = array_search( $key_id, array_column( $body['custom_fields'], 'custom_field_id' ) );
					if ( false !== $key_idx ) {
						$body['custom_fields'][ $key_idx ]['value'] = $data['custom_fields'][ $key ];
					} else {
						$body['custom_fields'][] = [
							'custom_field_id' => $key_id,
							'value'           => $data['custom_fields'][ $key ],
						];
					}
				}
			}
			if ( isset( $data['taggings'] ) ) { // Using isset and not empty because this can be an empty array.
				$body['taggings'] = $data['taggings'];
			}
		}

		try {
			$res = $this->request(
				$contact ? 'PUT' : 'POST',
				$contact ? 'contacts/' . $contact->contact_id : 'contacts',
				[ 'body' => wp_json_encode( $body ) ]
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletters_constant_contact_api_error', $e->getMessage() );
		}
		return $res;
	}

	/**
	 * Fetches a tag by its name
	 *
	 * Constant Contact API does not offer an endpoint to search for a tag by its name, so we have to query for all tags and look through them.
	 *
	 * If there are too many tags, we might not go over them all might fail in finding it, but that is a known limitation for now.
	 * That's why we chose to do a case insensitive search, to increase the chances of finding the tag.
	 *
	 * @param string $name The tag name you are looking for. Case insensitive.
	 * @return stdClass|WP_Error The tag object or a WP_Error if the tag was not found.
	 */
	public function get_tag_by_name( $name ) {
		$limit_attempts    = 10;
		$items_per_attempt = 100;
		$cursor            = '';
		for ( $attempt = 1; $attempt <= $limit_attempts; $attempt++ ) {
			$res = $this->request(
				'GET',
				'/contact_tags',
				[
					'query' => [
						'limit'  => $items_per_attempt,
						'cursor' => $cursor,
					],
				]
			);
			if ( ! empty( $res->tags ) ) {
				foreach ( $res->tags as $tag ) {
					if ( strtolower( $name ) === strtolower( $tag->name ) ) {
						return $tag;
					}
				}
			}

			if ( ! empty( $res->_links ) && ! empty( $res->_links->next ) && ! empty( $res->_links->next->href ) ) {
				$cursor = preg_match( '/cursor=([^&]+)$/', $res->_links->next->href, $matches ) ? $matches[1] : '';
			} else {
				return new WP_Error( 'newspack_newsletter_tag_not_found' );
			}
		}
	}

	/**
	 * Create a tag
	 *
	 * @param string $name The name of the tag to create.
	 * @return stdClass|WP_Error The tag object or a WP_Error if the tag could not be created.
	 */
	public function create_tag( $name ) {
		try {
			$res = $this->request(
				'POST',
				'/contact_tags',
				[
					'body' => wp_json_encode(
						[
							'name' => $name,
						]
					),
				]
			);
			return $res;
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletter_error_creating_tag', $e->getMessage() );
		}
	}

	/**
	 * Updates a Tag name on the provider
	 *
	 * @param string|int $tag_id The tag ID.
	 * @param string     $tag_name The Tag new name.
	 * @return array|WP_Error The tag representation with at least 'id' and 'name' keys on succes. WP_Error on failure.
	 */
	public function update_tag( $tag_id, $tag_name ) {
		try {
			$res = $this->request(
				'PUT',
				sprintf( '/contact_tags/%s', $tag_id ),
				[
					'body' => wp_json_encode(
						[
							'name' => $tag_name,
						]
					),
				]
			);
			return $res;
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletter_error_updating_tag', $e->getMessage() );
		}
	}

	/**
	 * Create a Segment that will group users tagged with a given tag
	 *
	 * @param string $tag_id The ID of the tag to create a segment for.
	 * @param string $tag_name The name of the tag to create a segment for.
	 * @return stdClass|WP_Error The segment object or a WP_Error if the segment could not be created.
	 */
	public function create_tag_segment( $tag_id, $tag_name = '' ) {
		$tag_name = $tag_name ? $tag_name : $tag_id;
		$name     = 'Tagged with ' . $tag_name;
		$criteria = [
			'version'  => '1.0.0',
			'criteria' => [
				'type'  => 'and',
				'group' => [
					[
						'type'  => 'or',
						'group' => [
							[
								'source' => 'tags',
								'field'  => 'tag_id',
								'op'     => 'eq',
								'value'  => $tag_id,
							],
						],
					],
				],
			],
		];

		$body = wp_json_encode(
			[
				'name'             => $name,
				'segment_criteria' => wp_json_encode( $criteria ),
			]
		);

		try {
			$res = $this->request(
				'POST',
				'/segments',
				[
					'body' => $body,
				]
			);
			return $res;
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletter_error_creating_tag_segment', $e->getMessage() );
		}
	}

	/**
	 * Get a tag from its ID
	 *
	 * @param string $tag_id The ID of the tag to get.
	 * @return stdClass|WP_Error The tag object or a WP_Error if the tag could not be found.
	 */
	public function get_tag_by_id( $tag_id ) {
		try {
			$res = $this->request( 'GET', '/contact_tags/' . $tag_id );
			return $res;
		} catch ( Exception $e ) {
			return new WP_Error( 'newspack_newsletter_tag_not_found', $e->getMessage() );
		}
	}
}
