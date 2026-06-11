<?php
/**
 * Newspack Newsletters ESP-Agnostic Subscription Functionality
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;
use Newspack\Newsletters\Reader_Activation;

/**
 * Manages Settings Subscription Class.
 */
class Newspack_Newsletters_Subscription {
	const EMAIL_VERIFIED_META    = 'newspack_newsletters_email_verified';
	const EMAIL_VERIFIED_REQUEST = 'newspack_newsletters_email_verification_request';
	const EMAIL_VERIFIED_CONFIRM = 'newspack_newsletters_email_verification';

	const WC_ENDPOINT         = 'newsletters';
	const SUBSCRIPTION_UPDATE = 'newspack_newsletters_subscription';

	const ASYNC_ACTION = 'newspack_newsletters_subscription_subscribe_contact';

	const SUBSCRIPTION_INTENT_CPT = 'np_nl_sub_intent';

	const LISTS_CACHE_PREFIX = 'newspack_newsletters_lists_';

	/**
	 * Memoized lists config.
	 *
	 * @var array
	 */
	private static $lists_config;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_api_endpoints' ] );
		add_action( 'newspack_registered_reader', [ __CLASS__, 'newspack_registered_reader' ], 10, 5 );

		add_action( 'save_post_' . Subscription_Lists::CPT, [ __CLASS__, 'clear_lists_cache' ] );
		add_action( 'deleted_post', [ __CLASS__, 'clear_lists_cache_on_delete' ], 10, 2 );
		add_action( 'newspack_newsletters_provider_credentials_changed', [ __CLASS__, 'clear_lists_cache' ] );

		/** User email verification for subscription management. */
		add_action( 'resetpass_form', [ __CLASS__, 'set_current_user_email_verified' ] );
		add_action( 'password_reset', [ __CLASS__, 'set_current_user_email_verified' ] );
		add_action( 'newspack_magic_link_authenticated', [ __CLASS__, 'set_current_user_email_verified' ] );
		add_action( 'newspack_reader_verified', [ __CLASS__, 'set_user_email_verified' ] );
		add_action( 'template_redirect', [ __CLASS__, 'process_email_verification_request' ] );
		add_action( 'template_redirect', [ __CLASS__, 'process_email_verification' ] );

		/** Subscription management through WC's "My Account".  */
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_filter( 'woocommerce_get_query_vars', [ __CLASS__, 'add_query_var' ] );
		add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'add_menu_item' ], 20 );
		add_action( 'woocommerce_account_newsletters_endpoint', [ __CLASS__, 'endpoint_content' ] );
		add_action( 'template_redirect', [ __CLASS__, 'process_subscription_update' ] );
		add_action( 'init', [ __CLASS__, 'flush_rewrite_rules' ] );

		/** Subscription intents */
		add_action( 'wp_ajax_' . self::ASYNC_ACTION, [ __CLASS__, 'handle_async_subscribe' ] );
		add_action( 'wp_ajax_nopriv_' . self::ASYNC_ACTION, [ __CLASS__, 'handle_async_subscribe' ] );
		add_action( 'init', [ __CLASS__, 'register_subscription_intents' ] );
	}

	/**
	 * Register API endpoints.
	 */
	public static function register_api_endpoints() {
		register_rest_route(
			Newspack_Newsletters::API_NAMESPACE,
			'/lists_config',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_lists_config' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			Newspack_Newsletters::API_NAMESPACE,
			'/lists',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_lists' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_administration_permissions_check' ],
			]
		);
		register_rest_route(
			Newspack_Newsletters::API_NAMESPACE,
			'/lists',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_update_lists' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_administration_permissions_check' ],
				'args'                => [
					'lists' => [
						'type'     => 'array',
						'required' => true,
						'items'    => [
							'type'       => 'object',
							'properties' => [
								'id'          => [
									'type' => 'string',
								],
								'active'      => [
									'type' => 'boolean',
								],
								'title'       => [
									'type' => 'string',
								],
								'description' => [
									'type' => 'string',
								],
							],
						],
					],
				],
			]
		);
		register_rest_route(
			Newspack_Newsletters::API_NAMESPACE,
			'/lists/local',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_create_local_list' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_administration_permissions_check' ],
				'args'                => [
					'title'       => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'description' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'audience'    => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
		register_rest_route(
			Newspack_Newsletters::API_NAMESPACE,
			'/lists/local/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'api_update_local_list' ],
					'permission_callback' => [ 'Newspack_Newsletters', 'api_administration_permissions_check' ],
					'args'                => [
						'id'          => [
							'type'     => 'integer',
							'required' => true,
						],
						'title'       => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'description' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'audience'    => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ __CLASS__, 'api_delete_local_list' ],
					'permission_callback' => [ 'Newspack_Newsletters', 'api_administration_permissions_check' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);
		register_rest_route(
			Newspack_Newsletters::API_NAMESPACE,
			'/lists/audiences',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_audiences' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_administration_permissions_check' ],
			]
		);
		register_rest_route(
			Newspack_Newsletters::API_NAMESPACE,
			'/lists/(?P<id>\d+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ __CLASS__, 'api_patch_list' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_administration_permissions_check' ],
				'args'                => [
					'id'          => [
						'type'     => 'integer',
						'required' => true,
					],
					'active'      => [
						'type'     => 'boolean',
						'required' => false,
					],
					'title'       => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'description' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);
	}

	/**
	 * API method to retrieve the current lists configuration.
	 *
	 * @return WP_REST_Response|WP_Error WP_REST_Response on success, or WP_Error object on failure.
	 */
	public static function api_get_lists_config() {
		return \rest_ensure_response( self::get_lists_config() );
	}

	/**
	 * API method to retrieve the current ESP lists.
	 *
	 * @return WP_REST_Response|WP_Error WP_REST_Response on success, or WP_Error object on failure.
	 */
	public static function api_get_lists() {
		return \rest_ensure_response( self::get_lists() );
	}

	/**
	 * API method to update the list configuration.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error WP_REST_Response on success, or WP_Error object on failure.
	 */
	public static function api_update_lists( $request ) {
		$lists = $request['lists'];
		if ( ! is_array( $lists ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'The "lists" parameter must be an array.', 'newspack-newsletters' ),
				[ 'status' => 400 ]
			);
		}
		$update = self::update_lists( $lists );
		if ( is_wp_error( $update ) ) {
			return \rest_ensure_response( $update );
		}
		return \rest_ensure_response( self::get_lists() );
	}

	/**
	 * API method to create a single local subscription list.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error WP_REST_Response on success, or WP_Error object on failure.
	 */
	public static function api_create_local_list( $request ) {
		// Mirror the React shell's CTA gate so a stale page (provider
		// switched in another tab) cannot create an unreachable list.
		$provider = Newspack_Newsletters::get_service_provider();
		if (
			empty( $provider )
			|| 'manual' === Newspack_Newsletters::service_provider()
			|| ! Newspack_Newsletters::is_service_provider_configured()
			|| empty( $provider::$support_local_lists )
		) {
			return \rest_ensure_response(
				new WP_Error(
					'newspack_newsletters_local_lists_unavailable',
					__( 'Local lists are not available for the configured provider.', 'newspack-newsletters' ),
					[ 'status' => 400 ]
				)
			);
		}

		$list = Subscription_Lists::create_local_list(
			(string) $request->get_param( 'title' ),
			(string) $request->get_param( 'description' ),
			(string) $request->get_param( 'audience' )
		);
		if ( is_wp_error( $list ) ) {
			return \rest_ensure_response( $list );
		}
		return \rest_ensure_response( $list->to_array() );
	}

	/**
	 * API method to update a single local subscription list.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error WP_REST_Response on success, or WP_Error object on failure.
	 */
	public static function api_update_local_list( $request ) {
		$list = Subscription_Lists::update_local_list(
			(int) $request->get_param( 'id' ),
			(string) $request->get_param( 'title' ),
			(string) $request->get_param( 'description' ),
			(string) $request->get_param( 'audience' )
		);
		if ( is_wp_error( $list ) ) {
			return \rest_ensure_response( $list );
		}
		return \rest_ensure_response( $list->to_array() );
	}

	/**
	 * API method to update a single subscription list. Locals must use
	 * /lists/local/{id} for title/description — that path also handles
	 * audience binding.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_patch_list( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || Subscription_Lists::CPT !== $post->post_type ) {
			return \rest_ensure_response(
				new WP_Error(
					'newspack_newsletters_list_not_found',
					__( 'Subscription list not found.', 'newspack-newsletters' ),
					[ 'status' => 404 ]
				)
			);
		}

		$list   = new Subscription_List( $post );
		$fields = [];

		if ( null !== $request->get_param( 'active' ) ) {
			$active = (bool) $request->get_param( 'active' );
			// Mirror the bulk path: signup forms can't see unconfigured locals, so don't let them be active.
			if ( $active && $list->is_local() && ! $list->is_configured_for_current_provider() ) {
				$active = false;
			}
			$fields['active'] = $active;
		}

		$has_title       = null !== $request->get_param( 'title' );
		$has_description = null !== $request->get_param( 'description' );
		if ( $has_title || $has_description ) {
			if ( $list->is_local() ) {
				return \rest_ensure_response(
					new WP_Error(
						'newspack_newsletters_local_list_use_local_endpoint',
						__( 'Local lists must be edited via /lists/local/{id}.', 'newspack-newsletters' ),
						[ 'status' => 400 ]
					)
				);
			}
			if ( $has_title ) {
				$title = trim( (string) $request->get_param( 'title' ) );
				if ( '' === $title ) {
					return \rest_ensure_response(
						new WP_Error(
							'newspack_newsletters_list_invalid_title',
							__( 'List title is required.', 'newspack-newsletters' ),
							[ 'status' => 400 ]
						)
					);
				}
				$fields['title'] = $title;
			}
			if ( $has_description ) {
				$fields['description'] = (string) $request->get_param( 'description' );
			}
		}

		if ( ! empty( $fields ) ) {
			$updated = $list->update( $fields );
			if ( is_wp_error( $updated ) ) {
				return \rest_ensure_response( $updated );
			}
		}

		return \rest_ensure_response( $list->to_array() );
	}

	/**
	 * API method to delete a single local subscription list.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error WP_REST_Response on success, or WP_Error object on failure.
	 */
	public static function api_delete_local_list( $request ) {
		$result = Subscription_Lists::delete_local_list( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return \rest_ensure_response( $result );
		}
		return \rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * API method to fetch native audiences from the active provider, plus
	 * the provider-aware labels the modal needs for its picker.
	 *
	 * @return WP_REST_Response
	 */
	public static function api_get_audiences() {
		$payload = [
			'audiences'        => [],
			'audience_label'   => __( 'List', 'newspack-newsletters' ),
			'help_before_save' => '',
		];

		$provider = Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return \rest_ensure_response( $payload );
		}

		$payload['audience_label']   = $provider::label( 'List' );
		$payload['help_before_save'] = $provider::label( 'tag_metabox_before_save' );

		if ( ! method_exists( $provider, 'get_lists' ) ) {
			return \rest_ensure_response( $payload );
		}

		$lists = $provider->get_lists();
		if ( is_wp_error( $lists ) || ! is_array( $lists ) ) {
			return \rest_ensure_response( $payload );
		}

		$audiences = [];
		foreach ( $lists as $list ) {
			// Native audiences only — providers (Mailchimp) tack groups
			// and tags onto the same payload with a non-empty `type`,
			// which would point our auto-generated tag at another tag.
			if ( ! empty( $list['type'] ) ) {
				continue;
			}
			if ( empty( $list['id'] ) || empty( $list['name'] ) ) {
				continue;
			}
			$audiences[] = [
				'id'   => (string) $list['id'],
				'name' => (string) $list['name'],
			];
		}
		$payload['audiences'] = $audiences;
		return \rest_ensure_response( $payload );
	}

	/**
	 * Get the lists available for subscription.
	 *
	 * @return array|WP_Error Lists or error.
	 */
	public static function get_lists() {
		$provider = Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return new WP_Error( 'newspack_newsletters_invalid_provider', __( 'Provider is not set.' ) );
		}
		$cache_key = self::LISTS_CACHE_PREFIX . Newspack_Newsletters::service_provider();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$lists = $provider->get_lists();
			if ( is_wp_error( $lists ) ) {
				return $lists;
			}
			/**
			 * We loop through the lists returned by the ESP.
			 * Only remote lists that still exist in the ESP will be returned.
			 */
			$return_lists = array_map(
				function ( $list ) {
					if ( ! isset( $list['id'], $list['name'] ) || empty( $list['id'] ) || empty( $list['name'] ) ) {
						return;
					}

					// This is messy, when the ESP returns lists, it's name, when we get it from our UIs, it's title... we need both.
					$list['title'] = $list['name'];

					$stored_list = Subscription_Lists::get_or_create_remote_list( $list );

					if ( is_wp_error( $stored_list ) ) {
						return;
					}

					return $stored_list->to_array();
				},
				$lists
			);

			/**
			 * Remove from the local DB lists that no longer exist in the ESP.
			 * This also cleans up the DB in case we accidentally created more than one list for the same ESP list.
			 */
			Subscription_Lists::garbage_collector( wp_list_pluck( $return_lists, 'db_id' ) );

			foreach ( Subscription_Lists::get_locals_for_current_provider() as $local_list ) {
				$return_lists[] = $local_list->to_array();
			}
			set_transient( $cache_key, $return_lists, self::get_lists_cache_ttl() );
			return $return_lists;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_get_lists',
				$e->getMessage()
			);
		}
		return [];
	}

	/**
	 * TTL, in seconds, for the cached subscription lists.
	 *
	 * @return int
	 */
	private static function get_lists_cache_ttl() {
		return (int) apply_filters( 'newspack_newsletters_lists_cache_ttl', 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Clear the cached subscription lists for every registered provider.
	 */
	public static function clear_lists_cache() {
		foreach ( array_keys( Newspack_Newsletters::get_registered_providers() ) as $slug ) {
			delete_transient( self::LISTS_CACHE_PREFIX . $slug );
		}
	}

	/**
	 * Clear the cache when a subscription list post is deleted.
	 *
	 * @param int           $post_id Deleted post ID.
	 * @param \WP_Post|null $post   Deleted post object.
	 */
	public static function clear_lists_cache_on_delete( $post_id, $post = null ) {
		if ( $post instanceof \WP_Post && Subscription_Lists::CPT === $post->post_type ) {
			self::clear_lists_cache();
		}
	}

	/**
	 * Get the lists configuration for the active provider.
	 *
	 * @return array[]|WP_Error Associative array with list configuration keyed by list ID or error.
	 */
	public static function get_lists_config() {
		if ( self::$lists_config ) {
			return self::$lists_config;
		}
		$provider = Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return new WP_Error( 'newspack_newsletters_invalid_provider', __( 'Provider is not set.', 'newspack-newsletters' ) );
		}

		$saved_lists  = Subscription_Lists::get_configured_for_current_provider();
		$active_lists = [];

		foreach ( $saved_lists as $list ) {
			if ( ! $list->is_active() ) {
				continue;
			}
			$active_lists[ $list->get_public_id() ] = $list->to_array();
		}

		self::$lists_config = $active_lists;
		return self::$lists_config;
	}

	/**
	 * Update the lists settings.
	 *
	 * @param array[] $lists {
	 *    Array of list configuration.
	 *
	 *    @type string  id          The list id in the ESP (not the ID in the DB)
	 *    @type boolean active      Whether the list is available for subscription.
	 *    @type string  title       The list title.
	 *    @type string  description The list description.
	 * }
	 *
	 * @return boolean|WP_Error Whether the lists were updated or error.
	 */
	public static function update_lists( $lists ) {
		$provider = Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return new WP_Error( 'newspack_newsletters_invalid_provider', __( 'Provider is not set.', 'newspack-newsletters' ) );
		}
		$lists = self::sanitize_lists( $lists );
		if ( empty( $lists ) ) {
			return new WP_Error( 'newspack_newsletters_invalid_lists', __( 'Invalid list configuration.', 'newspack-newsletters' ) );
		}

		return Subscription_Lists::update_lists( $lists );
	}

	/**
	 * Sanitize an array of list configuration.
	 *
	 * @param array[] $lists Array of list configuration.
	 *
	 * @return array[] Sanitized associative array of list configuration keyed by the list ID.
	 */
	public static function sanitize_lists( $lists ) {
		$sanitized = [];
		foreach ( $lists as $list ) {
			if ( ! isset( $list['id'] ) || empty( $list['id'] ) ) {
				continue;
			}
			$entry = [
				'id'     => $list['id'],
				'active' => isset( $list['active'] ) ? (bool) $list['active'] : false,
			];
			// Omit null/non-string title and description so update() no-ops the field.
			if ( isset( $list['title'] ) && is_string( $list['title'] ) ) {
				$title = trim( $list['title'] );
				if ( '' !== $title ) {
					$entry['title'] = $title;
				}
			}
			if ( isset( $list['description'] ) && is_string( $list['description'] ) ) {
				$entry['description'] = $list['description'];
			}
			$sanitized[] = $entry;
		}
		return $sanitized;
	}

	/**
	 * Whether the current provider setup support subscription management.
	 *
	 * @return boolean
	 */
	public static function has_subscription_management() {
		$provider = Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return false;
		}
		if ( ! method_exists( $provider, 'get_contact_lists' ) || ! method_exists( $provider, 'update_contact_lists' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Get contact data by email.
	 *
	 * @param string $email_address Email address.
	 * @param bool   $return_details Fetch full contact data.
	 *
	 * @return array|WP_Error Response or error.
	 */
	public static function get_contact_data( $email_address, $return_details = false ) {
		if ( ! $email_address || empty( $email_address ) ) {
			return new WP_Error( 'newspack_newsletters_invalid_email', __( 'Missing email address.', 'newspack-newsletters' ) );
		}

		$provider = Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return new WP_Error( 'newspack_newsletters_invalid_provider', __( 'Provider is not set.', 'newspack-newsletters' ) );
		}

		if ( ! method_exists( $provider, 'get_contact_data' ) ) {
			return new WP_Error( 'newspack_newsletters_not_implemented', __( 'Provider does not handle the contact-exists check.', 'newspack-newsletters' ) );
		}

		return $provider->get_contact_data( $email_address, $return_details );
	}

	/**
	 * Upserts a contact to lists.
	 *
	 * A contact can be added asynchronously, which means the request will return
	 * immediately and the contact will be added in the background. In this case
	 * the response will be `true` and the caller must handle it optimistically.
	 *
	 * @param array          $contact {
	 *          Contact information.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 * }
	 * @param string[]|false $lists   Array of list IDs to subscribe the contact to. If empty or false, contact will be created but not subscribed to any lists.
	 * @param bool           $async   Whether to add the contact asynchronously. Default is false.
	 *
	 * @return array|WP_Error|true Contact data if it was added, or error otherwise. True if async.
	 */
	public static function add_contact( $contact, $lists = false, $async = false ) {
		_deprecated_function( __METHOD__, '2.21', 'Newspack_Newsletters_Contacts::subscribe' );
		return Newspack_Newsletters_Contacts::subscribe( $contact, $lists, $async, 'deprecated' );
	}

	/**
	 * Register a subscription intent and dispatches a async request to process it.
	 *
	 * @param array  $contact     Contact information.
	 * @param array  $lists       Array of list IDs to subscribe the contact to.
	 * @param string $context Context of the update for logging purposes.
	 *
	 * @return int|WP_Error Subscription intent ID or error.
	 */
	public static function add_subscription_intent( $contact, $lists, $context = '' ) {
		$intent_id = \wp_insert_post(
			[
				'post_type'   => self::SUBSCRIPTION_INTENT_CPT,
				'post_status' => 'publish',
				'meta_input'  => [
					'contact' => $contact,
					'lists'   => $lists,
					'errors'  => [],
					'context' => $context,
				],
			]
		);
		if ( is_wp_error( $intent_id ) ) {
			Newspack_Newsletters_Logger::log( 'Error adding subscription intent: ' . $intent_id->get_error_message() );
		}

		$nonce     = wp_create_nonce( self::ASYNC_ACTION );
		$url       = admin_url( 'admin-ajax.php?action=' . self::ASYNC_ACTION . '&nonce=' . $nonce );
		$args      = [
			'timeout'   => 0.01,
			'blocking'  => false,
			'cookies'   => $_COOKIE, // phpcs:ignore
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => [
				'action_name' => self::ASYNC_ACTION,
				'intent_id'   => $intent_id,
			],
		];
		wp_remote_post( $url, $args );

		return $intent_id;
	}

	/**
	 * Get subscription intent.
	 *
	 * @param int|\WP_Post $intent_id_or_post Intent ID or post object.
	 *
	 * @return array|false Subscription intent data or false if not found.
	 */
	private static function get_subscription_intent( $intent_id_or_post ) {
		if ( is_numeric( $intent_id_or_post ) ) {
			$intent = \get_post( $intent_id_or_post );
		} else {
			$intent = $intent_id_or_post;
		}
		if ( ! $intent ) {
			return false;
		}
		return [
			'id'      => $intent->ID,
			'contact' => get_post_meta( $intent->ID, 'contact', true ),
			'lists'   => get_post_meta( $intent->ID, 'lists', true ),
			'errors'  => get_post_meta( $intent->ID, 'errors', true ),
			'context' => get_post_meta( $intent->ID, 'context', true ),
		];
	}

	/**
	 * Remove subscription intent.
	 *
	 * @param int $intent_id Intent ID.
	 *
	 * @return void
	 */
	private static function remove_subscription_intent( $intent_id ) {
		$intent = \get_post( $intent_id );
		if ( ! $intent || self::SUBSCRIPTION_INTENT_CPT !== $intent->post_type ) {
			return;
		}
		\wp_delete_post( $intent_id, true );
	}

	/**
	 * Process subscription intent.
	 *
	 * @param int|null $intent_id Optional intent ID. If not provided, all intents will be processed.
	 *
	 * @return void
	 */
	public static function process_subscription_intents( $intent_id = null ) {
		if ( $intent_id ) {
			$intents = [ self::get_subscription_intent( $intent_id ) ];
		} else {
			$intents = \get_posts(
				[
					'post_type'   => self::SUBSCRIPTION_INTENT_CPT,
					'numberposts' => 3, // Limit to 3 intents per request.
				]
			);
		}

		$provider = Newspack_Newsletters::get_service_provider();
		foreach ( $intents as $intent ) {
			if ( empty( $intent ) ) {
				continue;
			}
			if ( is_object( $intent ) || is_numeric( $intent ) ) {
				$intent = self::get_subscription_intent( $intent );
			}
			// An intent's `contact` meta can be missing or corrupted (e.g. cleared by
			// a third party, or never written due to a wp_insert_post race). Without a
			// valid contact array we have nothing to subscribe; remove the intent so
			// it doesn't keep throwing on every cron tick. Done regardless of provider
			// availability so a missing provider can't strand bad intents in the queue.
			if ( ! is_array( $intent ) || ! isset( $intent['id'] ) || ! is_array( $intent['contact'] ) || empty( $intent['contact']['email'] ) ) {
				if ( is_array( $intent ) && ! empty( $intent['id'] ) ) {
					Newspack_Newsletters_Logger::log( 'Removing malformed subscription intent ' . $intent['id'] . ' (missing or invalid contact data).' );
					self::remove_subscription_intent( $intent['id'] );
				}
				continue;
			}
			// No provider configured: leave valid intents queued for a later cron run.
			if ( empty( $provider ) ) {
				continue;
			}
			if ( is_array( $intent['errors'] ) && count( $intent['errors'] ) > 3 ) {
				Newspack_Newsletters_Logger::log( 'Too many errors for contact ' . $intent['contact']['email'] . '. Removing intent.' );
				self::remove_subscription_intent( $intent['id'] );
				continue;
			}

			$contact = $intent['contact'];
			$email   = $contact['email'];
			$lists   = $intent['lists'];
			$context = $intent['context'];

			$result = Newspack_Newsletters_Contacts::subscribe( $contact, $lists, false, $context . ' (ASYNC)' );

			$user = get_user_by( 'email', $email );
			if ( \is_wp_error( $result ) ) {
				$email = $contact['email'];
				if ( $user ) {
					update_user_meta( $user->ID, 'newspack_newsletters_subscription_error', $result->get_error_message() );
				}
				if ( ! is_array( $intent['errors'] ) ) {
					$intent['errors'] = [];
				}
				$intent['errors'][] = $result->get_error_message();
				\update_post_meta( $intent['id'], 'errors', $intent['errors'] );
				Newspack_Newsletters_Logger::log( 'Error adding contact: ' . $result->get_error_message() );
			} else {
				self::remove_subscription_intent( $intent['id'] );
				if ( $user ) {
					delete_user_meta( $user->ID, 'newspack_newsletters_subscription_error' );
				}
			}
		}
	}

	/**
	 * Get a user subscription intent error.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string|false Error message or false if not found.
	 */
	private static function get_user_subscription_intent_error( $user_id ) {
		return get_user_meta( $user_id, 'newspack_newsletters_subscription_error', true );
	}

	/**
	 * Register subscription intent custom post type
	 */
	public static function register_subscription_intents() {
		\register_post_type( self::SUBSCRIPTION_INTENT_CPT );
		$intents = get_posts( [ 'post_type' => self::SUBSCRIPTION_INTENT_CPT ] );
		if ( ! empty( $intents ) && ! \wp_next_scheduled( 'newspack_newsletters_process_subscription_intents' ) ) {
			\wp_schedule_single_event( time() + 5 * 60, 'newspack_newsletters_process_subscription_intents' );
		}
		add_action( 'newspack_newsletters_process_subscription_intents', [ __CLASS__, 'process_subscription_intents' ] );
	}

	/**
	 * Handle async strategy for `add_contact` method.
	 */
	public static function handle_async_subscribe() {
		// Don't lock up other requests while processing.
		session_write_close(); // phpcs:ignore

		if ( ! isset( $_REQUEST['nonce'] ) || ! \wp_verify_nonce( \sanitize_text_field( $_REQUEST['nonce'] ), self::ASYNC_ACTION ) ) {
			\wp_die( 'Invalid nonce.', '', 400 );
		}

		$intent_id = $_POST['intent_id'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$intent_id = \absint( $intent_id );

		if ( empty( $intent_id ) ) {
			\wp_die( 'Invalid intent ID.', '', 400 );
		}

		self::process_subscription_intents( $intent_id );
		\wp_die( 'OK', '', 200 );
	}

	/**
	 * Add a contact to ESP when a reader is registered.
	 *
	 * @param string         $email         Email address.
	 * @param bool           $authenticate  Whether to authenticate after registering.
	 * @param false|int      $user_id       The created user id.
	 * @param false|\WP_User $existing_user The existing user object.
	 * @param array          $metadata      Metadata.
	 */
	public static function newspack_registered_reader( $email, $authenticate, $user_id, $existing_user, $metadata ) {
		// Prevent double-syncing to audience if the registration method was through a Newsletter Subscription Form block.
		if ( isset( $metadata['registration_method'] ) && 'newsletters-subscription' === $metadata['registration_method'] ) {
			return;
		}

		if ( empty( $metadata['lists'] ) ) {
			return;
		}

		$lists = $metadata['lists'];
		unset( $metadata['lists'] );

		$metadata['newsletters_subscription_method'] = 'reader-registration';

		// Adding is actually upserting, so no need to check if the hook is called for an existing user.
		try {
			Newspack_Newsletters_Contacts::subscribe(
				[
					'email'    => $email,
					'metadata' => $metadata,
				],
				$lists,
				true, // Async.
				'Reader registration hook on Newsletters plugin'
			);
		} catch ( \Exception $e ) {
			// Avoid breaking the registration process.
			Newspack_Newsletters_Logger::log( 'Error adding contact: ' . $e->getMessage() );
		}
	}

	/**
	 * Update a contact lists subscription.
	 *
	 * This method will remove the contact from all subscription lists and add
	 * them to the specified lists.
	 *
	 * @param string   $email Contact email address.
	 * @param string[] $lists Array of list IDs to subscribe the contact to.
	 *
	 * @return bool|WP_Error Whether the contact was updated or error.
	 */
	private static function update_contact_lists( $email, $lists = [] ) {
		_deprecated_function( __METHOD__, '2.21', 'Newspack_Newsletters_Contacts::update_lists' );
		return Newspack_Newsletters_Contacts::update_lists( $email, $lists, 'deprecated' );
	}

	/**
	 * Get a contact status.
	 *
	 * @param string $email The contact email.
	 *
	 * @return string[]|false|WP_Error Contact subscribed list names keyed by ID, false if not found or error.
	 */
	public static function get_contact_lists( $email ) {
		if ( ! self::has_subscription_management() ) {
			return new WP_Error( 'newspack_newsletters_not_supported', __( 'Not supported for this provider', 'newspack-newsletters' ) );
		}
		return Newspack_Newsletters::get_service_provider()->get_contact_combined_lists( $email );
	}

	/**
	 * Whether the contact is a newsletter subscriber.
	 *
	 * This method will only check against the subscription lists configured in
	 * the plugin, not all lists in the ESP.
	 *
	 * @param string $email The contact email.
	 *
	 * @return bool|WP_Error Whether the contact is a newsletter subscriber or error.
	 */
	public static function is_newsletter_subscriber( $email ) {
		$list_config = self::get_lists_config();
		if ( is_wp_error( $list_config ) ) {
			return $list_config;
		}
		if ( empty( $list_config ) ) {
			return false;
		}
		$lists = self::get_contact_lists( $email );
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}
		if ( empty( $lists ) ) {
			return false;
		}
		$lists = array_flip( $lists );
		foreach ( $list_config as $list ) {
			if ( isset( $lists[ $list['id'] ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current user has its email verified in order to manage their
	 * newletters subscriptions.
	 *
	 * @param int    $user_id User ID. Default is the current user ID.
	 * @param string $email   Email address being verified. Default is the current user email.
	 *
	 * @return bool
	 */
	public static function is_email_verified( $user_id = 0, $email = '' ) {
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( empty( $email ) ) {
			$email = $user->user_email;
		}

		$verified_emails = get_user_meta( $user_id, self::EMAIL_VERIFIED_META, true );
		if ( ! is_array( $verified_emails ) ) {
			$verified_emails = [];
		}

		$verified = in_array( $email, $verified_emails, true );

		/**
		 * Filters whether the current user has its email verified.
		 *
		 * @param bool    $verified Whether the current user has its email verified.
		 * @param WP_User $user     User object.
		 * @param string  $email    Email address being verified.
		 */
		return (bool) apply_filters( 'newspack_newsletters_is_email_verified', $verified, $user, $email );
	}

	/**
	 * Set email as verified for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   Email address being verified. Default is the current user's email.
	 *
	 * @return bool Wether the email was marked as verified successfully.
	 */
	public static function set_email_verified( $user_id, $email = '' ) {
		$verified_emails = get_user_meta( $user_id, self::EMAIL_VERIFIED_META, true );
		if ( ! is_array( $verified_emails ) ) {
			$verified_emails = [];
		}
		if ( empty( $email ) ) {
			$email = get_user_by( 'id', $user_id )->user_email;
		}
		if ( ! in_array( $email, $verified_emails, true ) ) {
			$verified_emails[] = $email;
			return update_user_meta( $user_id, self::EMAIL_VERIFIED_META, $verified_emails );
		}
		return false;
	}

	/**
	 * Set the user's email as verified.
	 *
	 * @param WP_User $user User.
	 */
	public static function set_user_email_verified( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		self::set_email_verified( $user->ID, $user->user_email );
	}

	/**
	 * Set current user's email as verified.
	 */
	public static function set_current_user_email_verified() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		self::set_user_email_verified( wp_get_current_user() );
	}

	/**
	 * Get current user email verification transient key.
	 *
	 * @param string $email Email address being verified. Default is the current user's email.
	 */
	private static function get_email_verification_transient_key( $email = '' ) {
		$user_id = get_current_user_id();
		if ( empty( $email ) ) {
			$email = get_user_by( 'id', $user_id )->user_email;
		}
		return sprintf( 'newspack_newsletters_email_verification_%s_%s', $user_id, wp_hash( $email ) );
	}

	/**
	 * Process request to verify a user's email.
	 *
	 * A 1-day transient will hold a token to verify the email.
	 */
	public static function process_email_verification_request() {
		if ( ! isset( $_GET[ self::EMAIL_VERIFIED_REQUEST ] ) || ! wp_verify_nonce( sanitize_text_field( $_GET[ self::EMAIL_VERIFIED_REQUEST ] ), self::EMAIL_VERIFIED_REQUEST ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html( __( 'Invalid request.', 'newspack-newsletters' ) ), '', 400 );
		}

		$user               = wp_get_current_user();
		$transient_key      = self::get_email_verification_transient_key();
		$token              = \wp_generate_password( 43, false, false );
		$verification_nonce = wp_create_nonce( self::EMAIL_VERIFIED_CONFIRM );

		$url = home_url();
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$url = wc_get_account_endpoint_url( 'newsletters' );
		}
		$url = add_query_arg(
			[
				self::EMAIL_VERIFIED_CONFIRM => $verification_nonce,
				'token'                      => $token,
			],
			$url
		);

		set_transient( $transient_key, $token, DAY_IN_SECONDS );

		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$switched_locale = switch_to_locale( get_user_locale( $user ) );

		/* translators: %s: User display name. */
		$message  = sprintf( __( 'Hello, %s!', 'newspack-newsletters' ), $user->display_name ) . "\r\n\r\n";
		$message .= __( 'Verify your email address by visiting the following address:', 'newspack-newsletters' ) . "\r\n\r\n";
		$message .= $url . "\r\n";
		$headers  = '';

		if ( method_exists( '\Newspack\Emails', 'get_from_email' ) && method_exists( '\Newspack\Emails', 'get_from_name' ) ) {
			$headers = [
				sprintf(
					'From: %1$s <%2$s>',
					\Newspack\Emails::get_from_name(),
					\Newspack\Emails::get_from_email()
				),
			];
		}

		$email = [
			'to'      => $user->user_email,
			/* translators: %s Site title. */
			'subject' => __( '[%s] Verify your email', 'newspack-newsletters' ),
			'message' => $message,
			'headers' => $headers,
		];

		/**
		 * Filters the email verification email.
		 *
		 * @param array    $email          Email arguments. {
		 *   Used to build wp_mail().
		 *
		 *   @type string $to      The intended recipient - New user email address.
		 *   @type string $subject The subject of the email.
		 *   @type string $message The body of the email.
		 *   @type string $headers The headers of the email.
		 * }
		 * @param \WP_User $user           User to send the magic link to.
		 * @param string   $magic_link_url Magic link url.
		 */
		$email = \apply_filters( 'newspack_newsletters_email_verification_email', $email, $user, $url );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$sent = \wp_mail(
			$email['to'],
			\wp_specialchars_decode( sprintf( $email['subject'], $blogname ) ),
			$email['message'],
			$email['headers']
		);

		if ( $switched_locale ) {
			\restore_previous_locale();
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Check your email address for a verification link.', 'newspack-newsletters' ), 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'verification_sent' => 1 ], remove_query_arg( self::EMAIL_VERIFIED_REQUEST, wp_get_referer() ) ) );
		exit;
	}

	/**
	 * Process email verification.
	 */
	public static function process_email_verification() {
		if ( ! isset( $_GET[ self::EMAIL_VERIFIED_CONFIRM ] ) || ! wp_verify_nonce( sanitize_text_field( $_GET[ self::EMAIL_VERIFIED_CONFIRM ] ), self::EMAIL_VERIFIED_CONFIRM ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html( __( 'You\'re not logged in.', 'newspack-newsletters' ) ), '', 401 );
		}
		$transient_key = self::get_email_verification_transient_key();
		$token         = get_transient( $transient_key );
		if ( ! $token ) {
			wp_die( esc_html( __( 'Invalid request.', 'newspack-newsletters' ) ), '', 400 );
		}
		if ( ! isset( $_GET['token'] ) || sanitize_text_field( $_GET['token'] ) !== $token ) {
			wp_die( esc_html( __( 'Invalid request.', 'newspack-newsletters' ) ), '', 400 );
		}

		self::set_email_verified( get_current_user_id() );

		delete_transient( $transient_key );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Your email has been verified.', 'newspack-newsletters' ), 'success' );
		}
		wp_safe_redirect( remove_query_arg( [ self::EMAIL_VERIFIED_CONFIRM, 'token' ] ) );
		exit;
	}

	/**
	 * Enqueue subscription lists scripts and styles.
	 */
	public static function enqueue_scripts() {
		wp_enqueue_style(
			'newspack-newsletters-subscriptions',
			plugins_url( '../dist/subscriptions.css', __FILE__ ),
			[],
			filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/subscriptions.css' )
		);
	}

	/**
	 * Add query var
	 *
	 * @param array $vars Query var.
	 *
	 * @return array
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::WC_ENDPOINT;
		return $vars;
	}

	/**
	 * Flush rewrite rules for WC_ENDPOINT.
	 */
	public static function flush_rewrite_rules() {
		$option_name = 'newspack_newsletters_has_flushed_rewrite_rules';
		if ( ! get_option( $option_name ) ) {
			flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
			update_option( $option_name, true );
		}
	}

	/**
	 * Insert the new endpoint into the My Account menu.
	 *
	 * @param array $menu_items Menu items.
	 *
	 * @return array
	 */
	public static function add_menu_item( $menu_items ) {
		if ( ! self::has_subscription_management() ) {
			return $menu_items;
		}
		$position       = -1;
		$menu_item_name = __( 'Newsletters', 'newspack-newsletters' );
		return array_slice( $menu_items, 0, $position, true ) + [ self::WC_ENDPOINT => $menu_item_name ] + array_slice( $menu_items, $position, null, true );
	}

	/**
	 * Endpoint content.
	 */
	public static function endpoint_content() {
		if ( ! self::has_subscription_management() ) {
			return;
		}
		$user_id  = get_current_user_id();
		$email    = get_userdata( $user_id )->user_email;
		$verified = self::is_email_verified();
		?>
		<div class="newspack-newsletters__user-subscription">
			<?php if ( ! $verified && ! isset( $_GET['verification_sent'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<p>
					<?php esc_html_e( 'Please verify your email address before managing your newsletters subscriptions.', 'newspack-newsletters' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( wp_nonce_url( remove_query_arg( self::EMAIL_VERIFIED_REQUEST ), self::EMAIL_VERIFIED_REQUEST, self::EMAIL_VERIFIED_REQUEST ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Verify Email', 'newspack-newsletters' ); ?>
					</a>
				</p>
			<?php endif; ?>
			<?php
			if ( $verified ) :
				/**
				 * Filters the available lists for the user to manage in the Manage Newsletters page under My Account.
				 *
				 * @param array|WP_Error $lists_config Associative array with list configuration keyed by list ID or WP_Error.
				 */
				$list_config  = apply_filters( 'newspack_newsletters_manage_newsletters_available_lists', self::get_lists_config() );
				$user_lists   = array_flip( self::get_contact_lists( $email ) );
				$intent_error = self::get_user_subscription_intent_error( $user_id );
				if ( $intent_error ) :
					?>
					<ul class="woocommerce-error" role="alert">
						<li>
							<?php
							printf(
								// translators: %s: Error message.
								esc_html__( 'Error while attempting to subscribe: %s', 'newspack-newsletters' ),
								esc_html( $intent_error )
							);
							?>
						</li>
					</ul>
				<?php endif; ?>
				<p>
					<?php _e( 'Manage your newsletter preferences.', 'newspack-newsletters' ); ?>
				</p>
				<form method="post">
					<?php wp_nonce_field( self::SUBSCRIPTION_UPDATE, self::SUBSCRIPTION_UPDATE ); ?>
					<div class="newspack-newsletters__lists">
						<ul>
							<?php
							foreach ( $list_config as $list_id => $list ) :
								$checkbox_id = sprintf( 'newspack-newsletters-list-checkbox-%s', $list_id );
								?>
								<li>
									<span class="newspack-newsletters__lists__checkbox">
										<input
											type="checkbox"
											name="lists[]"
											value="<?php echo \esc_attr( $list_id ); ?>"
											id="<?php echo \esc_attr( $checkbox_id ); ?>"
											<?php if ( isset( $user_lists[ $list_id ] ) ) : ?>
												checked
											<?php endif; ?>
										/>
									</span>
									<span class="newspack-newsletters__lists__details">
										<label class="newspack-newsletters__lists__label" for="<?php echo \esc_attr( $checkbox_id ); ?>">
											<span class="newspack-newsletters__lists__title">
												<?php echo \esc_html( $list['title'] ); ?>
											</span>
											<span class="newspack-newsletters__lists__description"><?php echo \esc_html( $list['description'] ); ?></span>
										</label>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
					<button class="newspack-ui__button newspack-ui__button--primary" type="submit"><?php _e( 'Update subscriptions', 'newspack-newsletters' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Process user newsletters subscription update.
	 */
	public static function process_subscription_update() {
		if ( ! isset( $_POST[ self::SUBSCRIPTION_UPDATE ] ) || ! wp_verify_nonce( sanitize_text_field( $_POST[ self::SUBSCRIPTION_UPDATE ] ), self::SUBSCRIPTION_UPDATE ) ) {
			return;
		}
		if ( ! self::has_subscription_management() ) {
			return;
		}
		if ( ! is_user_logged_in() || ! self::is_email_verified() ) {
			wc_add_notice( __( 'You must be logged in and verified to update your subscriptions.', 'newspack-newsletters' ), 'error' );
		} else {
			$email         = get_userdata( get_current_user_id() )->user_email;
			$lists         = isset( $_POST['lists'] ) ? array_map( 'sanitize_text_field', $_POST['lists'] ) : [];
			$lists_config   = self::get_lists_config();
			$lists_to_add  = array_intersect( array_keys( $lists_config ), $lists );
			$current_lists = [];
			$lists_to_add  = array_values( array_diff( $lists_to_add, $current_lists ) );
			if ( false === self::is_newsletter_subscriber( $email ) ) {
				$result = Newspack_Newsletters_Contacts::subscribe( [ 'email' => $email ], $lists, false, 'User subscribed on My Account page' );
			} else {
				$current_lists = self::get_contact_lists( $email );
				$lists_to_add  = array_values( array_diff( $lists_to_add, $current_lists ) );
				$result        = Newspack_Newsletters_Contacts::update_lists( $email, $lists, 'User updated their subscriptions on My Account page' );
			}
			if ( is_wp_error( $result ) ) {
				// Get a reader-friendly error message to show to the user.
				$provider = Newspack_Newsletters::get_service_provider();
				$message  = $provider
					? $provider->get_reader_error_message(
						[
							'email' => $email,
							'lists' => $lists,
						],
						$result
					)
					: $result->get_error_message();
				wc_add_notice( $message, 'error' );
			} elseif ( false === $result ) {
				wc_add_notice( __( 'You must select newsletters to update.', 'newspack-newsletters' ), 'error' );
			} else {
				wc_add_notice( __( 'Your subscriptions were updated.', 'newspack-newsletters' ), 'success' );
				if ( ! empty( $lists_to_add ) ) {
					wp_safe_redirect(
						add_query_arg(
							[ self::SUBSCRIPTION_UPDATE . '_subscribed' => implode( ',', $lists_to_add ) ],
							remove_query_arg( self::SUBSCRIPTION_UPDATE, wp_get_referer() )
						)
					);
					exit;
				}
			}
		}
	}
}
Newspack_Newsletters_Subscription::init();
