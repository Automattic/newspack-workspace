<?php
/**
 * Service Provider: Mailchimp Implementation
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;

/**
 * Main Newspack Newsletters Class.
 */
abstract class Newspack_Newsletters_Service_Provider implements Newspack_Newsletters_ESP_API_Interface, Newspack_Newsletters_WP_Hookable_Interface {

	const BASE_NAMESPACE = 'newspack-newsletters/v1/';

	const MAX_SCHEDULED_RETRIES = 10;

	/**
	 * The controller.
	 *
	 * @var \WP_REST_Controller.
	 */
	protected $controller;

	/**
	 * Name of the service.
	 *
	 * @var string
	 */
	public $service;

	/**
	 * Instances of descendant service provider classes.
	 *
	 * @var array
	 */
	protected static $instances = [];

	/**
	 * Post statuses controlled by the service provider.
	 *
	 * @var string[]
	 */
	protected static $controlled_statuses = [ 'publish', 'private' ];

	/**
	 * Whether the provider has support to tags and tags based Subscription Lists.
	 *
	 * @var boolean
	 */
	public static $support_local_lists = false;

	/**
	 * Memoization of existing contacts.
	 *
	 * @var array
	 */
	private $existing_contacts = [];

	/**
	 * Class constructor.
	 */
	public function __construct() {
		if ( $this->controller && $this->controller instanceof \WP_REST_Controller ) {
			add_action( 'rest_api_init', [ $this->controller, 'register_routes' ] );
		}
		add_action( 'pre_post_update', [ $this, 'pre_post_update' ], 10, 2 );
		add_action( 'save_post', [ $this, 'save_post' ], 10, 2 );
		add_action( 'transition_post_status', [ $this, 'transition_post_status' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'updated_post_meta' ], 10, 4 );
		add_action( 'wp_insert_post', [ $this, 'insert_post' ], 10, 3 );
		add_filter( 'wp_insert_post_data', [ $this, 'insert_post_data' ], 10, 2 );
	}

	/**
	 * Manage singleton instances of all descendant service provider classes.
	 */
	public static function instance() {
		// Escape hatch from the OOP logic for tests. When running in PHPUnit, some tests only pass if
		// the class is always instantiated and not returned from self::$instances.
		$is_test = defined( 'IS_TEST_ENV' ) && IS_TEST_ENV;
		if ( $is_test || empty( self::$instances[ static::class ] ) ) {
			self::$instances[ static::class ] = new static();
		}
		return self::$instances[ static::class ];
	}

	/**
	 * Handle newsletter post status changes.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    Unslashed post data.
	 */
	public function pre_post_update( $post_id, $data ) {

		// Only run if it's a newsletter post.
		if ( ! Newspack_Newsletters::validate_newsletter_id( $post_id ) ) {
			return;
		}

		// Only run if this is the active provider.
		if ( Newspack_Newsletters::service_provider() !== $this->service ) {
			return;
		}

		$post       = get_post( $post_id );
		$old_status = $post->post_status;
		$new_status = $data['post_status'];
		$sent       = Newspack_Newsletters::is_newsletter_sent( $post_id );

		// Don't run if moving to/from trash.
		if ( 'trash' === $new_status || 'trash' === $old_status ) {
			return;
		}

		// Prevent status change from the controlled status if newsletter has been sent.
		if ( ! in_array( $new_status, self::$controlled_statuses, true ) && $old_status !== $new_status && $sent ) {
			$error = new WP_Error( 'newspack_newsletters_error', __( 'You cannot change a sent newsletter status.', 'newspack-newsletters' ), [ 'status' => 403 ] );
			wp_die( esc_html( $error->get_error_message() ), '', 400 );
		}

		// Send if changing from any status to controlled statuses - 'publish' or 'private'.
		if (
			! $sent &&
			$old_status !== $new_status &&
			in_array( $new_status, self::$controlled_statuses, true ) &&
			! in_array( $old_status, self::$controlled_statuses, true )
		) {
			$result = $this->send_newsletter( $post );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ), '', esc_html( $result->get_error_code() ) );
			}
		}
	}

	/**
	 * Delete layout defaults meta after saving the post.
	 * We don't want layout defaults overwriting saved values unless the layout has just been set.
	 *
	 * @param int $post_id The ID of the post being saved.
	 * @return void
	 */
	public function save_post( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT !== $post_type ) {
			return;
		}

		delete_post_meta( $post_id, 'stringifiedCampaignDefaults' );
	}

	/**
	 * Handle post status transition for scheduled newsletters.
	 *
	 * This is executed after the post is updated.
	 *
	 * Scheduling a post (future -> publish) does not trigger the
	 * `pre_post_update` action hook because it uses the `wp_publish_post()`
	 * function. Unfortunately, this function does not fire any action hook prior
	 * to updating the post, so, for this case, we need to handle sending after
	 * the post is published.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function transition_post_status( $new_status, $old_status, $post ) {
		// Only run if it's a newsletter post.
		if ( ! Newspack_Newsletters::validate_newsletter_id( $post->ID ) ) {
			return;
		}

		// Only run if this is the active provider.
		if ( Newspack_Newsletters::service_provider() !== $this->service ) {
			return;
		}

		// Handle scheduled newsletters.
		if ( in_array( $new_status, self::$controlled_statuses, true ) && 'future' === $old_status ) {
			update_post_meta( $post->ID, 'sending_scheduled', true );
			$result = $this->send_newsletter( $post );
			if ( is_wp_error( $result ) ) {
				$this->add_send_error( $post->ID, $result );
				$send_errors   = get_post_meta( $post->ID, 'newsletter_send_errors', true );
				$send_attempts = is_array( $send_errors ) ? count( $send_errors ) : 0;

				// If we've already tried to send this post too many times, give up.
				if ( self::MAX_SCHEDULED_RETRIES <= $send_attempts ) {
					wp_update_post(
						[
							'ID'          => $post->ID,
							'post_status' => 'draft',
						]
					);
					$max_attempts = new WP_Error(
						'newspack_newsletter_send_error',
						sprintf(
							// Translators: An error message to explain that the scheduled send failed the maximum number times and won't be retried automatically.
							__( 'Failed to send %d times. Please check the provider connection and try sending again.', 'newspack-newsletters' ),
							self::MAX_SCHEDULED_RETRIES
						)
					);
					$this->add_send_error( $post->ID, $max_attempts );
					do_action(
						'newspack_log',
						'newspack_esp_scheduled_send_error',
						sprintf(
							'Maximum send attempts hit for post ID: %d',
							$post->ID
						),
						[
							'type'       => 'error',
							'data'       => [
								'provider' => $this->service,
								'errors'   => $result->get_error_message(),
							],
							'user_email' => '',
							'file'       => 'newspack_' . $this->service,
						]
					);
					wp_die( esc_html( $max_attempts->get_error_message() ), '', esc_html( $max_attempts->get_error_code() ) );
				}

				// Schedule a retry with exponential backoff maxed to 12 hours.
				$delay = min( 720, pow( 2, $send_attempts ) );
				wp_update_post(
					[
						'ID'            => $post->ID,
						'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'Y-m-d H:i:s' ) . ' +' . $delay . ' minutes ' ) ),
						'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'Y-m-d H:i:s', true ) . ' +' . $delay . ' minutes ' ) ),
						'post_status'   => 'future', // Reset status to `future` so the newspack_scheduled_post_checker job retries it.
					]
				);

				do_action(
					'newspack_log',
					'newspack_esp_scheduled_send_error',
					sprintf(
						'Error sending scheduled newsletter ID: %d',
						$post->ID
					),
					[
						'type'       => 'error',
						'data'       => [
							'provider' => $this->service,
							'errors'   => $result->get_error_message(),
						],
						'user_email' => '',
						'file'       => 'newspack_' . $this->service,
					]
				);
				wp_die( esc_html( $result->get_error_message() ), '', esc_html( $result->get_error_code() ) );
			}
			delete_post_meta( $post->ID, 'sending_scheduled' );
		}
	}

	/**
	 * Updated post meta
	 *
	 * @param int    $meta_id    ID of updated metadata entry.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value.
	 */
	public function updated_post_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
		// Only run if it's a newsletter post.
		if ( ! Newspack_Newsletters::validate_newsletter_id( $post_id ) ) {
			return;
		}

		// Only run if this is the active provider.
		if ( Newspack_Newsletters::service_provider() !== $this->service ) {
			return;
		}

		// Only run if the meta key is the one we're interested in.
		if ( 'is_public' !== $meta_key ) {
			return;
		}

		$is_public = $meta_value;

		$post = get_post( $post_id );
		if ( in_array( $post->post_status, self::$controlled_statuses, true ) ) {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => $is_public ? 'publish' : 'private',
				]
			);
		}
	}

	/**
	 * Fix a newsletter controlled status after update.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public function insert_post( $post_id, $post, $update ) {
		// Only run if it's a newsletter post.
		if ( ! Newspack_Newsletters::validate_newsletter_id( $post_id ) ) {
			return;
		}

		// Only run if this is the active provider.
		if ( Newspack_Newsletters::service_provider() !== $this->service ) {
			return;
		}

		// Only run if the post already exists.
		if ( ! $update ) {
			return;
		}

		$is_public = (bool) get_post_meta( $post_id, 'is_public', true );

		/**
		 * Control 'publish' and 'private' statuses using the 'is_public' meta.
		 */
		$target_status = 'private';
		if ( $is_public ) {
			$target_status = 'publish';
		}

		if ( in_array( $post->post_status, self::$controlled_statuses, true ) && $target_status !== $post->post_status ) {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => $target_status,
				]
			);
		}
	}

	/**
	 * Handle newsletter post status changes.
	 *
	 * @param array $data An array of slashed, sanitized, and processed post data.
	 * @param array $postarr An array of sanitized (and slashed) but otherwise unmodified post data.
	 *
	 * @return array An array of slashed, sanitized, and processed post data.
	 */
	public function insert_post_data( $data, $postarr ) {
		$post_id = $postarr['ID'];

		// Only run if it's a newsletter post.
		if ( ! Newspack_Newsletters::validate_newsletter_id( $post_id ) ) {
			return $data;
		}

		// Only run if this is the active provider.
		if ( Newspack_Newsletters::service_provider() !== $this->service ) {
			return $data;
		}

		$post       = get_post( $post_id );
		$old_status = $post->post_status;
		$new_status = $data['post_status'];
		$sent       = Newspack_Newsletters::is_newsletter_sent( $post_id );
		$is_public  = (bool) get_post_meta( $post->ID, 'is_public', true );

		/**
		 * Control 'publish' and 'private' statuses using the 'is_public' meta.
		 */
		$target_status = 'private';
		if ( $is_public ) {
			$target_status = 'publish';
		}
		if ( in_array( $new_status, self::$controlled_statuses, true ) ) {
			$data['post_status'] = $target_status;
		}

		/**
		 * Ensure sent newsletter will not be set to draft.
		 */
		if ( $sent && 'draft' === $new_status ) {
			$data['post_status'] = $target_status;
		}

		/**
		 * If the newsletter is being restored from trash and has been sent,
		 * use controlled status.
		 */
		if ( 'trash' === $old_status && 'trash' !== $new_status && $sent ) {
			$data['post_status'] = $target_status;
		}

		return $data;
	}

	/**
	 * Add send errors to the post.
	 *
	 * @param int      $post_id The post ID.
	 * @param WP_Error $error The WP_Error object to add.
	 */
	public function add_send_error( $post_id, $error ) {
		$existing_errors = get_post_meta( $post_id, 'newsletter_send_errors', true );
		if ( ! is_array( $existing_errors ) ) {
			$existing_errors = [];
		}
		$error_message = $error->get_error_message();
		$existing_errors[] = [
			'timestamp' => time(),
			'message'   => $error_message,
		];
		$existing_errors   = array_slice( $existing_errors, -10, 10, true );
		update_post_meta( $post_id, 'newsletter_send_errors', $existing_errors );
	}

	/**
	 * Whether the given post is a layout-CPT post. Layouts share the
	 * email editor surface with newsletters but must never trigger ESP
	 * campaign sync / dispatch — every lifecycle hook in this class
	 * (and its subclasses) gates on this.
	 *
	 * @param int|\WP_Post $post_or_id Post ID or post object.
	 * @return bool
	 */
	protected function is_layout_post( $post_or_id ) {
		return Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT === get_post_type( $post_or_id );
	}

	/**
	 * Send a newsletter.
	 *
	 * @param WP_Post $post The post object — typically a newsletter, but
	 *                      layout posts are also accepted and short-circuit
	 *                      before reaching the provider.
	 *
	 * @return true|WP_Error|null True if successful, WP_Error on failure, null
	 *                            when the send is short-circuited (layout post,
	 *                            or newsletter already marked as sent).
	 */
	public function send_newsletter( $post ) {
		$post_id = $post->ID;

		// Defence in depth: layouts must never dispatch a campaign, regardless of upstream guards.
		if ( $this->is_layout_post( $post ) ) {
			return null;
		}

		if ( Newspack_Newsletters::is_newsletter_sent( $post_id ) ) {
			return null;
		}

		try {
			$result = $this->send( $post );
		} catch ( Exception $e ) {
			$result = new WP_Error( 'newspack_newsletter_error', $e->getMessage(), [ 'status' => 400 ] );
		}

		if ( true === $result ) {
			Newspack_Newsletters::set_newsletter_sent( $post_id );
		}

		if ( \is_wp_error( $result ) ) {
			$this->add_send_error( $post_id, $result );

			/**
			 * Disables the email notification sent to site administrators when
			 * a newsletter campaign fails to send. Useful for sites that use
			 * alternative monitoring systems.
			 *
			 * @constant NEWSPACK_NEWSLETTERS_DISABLE_SEND_FAILURE_EMAIL
			 * @type     bool
			 * @default  Failure emails enabled
			 * @status   draft
			 *
			 * @example define( 'NEWSPACK_NEWSLETTERS_DISABLE_SEND_FAILURE_EMAIL', true );
			 */
			$email_sending_disabled = defined( 'NEWSPACK_NEWSLETTERS_DISABLE_SEND_FAILURE_EMAIL' ) && NEWSPACK_NEWSLETTERS_DISABLE_SEND_FAILURE_EMAIL;

			$is_scheduled  = get_post_meta( $post->ID, 'sending_scheduled', true );
			$send_errors   = get_post_meta( $post->ID, 'newsletter_send_errors', true );
			$send_attempts = is_array( $send_errors ) ? count( $send_errors ) : 0;

			// For scheduled sends with auto-retry, only send an email on the last failed send attempt.
			if ( $is_scheduled && self::MAX_SCHEDULED_RETRIES > $send_attempts ) {
				$email_sending_disabled = true;
			}

			if ( ! $email_sending_disabled ) {
				$errors  = is_array( $send_errors ) ? implode( PHP_EOL, array_column( $send_errors, 'message' ) ) : $result->get_error_message();
				$message = sprintf(
					/* translators: %1$s is the campaign title, %2$s is the edit link, %3$s is the error message. */
					__(
						'Hi,

A newsletter campaign called "%1$s" failed to send on your site.

You can edit the campaign here: %2$s

Error message(s) received:

%3$s
	',
						'newspack-newsletters'
					),
					$post->post_title,
					admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
					$errors
				);

				\wp_mail( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
					get_option( 'admin_email' ),
					__( 'ERROR: Sending a newsletter failed', 'newspack-newsletters' ),
					$message
				);
			}
		}

		return $result;
	}

	/**
	 * Get campaign name.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return string Campaign name.
	 */
	public function get_campaign_name( $post ) {
		$campaign_name = get_post_meta( $post->ID, 'campaign_name', true );
		if ( $campaign_name ) {
			return $campaign_name;
		}
		return sprintf( 'Newspack Newsletter (%d)', $post->ID );
	}

	/**
	 * Get the provider specific labels
	 *
	 * This allows us to make reference to provider specific features in the way the user is used to see them in the provider's UI
	 *
	 * This methos must return an array with localized labels forfollowing keys:
	 * - name: The provider name.
	 * - list: "list" in lower case singular format.
	 * - lists: "list" in lower case plural format.
	 * - sublist: Sublist entities in lowercase singular format.
	 * - List: "list" in uppercase case singular format.
	 * - Lists: "list" in uppercase case plural format.
	 * - Sublist: Sublist entities in uppercase singular format.
	 * - tag_prefix: The prefix to be used in tags.
	 * - tag_metabox_before_save: The message to show before saving a list that will create a tag.
	 * - tag_metabox_after_save: The message to show after saving a list that created a tag.
	 *
	 * @param mixed $context The context in which the labels are being applied. Either list_explanation or local_list_explanation.
	 * @return array
	 */
	public static function get_labels( $context = '' ) {
		return [
			'name'                    => '', // The provider name.
			'list'                    => __( 'list', 'newspack-newsletters' ), // "list" in lower case singular format.
			'lists'                   => __( 'lists', 'newspack-newsletters' ), // "list" in lower case plural format.
			'sublist'                 => __( 'sublist', 'newspack-newsletters' ), // Sublist entities in lowercase singular format.
			'List'                    => __( 'List', 'newspack-newsletters' ), // "list" in uppercase case singular format.
			'Lists'                   => __( 'Lists', 'newspack-newsletters' ), // "list" in uppercase case plural format.
			'Sublist'                 => __( 'Sublist', 'newspack-newsletters' ), // Sublist entities in uppercase singular format.
			'tag_prefix'              => 'Newspack: ', // The prefix to be used in tags.
			'tag_metabox_before_save' => __( 'Once this list is saved, a tag will be created for it.', 'newspack-newsletters' ),
			'tag_metabox_after_save'  => __( 'Tag created for this list', 'newspack-newsletters' ),
		];
	}

	/**
	 * Get one specific label for the current provider
	 *
	 * @param string $key The label key.
	 * @param mixed  $context The context of the label. Optional.
	 * @return string Empty string in case the label is not found.
	 */
	public static function label( $key, $context = '' ) {
		$labels = static::get_labels( $context );
		return $labels[ $key ] ?? '';
	}

	/**
	 * Get the merge-tag dictionary for this ESP.
	 *
	 * Override on each concrete provider to expose its merge-tag autocomplete
	 * dictionary in the newsletter editor. The default below returns an empty
	 * dictionary so providers without an override silently disable the
	 * completer (no fatal, no UI noise).
	 *
	 * Shape:
	 *   [
	 *     'label'          => string  // ESP-native singular noun (e.g. "merge tag", "personalization tag").
	 *     'trigger_prefix' => string  // Legacy 2-character ESP-native trigger preserved for existing Mailchimp muscle memory (`*|`). Leave empty for any other provider; the universal `{}` picker trigger always works and is the only trigger new providers should rely on.
	 *     'tags'           => array[] // Each: [ 'tag' => '<inserted string>', 'label' => '<description>', 'keywords' => string[] ].
	 *   ]
	 *
	 * @return array
	 */
	public static function get_merge_tags() {
		return [
			'label'          => '',
			'trigger_prefix' => '',
			'tags'           => [],
		];
	}

	/**
	 * Upserts a contact to the ESP using the provider specific methods.
	 *
	 * Note: Mailchimp overrides this method.
	 *
	 * @param array               $contact      {
	 *               Contact data.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 * }
	 * @param Subscription_List[] $lists The lists.
	 * @return array|WP_Error Contact data if it was added, or error otherwise.
	 */
	public function upsert_contact( $contact, $lists ) {

		if ( empty( $lists ) ) {
			return $this->add_contact( $contact );
		}

		foreach ( $lists as $list ) {
			if ( $list->is_local() ) {
				$result = $this->add_contact_to_local_list( $contact, $list );
			} else {
				$result = $this->add_contact( $contact, $list->get_public_id() );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// on success, return the last result.
		return $result;
	}

	/**
	 * Get a reader-facing error message to be shown when the add_contact method fails.
	 *
	 * @param array $params Additional information about the request that triggered the error.
	 * @param mixed $raw_error Raw error data from the ESP's API. This can vary depending on the provider.
	 *
	 * @return string
	 */
	public function get_reader_error_message( $params = [], $raw_error = null ) {
		/**
		 * A default error message to show to readers if their signup request results in an error.
		 *
		 * @param string $reader_error The default error message.
		 * @param array  $params Additional information about the request that triggered the error.
		 * @param mixed $raw_error Raw error data from the ESP's API. This can vary depending on the provider.
		 */
		$reader_error = apply_filters(
			'newspack_newsletters_add_contact_reader_error_message',
			__( 'Sorry, an error has occurred. Please try again later or contact us for support.', 'newspack-newsletters' ),
			$params,
			$raw_error
		);
		return $reader_error;
	}

	/**
	 * Check if a contact exists in the ESP.
	 *
	 * @param string $email The contact email address.
	 *
	 * @return bool True if the contact exists, false otherwise.
	 */
	public function contact_exists( $email ) {
		if ( in_array( $email, $this->existing_contacts, true ) ) {
			return true;
		}

		$contact = $this->get_contact_data( $email );
		if ( is_wp_error( $contact ) ) {
			// Don't memoize missing contacts.
			return false;
		}

		// Memoize existing contacts.
		$this->existing_contacts[] = $email;

		return true;
	}

	/**
	 * Handle adding to local lists.
	 * If the $list_id is a local list, a tag will be added to the contact.
	 *
	 * @param array             $contact      {
	 *               Contact data.
	 *
	 *    @type string   $email    Contact email address.
	 *    @type string   $name     Contact name. Optional.
	 *    @type string[] $metadata Contact additional metadata. Optional.
	 * }
	 * @param Subscription_List $list      The list object.
	 *
	 * @return true|WP_Error True or error.
	 */
	protected function add_contact_to_local_list( $contact, Subscription_List $list ) {
		if ( ! static::$support_local_lists ) {
			return true;
		}

		if ( ! $list->is_local() ) {
			return new WP_Error( 'newspack_newsletters_list_not_local', "List {$list->get_public_id()} is not a local list" );
		}

		if ( ! $list->is_configured_for_provider( $this->service ) ) {
			return new WP_Error( 'newspack_newsletters_list_not_configured_for_provider', "List $list_id not properly configured for the provider" );
		}

		$list_settings = $list->get_provider_settings( $this->service );

		// If the contact doesn't exist, create it.
		if ( ! $this->contact_exists( $contact['email'] ) ) {
			$result = $this->add_contact( $contact, $list_settings['list'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $this->add_esp_local_list_to_contact( $contact['email'], $list_settings['tag_id'], $list_settings['list'] );
	}

	/**
	 * Update a contact lists subscription, but handling local Subscription Lists
	 *
	 * The difference between this method and update_contact_lists is that this method will identify and handle local lists
	 *
	 * @param string   $email           Contact email address.
	 * @param string[] $lists_to_add    Array of list IDs to subscribe the contact to.
	 * @param string[] $lists_to_remove Array of list IDs to remove the contact from.
	 * @param string   $context         The context in which the update is being performed. For logging purposes.
	 *
	 * @return true|WP_Error True if the contact was updated or error.
	 */
	public function update_contact_lists_handling_local( $email, $lists_to_add = [], $lists_to_remove = [], $context = 'Unknown' ) {
		$contact = $this->get_contact_data( $email );
		if ( is_wp_error( $contact ) ) {
			// Create contact.
			// Use Newspack_Newsletters_Contacts::upsert to trigger hooks and call add_contact_handling_local_list if needed.
			$result = Newspack_Newsletters_Contacts::upsert( [ 'email' => $email ], $lists_to_add, $context );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return true;
		}
		if ( static::$support_local_lists ) {
			$lists_to_add    = $this->update_contact_local_lists( $email, $lists_to_add, 'add' );
			$lists_to_remove = $this->update_contact_local_lists( $email, $lists_to_remove, 'remove' );
		}
		return $this->update_contact_lists( $email, $lists_to_add, $lists_to_remove );
	}

	/**
	 * Bulk update a contact local lists, by adding or removing tags
	 *
	 * @param string $email The contact email.
	 * @param array  $lists An array with List IDs, mixing local and providers lists. Only local lists will be handled.
	 * @param string $action The action to be performed. add or remove.
	 * @return array The remaining lists that were not handled by this method, because they are not local lists.
	 */
	protected function update_contact_local_lists( $email, $lists = [], $action = 'add' ) {
		foreach ( $lists as $key => $list_id ) {
			if ( Subscription_List::is_local_public_id( $list_id ) ) {
				try {
					$list = Subscription_List::from_public_id( $list_id );

					if ( ! $list->is_configured_for_provider( $this->service ) ) {
						do_action(
							'newspack_log',
							'newspack_esp_update_contact_lists_error',
							__( 'Local list not properly configured for the provider', 'newspack-newsletters' ),
							[
								'type'       => 'error',
								'data'       => [
									'provider' => $this->service,
									'list_id'  => $list_id,
								],
								'user_email' => $email,
								'file'       => 'newspack_' . $this->service,
							]
						);
						unset( $lists[ $key ] );
						continue;
					}
					$list_settings = $list->get_provider_settings( $this->service );

					if ( 'add' === $action ) {
						$this->add_esp_local_list_to_contact( $email, $list_settings['tag_id'], $list_settings['list'] );
					} elseif ( 'remove' === $action ) {
						$this->remove_esp_local_list_from_contact( $email, $list_settings['tag_id'], $list_settings['list'] );
					}
					unset( $lists[ $key ] );
				} catch ( \InvalidArgumentException $e ) {
					do_action(
						'newspack_log',
						'newspack_esp_update_contact_lists_error',
						__( 'Local list not found', 'newspack-newsletters' ),
						[
							'type'       => 'error',
							'data'       => [
								'provider' => $this->service,
								'list_id'  => $list_id,
							],
							'user_email' => $email,
							'file'       => 'newspack_' . $this->service,
						]
					);
					unset( $lists[ $key ] );
				}
			}
		}
		return $lists;
	}

	/**
	 * Get the contact local lists IDs
	 *
	 * Note: Mailchimp overrides this method.
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
			if ( in_array( $list_settings['tag_id'], $tags, false ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.FoundNonStrictFalse
				$ids[] = $list->get_public_id();
			}
		}
		return $ids;
	}

	/**
	 * Get contact lists combining local lists and provider lists
	 *
	 * @param string $email The contact email.
	 * @return WP_Error|array
	 */
	public function get_contact_combined_lists( $email ) {
		$lists = $this->get_contact_lists( $email );
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}
		$local_lists = [];
		if ( static::$support_local_lists ) {
			$local_lists = $this->get_contact_local_lists( $email );
			if ( is_wp_error( $local_lists ) ) {
				return $local_lists;
			}
		}
		return array_merge( $lists, $local_lists );
	}

	/**
	 * Retrieve the ESP's Local list ID from its name
	 *
	 * By default it will use Tags, but the provider can override this method to use something else
	 *
	 * @param string  $esp_local_list_name The esp_local_list.
	 * @param boolean $create_if_not_found Whether to create a new esp_local_list if not found. Default to true.
	 * @param string  $list_id The List ID.
	 * @return int|WP_Error The esp_local_list ID on success. WP_Error on failure.
	 */
	public function get_esp_local_list_id( $esp_local_list_name, $create_if_not_found = true, $list_id = null ) {
		return $this->get_tag_id( $esp_local_list_name, $create_if_not_found, $list_id );
	}

	/**
	 * Retrieve the ESP's Local list name from its ID
	 *
	 * By default it will use Tags, but the provider can override this method to use something else
	 *
	 * @param string|int $esp_local_list_id The esp_local_list ID.
	 * @param string     $list_id The List ID.
	 * @return string|WP_Error The esp_local_list name on success. WP_Error on failure.
	 */
	public function get_esp_local_list_by_id( $esp_local_list_id, $list_id = null ) {
		return $this->get_tag_by_id( $esp_local_list_id, $list_id );
	}

	/**
	 * Create a Local list on the ESP
	 *
	 * By default it will use Tags, but the provider can override this method to use something else
	 *
	 * @param string $esp_local_list The Tag name.
	 * @param string $list_id The List ID.
	 * @return array|WP_Error The esp_local_list representation with at least 'id' and 'name' keys on succes. WP_Error on failure.
	 */
	public function create_esp_local_list( $esp_local_list, $list_id = null ) {
		return $this->create_tag( $esp_local_list, $list_id );
	}

	/**
	 * Update a Local list name on the ESP
	 *
	 * By default it will use Tags, but the provider can override this method to use something else
	 *
	 * @param string|int $esp_local_list_id The esp_local_list ID.
	 * @param string     $esp_local_list The Tag name.
	 * @param string     $list_id The List ID.
	 * @return array|WP_Error The esp_local_list representation with at least 'id' and 'name' keys on succes. WP_Error on failure.
	 */
	public function update_esp_local_list( $esp_local_list_id, $esp_local_list, $list_id = null ) {
		return $this->update_tag( $esp_local_list_id, $esp_local_list, $list_id );
	}

	/**
	 * Add a Local list to a contact in the ESP
	 *
	 * By default it will use Tags, but the provider can override this method to use something else
	 *
	 * @param string     $email The contact email.
	 * @param string|int $esp_local_list The esp_local_list ID retrieved with get_esp_local_list_id() or the the esp_local_list string.
	 * @param string     $list_id The List ID.
	 * @return true|WP_Error
	 */
	public function add_esp_local_list_to_contact( $email, $esp_local_list, $list_id = null ) {
		return $this->add_tag_to_contact( $email, $esp_local_list, $list_id );
	}

	/**
	 * Remove a Local list from a contact in the ESP
	 *
	 * By default it will use Tags, but the provider can override this method to use something else
	 *
	 * @param string     $email The contact email.
	 * @param string|int $esp_local_list The esp_local_list ID retrieved with get_esp_local_list_id() or the the esp_local_list string.
	 * @param string     $list_id The List ID.
	 * @return true|WP_Error
	 */
	public function remove_esp_local_list_from_contact( $email, $esp_local_list, $list_id = null ) {
		return $this->remove_tag_from_contact( $email, $esp_local_list, $list_id );
	}

	/**
	 * Get the IDs of the Local lists associated with a contact in the ESP.
	 *
	 * By default it will use Tags, but the provider can override this method to use something else
	 *
	 * @param string $email The contact email.
	 * @return array|WP_Error The esp_local_list IDs on success. WP_Error on failure.
	 */
	public function get_contact_esp_local_lists_ids( $email ) {
		return $this->get_contact_tags_ids( $email );
	}

	/**
	 * Test the provider's API connection.
	 *
	 * @return true|WP_Error True if the connection is successful, WP_Error otherwise.
	 */
	public function test_connection() {
		return true;
	}

	/**
	 * Get transient name for async error messages.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string The transient name.
	 */
	public function get_transient_name( $post_id ) {
		return sprintf( 'newspack_newsletters_error_%s_%s', $post_id, get_current_user_id() );
	}

	/**
	 * Get contact fields for Newspack integrations.
	 *
	 * By default, this method returns an empty array. Providers should override it to return the fields
	 * available in the ESP in a shape that can be consumed by the Newspack ESP integration to configure
	 * Reader Activation incoming fields (content gate access rules and popups segmentation criteria).
	 *
	 * Each returned entry is an associative array with the following keys. All keys except 'key' are
	 * optional — defaults kick in from the Incoming_Field class when a key is missing.
	 *
	 *   - key (string, required): Machine key for the field. Used as the Incoming_Field key.
	 *   - name (string): Human-readable label. Defaults to the key.
	 *   - value_type (string): Defaults to 'string'. The Incoming_Field setter accepts other values
	 *       (e.g. 'boolean'); current providers only emit 'string'.
	 *   - matching_function (string): Defaults to 'default' (strict equality). Use 'list__in' for
	 *       multi-select fields whose stored value is a delimited list. The consumer also
	 *       recognizes 'list__not_in' and 'range', though no current provider emits them.
	 *   - options (array): List of [ 'value' => ..., 'label' => ... ] pairs for enumerated fields.
	 *   - description (string): Optional help text surfaced in the admin UI.
	 *   - is_access_rule (bool): Whether the field is eligible as a content-gate access rule by default.
	 *   - is_segment_criteria (bool): Whether the field is eligible as a popups segmentation criterion by default.
	 *
	 * @param string|null $list_id The List ID. Optional, as some providers might not have different fields per list.
	 * @return array|WP_Error The contact fields for the list, or WP_Error if the request failed.
	 */
	public function get_contact_fields_for_integrations( $list_id = null ) {
		return [];
	}
}
