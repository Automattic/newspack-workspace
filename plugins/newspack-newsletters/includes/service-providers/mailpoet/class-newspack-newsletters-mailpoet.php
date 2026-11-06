<?php
/**
 * Service Provider: MailPoet Implementation
 *
 * MailPoet runs inside WordPress rather than behind a remote API, so this
 * provider differs from the others in two structural ways:
 *
 * - There are no credentials. Connection means "the plugin is active".
 * - Composing and sending are MailPoet's, not ours. The campaign methods on the
 *   ESP interface are stubbed here; each exploratory branch replaces them with
 *   its own approach. See LEO-65.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack\Newsletters\Send_Lists;
use Newspack\Newsletters\Send_List;

/**
 * Main Newspack Newsletters Class for the MailPoet ESP.
 */
final class Newspack_Newsletters_Mailpoet extends \Newspack_Newsletters_Service_Provider {

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	public $name = 'MailPoet';

	/**
	 * Cached SDK instance.
	 *
	 * @var Newspack_Newsletters_Mailpoet_SDK|null
	 */
	private $sdk = null;

	/**
	 * Memoized contact payloads, keyed by email.
	 *
	 * MailPoet returns lists, tags and profile data in one call, so several
	 * interface methods can share a fetch within a request.
	 *
	 * @var array
	 */
	private $contact_data = [];

	/**
	 * Whether the provider supports tag-based local Subscription Lists.
	 *
	 * Left at the base class default. MailPoet has tags, but local lists are not
	 * part of this integration; see LEO-65.
	 *
	 * @var boolean
	 */
	public static $support_local_lists = false;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->service    = 'mailpoet';
		$this->controller = new Newspack_Newsletters_Mailpoet_Controller( $this );

		// The base class hooks updated_post_meta for its own `is_public` handling
		// only, so the campaign sync and cleanup are wired here as the other
		// providers do.
		add_action( 'updated_post_meta', [ $this, 'save' ], 10, 4 );
		add_action( 'wp_trash_post', [ $this, 'trash' ], 10, 1 );

		parent::__construct( $this );
	}

	/**
	 * Get the SDK wrapper.
	 *
	 * @return Newspack_Newsletters_Mailpoet_SDK
	 */
	public function get_sdk() {
		if ( ! $this->sdk ) {
			$this->sdk = new Newspack_Newsletters_Mailpoet_SDK();
		}
		return $this->sdk;
	}

	// Connection.
	//
	// MailPoet is a local plugin, so there is no key to store. The credential.
	// methods exist to satisfy the ESP interface and report connection state.

	/**
	 * Get API credentials for the service provider.
	 *
	 * @return array Always empty; MailPoet needs no credentials.
	 */
	public function api_credentials() {
		return [];
	}

	/**
	 * Set the API credentials for the service provider.
	 *
	 * @param object $credentials API credentials.
	 *
	 * @return true|WP_Error
	 */
	public function set_api_credentials( $credentials ) {
		return true;
	}

	/**
	 * Check if the provider has all necessary credentials set.
	 *
	 * Connection here means MailPoet is installed and its API is reachable.
	 *
	 * @return boolean
	 */
	public function has_api_credentials() {
		return Newspack_Newsletters_Mailpoet_SDK::is_available();
	}

	/**
	 * Whether MailPoet's signup confirmation (double opt-in) is on.
	 *
	 * MailPoet decides subscriber status from this site-wide setting and ignores
	 * the status we request, so admin surfaces explaining reader status need it.
	 * A contact added while this is enabled lands as `unconfirmed`, MailPoet's
	 * equivalent of Mailchimp's `pending`.
	 *
	 * @return boolean
	 */
	public function is_signup_confirmation_enabled() {
		return $this->get_sdk()->is_signup_confirmation_enabled();
	}

	// Labels.

	/**
	 * Get the provider-specific labels.
	 *
	 * @param mixed $context The context in which the labels are being applied.
	 *
	 * @return array
	 */
	public static function get_labels( $context = '' ) {
		return array_merge(
			parent::get_labels(),
			[
				'name'    => 'MailPoet',
				'list'    => __( 'list', 'newspack-newsletters' ),
				'lists'   => __( 'lists', 'newspack-newsletters' ),
				'sublist' => __( 'tag', 'newspack-newsletters' ),
				'List'    => __( 'List', 'newspack-newsletters' ),
				'Lists'   => __( 'Lists', 'newspack-newsletters' ),
				'Sublist' => __( 'Tag', 'newspack-newsletters' ),
			]
		);
	}

	/**
	 * Get configuration for conditional tag support.
	 *
	 * MailPoet's shortcodes are not conditional in the sense the newsletter
	 * editor means, so this reports no support.
	 *
	 * @return array
	 */
	public static function get_conditional_tag_support() {
		return [
			'support_url' => 'https://kb.mailpoet.com/article/215-personalize-your-newsletter-with-shortcodes',
			'example'     => [
				'before' => '',
				'after'  => '',
			],
		];
	}

	// Lists.

	/**
	 * List the ESP's contact lists.
	 *
	 * @return array|WP_Error
	 */
	public function get_lists() {
		$lists = $this->get_sdk()->get_lists();
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}
		return array_map(
			function ( $list ) {
				return [
					'id'          => (string) $list['id'],
					'name'        => $list['name'],
					'description' => $list['description'] ?? '',
				];
			},
			$lists
		);
	}

	/**
	 * Get the ESP's available lists and sublists as Send_List items.
	 *
	 * MailPoet lists map to top-level lists and MailPoet tags to sublists.
	 *
	 * @param array   $args     Search args. See Send_Lists::get_default_args().
	 * @param boolean $to_array Whether to convert Send_List objects to arrays.
	 *
	 * @return Send_List[]|array|WP_Error
	 */
	public function get_send_lists( $args = [], $to_array = false ) {
		$lists = $this->get_lists();
		if ( is_wp_error( $lists ) ) {
			return $lists;
		}

		$send_lists = array_map(
			function ( $list ) {
				return new Send_List(
					[
						'provider'    => $this->service,
						'type'        => 'list',
						'id'          => $list['id'],
						'name'        => $list['name'],
						'entity_type' => 'list',
						'edit_link'   => admin_url( 'admin.php?page=mailpoet-lists' ),
					]
				);
			},
			$lists
		);

		$tags = $this->get_sdk()->get_tags();
		if ( is_wp_error( $tags ) ) {
			return $tags;
		}
		$send_tags = array_map(
			function ( $tag ) {
				return new Send_List(
					[
						'provider'    => $this->service,
						'type'        => 'sublist',
						'id'          => (string) $tag['id'],
						'name'        => $tag['name'],
						'entity_type' => 'tag',
						'edit_link'   => admin_url( 'admin.php?page=mailpoet-subscribers' ),
					]
				);
			},
			$tags
		);

		$filtered = array_merge( $send_lists, $send_tags );

		if ( ! empty( $args['ids'] ) ) {
			$ids      = is_array( $args['ids'] ) ? $args['ids'] : [ $args['ids'] ];
			$filtered = array_values(
				array_filter(
					$filtered,
					function ( $list ) use ( $ids ) {
						return Send_Lists::matches_id( $ids, $list->get_id() );
					}
				)
			);
		}

		if ( ! empty( $args['search'] ) ) {
			$search   = is_array( $args['search'] ) ? $args['search'] : [ $args['search'] ];
			$filtered = array_values(
				array_filter(
					$filtered,
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
			$filtered = array_slice( $filtered, 0, $args['limit'] );
		}

		if ( $to_array ) {
			$filtered = array_map(
				function ( $list ) {
					return $list->to_array();
				},
				$filtered
			);
		}

		return $filtered;
	}

	// Contacts.

	/**
	 * Add a contact to a list, or update an existing contact.
	 *
	 * MailPoet decides the resulting subscriber status from its own signup
	 * confirmation setting; see is_signup_confirmation_enabled().
	 *
	 * @param array  $contact Contact data: `email`, optional `name` and `metadata`.
	 * @param string $list_id List to add the contact to.
	 *
	 * @return array|WP_Error
	 */
	public function add_contact( $contact, $list_id = false ) {
		if ( empty( $contact['email'] ) ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_invalid_contact',
				__( 'A contact email address is required.', 'newspack-newsletters' )
			);
		}

		$sdk      = $this->get_sdk();
		$email    = $contact['email'];
		$list_ids = $list_id ? [ $list_id ] : [];
		$existing = $sdk->get_subscriber( $email );
		$payload  = $this->build_subscriber_payload( $contact );

		if ( is_wp_error( $existing ) ) {
			// Treat any lookup failure as "not present" and create. MailPoet throws.
			// rather than returning null for an unknown subscriber.
			$payload['email'] = $email;
			$result           = $sdk->add_subscriber( $payload, $list_ids );
		} else {
			$result = $sdk->update_subscriber( $email, $payload );
			if ( ! is_wp_error( $result ) && ! empty( $list_ids ) ) {
				$subscribed = $sdk->subscribe_to_lists( $existing['id'], $list_ids );
				if ( is_wp_error( $subscribed ) ) {
					return $subscribed;
				}
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		unset( $this->contact_data[ $email ] );
		return $result;
	}

	/**
	 * Build a MailPoet subscriber payload from Newspack contact data.
	 *
	 * Newspack metadata keys are resolved to MailPoet custom field IDs, creating
	 * fields as needed — MailPoet silently drops writes to undeclared fields.
	 *
	 * @param array $contact Contact data.
	 *
	 * @return array
	 */
	private function build_subscriber_payload( $contact ) {
		$payload = [];

		if ( ! empty( $contact['name'] ) ) {
			$name                  = explode( ' ', $contact['name'], 2 );
			$payload['first_name'] = $name[0];
			$payload['last_name']  = $name[1] ?? '';
		}

		if ( ! empty( $contact['metadata'] ) && is_array( $contact['metadata'] ) ) {
			$metadata = array_filter(
				$contact['metadata'],
				function ( $value ) {
					return is_scalar( $value );
				}
			);
			if ( ! empty( $metadata ) ) {
				$field_ids = $this->get_sdk()->resolve_field_ids( array_keys( $metadata ) );
				if ( ! is_wp_error( $field_ids ) ) {
					foreach ( $field_ids as $key => $field_id ) {
						$payload[ $field_id ] = (string) $metadata[ $key ];
					}
				}
			}
		}

		return $payload;
	}

	/**
	 * Get contact data by email.
	 *
	 * @param string $email          Email address.
	 * @param bool   $return_details Whether to return the full payload.
	 *
	 * @return array|WP_Error
	 */
	public function get_contact_data( $email, $return_details = false ) {
		if ( isset( $this->contact_data[ $email ] ) ) {
			return $this->contact_data[ $email ];
		}
		$subscriber = $this->get_sdk()->get_subscriber( $email );
		if ( is_wp_error( $subscriber ) ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_contact_not_found',
				__( 'Contact not found.', 'newspack-newsletters' )
			);
		}
		$this->contact_data[ $email ] = $subscriber;
		return $subscriber;
	}

	/**
	 * Get the lists a contact is subscribed to.
	 *
	 * Mirrors the Mailchimp provider, which reports only lists with a
	 * `subscribed` status. A contact awaiting double opt-in reports no lists,
	 * because MailPoet will not mail them until they confirm.
	 *
	 * @param string $email The contact email.
	 *
	 * @return string[]
	 */
	public function get_contact_lists( $email ) {
		$contact = $this->get_contact_data( $email );
		if ( is_wp_error( $contact ) ) {
			return [];
		}
		// A subscriber who has not confirmed is not mailable, whatever the.
		// per-list subscription says.
		if ( 'subscribed' !== ( $contact['status'] ?? '' ) ) {
			return [];
		}
		$lists = [];
		foreach ( $contact['subscriptions'] ?? [] as $subscription ) {
			if ( 'subscribed' === ( $subscription['status'] ?? '' ) ) {
				$lists[] = (string) $subscription['segment_id'];
			}
		}
		return $lists;
	}

	/**
	 * Update a contact's list subscriptions.
	 *
	 * @param string   $email           Contact email address.
	 * @param string[] $lists_to_add    List IDs to subscribe to.
	 * @param string[] $lists_to_remove List IDs to remove from.
	 *
	 * @return true|WP_Error
	 */
	public function update_contact_lists( $email, $lists_to_add = [], $lists_to_remove = [] ) {
		$contact = $this->get_contact_data( $email );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$sdk = $this->get_sdk();

		if ( ! empty( $lists_to_add ) ) {
			$result = $sdk->subscribe_to_lists( $contact['id'], $lists_to_add );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( ! empty( $lists_to_remove ) ) {
			$result = $sdk->unsubscribe_from_lists( $contact['id'], $lists_to_remove );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		unset( $this->contact_data[ $email ] );
		return true;
	}

	// Tags.

	/**
	 * Retrieve a tag ID from its name.
	 *
	 * @param string  $tag_name            The tag name.
	 * @param boolean $create_if_not_found Whether to create the tag if absent.
	 * @param string  $list_id             Unused; MailPoet tags are global.
	 *
	 * @return int|WP_Error
	 */
	public function get_tag_id( $tag_name, $create_if_not_found = true, $list_id = null ) {
		$tag = $this->get_sdk()->get_tag( $tag_name );
		if ( ! is_wp_error( $tag ) && ! empty( $tag['id'] ) ) {
			return (int) $tag['id'];
		}
		if ( ! $create_if_not_found ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_tag_not_found',
				__( 'Tag not found.', 'newspack-newsletters' )
			);
		}
		$created = $this->create_tag( $tag_name );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return (int) $created['id'];
	}

	/**
	 * Retrieve a tag name from its ID.
	 *
	 * @param int    $tag_id  The tag ID.
	 * @param string $list_id Unused; MailPoet tags are global.
	 *
	 * @return string|WP_Error
	 */
	public function get_tag_by_id( $tag_id, $list_id = null ) {
		$tag = $this->get_sdk()->get_tag( $tag_id );
		if ( is_wp_error( $tag ) ) {
			return $tag;
		}
		return $tag['name'];
	}

	/**
	 * Create a tag.
	 *
	 * @param string $tag     The tag name.
	 * @param string $list_id Unused; MailPoet tags are global.
	 *
	 * @return array|WP_Error Tag representation with `id` and `name`.
	 */
	public function create_tag( $tag, $list_id = null ) {
		$created = $this->get_sdk()->add_tag( $tag );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return [
			'id'   => (int) $created['id'],
			'name' => $created['name'],
		];
	}

	/**
	 * Rename a tag.
	 *
	 * @param string|int $tag_id  The tag ID.
	 * @param string     $tag     The new tag name.
	 * @param string     $list_id Unused; MailPoet tags are global.
	 *
	 * @return array|WP_Error
	 */
	public function update_tag( $tag_id, $tag, $list_id = null ) {
		$updated = $this->get_sdk()->update_tag( $tag_id, $tag );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return [
			'id'   => (int) $updated['id'],
			'name' => $updated['name'],
		];
	}

	/**
	 * Add a tag to a contact.
	 *
	 * @param string     $email   The contact email.
	 * @param string|int $tag     The tag ID or name.
	 * @param string     $list_id Unused; MailPoet tags are global.
	 *
	 * @return true|WP_Error
	 */
	public function add_tag_to_contact( $email, $tag, $list_id = null ) {
		$result = $this->get_sdk()->tag_subscriber( $email, $tag );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		unset( $this->contact_data[ $email ] );
		return true;
	}

	/**
	 * Remove a tag from a contact.
	 *
	 * @param string     $email   The contact email.
	 * @param string|int $tag     The tag ID or name.
	 * @param string     $list_id Unused; MailPoet tags are global.
	 *
	 * @return true|WP_Error
	 */
	public function remove_tag_from_contact( $email, $tag, $list_id = null ) {
		$result = $this->get_sdk()->untag_subscriber( $email, $tag );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		unset( $this->contact_data[ $email ] );
		return true;
	}

	/**
	 * Get the IDs of the tags associated with a contact.
	 *
	 * @param string $email The contact email.
	 *
	 * @return array|WP_Error
	 */
	public function get_contact_tags_ids( $email ) {
		$contact = $this->get_contact_data( $email );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		return array_map(
			function ( $tag ) {
				return (int) $tag['tag_id'];
			},
			$contact['tags'] ?? []
		);
	}

	// Campaigns.
	//
	// MailPoet exposes no campaign API. Composing and sending are the subject of.
	// the architecture spikes on LEO-65; each branch replaces these.

	/**
	 * Error returned by the unimplemented campaign methods.
	 *
	 * @return WP_Error
	 */
	private function campaigns_not_supported() {
		return new WP_Error(
			'newspack_newsletters_mailpoet_campaigns_unsupported',
			__( 'Composing and sending newsletters is handled by MailPoet.', 'newspack-newsletters' )
		);
	}

	/**
	 * Set the list for a campaign.
	 *
	 * @param string $post_id Campaign ID.
	 * @param string $list_id List ID.
	 *
	 * @return WP_Error
	 */
	public function list( $post_id, $list_id ) {
		return $this->campaigns_not_supported();
	}

	/**
	 * Retrieve a campaign.
	 *
	 * Creates the MailPoet newsletter on first call, mirroring the other
	 * providers: the editor asks for campaign data as soon as a newsletter is
	 * opened, and that is when the campaign should come into existence.
	 *
	 * @param integer $post_id Numeric ID of the Newsletter post.
	 *
	 * @return array|WP_Error
	 */
	public function retrieve( $post_id ) {
		if ( ! Newspack_Newsletters_Mailpoet_Campaigns::is_available() ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_unavailable',
				__( 'MailPoet is not available.', 'newspack-newsletters' )
			);
		}

		$newsletter_id = (int) get_post_meta( $post_id, Newspack_Newsletters_Mailpoet_Campaigns::CAMPAIGN_ID_META, true );
		$newsletter    = Newspack_Newsletters_Mailpoet_Campaigns::find( $newsletter_id );

		if ( ! $newsletter ) {
			// Either never synced, or the newsletter was deleted in MailPoet. Drop
			// the stale ID so the sync creates a fresh one rather than failing.
			delete_post_meta( $post_id, Newspack_Newsletters_Mailpoet_Campaigns::CAMPAIGN_ID_META );
			$synced = $this->sync( get_post( $post_id ) );
			if ( is_wp_error( $synced ) ) {
				return $synced;
			}
			$newsletter_id = (int) get_post_meta( $post_id, Newspack_Newsletters_Mailpoet_Campaigns::CAMPAIGN_ID_META, true );
			$newsletter    = Newspack_Newsletters_Mailpoet_Campaigns::find( $newsletter_id );
		}

		if ( ! $newsletter ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_campaign_not_found',
				__( 'Could not find or create the MailPoet newsletter.', 'newspack-newsletters' )
			);
		}

		return [
			'campaign'    => Newspack_Newsletters_Mailpoet_Campaigns::to_array( $newsletter ),
			'campaign_id' => $newsletter_id,
			'link'        => Newspack_Newsletters_Mailpoet_Campaigns::get_edit_url( $newsletter_id ),
		];
	}

	/**
	 * Send a test email.
	 *
	 * @param integer $post_id Numeric ID of the Newsletter post.
	 * @param array   $emails  Array of email addresses.
	 *
	 * @return WP_Error
	 */
	public function test( $post_id, $emails ) {
		return $this->campaigns_not_supported();
	}

	/**
	 * Synchronize a post with its MailPoet newsletter.
	 *
	 * Creates the MailPoet newsletter on first sync and updates it thereafter,
	 * so a newsletter composed in the Newspack editor shows up in MailPoet as a
	 * draft ready to send.
	 *
	 * @param WP_Post $post Post to synchronize.
	 *
	 * @return int|WP_Error MailPoet newsletter ID on success.
	 */
	public function sync( $post ) {
		$transient_name = $this->get_transient_name( $post->ID );
		delete_transient( $transient_name );

		if ( ! Newspack_Newsletters_Mailpoet_Campaigns::is_available() ) {
			return new WP_Error(
				'newspack_newsletters_mailpoet_unavailable',
				__( 'MailPoet is not available.', 'newspack-newsletters' )
			);
		}

		if ( empty( $post->post_title ) ) {
			$error = new WP_Error(
				'newspack_newsletters_mailpoet_no_subject',
				__( 'The newsletter subject cannot be empty.', 'newspack-newsletters' )
			);
			set_transient( $transient_name, $error->get_error_message(), 45 );
			return $error;
		}

		$result = Newspack_Newsletters_Mailpoet_Campaigns::upsert(
			$post,
			[
				'subject'       => $post->post_title,
				'campaign_name' => $this->get_campaign_name( $post ),
				'sender_name'   => get_post_meta( $post->ID, 'senderName', true ),
				'sender_email'  => get_post_meta( $post->ID, 'senderEmail', true ),
				'html'          => get_post_meta( $post->ID, Newspack_Newsletters::EMAIL_HTML_META, true ),
			]
		);

		if ( is_wp_error( $result ) ) {
			set_transient( $transient_name, 'MailPoet sync error: ' . $result->get_error_message(), 45 );
		}

		return $result;
	}

	/**
	 * Update the MailPoet newsletter after the email HTML post meta is saved.
	 *
	 * Guards mirror the other providers: skip autosaves, only react to the email
	 * HTML meta, never sync a layout post, and never sync a trashed one.
	 *
	 * @param int   $meta_id  Numeric ID of the meta field being updated.
	 * @param int   $post_id  The post ID for the meta field being updated.
	 * @param mixed $meta_key The meta key being updated.
	 *
	 * @return void
	 */
	public function save( $meta_id, $post_id, $meta_key ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( Newspack_Newsletters::EMAIL_HTML_META !== $meta_key ) {
			return;
		}
		if ( $this->is_layout_post( $post_id ) ) {
			return;
		}
		if ( ! Newspack_Newsletters_Editor::is_editing_email( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status ) {
			return;
		}
		$this->sync( $post );
	}

	/**
	 * Send a campaign.
	 *
	 * Required by Newspack_Newsletters_WP_Hookable_Interface, and reached from
	 * the base class on publish. Returning an error keeps a stray publish from
	 * looking like a successful send.
	 *
	 * @param \WP_Post $post Post to send.
	 *
	 * @return WP_Error
	 */
	public function send( $post ) {
		return $this->campaigns_not_supported();
	}

	/**
	 * Clean up the MailPoet newsletter after a Newsletter post is deleted.
	 *
	 * @param string $post_id Numeric ID of the campaign.
	 *
	 * @return void
	 */
	public function trash( $post_id ) {
		Newspack_Newsletters_Mailpoet_Campaigns::delete_for_post( $post_id );
	}

	// Reporting.

	/**
	 * Get the usage report for yesterday.
	 *
	 * MailPoet's public API exposes only a subscriber count. The remaining
	 * figures live in its `mailpoet_statistics_*` tables, which carry no
	 * compatibility promise, so the report is deliberately left for follow-up
	 * work rather than reaching into them here. See LEO-65.
	 *
	 * @return WP_Error
	 */
	public function get_usage_report() {
		return new WP_Error(
			'newspack_newsletters_mailpoet_usage_report_unsupported',
			__( 'Usage reports are not yet available for MailPoet.', 'newspack-newsletters' )
		);
	}
}
