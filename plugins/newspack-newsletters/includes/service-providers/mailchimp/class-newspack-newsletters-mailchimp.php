<?php
/**
 * Service Provider: Mailchimp Implementation
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack_Newsletters_Mailchimp_Api as Mailchimp;
use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;
use Newspack\Newsletters\Send_Lists;
use Newspack\Newsletters\Send_List;

use function cli\err;

/**
 * Main Newspack Newsletters Class.
 */
final class Newspack_Newsletters_Mailchimp extends \Newspack_Newsletters_Service_Provider {

	use Newspack_Newsletters_Mailchimp_Groups;

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	public $name = 'Mailchimp';

	/**
	 * Whether the provider has support to tags and tags based Subscription Lists.
	 *
	 * @var boolean
	 */
	public static $support_local_lists = true;

	/**
	 * Cache of contact added on execution. Control to avoid adding the same
	 * contact multiple times due to optimistic nature of RAS.
	 *
	 * @var array[]
	 */
	private static $contacts_added = [];

	/**
	 * Controller.
	 *
	 * @var Newspack_Newsletters_Mailchimp_Controller
	 */
	public $controller;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->service    = 'mailchimp';
		$this->controller = new Newspack_Newsletters_Mailchimp_Controller( $this );
		Newspack_Newsletters_Mailchimp_Cached_Data::init();

		add_filter( 'newspack_newsletters_newsletter_content', [ $this, 'newsletter_content' ], 1, 2 );
		add_action( 'updated_post_meta', [ $this, 'save' ], 10, 4 );
		add_action( 'wp_trash_post', [ $this, 'trash' ], 10, 1 );
		add_filter( 'newspack_newsletters_process_link', [ $this, 'process_link' ], 10, 2 );
		add_filter( 'newspack_newsletters_add_contact_reader_error_message', [ $this, 'reader_error_message' ], 10, 3 );
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
			'support_url' => 'https://mailchimp.com/help/use-conditional-merge-tag-blocks/',
			'example'     => [
				'before' => '*|IF:FNAME|*',
				'after'  => '*|END:IF|*',
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
			// 'newspack_mailchimp_api_key' is a new option introduced to manage MC API key accross Newspack plugins.
			// Keeping the old option for backwards compatibility.
			'api_key' => get_option( 'newspack_mailchimp_api_key', get_option( 'newspack_newsletters_mailchimp_api_key', '' ) ),
		];
	}

	/**
	 * Test the Mailchimp API connection.
	 *
	 * @return true|WP_Error True if the connection is successful, WP_Error otherwise.
	 */
	public function test_connection() {
		if ( ! $this->has_api_credentials() ) {
			return new \WP_Error( 'newspack_newsletters_missing_credentials', __( 'Missing Mailchimp API credentials.', 'newspack-newsletters' ) );
		}
		try {
			$mc = new Mailchimp( $this->api_key() );
			$mc->get( 'ping' );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'newspack_newsletters_connection_error', $e->getMessage() );
		}
		if ( ! $mc->success() ) {
			return new \WP_Error( 'newspack_newsletters_connection_error', $mc->getLastError() );
		}
		return true;
	}

	/**
	 * Check if provider has all necessary credentials set.
	 *
	 * @return Boolean Result.
	 */
	public function has_api_credentials() {
		return ! empty( $this->api_key() );
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
	 * Get the base URL for the Mailchimp admin dashboard.
	 *
	 * @return string|boolean The URL on success. False on failure.
	 */
	public function get_admin_url() {
		$api_key = $this->api_key();
		if ( strpos( $api_key, '-' ) === false ) {
			return false;
		}
		list(, $data_center) = explode( '-', $api_key );
		return 'https://' . $data_center . '.admin.mailchimp.com/';
	}

	/**
	 * Set the API credentials for the service provider.
	 *
	 * @param object $credentials API credentials.
	 */
	public function set_api_credentials( $credentials ) {
		$api_key = $credentials['api_key'];
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'newspack_newsletters_invalid_keys',
				__( 'Please input a Mailchimp API key.', 'newspack-newsletters' )
			);
		}
		try {
			$mc   = new Mailchimp( $api_key );
			$ping = $mc->get( 'ping' );
		} catch ( Exception $e ) {
			$ping = null;
		}
		if ( ! $ping ) {
			return new WP_Error(
				'newspack_newsletters_invalid_keys',
				__( 'Please input a valid Mailchimp API key.', 'newspack-newsletters' )
			);
		}
		$updated = update_option( 'newspack_mailchimp_api_key', $api_key );
		do_action( 'newspack_newsletters_provider_credentials_changed', 'mailchimp' );
		return $updated;
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
		$mc     = new Mailchimp( $this->api_key() );
		$search = $mc->get(
			sprintf( 'lists/%s/tag-search', $list_id ),
			[
				'name' => $tag_name,
			]
		);
		if ( ! empty( $search['total_items'] ) ) {
			foreach ( $search['tags'] as $found_tag ) {
				// tag-search is case insensitive.
				if ( strtolower( $tag_name ) === strtolower( $found_tag['name'] ) ) {
					return $found_tag['id'];
				}
			}
		}

		// Tag was not found.
		if ( ! $create_if_not_found ) {
			return new WP_Error(
				'newspack_newsletter_tag_not_found'
			);
		}

		$created = $this->create_tag( $tag_name, $list_id );

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
		$mc     = new Mailchimp( $this->api_key() );
		$search = $mc->get(
			sprintf( 'lists/%s/segments/%d', $list_id, $tag_id )
		);
		if ( ! empty( $search['name'] ) ) {
			return $search['name'];
		}
		return new WP_Error(
			'newspack_newsletter_tag_not_found'
		);
	}

	/**
	 * Create a Tag on the provider
	 *
	 * @param string $tag The Tag name.
	 * @param string $list_id The List ID.
	 * @return array|WP_Error The tag representation sent from the server on succes. WP_Error on failure.
	 */
	public function create_tag( $tag, $list_id = null ) {

		$mc      = new Mailchimp( $this->api_key() );
		$created = $mc->post(
			sprintf( 'lists/%s/segments', $list_id ),
			[
				'name'           => $tag,
				'static_segment' => [],
			]
		);

		if ( is_array( $created ) && ! empty( $created['id'] ) && ! empty( $created['name'] ) ) {
			return $created;
		}
		return new WP_Error(
			'newspack_newsletters_error_creating_tag',
			! empty( $created['detail'] ) ? $created['detail'] : ''
		);
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
		$mc      = new Mailchimp( $this->api_key() );
		$created = $mc->patch(
			sprintf( 'lists/%s/segments/%s', $list_id, $tag_id ),
			[
				'name'           => $tag,
				'static_segment' => [],
			]
		);

		if ( is_array( $created ) && ! empty( $created['id'] ) && ! empty( $created['name'] ) ) {
			return $created;
		}
		return new WP_Error(
			'newspack_newsletters_error_updating_tag',
			! empty( $created['detail'] ) ? $created['detail'] : ''
		);
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
		$existing_contact = $this->get_contact_data( $email );
		if ( is_wp_error( $existing_contact ) ) {
			return $existing_contact;
		}
		$mc      = new Mailchimp( $this->api_key() );
		$created = $mc->post(
			sprintf( 'lists/%s/segments/%d', $list_id, $tag ),
			[
				'members_to_add' => [ $email ],
			]
		);

		if ( is_array( $created ) && ! empty( $created['members_added'] ) ) {
			return true;
		}

		return new WP_Error(
			'newspack_newsletter_error_adding_tag_to_contact',
			! empty( $created['errors'] ) && ! empty( $created['errors'][0]['error'] ) ? $created['errors'][0]['error'] : ''
		);
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
		$existing_contact = $this->get_contact_data( $email );
		if ( is_wp_error( $existing_contact ) ) {
			return $existing_contact;
		}
		$mc      = new Mailchimp( $this->api_key() );
		$created = $mc->post(
			sprintf( 'lists/%s/segments/%d', $list_id, $tag ),
			[
				'members_to_remove' => [ $email ],
			]
		);

		if ( is_array( $created ) && ! empty( $created['members_removed'] ) ) {
			return true;
		}

		return new WP_Error(
			'newspack_newsletter_error_adding_tag_to_contact',
			! empty( $created['errors'] ) && ! empty( $created['errors'][0]['error'] ) ? $created['errors'][0]['error'] : ''
		);
	}

	/**
	 * Get available campaign folders.
	 *
	 * @return array|WP_Error List of folders or error.
	 */
	public function get_folders() {
		return Newspack_Newsletters_Mailchimp_Cached_Data::get_folders();
	}

	/**
	 * Set folder for a campaign.
	 *
	 * @param string $post_id Campaign Id.
	 * @param string $folder_id ID of the folder.
	 * @return object|WP_Error API API Response or error.
	 */
	public function folder( $post_id, $folder_id = '' ) {
		$mc_campaign_id = get_post_meta( $post_id, 'mc_campaign_id', true );
		if ( ! $mc_campaign_id ) {
			return new WP_Error(
				'newspack_newsletters_no_campaign_id',
				__( 'Mailchimp campaign ID not found.', 'newspack-newsletters' )
			);
		}

		try {
			$mc      = new Mailchimp( $this->api_key() );
			$payload = [
				'settings' => [
					'folder_id' => $folder_id,
				],
			];
			$result  = $mc->patch( sprintf( 'campaigns/%s', $mc_campaign_id ), $payload );

			$data = $this->retrieve( $post_id );
			if ( is_wp_error( $data ) ) {
				return \rest_ensure_response( $data );
			}

			$data['result'] = $result;
			return \rest_ensure_response( $data );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_error_setting_folder',
				$e->getMessage()
			);
		}
	}

	/**
	 * Set list for a campaign.
	 *
	 * @param string $post_id Campaign Id.
	 * @param string $list_id ID of the list.
	 * @return object|WP_Error API API Response or error.
	 */
	public function list( $post_id, $list_id ) {
		$mc_campaign_id = get_post_meta( $post_id, 'mc_campaign_id', true );
		if ( ! $mc_campaign_id ) {
			return new WP_Error(
				'newspack_newsletters_no_campaign_id',
				__( 'Mailchimp campaign ID not found.', 'newspack-newsletters' )
			);
		}

		try {
			$mc      = new Mailchimp( $this->api_key() );
			$payload = [
				'recipients' => [
					'list_id' => $list_id,
				],
			];
			$result  = $this->validate(
				$mc->patch( "campaigns/$mc_campaign_id", $payload ),
				__( 'Error setting Mailchimp list.', 'newspack_newsletters' )
			);

			$data = $this->retrieve( $post_id );
			if ( is_wp_error( $data ) ) {
				return \rest_ensure_response( $data );
			}

			$data['result'] = $result;

			return \rest_ensure_response( $data );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Wrapper for fetching campaign from MC API.
	 *
	 * @param string $mc_campaign_id Campaign ID.
	 * @return object|WP_Error API Response or error.
	 */
	private function fetch_synced_campaign( $mc_campaign_id ) {
		try {
			$mc                  = new Mailchimp( $this->api_key() );
			$campaign            = $this->validate(
				$mc->get(
					"campaigns/$mc_campaign_id",
					[
						'fields' => 'id,web_id,type,status,emails_sent,content_type,recipients,settings',
					]
				),
				__( 'Error retrieving Mailchimp campaign.', 'newspack_newsletters' )
			);
			return $campaign;
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_error',
				$e->getMessage()
			);
		}
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
		$campaign_info = [];if ( empty( $newsletter_data['campaign'] ) ) {
			return $campaign_info;
		}
		$campaign = $newsletter_data['campaign'];

		// Sender info.
		if ( ! empty( $campaign['settings']['from_name'] ) ) {
			$campaign_info['senderName'] = $campaign['settings']['from_name'];
		}
		if ( ! empty( $campaign['settings']['reply_to'] ) ) {
			$campaign_info['senderEmail'] = $campaign['settings']['reply_to'];
		}

		// Audience.
		if ( ! empty( $campaign['recipients']['list_id'] ) ) {
			$campaign_info['list_id'] = $campaign['recipients']['list_id'];
		}

		// Group, segment, or tag.
		if ( ! empty( $campaign['recipients']['segment_opts'] ) ) {
			$segment_opts  = $campaign['recipients']['segment_opts'];
			$target_id_raw = $segment_opts['saved_segment_id'] ?? null;
			if ( ! $target_id_raw ) {
				$target_id_raw = $segment_opts['conditions'][0]['value'] ?? null;
			}
			if ( $target_id_raw ) {
				$target_id = strval( is_array( $target_id_raw ) && ! empty( $target_id_raw[0] ) ? $target_id_raw[0] : $target_id_raw );
				if ( ! $target_id ) {
					$target_id = (string) $target_id_raw;
				}
				if ( $target_id ) {
					$campaign_info['sublist_id'] = $target_id;
				}
			}
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
				throw new Exception( esc_html__( 'Missing or invalid Mailchimp credentials.', 'newspack-newsletters' ) );
			}
			$mc_campaign_id = get_post_meta( $post_id, 'mc_campaign_id', true );

			// If there's no synced campaign ID yet, create it.
			if ( ! $mc_campaign_id ) {
				Newspack_Newsletters_Logger::log( 'Creating new campaign for post ID ' . $post_id );
				$sync_result = $this->sync( get_post( $post_id ) );
				if ( is_wp_error( $sync_result ) ) {
					throw new Exception( wp_kses_post( $sync_result->get_error_message() ) );
				}
				$campaign       = $sync_result['campaign_result'];
				$mc_campaign_id = $campaign['id'];
			} else {
				Newspack_Newsletters_Logger::log( 'Retrieving campaign ' . $mc_campaign_id . ' for post ID ' . $post_id );
				$campaign = $this->fetch_synced_campaign( $mc_campaign_id );

				// If we couldn't get the campaign, delete the mc_campaign_id so it gets recreated on the next sync.
				if ( is_wp_error( $campaign ) ) {
					delete_post_meta( $post_id, 'mc_campaign_id' );
					throw new Exception( wp_kses_post( $campaign->get_error_message() ) );
				}
			}

			$campaign_info   = $this->extract_campaign_info( [ 'campaign' => $campaign ] );
			$list_id         = $campaign_info['list_id'] ?? null;
			$send_list_id    = get_post_meta( $post_id, 'send_list_id', true );
			$send_sublist_id = get_post_meta( $post_id, 'send_sublist_id', true );
			$newsletter_data = [
				'campaign'                          => $campaign,
				'campaign_id'                       => $mc_campaign_id,
				'folders'                           => Newspack_Newsletters_Mailchimp_Cached_Data::get_folders(),
				'allowed_sender_domains'            => $this->get_verified_domains(),
				'merge_fields'                      => $list_id ? Newspack_Newsletters_Mailchimp_Cached_Data::get_merge_fields( $list_id ) : [],
				'link'                              => sprintf( 'https://%s.admin.mailchimp.com/campaigns/edit?id=%d', explode( '-', $this->api_key() )[1], $campaign['web_id'] ),
				'supports_multiple_test_recipients' => true,
			];

			// Reconcile campaign settings with info fetched from the ESP for a true two-way sync.
			if ( ! empty( $campaign_info['senderName'] ) && $campaign_info['senderName'] !== get_post_meta( $post_id, 'senderName', true ) ) {
				$newsletter_data['senderName'] = $campaign_info['senderName']; // If campaign has different sender info set, update ours.
			}
			if ( ! empty( $campaign_info['senderEmail'] ) && $campaign_info['senderEmail'] !== get_post_meta( $post_id, 'senderEmail', true ) ) {
				$newsletter_data['senderEmail'] = $campaign_info['senderEmail']; // If campaign has different sender info set, update ours.
			}
			if ( $list_id && $list_id !== $send_list_id ) {
				$newsletter_data['send_list_id'] = $list_id; // If campaign has a different list selected, update ours.
				$send_list_id                    = $list_id;

				if ( ! empty( $campaign_info['sublist_id'] ) && $campaign_info['sublist_id'] !== $send_sublist_id ) {
						$newsletter_data['send_sublist_id'] = $campaign_info['sublist_id']; // If campaign has a different sublist selected, update ours.
						$send_sublist_id = $campaign_info['sublist_id'];
				}
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

			$send_sublists = $send_list_id || $send_sublist_id ? // Prefetch send lists only if we have something selected already.
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

			return $newsletter_data;
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Get audiences, groups, and tags that can be configured as subscription lists.
	 * Reconcile edited names for locally-configured lists.
	 *
	 * @param boolean $audiences_only Whether to include groups and tags. If true, only return audiences.
	 *
	 * @return array|WP_Error List of subscription lists or error.
	 */
	public function get_lists( $audiences_only = false ) {
		$audiences = Newspack_Newsletters_Mailchimp_Cached_Data::get_lists();
		if ( $audiences_only || is_wp_error( $audiences ) ) {
			return $audiences;
		}
		$lists = [];

		// In addition to Audiences, we also automatically fetch all groups and tags and offer them as Subscription Lists.
		// Build the final list inside the loop so groups are added after the list they belong to and we can then represent the hierarchy in the UI.
		foreach ( $audiences as $list ) {

			$lists[]        = $list;
			$all_categories = Newspack_Newsletters_Mailchimp_Cached_Data::get_interest_categories( $list['id'] );
			$all_categories = $all_categories['categories'] ?? [];
			$all_tags       = Newspack_Newsletters_Mailchimp_Cached_Data::get_tags( $list['id'] ) ?? [];

			foreach ( $all_categories as $found_category ) {

				// Do not include groups under the category we use to store "Local" lists.
				if ( $this->get_group_category_name() === $found_category['title'] ) {
					continue;
				}

				$all_groups = $found_category['interests'] ?? [];

				$groups = array_map(
					function ( $group ) use ( $list ) {
						$group['id']   = Subscription_List::mailchimp_generate_public_id( $group['id'], $list['id'] );
						$group['type'] = 'mailchimp-group';
						return $group;
					},
					$all_groups['interests'] ?? [] // Yes, two levels of 'interests'.
				);
				$lists  = array_merge( $lists, $groups );
			}

			foreach ( $all_tags as $tag ) {
				$tag['id']   = Subscription_List::mailchimp_generate_public_id( $tag['id'], $list['id'], 'tag' );
				$tag['type'] = 'mailchimp-tag';
				$lists[]     = $tag;
			}
		}

		// Reconcile edited names for locally-configured lists.
		$configured_lists = Newspack_Newsletters_Subscription::get_lists_config();
		if ( ! empty( $configured_lists ) ) {
			foreach ( $lists as &$list ) {
				if ( ! empty( $configured_lists[ $list['id'] ]['name'] ) ) {
					$list['local_name'] = $configured_lists[ $list['id'] ]['name'];
				}
			}
		}

		return $lists;
	}

	/**
	 * Get all applicable audiences, groups, tags, and segments as Send_List objects.
	 *
	 * @param array   $args Array of search args. See Send_Lists::get_default_args() for supported params and default values.
	 * @param boolean $to_array If true, convert Send_List objects to arrays before returning.
	 *
	 * @return Send_List[]|array|WP_Error Array of Send_List objects or arrays on success, or WP_Error object on failure.
	 */
	public function get_send_lists( $args = [], $to_array = false ) {
		$defaults   = Send_Lists::get_default_args();
		$args       = wp_parse_args( $args, $defaults );
		$by_id      = ! empty( $args['ids'] );
		$admin_url  = self::get_admin_url();
		$audiences  = Newspack_Newsletters_Mailchimp_Cached_Data::get_lists( $args['limit'] );
		$send_lists = [];

		$entity_type = 'audience';
		foreach ( $audiences as $audience ) {
			if ( ! empty( $args['parent_id'] ) && $audience['id'] !== $args['parent_id'] ) {
				continue;
			}
			$matches = $by_id ? Send_Lists::matches_id( $args['ids'], $audience['id'] ) : Send_Lists::matches_search( $args['search'], [ $audience['id'], $audience['name'], $entity_type ] );
			if ( ( ! $args['type'] || 'list' === $args['type'] ) && $matches ) {
				$config = [
					'provider'    => $this->service,
					'type'        => 'list',
					'id'          => $audience['id'],
					'name'        => $audience['name'],
					'entity_type' => $entity_type,
					'count'       => $audience['stats']['member_count'] ?? 0,
				];
				if ( $admin_url && ! empty( $audience['web_id'] ) ) {
					$config['edit_link'] = $admin_url . 'audience/contacts/?id=' . $audience['web_id'];
				}
				$send_lists[] = new Send_List( $config );
			}

			if ( 'list' === $args['type'] ) {
				continue;
			}

			$groups      = Newspack_Newsletters_Mailchimp_Cached_Data::get_interest_categories( $audience['id'], $args['limit'] );
			$entity_type = 'group';
			if ( isset( $groups['categories'] ) ) {
				foreach ( $groups['categories'] as $category ) {
					if ( isset( $category['interests']['interests'] ) ) {
						foreach ( $category['interests']['interests'] as $interest ) {
							$matches = $by_id ? Send_Lists::matches_id( $args['ids'], $interest['id'] ) : Send_Lists::matches_search( $args['search'], [ $interest['id'], $interest['name'], $entity_type ] );
							if ( $matches ) {
								$config = [
									'provider'    => $this->service,
									'type'        => 'sublist',
									'id'          => $interest['id'],
									'name'        => $interest['name'],
									'entity_type' => $entity_type,
									'parent_id'   => $interest['list_id'],
									'count'       => $interest['subscriber_count'],
								];
								if ( $admin_url && $audience['web_id'] ) {
									$config['edit_link'] = $admin_url . 'audience/groups/?id=' . $audience['web_id'];
								}
								$send_lists[] = new Send_List( $config );
							}
						}
					}
				}
			}

			$tags        = Newspack_Newsletters_Mailchimp_Cached_Data::get_tags( $audience['id'], $args['limit'] );
			$entity_type = 'tag';
			foreach ( $tags as $tag ) {
				$matches = $by_id ? Send_Lists::matches_id( $args['ids'], $tag['id'] ) : Send_Lists::matches_search( $args['search'], [ $tag['id'], $tag['name'], $entity_type ] );
				if ( $matches ) {
					$config = [
						'provider'    => $this->service,
						'type'        => 'sublist',
						'id'          => $tag['id'],
						'name'        => $tag['name'],
						'entity_type' => $entity_type,
						'parent_id'   => $tag['list_id'],
						'count'       => $tag['member_count'],
					];
					if ( $admin_url && $audience['web_id'] ) {
						$config['edit_link'] = $admin_url . 'audience/tags/?id=' . $audience['web_id'];
					}
					$send_lists[] = new Send_List( $config );
				}
			}

			$segments    = Newspack_Newsletters_Mailchimp_Cached_Data::get_segments( ( $parent_id ?? $audience['id'] ), $args['limit'] );
			$entity_type = 'segment';
			foreach ( $segments as $segment ) {
				$matches = $by_id ? Send_Lists::matches_id( $args['ids'], $segment['id'] ) : Send_Lists::matches_search( $args['search'], [ $segment['id'], $segment['name'], $entity_type ] );
				if ( $matches ) {
					$config = [
						'provider'    => $this->service,
						'type'        => 'sublist',
						'id'          => $segment['id'],
						'name'        => $segment['name'],
						'entity_type' => $entity_type,
						'parent_id'   => $segment['list_id'],
						'count'       => $segment['member_count'],
					];
					if ( $admin_url && $audience['web_id'] ) {
						$config['edit_link'] = $admin_url . 'audience/segments/?id=' . $audience['web_id'];
					}
					$send_lists[] = new Send_List( $config );
				}
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
	 * Get interest categories and their groups.
	 * Reconcile edited names for locally-configured lists.
	 *
	 * @param string $list_id List ID.
	 *
	 * @return array
	 */
	public function get_interest_categories( $list_id = null ) {
		if ( ! $list_id ) {
			return [];
		}
		$categories = Newspack_Newsletters_Mailchimp_Cached_Data::get_interest_categories( $list_id );
		if ( empty( $categories['categories'] ) ) {
			return [];
		}

		// Reconcile edited names for locally-configured lists.
		$configured_lists = Newspack_Newsletters_Subscription::get_lists_config();
		if ( ! empty( $configured_lists ) ) {
			foreach ( $categories['categories'] as &$category ) {
				if ( ! empty( $category['interests']['interests'] ) ) {
					foreach ( $category['interests']['interests'] as &$interest ) {
						$local_id = Subscription_List::mailchimp_generate_public_id( $interest['id'], $list_id );
						if ( isset( $configured_lists[ $local_id ]['name'] ) ) {
							$interest['local_name'] = $configured_lists[ $local_id ]['name'];
						}
					}
				}
			}
		}

		return $categories;
	}

	/**
	 * Get tags. Reconcile edited names for locally-configured lists.
	 *
	 * @param string $list_id List ID.
	 *
	 * @return array
	 */
	public function get_tags( $list_id = null ) {
		if ( ! $list_id ) {
			return [];
		}
		$tags = Newspack_Newsletters_Mailchimp_Cached_Data::get_tags( $list_id );
		if ( empty( $tags ) ) {
			return [];
		}

		// Reconcile edited names for locally-configured lists.
		$configured_lists = Newspack_Newsletters_Subscription::get_lists_config();
		if ( ! empty( $configured_lists ) ) {
			foreach ( $tags as &$tag ) {
				$local_id = Subscription_List::mailchimp_generate_public_id( $tag['id'], $list_id, 'tag' );
				if ( isset( $configured_lists[ $local_id ]['name'] ) ) {
					$tag['local_name'] = $configured_lists[ $local_id ]['name'];
				}
			}
		}

		return $tags;
	}

	/**
	 * Retrieve the list merge fields.
	 *
	 * @deprecated 1.57
	 *
	 * @param string $list_id List ID.
	 *
	 * @return array|WP_Error List of merge fields or error.
	 */
	public function get_list_merge_fields( $list_id ) {
		_deprecated_function( __METHOD__, '1.57', 'Newspack_Newsletters_Mailchimp_Cached_Data::get_merge_fields' );
		try {
			$merge_fields = Newspack_Newsletters_Mailchimp_Cached_Data::get_merge_fields( $list_id );
			return $merge_fields;
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Get verified domains from the MC account.
	 *
	 * @return array List of verified domains.
	 */
	public function get_verified_domains() {
		$mc = new Mailchimp( $this->api_key() );
		$result = $this->validate(
			$mc->get( 'verified-domains', [ 'count' => 1000 ] ),
			__( 'Error retrieving verified domains from Mailchimp.', 'newspack-newsletters' )
		);

		return array_values(
			array_filter(
				array_map(
					function ( $domain ) {
						return $domain['verified'] ? strtolower( trim( $domain['domain'] ) ) : null;
					},
					$result['domains']
				),
				function ( $domain ) {
					return ! empty( $domain );
				}
			)
		);
	}

	/**
	 * Set sender data.
	 *
	 * @param string $email Reply to email address.
	 * @return boolean|WP_Error True if the email address is valid, otherwise error.
	 */
	public function validate_sender_email( $email ) {
		try {
			$verified_domains = $this->get_verified_domains();
			$explode         = explode( '@', $email );
			$domain          = strtolower( trim( array_pop( $explode ) ) );

			if ( ! in_array( $domain, $verified_domains ) ) {
				return new WP_Error(
					'newspack_newsletters_unverified_sender_domain',
					sprintf(
					// Translators: explanation that current domain is not verified, list of verified options.
						__( '%1$s is not a verified domain. Verified domains for the linked Mailchimp account are: %2$s.', 'newspack-newsletters' ),
						$domain,
						implode( ', ', $verified_domains )
					)
				);
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_error',
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
		$mc_campaign_id = get_post_meta( $post_id, 'mc_campaign_id', true );
		if ( ! $mc_campaign_id ) {
			return new WP_Error(
				'newspack_newsletters_no_campaign_id',
				__( 'Mailchimp campaign ID not found.', 'newspack-newsletters' )
			);
		}
		try {
			$mc      = new Mailchimp( $this->api_key() );
			$payload = [
				'test_emails' => $emails,
				'send_type'   => 'html',
			];
			$result  = $this->validate(
				$mc->post(
					"campaigns/$mc_campaign_id/actions/test",
					$payload,
					60
				),
				__( 'Error sending test email.', 'newspack_newsletters' )
			);

			$data            = $this->retrieve( $post_id );
			$data['result']  = $result;
			$data['message'] = sprintf(
			// translators: Message after successful test email.
				__( 'Mailchimp test sent successfully to %s.', 'newspack-newsletters' ),
				implode( ', ', $emails )
			);

			return \rest_ensure_response( $data );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_error',
				$this->get_better_error_message( $e->getMessage() )
			);
		}
	}

	/**
	 * Get a payload for syncing post data to the ESP campaign.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return array|WP_Error Payload for syncing, or WP_Error if the payload cannot be safely built (e.g. an unverified sender domain, or a configured sublist that cannot be resolved against Mailchimp).
	 */
	public function get_sync_payload( $post ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}
		$payload = [
			'type'         => 'regular',
			'content_type' => 'template',
			'settings'     => [
				'subject_line' => html_entity_decode( $post->post_title ),
				'title'        => $this->get_campaign_name( $post ),
			],
		];

		// Sync sender name + email.
		$sender_name  = get_post_meta( $post->ID, 'senderName', true );
		$sender_email = get_post_meta( $post->ID, 'senderEmail', true );
		if ( ! empty( $sender_name ) ) {
			$payload['settings']['from_name'] = $sender_name;
		}
		if ( ! empty( $sender_email ) ) {
			$is_valid_email = $this->validate_sender_email( $sender_email );
			if ( is_wp_error( $is_valid_email ) && $is_valid_email->get_error_code() === 'newspack_newsletters_unverified_sender_domain' ) {
				delete_post_meta( $post->ID, 'senderEmail' ); // Delete invalid email so we can't accidentally attempt to send with it.
				return $is_valid_email;
			}
			$payload['settings']['reply_to'] = $sender_email;
		}

		// Sync send-to selections.
		$send_list_id = get_post_meta( $post->ID, 'send_list_id', true );
		if ( ! empty( $send_list_id ) ) {
			$payload['recipients'] = [
				'list_id' => $send_list_id,
			];
			$send_sublist_id = get_post_meta( $post->ID, 'send_sublist_id', true );
			// Only an explicitly unset send_sublist_id (null or '' — no sublist
			// ever picked) is treated as an intentional whole-list send. Any
			// other value, including a literal "0", is treated as
			// configured-and-must-resolve so a garbage value can't quietly fall
			// through to the whole audience.
			if ( null !== $send_sublist_id && '' !== $send_sublist_id ) {
				// Note: Mailchimp groups and tags are looked up via
				// Newspack_Newsletters_Mailchimp_Cached_Data, which refreshes
				// asynchronously (~10-minute TTL). A group or tag that was
				// deleted upstream within that window can still resolve from
				// stale cache and pass this check. Saved segments are looked
				// up live via `fetch_segment()` further down and don't have
				// this gap. Closing the group/tag gap would mean a live
				// lookup at send time — tracked as follow-up.
				$sublist = $this->get_send_lists(
					[
						'ids'       => [ $send_sublist_id ],
						'limit'     => 1000,
						'parent_id' => $send_list_id,
						'type'      => 'sublist',
					]
				);
				// A configured-but-unresolvable sublist must NOT silently fall
				// through to a list-only payload — Mailchimp would treat the
				// missing segment_opts as "send to the entire audience", and
				// sent email cannot be unsent.
				if ( is_wp_error( $sublist ) ) {
					return new WP_Error(
						'newspack_newsletters_mailchimp_sublist_lookup_failed',
						sprintf(
							// Translators: %s is the upstream error message from Mailchimp.
							__( 'Could not verify the selected sublist with Mailchimp (%s). Sending was aborted to avoid sending to the entire audience.', 'newspack-newsletters' ),
							$sublist->get_error_message()
						)
					);
				}
				if ( empty( $sublist ) || empty( $sublist[0]->get_entity_type() ) ) {
					return new WP_Error(
						'newspack_newsletters_mailchimp_sublist_not_found',
						__( 'The selected sublist could not be found in Mailchimp. Sending was aborted to avoid sending to the entire audience. Please re-select a sublist and try again.', 'newspack-newsletters' )
					);
				}
				$sublist_type = $sublist[0]->get_entity_type();
				switch ( $sublist_type ) {
					case 'group':
						$payload['recipients']['segment_opts'] = [
							'match'      => 'all',
							'conditions' => [
								[
									'condition_type' => 'Interests',
									'field'          => 'interests-' . $send_sublist_id,
									'op'             => 'interestcontains',
									'value'          => [ $send_sublist_id ],
								],
							],
						];
						break;
					case 'tag':
						$payload['recipients']['segment_opts'] = [
							'match'      => 'all',
							'conditions' => [
								[
									'condition_type' => 'StaticSegment',
									'field'          => 'static_segment',
									'op'             => 'static_is',
									'value'          => $send_sublist_id,
								],
							],
						];
						break;
					case 'segment':
						$segment_data = Newspack_Newsletters_Mailchimp_Cached_Data::fetch_segment( $send_sublist_id, $send_list_id );
						if ( is_wp_error( $segment_data ) ) {
							return $segment_data;
						}
						if ( ! empty( $segment_data['options'] ) ) {
							$payload['recipients']['segment_opts'] = $segment_data['options'];
						} else {
							return new WP_Error(
								'newspack_newsletters_mailchimp_error',
								sprintf(
									// Translators: %s is the name of the Mailchimp segment.
									__( 'Could not fetch segment criteria for segment %s.', 'newspack-newsletters' ),
									$sublist[0]->get_name()
								)
							);
						}
						break;
					default:
						return new WP_Error(
							'newspack_newsletters_mailchimp_sublist_unknown_type',
							sprintf(
								// Translators: %s is the unrecognized sublist entity type.
								__( 'Unrecognized Mailchimp sublist type "%s". Sending was aborted to avoid sending to the entire audience.', 'newspack-newsletters' ),
								$sublist_type
							)
						);
				}
			} else {
				$payload['recipients']['segment_opts'] = (object) [];
			}
		}

		// Sync folder selection.
		$folder_id = get_post_meta( $post->ID, 'mc_folder_id', true );
		if ( $folder_id ) {
			$payload['settings']['folder_id'] = $folder_id;
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
				throw new Exception( __( 'Missing or invalid Mailchimp credentials.', 'newspack-newsletters' ) );
			}
			if ( empty( $post->post_title ) ) {
				throw new Exception( __( 'The newsletter subject cannot be empty.', 'newspack-newsletters' ) );
			}
			$mc             = new Mailchimp( $api_key );
			$mc_campaign_id = get_post_meta( $post->ID, 'mc_campaign_id', true );
			$payload        = $this->get_sync_payload( $post );

			// Short-circuit on a WP_Error payload before reaching the filter
			// below — its contract is an array, and passing a WP_Error through
			// would fatal any third-party callback that assumes the documented
			// shape.
			if ( is_wp_error( $payload ) ) {
				throw new Exception( esc_html( $payload->get_error_message() ) );
			}

			/**
			 * Filter the metadata payload sent to Mailchimp when syncing.
			 *
			 * Allows custom tracking codes to be sent.
			 *
			 * @param array  $payload        Mailchimp payload.
			 * @param object $post           Post object.
			 * @param string $mc_campaign_id Mailchimp campaign ID, if defined.
			 */
			$payload = apply_filters( 'newspack_newsletters_mc_payload_sync', $payload, $post, $mc_campaign_id );

			if ( $mc_campaign_id ) {
				// Mailchimp snapshots ad-hoc "advanced segments"
				// (segment_opts.conditions, as opposed to a saved segment
				// referenced by ID) at PATCH time and does not refresh the
				// campaign's `recipient_count` when a populated segment_opts is
				// swapped for another populated segment_opts in a subsequent
				// PATCH — the campaign keeps the prior snapshot's recipient
				// count even though its stored conditions are correctly updated.
				// Empirically the only PATCH shape that triggers a fresh
				// snapshot is the transition from "no segment" to "populated
				// segment". So before PATCHing a populated segment_opts onto an
				// existing campaign, first PATCH segment_opts to an empty
				// object to force Mailchimp through that transition.
				//
				// The reset PATCH lands a transient "send to the entire
				// audience" state on Mailchimp's side. To make sure a failed
				// main PATCH below can't leave the campaign stuck in that
				// state (which any subsequent scheduled-send retry,
				// hub-driven sync, or parallel actions/send would otherwise
				// blast to the full list), capture the campaign's existing
				// recipients first and roll back to them if the main PATCH
				// throws.
				//
				// References:
				// - https://mailchimp.com/help/troubleshooting-advanced-segments.
				// - https://mailchimp.com/help/schedule-or-pause-a-regular-email-campaign.
				$rollback_recipients = null;
				if (
					isset( $payload['recipients']['segment_opts'], $payload['recipients']['list_id'] ) &&
					! empty( (array) $payload['recipients']['segment_opts'] )
				) {
					// Capture the existing recipients so we can roll back
					// if the main PATCH below fails. Missing/empty
					// segment_opts on the existing campaign is fine — we'll
					// rollback to (object) [] (whole audience) which is the
					// state the reset left us in anyway.
					try {
						$existing = $this->validate( $mc->get( "campaigns/$mc_campaign_id", [ 'fields' => 'recipients' ] ) );
						if ( is_array( $existing ) && ! empty( $existing['recipients']['list_id'] ) ) {
							$prior_segment_opts  = $existing['recipients']['segment_opts'] ?? [];
							$rollback_recipients = [
								'list_id'      => $existing['recipients']['list_id'],
								'segment_opts' => empty( $prior_segment_opts ) ? (object) [] : $prior_segment_opts,
							];
						}
					} catch ( Exception $capture_error ) {
						// Without a prior snapshot we can't safely roll
						// back. Log so operators can correlate if the main
						// PATCH then also fails; sync continues.
						Newspack_Newsletters_Logger::log(
							'Mailchimp prior recipients capture failed for campaign ' . $mc_campaign_id . ': ' . $capture_error->getMessage() . ' — proceeding without rollback safety.'
						);
					}

					// Reset PATCH is best-effort: if Mailchimp rejects it
					// (e.g. the campaign is already sent or otherwise
					// locked), let the main PATCH below produce the
					// canonical error.
					try {
						if ( null !== $rollback_recipients ) {
							$this->validate(
								$mc->patch(
									"campaigns/$mc_campaign_id",
									[
										'recipients' => [
											'list_id'      => $payload['recipients']['list_id'],
											'segment_opts' => (object) [],
										],
									]
								)
							);
						}
					} catch ( Exception $reset_error ) {
						Newspack_Newsletters_Logger::log(
							'Mailchimp segment_opts reset failed for campaign ' . $mc_campaign_id . ': ' . $reset_error->getMessage() . ' — proceeding with main PATCH.'
						);
						// The reset didn't apply, so the campaign is
						// still in its prior recipients state and there's
						// nothing to roll back if the main PATCH also
						// fails.
						$rollback_recipients = null;
					}
				}

				try {
					$campaign_result = $this->validate(
						$mc->patch( "campaigns/$mc_campaign_id", $payload )
					);
				} catch ( Exception $main_error ) {
					// The reset PATCH already neutered segment_opts;
					// without restoring the prior state the Mailchimp
					// campaign is now configured to send to the entire
					// audience. Attempt a rollback PATCH. Rollback
					// failures are logged but cannot be propagated
					// further — we re-throw the original main-PATCH
					// error so callers see the canonical sync error.
					if ( null !== $rollback_recipients ) {
						try {
							$this->validate(
								$mc->patch( "campaigns/$mc_campaign_id", [ 'recipients' => $rollback_recipients ] )
							);
							Newspack_Newsletters_Logger::log(
								'Mailchimp main PATCH failed after segment_opts reset — rolled back recipients for campaign ' . $mc_campaign_id . '.'
							);
						} catch ( Exception $rollback_error ) {
							Newspack_Newsletters_Logger::log(
								'Mailchimp rollback PATCH failed for campaign ' . $mc_campaign_id . ': ' . $rollback_error->getMessage() . ' — campaign may be left in an inconsistent recipients state.'
							);
						}
					}
					throw $main_error;
				}
			} else {
				$campaign_result = $this->validate(
					$mc->post( 'campaigns', $payload )
				);
				$mc_campaign_id  = $campaign_result['id'];
				update_post_meta( $post->ID, 'mc_campaign_id', $mc_campaign_id );
			}

			// Prevent updating content of a sent campaign.
			if ( in_array( $campaign_result['status'], [ 'sent', 'sending' ] ) ) {
				return;
			}

			$renderer        = new Newspack_Newsletters_Renderer();
			$content_payload = [
				'html' => $renderer->retrieve_email_html( $post ),
			];

			$content_result = $this->validate(
				$mc->put( "campaigns/$mc_campaign_id/content", $content_payload ),
				__( 'Error updating campaign content.', 'newspack_newsletters' )
			);
			return [
				'campaign_result' => $campaign_result,
				'content_result'  => $content_result,
			];
		} catch ( Exception $e ) {
			set_transient( $transient_name, 'Mailchimp campaign sync error: ' . wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), 45 );
			return new WP_Error( 'newspack_newsletters_mailchimp_error', $e->getMessage() );
		}
	}

	/**
	 * Filter newsletter content prior to converting to MJML.
	 *
	 * @param string $content The post content.
	 * @return string The filtered post content.
	 */
	public function newsletter_content( $content ) {
		// Strip protocol prefixes from link-based Mailchimp merge tags.
		$content = preg_replace( '/href="(?:https?:)?\/\/\*\|/', 'href="*|', $content );
		return $content;
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
	 * Send a campaign.
	 *
	 * @param WP_Post $post Post to send.
	 *
	 * @return true|WP_Error True if the campaign was sent or error if failed.
	 */
	public function send( $post ) {
		$post_id = $post->ID;

		// Check if campaign has already been sent and if so, don't attempt to
		// send again.
		$campaign_data = $this->retrieve( $post_id );
		if ( is_wp_error( $campaign_data ) ) {
			return $campaign_data;
		}
		if (
				isset( $campaign_data['campaign']['status'] ) &&
				in_array( $campaign_data['campaign']['status'], [ 'sent', 'sending' ], true )
			) {
			return true;
		}

		$sync_result = $this->sync( $post );

		if ( is_wp_error( $sync_result ) ) {
			return $sync_result;
		}

		if ( ! $sync_result ) {
			return new WP_Error(
				'newspack_newsletters_error',
				__( 'Unable to synchronize with Mailchimp.', 'newspack-newsletters' )
			);
		}

		$mc_campaign_id = get_post_meta( $post_id, 'mc_campaign_id', true );
		if ( ! $mc_campaign_id ) {
			return new WP_Error(
				'newspack_newsletters_error',
				__( 'Mailchimp campaign ID not found.', 'newspack-newsletters' )
			);
		}

		$mc = new Mailchimp( $this->api_key() );

		$payload = [
			'send_type' => 'html',
		];
		try {
			$this->validate(
				$mc->post( "campaigns/$mc_campaign_id/actions/send", $payload ),
				__( 'Error sending campaign.', 'newspack_newsletters' )
			);
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
		$mc_campaign_id = get_post_meta( $post_id, 'mc_campaign_id', true );
		if ( ! $mc_campaign_id ) {
			return;
		}

		$api_key = $this->api_key();
		if ( ! $api_key ) {
			return;
		}
		try {
			$mc       = new Mailchimp( $api_key );
			$campaign = $mc->get( "campaigns/$mc_campaign_id" );
			if ( $campaign ) {
				$status = $campaign['status'];
				if ( ! in_array( $status, [ 'sent', 'sending' ] ) ) {
					$result = $mc->delete( "campaigns/$mc_campaign_id" );
					delete_post_meta( $post_id, 'mc_campaign_id', $mc_campaign_id );
				}
			}
		} catch ( Exception $e ) {
			return; // Fail silently.
		}
	}

	/**
	 * Set Mailchimp Audience segments for a Campaign.
	 *
	 * @param string     $post_id   Numeric ID of the post.
	 * @param string|int $target_id Segment/tag ID or compound interest ID (field name and ID).
	 *
	 * @return object|WP_Error API API Response or error.
	 */
	public function audience_segments( $post_id, $target_id ) {

		$interest_id = false;
		$segment_id  = false;

		// Determine if we're dealing with an interest or a segment.
		if ( false !== strpos( $target_id, ':' ) ) {
			$exploded    = explode( ':', $target_id );
			$field       = count( $exploded ) ? $exploded[0] : null;
			$interest_id = count( $exploded ) > 1 ? $exploded[1] : null;
		} elseif ( '' !== $target_id ) {
			$segment_id = $target_id;
		}

		$mc_campaign_id = get_post_meta( $post_id, 'mc_campaign_id', true );
		if ( ! $mc_campaign_id ) {
			return new WP_Error(
				'newspack_newsletters_no_campaign_id',
				__( 'Mailchimp campaign ID not found.', 'newspack-newsletters' )
			);
		}

		if ( '' !== $target_id && ! $interest_id && ! $segment_id ) {
			return new WP_Error(
				'newspack_newsletters_invalid_mailchimp_interest',
				__( 'Invalid Mailchimp Interest.', 'newspack-newsletters' )
			);
		}

		try {
			$mc       = new Mailchimp( $this->api_key() );
			$campaign = $this->validate(
				$mc->get( "campaigns/$mc_campaign_id" ),
				__( 'Error retrieving Mailchimp campaign.', 'newspack_newsletters' )
			);
			$list_id  = isset( $campaign, $campaign['recipients'], $campaign['recipients']['list_id'] ) ? $campaign['recipients']['list_id'] : null;

			if ( ! $list_id ) {
				return new WP_Error(
					'newspack_newsletters_no_campaign_id',
					__( 'Mailchimp list ID not found.', 'newspack-newsletters' )
				);
			}

			$segment_opts = (object) [];

			if ( $interest_id ) {
				$segment_opts = [
					'match'      => 'all',
					'conditions' => [
						[
							'condition_type' => 'Interests',
							'field'          => $field,
							'op'             => 'interestcontains',
							'value'          => [ $interest_id ],
						],
					],
				];
			} elseif ( $segment_id ) {
				$segment_data = $mc->get( "lists/$list_id/segments/$segment_id" );
				// A configured-but-unresolvable saved segment must NOT silently
				// fall through to an empty segment_opts payload — Mailchimp
				// would PATCH the campaign to "send to the entire audience",
				// and a subsequent send would mail the full list.
				if ( empty( $segment_data ) || empty( $segment_data['type'] ) ) {
					return new WP_Error(
						'newspack_newsletters_mailchimp_segment_not_found',
						__( 'The selected segment could not be found in Mailchimp. The audience was not updated to avoid sending to the entire list.', 'newspack-newsletters' )
					);
				}
				if ( 'static' === $segment_data['type'] ) {
					// Handle static segments (tags).
					$segment_opts = [
						'match'      => 'all',
						'conditions' => [
							[
								'condition_type' => 'StaticSegment',
								'field'          => 'static_segment',
								'op'             => 'static_is',
								'value'          => $segment_id,
							],
						],
					];
				} elseif ( 'saved' === $segment_data['type'] ) {
					// Handle saved segments.
					$segment_opts = $segment_data['options'];
				} else {
					return new WP_Error(
						'newspack_newsletters_mailchimp_segment_unknown_type',
						sprintf(
							// Translators: %s is the unrecognized segment type returned by Mailchimp.
							__( 'Unrecognized Mailchimp segment type "%s". The audience was not updated to avoid sending to the entire list.', 'newspack-newsletters' ),
							$segment_data['type']
						)
					);
				}
			}

			$payload = [
				'recipients' => [
					'list_id'      => $list_id,
					'segment_opts' => $segment_opts,
				],
			];

			// Add saved segment ID to payload if present.
			if ( ! empty( $segment_data ) && 'saved' === $segment_data['type'] ) {
				$payload['recipients']['segment_opts']['saved_segment_id'] = (int) $segment_id;
			}

			$result = $this->validate(
				$mc->patch( "campaigns/$mc_campaign_id", $payload ),
				__( 'Error updating Mailchimp segments.', 'newspack_newsletters' )
			);

			$data           = $this->retrieve( $post_id );
			$data['result'] = $result;

			return \rest_ensure_response( $data );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Filters the error message shown to readers when an error occurs.
	 *
	 * @param string $reader_error The default error message.
	 * @param array  $params Additional information about the request that triggered the error.
	 * @param mixed  $raw_error Raw error data from the ESP's API. This can vary depending on the provider.
	 *
	 * @return string The filtered error message.
	 */
	public function reader_error_message( $reader_error, $params, $raw_error ) {
		// Handle special case where a user is in compliance state.
		if ( is_wp_error( $raw_error ) && false !== strpos( $raw_error->get_error_message(), 'Member In Compliance State' ) ) {
			$reader_error = __( "We'll need to subscribe this email address manually. Please contact our support team.", 'newspack-newsletters' );
		}
		return $reader_error;
	}

	/**
	 * Throw an Exception if Mailchimp response indicates an error.
	 *
	 * @param Object $result Result of the Mailchimp operation.
	 * @param String $preferred_error Error message to show to readers instead of showing Mailchimp API errors.
	 * @throws Exception Error message.
	 * @return The results of the API call.
	 */
	public function validate( $result, $preferred_error = null ) {
		$default_error = __( 'An unknown Mailchimp error occurred.', 'newspack-newsletters' );
		if ( ! $preferred_error ) {
			$preferred_error = $default_error;
		}
		if ( ! $result ) {
			throw new Exception( esc_html( $preferred_error ) );
		}
		// See Mailchimp error code glossary: https://mailchimp.com/developer/marketing/docs/errors/#error-glossary.
		if ( ! empty( $result['status'] ) && (int) $result['status'] >= 400 ) {
			$messages = [];
			if ( ! empty( $result['title'] ) ) {
				$messages[] = $result['title'] . ':';
			}
			if ( ! empty( $result['errors'] ) ) {
				foreach ( $result['errors'] as $error ) {
					if ( ! empty( $error['message'] ) ) {
						$messages[] = $error['message'];
					}
				}
			}
			if ( ! empty( $result['detail'] ) ) {
				$messages[] = $result['detail'];
			}
			if ( ! count( $messages ) ) {
				$messages[] = $preferred_error;
			}

			throw new Exception( esc_html( implode( ' ', $messages ) ), ! empty( $result['status'] ) ? intval( $result['status'] ) : 400 );
		}
		return $result;
	}

	/**
	 * Special handling for link hrefs containing Mailchimp merge fields.
	 *
	 * @param String $processed The processed link, with utm_medium parameter added.
	 * @param String $original The original, unprocessed link.
	 * @return The link to use.
	 */
	public function process_link( $processed, $original ) {
		// Match Mailchimp Merge Fields.
		if ( preg_match( '/\*\|([A-Z_0-9:]+)\|\*/', $original ) ) {
			// Check if http:// was prepended.
			if ( 0 === strpos( $original, 'http://*|' ) ) {
				$original = substr( $original, 7 );
			}
			return $original;
		}
		return $processed;
	}

	/**
	 * Upserts a contact to the ESP using the provider specific methods.
	 *
	 * Here we group all groups and tags by Audience, so we can add many lists at once.
	 *
	 * @param array               $contact The contact, as for the add_contact method.
	 * @param Subscription_List[] $lists The lists.
	 * @return array|WP_Error Contact data if it was added, or error otherwise.
	 */
	public function upsert_contact( $contact, $lists ) {

		$prepared_lists = $this->prepare_lists_to_add_contact( $lists );

		if ( empty( $prepared_lists ) ) {
			return new WP_Error( 'No lists found.' );
		}

		foreach ( $prepared_lists as $audience_id => $sublists ) {
			$result = $this->add_contact( $contact, $audience_id, $sublists['tags'], $sublists['interests'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// on success, return the last resutl.
		return $result;
	}

	/**
	 * Loops through a list of Subscription_Lists objects and group them to be sent to the `add_contact` method with tags and interests attached.
	 *
	 * @param Subscription_List[] $lists The lists.
	 * @return array The lists array where the keys are the Audience IDs and the values are an array with 'tags' and 'interests' keys.
	 */
	private function prepare_lists_to_add_contact( $lists ) {

		$result = [];

		foreach ( $lists as $list ) {

			$audience_id = $list->mailchimp_get_audience_id();

			if ( ! isset( $result[ $audience_id ] ) ) {
				$result[ $audience_id ] = [
					'tags'      => [],
					'interests' => [],
				];
			}

			if ( 'tag' === $list->mailchimp_get_sublist_type() ) {
				// Mailchimp API expects the tag name and not ID.
				$result[ $audience_id ]['tags'][] = $list->get_remote_name();
			} elseif ( 'group' === $list->mailchimp_get_sublist_type() ) {
				// Local lists are included here.
				$result[ $audience_id ]['interests'][ $list->mailchimp_get_sublist_id() ] = true;
			}
		}

		return $result;
	}

	/**
	 * Get merge field type.
	 *
	 * @param mixed $value Value to check.
	 *
	 * @return string Merge field type.
	 */
	private function get_merge_field_type( $value ) {
		if ( is_numeric( $value ) ) {
			return 'number';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		return 'text';
	}

	/**
	 * Given a contact metadata array, build the `merge_fields` array to be sent to Mailchimp
	 * by sarching for existing merge fields and creating new ones as needed.
	 *
	 * @param string $audience_id Audience ID.
	 * @param array  $contact     The contact.
	 *
	 * @return array Merge fields.
	 *
	 * @throws Exception Error message.
	 */
	private function prepare_merge_fields( $audience_id, $contact ) {
		$mc           = new Mailchimp( $this->api_key() );
		$merge_fields = [];
		$data         = $contact['metadata'];

		// Strip arrays and statuses.
		$data = array_filter(
			$data,
			function( $value, $key ) {
				return ! is_array( $value ) && 'status' !== $key && 'status_if_new' !== $key;
			},
			ARRAY_FILTER_USE_BOTH
		);

		// Get and match existing merge fields. Merge fields must always be fetched from the API to ensure we have the latest data.
		try {
			$response = $this->validate(
				$mc->get(
					"lists/$audience_id/merge-fields",
					[ 'count' => 1000 ],
					60
				)
			);

			// If we didn't get ANY merge fields in the response, something is wrong. Bail to avoid creating duplicate fields.
			if ( empty( $response['merge_fields'] ) ) {
				throw new Exception( esc_html__( 'Response returned no merge_fields', 'newspack-newsletters' ) );
			}
			$existing_fields = $response['merge_fields'];
		} catch ( \Exception $e ) {
			do_action(
				'newspack_log',
				'newspack_mailchimp_prepare_merge_fields',
				'Error getting merge fields',
				[
					'type'       => 'error',
					'data'       => [
						'audience_id' => $audience_id,
						'error'       => $e->getMessage(),
					],
					'user_email' => $contact['email'],
					'file'       => 'newspack_mailchimp',
				]
			);
			return new \WP_Error(
				'newspack_mailchimp_prepare_merge_fields',
				$e->getMessage()
			);
		}

		if ( empty( $existing_fields ) ) {
			$existing_fields = [];
		}

		usort(
			$existing_fields,
			function( $a, $b ) {
				return $a['merge_id'] - $b['merge_id'];
			}
		);

		$list_merge_fields = [];

		// Handle duplicate fields.
		foreach ( $existing_fields as $field ) {
			if ( ! isset( $list_merge_fields[ $field['name'] ] ) ) {
				$list_merge_fields[ $field['name'] ] = $field['tag'];
			} else {
				do_action(
					'newspack_log',
					'newspack_mailchimp_prepare_merge_fields',
					sprintf( 'Duplicate merge field %1$s found with tag %2$s.', $field['name'], $field['tag'] ),
					[
						'type'       => 'error',
						'data'       => [
							'audience_id' => $audience_id,
							'field'       => $field,
						],
						'user_email' => $contact['email'],
						'file'       => 'newspack_mailchimp',
					]
				);
			}
		}

		foreach ( $data as $field_name => $field_value ) {
			// If field already exists, add it to the payload.
			if ( isset( $list_merge_fields[ $field_name ] ) ) {
				$merge_fields[ $list_merge_fields[ $field_name ] ] = $data[ $field_name ];
				unset( $data[ $field_name ] );
			}
		}

		// Create remaining fields.
		$remaining_fields = array_keys( $data );
		foreach ( $remaining_fields as $field_name ) {
			$field_data = [
				'name' => $field_name,
				'type' => $this->get_merge_field_type( $data[ $field_name ] ),
			];
			$created_field = $mc->post( "lists/$audience_id/merge-fields", $field_data );
			if ( empty( $created_field['merge_id'] ) ) {
				$message = sprintf(
					// Translators: %1$s is the merge field key, %2$s is the error message.
					__( 'Failed to create merge field %1$s. Error response: %2$s', 'newspack-newsletters' ),
					$field_name,
					$created_field['detail'] ?? 'Unknown error'
				);
			} else {
				$message = sprintf(
					// Translators: %1$s is the merge field key, %2$s is the merge field tag.
					__( 'Created merge field %1$s with tag %2$s.', 'newspack-newsletters' ),
					$field_name,
					$created_field['tag']
				);
			}
			do_action(
				'newspack_log',
				'newspack_mailchimp_prepare_merge_fields',
				$message,
				[
					'type'       => empty( $created_field['merge_id'] ) ? 'error' : 'debug',
					'data'       => [
						'audience_id'   => $audience_id,
						'field_data'    => $field_data,
						'created_field' => $created_field,
					],
					'user_email' => $contact['email'],
					'file'       => 'newspack_mailchimp',
				]
			);
			// Add the field to the merge fields array if it was created.
			if ( ! empty( $created_field['merge_id'] ) ) {
				$merge_fields[ $created_field['tag'] ] = $data[ $field_name ];
			}
		}

		return $merge_fields;
	}

	/**
	 * Gets the status and/or status_if_new keys based on the contact data.
	 *
	 * @param array  $contact      {
	 *    Contact data.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 * }
	 * @param string $list_id List (Audience) to add the contact to, if any.
	 *
	 * @return array The status and/or status_if_new keys to be added to the payload
	 */
	private function get_status_for_payload( $contact, $list_id = null ) {
		if ( $list_id && ! empty( $contact['existing_contact_data']['lists'][ $list_id ]['status'] ) ) {
			$status = $contact['existing_contact_data']['lists'][ $list_id ]['status'];
			if ( 'unsubscribed' === $status ) {
				// Check if the contact has unsubscribed before. Mailchimp requires a double opt-in to resubscribe, so we set the status to 'pending'.
				return [ 'status' => 'pending' ];
			} else {
				// Otherwise we set the status to the existing status.
				return [ 'status' => $status ];
			}
		}
		$return = [];
		if ( ! empty( $contact['metadata']['status_if_new'] ) ) {
			$return['status_if_new'] = $contact['metadata']['status_if_new'];
		}

		if ( ! empty( $contact['metadata']['status'] ) ) {
			$return['status'] = $contact['metadata']['status'];
		}

		// If we're subscribing the contact to a newsletter, they should have some status
		// because 'non-subscriber' status can't receive newsletters.
		if ( empty( $return['status'] ) && empty( $return['status_if_new'] ) ) {
			$return['status'] = 'subscribed';
		}
		return $return;
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
	 * @param string $list_id   List (Audience) to add the contact to.
	 * @param array  $tags      An array of tag names to be added to the contact. Tags are always appended.
	 * @param array  $interests An array of interests as expected by the API, where the key is the interest ID and the value is a bool (add or remove).
	 *
	 * @return array|WP_Error Contact data if it was added, or error otherwise.
	 *
	 * @throws Exception Error message.
	 */
	public function add_contact( $contact, $list_id = false, $tags = [], $interests = [] ) {
		if ( false === $list_id ) {
			return new WP_Error( 'newspack_newsletters_mailchimp_list_id', __( 'Missing list id.' ) );
		}
		$email_address = trim( strtolower( $contact['email'] ) );
		// If contact was added in this execution, we can return the previous
		// result and bail.
		$cache_key = md5( $list_id . $email_address . wp_json_encode( $tags ) . wp_json_encode( $interests ) );
		if ( ! empty( self::$contacts_added[ $cache_key ] ) ) {
			return self::$contacts_added[ $cache_key ];
		}

		$update_payload = [ 'email_address' => $email_address ];
		$update_payload = array_merge(
			$update_payload,
			$this->get_status_for_payload( $contact, $list_id )
		);

		// Parse full name into first + last.
		if ( isset( $contact['name'] ) ) {
			$name_fragments = explode( ' ', $contact['name'], 2 );
			$contact['metadata']['First Name'] = $name_fragments[0];
			if ( isset( $name_fragments[1] ) ) {
				$contact['metadata']['Last Name'] = $name_fragments[1];
			}
		}

		try {
			$mc = new Mailchimp( $this->api_key() );

			if ( isset( $contact['metadata'] ) && is_array( $contact['metadata'] ) && ! empty( $contact['metadata'] ) ) {
				/**
				 * Filter the merge fields payload.
				 *
				 * @param array $merge_fields The merge fields payload to pass to the Mailchimp API.
				 */
				$merge_fields = apply_filters( 'newspack_mailchimp_merge_fields', $this->prepare_merge_fields( $list_id, $contact ) );
				if ( is_wp_error( $merge_fields ) ) {
					throw new Exception( $merge_fields->get_error_message() );
				}
				$update_payload['merge_fields'] = $merge_fields;
			}

			// Add groups and tags, if any.
			if ( ! empty( $tags ) ) {
				$update_payload['tags'] = $tags;
			}
			if ( ! empty( $interests ) ) {
				$update_payload['interests'] = $interests;
			}
			$member_hash            = Mailchimp::subscriberHash( $email_address );
			$existing_email_address = isset( $contact['existing_contact_data']['email_address'] ) ? trim( strtolower( $contact['existing_contact_data']['email_address'] ) ) : null;
			$existing_member_hash   = $existing_email_address ? Mailchimp::subscriberHash( $existing_email_address ) : null;
			$is_email_update        = $existing_email_address && isset( $contact['is_email_change'] ) && $contact['is_email_change'] && $existing_email_address !== $email_address;
			$is_subscribed          = isset( $update_payload['status'] ) && 'subscribed' === $update_payload['status'];

			// Mailchimp only allows subscribed contacts to update the email address field
			// so if the contact is not subscribed, we need to migrate existing data.
			if ( $is_email_update && ! $is_subscribed ) {
				$existing_contact = $this->get_contact_data( $existing_email_address, true );
				try {
					if ( is_wp_error( $existing_contact ) ) {
						throw new Exception( $existing_contact->get_error_message() );
					}
					$update_payload = array_merge(
						$update_payload,
						[
							'interests'    => $existing_contact['interests'][ $list_id ] ?? [],
							'merge_fields' => $existing_contact['merge_fields'],
							'tags'         => array_map(
								function ( $tag ) {
									return $tag['name'];
								},
								$existing_contact['tags'][ $list_id ] ?? []
							),
						]
					);
				} catch ( \Exception $e ) {
					Newspack_Newsletters_Logger::log( 'Failed to migrate merge fields: ' . $e->getMessage() );
				}
			}

			Newspack_Newsletters_Logger::log( 'Mailchimp add_contact PUT payload: ' . wp_json_encode( $update_payload ) );

			$hash   = $is_email_update && $is_subscribed ? $existing_member_hash : $member_hash;
			$result = $this->validate(
				$mc->put( "lists/$list_id/members/$hash", $update_payload ),
				__( 'Error upserting contact to Mailchimp.', 'newspack-newsletters' )
			);
			if ( $is_email_update && ! $is_subscribed ) {
				// For non-subscribed accounts, we create notes linking old and new contacts and archive the old contact.
				$this->validate(
					$mc->post(
						"lists/$list_id/members/$existing_member_hash/notes",
						[
							'note' => sprintf(
								// Translators: 1 is a hash value representing the contact's new ID. 2 is the contact's new email address.
								__( 'Contact requested email change. Migrated to %1$s (%2$s).', 'newspack-newsletters' ),
								$member_hash,
								$email_address
							),
						]
					),
					__( 'Error adding migration note to existing contact.', 'newspack-newsletters' )
				);
				$this->validate(
					$mc->post(
						"lists/$list_id/members/$member_hash/notes",
						[
							'note' => sprintf(
								// Translators: 1 is a hash value representing the contact's previous ID. 2 is the contact's previous email address.
								__( 'Contact requested email change. Migrated from %1$s (%2$s).', 'newspack-newsletters' ),
								$existing_member_hash,
								$existing_email_address
							),
						]
					),
					__( 'Error adding migration note to new contact.', 'newspack-newsletters' )
				);
				$this->validate(
					$mc->delete( "lists/$list_id/members/$existing_member_hash" ),
					__( 'Error deleting existing contact.', 'newspack-newsletters' )
				);
			}
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'newspack_newsletters_mailchimp_api_error',
				$e->getMessage()
			);
		}
		self::$contacts_added[ $cache_key ] = $result;
		return $result;
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
		foreach ( $contact['lists'] as $list_id => $list ) {
			try {
				$member_id = $list['id'];
				$mc        = new Mailchimp( $this->api_key() );
				$mc->delete( "lists/$list_id/members/$member_id" );
			} catch ( \Exception $e ) {
				return new \WP_Error(
					'newspack_newsletters_mailchimp_delete_contact_failed',
					$e->getMessage()
				);
			}
		}
		return true;
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
		$audience_lists = array_keys(
			array_filter(
				$contact['lists'],
				function ( $list ) {
					return 'subscribed' === $list['status'];
				}
			)
		);
		$groups_lists   = [];
		foreach ( $contact['interests'] as $list_id => $interests ) {
			foreach ( $interests as $group_id => $active ) {
				if ( $active ) {
					$groups_lists[] = Subscription_List::mailchimp_generate_public_id( $group_id, $list_id );
				}
			}
		}
		$tags_lists = [];
		foreach ( $contact['tags'] as $list_id => $tags ) {
			foreach ( $tags as $tag ) {
				$tags_lists[] = Subscription_List::mailchimp_generate_public_id( $tag['id'], $list_id, 'tag' );
			}
		}
		return array_merge( $audience_lists, $groups_lists, $tags_lists );
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
		$contact = $this->get_contact_data( $email );
		if ( is_wp_error( $contact ) ) {
			/** Create contact */
			$result = Newspack_Newsletters_Contacts::upsert( [ 'email' => $email ], $lists_to_add );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return true;
		}
		$mc = new Mailchimp( $this->api_key() );
		try {
			// Remove lists.
			foreach ( $lists_to_remove as $list_id ) {
				$list_obj = Subscription_List::from_public_id( $list_id );
				if ( ! $list_obj ) {
					continue;
				}

				if ( 'group' === $list_obj->mailchimp_get_sublist_type() ) {
					$this->remove_group_from_contact( $email, $list_obj->mailchimp_get_sublist_id(), $list_obj->mailchimp_get_audience_id() );
				} elseif ( 'tag' === $list_obj->mailchimp_get_sublist_type() ) {
					$this->remove_tag_from_contact( $email, $list_obj->mailchimp_get_sublist_id(), $list_obj->mailchimp_get_audience_id() );
				}

				// If $list_id is an Audience the contact is a part of, unsubscribe them.
				if ( isset( $contact['lists'][ $list_id ] ) ) {
					$mc->patch( "lists/$list_id/members/" . $contact['lists'][ $list_id ]['contact_id'], [ 'status' => 'unsubscribed' ] );
				}
			}

			// Add lists.
			if ( ! empty( $lists_to_add ) ) {
				$lists_objects = [];
				foreach ( $lists_to_add as $list_id ) {
					$list_obj = Subscription_List::from_public_id( $list_id );
					if ( ! $list_obj ) {
						continue;
					}
					$lists_objects[] = $list_obj;
				}
				$this->upsert_contact( [ 'email' => $email ], $lists_objects );
			}
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'newspack_newsletters_mailchimp_update_contact_failed',
				$e->getMessage()
			);
		}
		return true;
	}

	/**
	 * Get contact data by email.
	 *
	 * @param string $email          Email address.
	 * @param bool   $return_details Whether to return detailed information.
	 *
	 * @return array|WP_Error Response or error if contact was not found.
	 */
	public function get_contact_data( $email, $return_details = false ) {
		try {
			$mc    = new Mailchimp( $this->api_key() );
			$result  = $mc->get(
				'search-members',
				[
					'query' => $email,
				]
			);

			if ( ! isset( $result['exact_matches']['members'] ) ) {
				return new WP_Error( 'newspack_newsletters_mailchimp_search_members', __( 'Error reaching to search-members endpoint', 'newspack-newsletters' ) );
			}

			$found = $result['exact_matches']['members'];
			if ( empty( $found ) ) {
				return new WP_Error( 'newspack_newsletters_mailchimp_contact_not_found', __( 'Contact not found', 'newspack-newsletters' ) );
			}

			$keys = [ 'full_name', 'email_address', 'id' ];
			$data = [
				'lists'        => [],
				'tags'         => [],
				'interests'    => [],
				'merge_fields' => [],
			];
			foreach ( $found as $contact ) {
				foreach ( $keys as $key ) {
					if ( ! isset( $data[ $key ] ) || empty( $data[ $key ] ) ) {
						$data[ $key ] = $contact[ $key ];
					}
				}
				if ( isset( $contact['tags'] ) ) {
					$data['tags'][ $contact['list_id'] ] = $contact['tags'];
				}
				if ( isset( $contact['interests'] ) ) {
					$data['interests'][ $contact['list_id'] ] = $contact['interests'];
				}
				$data['lists'][ $contact['list_id'] ] = [
					'id'         => $contact['id'], // md5 hash of email.
					'contact_id' => $contact['contact_id'],
					'status'     => $contact['status'],
				];
				if ( isset( $contact['merge_fields'] ) ) {
					$data['merge_fields'] = $contact['merge_fields'];
				}
			}
			return $data;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_get_contact_data_failed',
				$e->getMessage()
			);
		}
	}

	/**
	 * Get the IDs of the tags associated with a contact.
	 *
	 * @param string $email The contact email.
	 * @return array|WP_Error The tag IDs on success, grouped by lists. WP_Error on failure.
	 */
	public function get_contact_tags_ids( $email ) {
		$contact_data = $this->get_contact_data( $email );
		if ( is_wp_error( $contact_data ) ) {
			return $contact_data;
		}

		$contact_tags = [];

		foreach ( $contact_data['tags'] as $list_id => $tags ) {
			$contact_tags[ $list_id ] = array_map(
				function ( $tag ) {
					return (int) $tag['id'];
				},
				$tags
			);
		}

		return $contact_tags;
	}

	/**
	 * Get the contact local lists IDs
	 *
	 * Mailchimp has to override this method because we need to handle groups under many lists.
	 *
	 * In other providers, get_contact_esp_local_lists_ids returns a simple array with IDs, but in Mailchimp it returns IDs grouped by lists.
	 *
	 * @param string $email The contact email.
	 * @return string[] Array of local lists IDs or error.
	 */
	public function get_contact_local_lists( $email ) {
		$tags = $this->get_contact_esp_local_lists_ids( $email );
		if ( is_wp_error( $tags ) ) {
			return [];
		}
		$lists = Subscription_Lists::get_configured_for_provider( $this->service );
		$ids   = [];
		foreach ( $lists as $list ) {
			if ( ! $list->is_local() ) {
				continue;
			}
			$list_settings = $list->get_provider_settings( $this->service );

			if ( ! empty( $tags[ $list_settings['list'] ] ) ) {
				if ( in_array( $list_settings['tag_id'], $tags[ $list_settings['list'] ], false ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.FoundNonStrictFalse
					$ids[] = $list->get_public_id();
				}
			}
		}
		return $ids;
	}

	/**
	 * Get the provider specific labels
	 *
	 * This allows us to make reference to provider specific features in the way the user is used to see them in the provider's UI
	 *
	 * @param string $context The context in which the labels are being applied.
	 * @return array
	 */
	public static function get_labels( $context = '' ) {
		$labels = [
			'name'                    => 'Mailchimp', // The provider name.
			'list'                    => __( 'audience', 'newspack-newsletters' ), // "list" in lower case singular format.
			'lists'                   => __( 'audiences', 'newspack-newsletters' ), // "list" in lower case plural format.
			'sublist'                 => __( 'group, segment, or tag', 'newspack-newsletters' ), // Sublist entities in lowercase singular format.
			'List'                    => __( 'Audience', 'newspack-newsletters' ), // "list" in uppercase case singular format.
			'Lists'                   => __( 'Audiences', 'newspack-newsletters' ), // "list" in uppercase case plural format.
			'Sublist'                 => __( 'Group, Segment, or Tag', 'newspack-newsletters' ), // Sublist entities in uppercase singular format.
			'list_explanation'        => __( 'Mailchimp Audience', 'newspack-newsletters' ),
			// translators: %s is the name of the group category. "Newspack newsletters" by default.
			'local_list_explanation'  => sprintf( __( 'Mailchimp Group under the %s category', 'newspack-newsletters' ), self::get_group_category_name() ),
			'tag_prefix'              => '',
			'tag_metabox_before_save' => __( 'Once this list is saved, a Group will be created for it.', 'newspack-newsletters' ),
			// translators: %s is the name of the group category. "Newspack newsletters" by default.
			'tag_metabox_after_save'  => sprintf( __( 'Group created for this list under %s:', 'newspack-newsletters' ), self::get_group_category_name() ),
		];
		if ( ! empty( $context ) && strpos( $context, 'group-' ) === 0 ) {
			$labels['list_explanation'] = __( 'Mailchimp Group', 'newspack-newsletters' );
		}
		if ( ! empty( $context ) && strpos( $context, 'tag-' ) === 0 ) {
			$labels['list_explanation'] = __( 'Mailchimp Tag', 'newspack-newsletters' );
		}
		return $labels;
	}

	/**
	 * Mailchimp merge-tag dictionary for the editor autocomplete.
	 *
	 * @return array
	 */
	public static function get_merge_tags() {
		return [
			'label'          => __( 'merge tag', 'newspack-newsletters' ),
			'trigger_prefix' => '*|',
			'tags'           => [
				/* Campaigns. */
				[
					'tag'   => '*|ARCHIVE|*',
					'label' => __( 'Creates a "View this email in your browser link" to your campaign page.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|CAMPAIGN_UID|*',
					'label' => __( 'Displays the unique ID for your campaign.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|REWARDS|*',
					'label' => __( 'Adds the Referral badge to your campaign.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|REWARDS_TEXT|*',
					'label' => __( 'Adds a text-only version of the Rewards link.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|MC:TRANSLATE|*',
					'label' => __( 'Inserts links to translate your sent campaign into different languages.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|TRANSLATE:xx|*',
					'label' => __( "Adds a list of links to translate the content in your campaign. Replace xx with the code for the language your campaign is written in, and we'll display other available languages.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|MC_LANGUAGE|*',
					'label' => __( "Displays the language code for a particular subscriber. For example, if your subscriber's language is set to English, the merge tag output will display the code en.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|MC_LANGUAGE_LABEL|*',
					'label' => __( 'Displays the plain-text language for a particular subscriber. All languages are written English, so if your subscriber\'s language is set to German we\'ll display "German" instead of Deutsch.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|DATE:X|*',
					'label' => __( 'Use to show the current date in a given format. Replace X with the format of your choice.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:RECENTX|*',
					'label' => __( 'Displays a list of links to recent campaigns sent to the audience indicated. Replace X with the number of campaigns to show.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|MC:TOC|*',
					'label' => __( 'Creates a linked table of contents in your campaign.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|MC:TOC_TEXT|*',
					'label' => __( 'Creates a table of contents in your campaigns as plain-text.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|MC_PREVIEW_TEXT|*',
					'label' => __( 'Use this merge tag to generate preview text in a custom-coded campaign.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|POLL:RATING:x|* *|END:POLL|*',
					'label' => __( 'Creates a poll to record subscriber ratings of 1–10.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|SURVEY|* *|END:|*',
					'label' => __( 'Creates a one-question survey with a set number of responses that subscribers can choose from.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|PROMO_CODE:[$store_id=x, $rule_id=x, $code_id=x]|*',
					'label' => __( 'Use this tag to include a promo code in a campaign. Replace the "x" variables in your Promo Code merge tag to specify what promo code to display.', 'newspack-newsletters' ),
				],
				/* Personalization. */
				[
					'tag'      => '*|FNAME|*',
					'label'    => __( "Inserts your subscriber's first name if it's available in your audience.", 'newspack-newsletters' ),
					'keywords' => [ 'first name' ],
				],
				[
					'tag'      => '*|LNAME|*',
					'label'    => __( "Inserts your subscriber's last name if it's available in your audience.", 'newspack-newsletters' ),
					'keywords' => [ 'last name' ],
				],
				[
					'tag'   => '*|EMAIL|*',
					'label' => __( "Inserts your subscriber's email address.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|PHONE|*',
					'label' => __( 'Inserts your subscriber’s phone number if it’s available in your audience.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|ADDRESS|*',
					'label' => __( 'Inserts your subscriber’s address if it’s available in your audience.', 'newspack-newsletters' ),
				],
				/* Email subject lines. */
				[
					'tag'   => '*|LIST:NAME|*',
					'label' => __( 'Inserts the name of your audience.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:COMPANY|*',
					'label' => __( "Inserts the name of your company or organization that's listed in the required email footer content for your audience.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:SUBSCRIBERS|*',
					'label' => __( 'Inserts the number of subscribers in your audience in plain-text.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|USER:COMPANY|*',
					'label' => __( 'Inserts the company or organization name listed under Primary Account Contact info in your Mailchimp account.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|MC:DATE|*',
					'label' => __( 'Displays MM/DD/YYYY or DD/MM/YYYY based on your settings in your account Details.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|CURRENT_YEAR|*',
					'label' => __( 'Displays the current year. This is great if you include a copyright date in your campaign, because it will update automatically every year.', 'newspack-newsletters' ),
				],
				/* Email footers. */
				[
					'tag'   => '*|UNSUB|*',
					'label' => __( "Gives your subscribers the opportunity to unsubscribe from your emails. (Required by law and Mailchimp's Terms of Use.)", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:DESCRIPTION|*',
					'label' => __( "Inserts your audience's permission reminder", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|HTML:LIST_ADDRESS_HTML|*',
					'label' => __( 'Inserts in your mailing address and the "Add us to your address book" link that points to the vcard (.vcf) file with your address details.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:ADDRESS_VCARD|*',
					'label' => __( 'Inserts an "Add us to your address book" link to your campaign.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:ADDRESS_VCARD_HREF|*',
					'label' => __( "Inserts a text URL that points to your vcard (.vcf) file of your address details. Use this as a link's Web Address to create a linked version.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|ABOUT_LIST|*',
					'label' => __( 'Creates a link to the About Your List page.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:UID|*',
					'label' => __( "Inserts your audience's unique ID from your audience's hosted forms.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:URL|*',
					'label' => __( 'Inserts the website URL set in the Required Email Footer Content for this audience.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:ADDRESS|*',
					'label' => __( 'Inserts your company or organization postal mailing address or P.O. Box as plain text.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:ADDRESSLINE|*',
					'label' => __( 'Inserts your mailing address as plain text on a single line.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:PHONE|*',
					'label' => __( 'Inserts your company or organization telephone number.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|ABUSE_EMAIL|*',
					'label' => __( 'Inserts the email address located in the Required Email Footer Content for this audience.', 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|LIST:SUBSCRIBE|*',
					'label' => __( "Inserts the URL for your audience's hosted signup form.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|UPDATE_PROFILE|*',
					'label' => __( "Inserts a link to the contact's update profile page.", 'newspack-newsletters' ),
				],
				[
					'tag'   => '*|FORWARD|*',
					'label' => __( "Inserts the URL to your audience's Forward to a Friend form.", 'newspack-newsletters' ),
				],
				/* Social share — X (formerly Twitter). */
				[
					'tag'      => '*|TWITTER:FULLPROFILE|*',
					'label'    => __( 'Inserts your X (formerly Twitter) avatar, follower, post, and following counts; a follow link; and your latest posts.', 'newspack-newsletters' ),
					'keywords' => [ 'x', 'twitter' ],
				],
				[
					'tag'      => '*|TWITTER:PROFILE|*',
					'label'    => __( 'Inserts your X (formerly Twitter) avatar, follower, post, and following counts, and a follow link. Excludes your latest posts.', 'newspack-newsletters' ),
					'keywords' => [ 'x', 'twitter' ],
				],
				[
					'tag'      => '*|TWITTER:PROFILEURL|*',
					'label'    => __( 'Displays your X (formerly Twitter) profile URL.', 'newspack-newsletters' ),
					'keywords' => [ 'x', 'twitter' ],
				],
				[
					'tag'      => '*|TWITTER:TWEETS2|*',
					'label'    => __( "Sets the number of X (formerly Twitter) posts to show. Replace 2 with the number you'd like to display.", 'newspack-newsletters' ),
					'keywords' => [ 'x', 'twitter' ],
				],
				[
					'tag'      => '*|TWITTER:PROFILE:TWITTERUSERNAME|*',
					'label'    => __( "Inserts another user's X (formerly Twitter) profile. Replace TWITTERUSERNAME with their handle.", 'newspack-newsletters' ),
					'keywords' => [ 'x', 'twitter' ],
				],
				[
					'tag'      => '*|TWITTER:TWEET|*',
					'label'    => __( 'Adds a Share button that lets subscribers post a link to your campaign on X (formerly Twitter).', 'newspack-newsletters' ),
					'keywords' => [ 'x', 'twitter' ],
				],
				[
					'tag'      => '*|TWITTER:TWEET [$text=my custom text here]|*',
					'label'    => __( 'Posts custom text (instead of your subject line) alongside a link to your campaign on X (formerly Twitter).', 'newspack-newsletters' ),
					'keywords' => [ 'x', 'twitter' ],
				],
			],
		];
	}

	/**
	 * Add a notice to the Subscription Lists metabox letting the user know that readers are also subscribed to the parent Audience
	 *
	 * @param array $settings The List settings.
	 * @return void
	 */
	public function lists_metabox_notice( $settings ) {
		if ( $settings['tag_name'] ) {
			?>
			<p class="subscription-list-warning">
				<?php
				esc_html_e( 'Note for Mailchimp: The group is a subset of the Audience selected above. When a reader subscribes to this List, they will also be subscribed to the selected Audience.', 'newspack-newsletters' );
				?>
			</p>
			<?php
		}
	}

	/**
	 * Replace some of the error messages sent by Mailchimp servers with a message that makes more sense to the user in the context of the plugin
	 *
	 * @param string $message The error message retrieved by the API.
	 * @return string The new error message if we have an option for it. The same message otherwise.
	 */
	public function get_better_error_message( $message ) {
		$known_errors = [
			'Error sending test email. This campaign cannot be tested:" A From Name must be entered on the Setup step."' => __( 'Error sending test email. Please enter a name and email in the "FROM" section.', 'newspack-newsletters' ),
		];
		return isset( $known_errors[ $message ] ) ? $known_errors[ $message ] : $message;
	}

	/**
	 * Get usage report.
	 */
	public function get_usage_report() {
		return Newspack_Newsletters_Mailchimp_Usage_Reports::get_usage_report();
	}

	/**
	 * Get contact fields for Newspack integrations.
	 *
	 * @param string|null $list_id The List ID.
	 * @return array|WP_Error
	 */
	public function get_contact_fields_for_integrations( $list_id = null ) {
		if ( empty( $list_id ) ) {
			return new WP_Error(
				'newspack_mailchimp_get_contact_fields_failed',
				__( 'List ID is required.', 'newspack-newsletters' )
			);
		}

		try {
			$all_fields = Newspack_Newsletters_Mailchimp_Cached_Data::get_merge_fields( $list_id );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_mailchimp_get_contact_fields_failed',
				$e->getMessage()
			);
		}

		if ( ! is_array( $all_fields ) ) {
			$all_fields = [];
		}

		$fields = [];
		foreach ( $all_fields as $field ) {
			$mapped = self::map_merge_field_to_integration_schema( $field );
			if ( null !== $mapped ) {
				$fields[] = $mapped;
			}
		}
		return $fields;
	}

	/**
	 * Map a Mailchimp merge field to the Newspack integrations schema.
	 *
	 * Mailchimp types eligible for access-rule / segmentation defaults: text, number, date, radio, dropdown.
	 * Other types (phone, url, imageurl, birthday, zip, address) are exposed but not promoted by default.
	 *
	 * @param array $field Raw merge field from the Mailchimp API.
	 * @return array|null Mapped field, or null if no usable identifier is available.
	 */
	private static function map_merge_field_to_integration_schema( $field ) {
		$tag = isset( $field['tag'] ) ? (string) $field['tag'] : '';
		if ( '' === $tag ) {
			return null;
		}

		$type                 = isset( $field['type'] ) ? $field['type'] : 'text';
		$eligible_types       = [ 'text', 'number', 'date', 'radio', 'dropdown' ];
		$is_promoted_by_default = in_array( $type, $eligible_types, true );

		$options = [];
		if ( in_array( $type, [ 'radio', 'dropdown' ], true ) && ! empty( $field['options']['choices'] ) ) {
			foreach ( (array) $field['options']['choices'] as $choice ) {
				$options[] = [
					'value' => $choice,
					'label' => $choice,
				];
			}
		}

		return [
			'key'                 => $tag,
			'name'                => ! empty( $field['name'] ) ? $field['name'] : $tag,
			'value_type'          => 'string',
			'matching_function'   => 'default',
			'options'             => $options,
			'description'         => isset( $field['help_text'] ) ? $field['help_text'] : '',
			'is_access_rule'      => $is_promoted_by_default,
			'is_segment_criteria' => $is_promoted_by_default,
		];
	}
}
