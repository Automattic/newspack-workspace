<?php
/**
 * Newspack Network Content Distribution.
 *
 * @package Newspack
 */

namespace Newspack_Network;

use Newspack\Data_Events;
use Newspack_Network\Content_Distribution\Admin;
use Newspack_Network\Content_Distribution\API;
use Newspack_Network\Content_Distribution\Canonical_Url;
use Newspack_Network\Content_Distribution\Cap_Authors;
use Newspack_Network\Content_Distribution\CLI;
use Newspack_Network\Content_Distribution\Distributor_Migrator;
use Newspack_Network\Content_Distribution\Editor;
use Newspack_Network\Content_Distribution\Incoming_Post;
use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Content_Distribution\Yoast_Primary_Cat;
use Newspack_Network\Content_Distribution\Blocks;
use WP_Post;

/**
 * Main class for content distribution
 */
class Content_Distribution {

	const PAYLOAD_HASH_META = '_newspack_network_payload_hash';

	/**
	 * Queued network post updates.
	 *
	 * @var array Post IDs to update.
	 */
	private static $queued_distributions = [];

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_data_event_actions' ] );
		add_action( 'shutdown', [ __CLASS__, 'distribute_queued_posts' ] );
		add_filter( 'newspack_webhooks_request_priority', [ __CLASS__, 'webhooks_request_priority' ], 10, 2 );
		add_filter( 'update_post_metadata', [ __CLASS__, 'maybe_short_circuit_distributed_meta' ], 10, 4 );
		add_action( 'wp_after_insert_post', [ __CLASS__, 'handle_post_updated' ] );
		add_action( 'set_object_terms', [ __CLASS__, 'handle_post_updated' ] );
		add_action( 'updated_post_meta', [ __CLASS__, 'handle_postmeta_update' ], 10, 3 );
		add_action( 'added_post_meta', [ __CLASS__, 'handle_postmeta_update' ], 10, 3 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'handle_postmeta_update' ], 10, 3 );
		add_action( 'before_delete_post', [ __CLASS__, 'handle_outgoing_post_deleted' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'handle_incoming_post_deleted' ] );
		add_action( 'newspack_network_incoming_post_inserted', [ __CLASS__, 'handle_incoming_post_inserted' ], 10, 3 );

		Admin::init();
		CLI::init();
		API::init();
		Editor::init();
		Canonical_Url::init();
		Distributor_Migrator::init();
		Cap_Authors::init();
		Yoast_Primary_Cat::init();
		Blocks::init();
	}

	/**
	 * Register the data event actions for content distribution.
	 *
	 * @return void
	 */
	public static function register_data_event_actions() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		Data_Events::register_action( 'network_post_updated' );
		Data_Events::register_action( 'network_post_deleted' );
		Data_Events::register_action( 'network_incoming_post_inserted' );
		Data_Events::register_action( 'network_incoming_post_deleted' );
	}

	/**
	 * Queue post distribution to run on PHP shutdown.
	 *
	 * @param int         $post_id       The post ID.
	 * @param null|string $post_data_key The post data key to update.
	 *                                   Default is null (entire post payload).
	 *
	 * @return void
	 */
	public static function queue_post_distribution( $post_id, $post_data_key = null ) {
		// Bail if the post is already queued for a full update.
		if ( isset( self::$queued_distributions[ $post_id ] ) && self::$queued_distributions[ $post_id ] === true ) {
			return;
		}

		// Queue for a full post update.
		if ( empty( $post_data_key ) ) {
			self::$queued_distributions[ $post_id ] = true;
			return;
		}

		// Queue for a partial update.
		if ( ! isset( self::$queued_distributions[ $post_id ] ) ) {
			self::$queued_distributions[ $post_id ] = [];
		}
		self::$queued_distributions[ $post_id ][] = $post_data_key;
	}

	/**
	 * Get queued post distributions.
	 */
	public static function get_queued_distributions() {
		return self::$queued_distributions;
	}

	/**
	 * Distribute queued posts.
	 */
	public static function distribute_queued_posts() {
		if ( empty( self::$queued_distributions ) ) {
			return;
		}
		foreach ( self::$queued_distributions as $post_id => $post_data_keys ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			if ( is_array( $post_data_keys ) ) {
				self::distribute_post_partial( $post, array_unique( $post_data_keys ) );
			} else {
				self::distribute_post( $post );
			}
		}
		self::$queued_distributions = [];
	}

	/**
	 * Filter the webhooks request priority so `network_post_updated` and
	 * `network_post_deleted` are prioritized.
	 *
	 * @param int    $priority    The request priority.
	 * @param string $action_name The action name.
	 *
	 * @return int The request priority.
	 */
	public static function webhooks_request_priority( $priority, $action_name ) {
		if ( in_array( $action_name, [ 'network_post_updated', 'network_post_deleted' ], true ) ) {
			return 1;
		}
		return $priority;
	}

	/**
	 * Validate whether an update to DISTRIBUTED_POST_META is allowed.
	 *
	 * @param null|bool $check      Whether to allow updating metadata for the given type. Default null.
	 * @param int       $object_id  Object ID.
	 * @param string    $meta_key   Meta key.
	 * @param mixed     $meta_value Metadata value.
	 */
	public static function maybe_short_circuit_distributed_meta( $check, $object_id, $meta_key, $meta_value ) {
		if ( Outgoing_Post::DISTRIBUTED_POST_META !== $meta_key ) {
			return $check;
		}

		// Ignore unchanged values.
		$current_value = get_post_meta( $object_id, $meta_key, true );
		if ( $current_value === $meta_value || ( empty( $current_value ) && empty( $meta_value ) ) ) {
			return $check;
		}

		if ( ! is_array( $meta_value ) ) {
			return false;
		}

		// Ensure the post type can be distributed.
		$post_types = self::get_distributed_post_types();
		if ( ! in_array( get_post_type( $object_id ), $post_types, true ) ) {
			return false;
		}

		if ( is_wp_error( Outgoing_Post::validate_distribution( $meta_value ) ) ) {
			return false;
		}

		// Manage removing existing distributions.
		$diff = array_values( array_diff( empty( $current_value ) ? [] : $current_value, $meta_value ) );
		if ( ! empty( $diff ) ) {
			if ( 1 < count( $diff ) ) {
				return false;
			}

			// Ensure there's an intention to remove the distribution.
			$removing = get_post_meta( $object_id, 'newspack_network_remove_distribution', true );
			delete_post_meta( $object_id, 'newspack_network_remove_distribution' ); // Remove deletion meta after reading.

			if ( ! $removing || $removing !== $diff[0] ) {
				return false;
			}
		}

		return $check;
	}

	/**
	 * Distribute post on postmeta update.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Meta key.
	 *
	 * @return void
	 */
	public static function handle_postmeta_update( $meta_id, $object_id, $meta_key ) {
		if ( ! $object_id ) {
			return;
		}
		$post = get_post( $object_id );
		if ( ! $post ) {
			return;
		}
		if ( ! self::is_post_distributed( $post ) ) {
			return;
		}
		// Skip ignored keys but run if the meta is setting the distribution.
		if (
			Outgoing_Post::DISTRIBUTED_POST_META !== $meta_key &&
			in_array( $meta_key, self::get_ignored_post_meta_keys(), true )
		) {
			return;
		}
		self::queue_post_distribution( $post->ID, 'post_meta' );
	}

	/**
	 * Distribute post on post updated.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 *
	 * @return void
	 */
	public static function handle_post_updated( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		if ( ! self::is_post_distributed( $post ) ) {
			return;
		}
		self::queue_post_distribution( $post->ID );
	}

	/**
	 * Distribute post deletion.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return void
	 */
	public static function handle_outgoing_post_deleted( $post_id ) {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		$post = self::get_distributed_post( $post_id );
		if ( ! $post ) {
			return;
		}
		Data_Events::dispatch( 'network_post_deleted', $post->get_payload() );
	}

	/**
	 * Incoming post inserted listener callback.
	 *
	 * @param int     $post_id      The post ID.
	 * @param boolean $is_linked    Whether the post is unlinked.
	 * @param array   $post_payload The post payload.
	 */
	public static function handle_incoming_post_inserted( $post_id, $is_linked, $post_payload ) {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		$data = [
			'network_post_id' => $post_payload['network_post_id'],
			'outgoing'        => [
				'site_url' => $post_payload['site_url'],
				'post_id'  => $post_payload['post_id'],
				'post_url' => $post_payload['post_url'],
			],
			'incoming'        => [
				'site_url'  => get_bloginfo( 'url' ),
				'post_id'   => $post_id,
				'post_url'  => get_permalink( $post_id ),
				'is_linked' => $is_linked,
			],
		];
		Data_Events::dispatch( 'network_incoming_post_inserted', $data );
	}

	/**
	 * Handle incoming post deletion.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return void
	 */
	public static function handle_incoming_post_deleted( $post_id ) {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		if ( ! self::is_post_incoming( $post_id ) ) {
			return;
		}
		$incoming_post = new Incoming_Post( $post_id );
		$payload       = $incoming_post->get_post_payload();
		if ( empty( $payload ) ) {
			return;
		}
		$data = [
			'network_post_id' => $payload['network_post_id'],
			'outgoing'        => [
				'site_url' => $payload['site_url'],
				'post_id'  => $payload['post_id'],
				'post_url' => $payload['post_url'],
			],
			'incoming'        => [
				'site_url'  => get_bloginfo( 'url' ),
				'post_id'   => $post_id,
				'post_url'  => get_permalink( $post_id ),
				'is_linked' => $incoming_post->is_linked(),
			],
		];
		Data_Events::dispatch( 'network_incoming_post_deleted', $data );
	}

	/**
	 * Get the post types that are allowed to be distributed across the network.
	 *
	 * @return array Array of post types.
	 */
	public static function get_distributed_post_types() {
		/**
		 * Filters the post types that are allowed to be distributed across the network.
		 *
		 * @param array $post_types Array of post types.
		 */
		return apply_filters(
			'newspack_network_distributed_post_types',
			[
				'post',
				'page',
				'newspack_lst_event',
				'newspack_lst_generic',
				'newspack_lst_mktplce',
				'newspack_lst_place',
			]
		);
	}

	/**
	 * Get post meta keys that should be ignored on content distribution.
	 *
	 * When generating the payload for content distribution, post meta with these
	 * keys will not be included.
	 *
	 * Upon sync, these keys will also be ignored when updating the post meta,
	 * so they'll never be deleted or updated.
	 *
	 * @return string[] The ignored post meta keys.
	 */
	public static function get_ignored_post_meta_keys() {
		$ignored_keys = [
			'_edit_lock',
			'_edit_last',
			'_thumbnail_id',
			'_yoast_wpseo_primary_category',
			'_pingme',
			'_encloseme',
		];

		/**
		 * Filters the ignored post meta keys that should not be distributed.
		 *
		 * @param string[] $ignored_keys The ignored post meta keys.
		 * @param WP_Post  $post         The post object.
		 */
		$ignored_keys = apply_filters( 'newspack_network_content_distribution_ignored_post_meta_keys', $ignored_keys );

		// Always ignore Distributor meta.
		$distributor_meta = [
			'dt_full_connection',
			'dt_original_post_id',
			'dt_original_post_url',
			'dt_original_site_name',
			'dt_original_site_url',
			'dt_original_source_id',
			'dt_subscription_signature',
			'dt_syndicate_time',
			'dt_unlinked',
			'dt_subscriptions',
			'dt_subscription_update',
			'dt_connection_map',
		];
		$ignored_keys = array_merge( $ignored_keys, $distributor_meta );

		// Always ignore content distribution post meta.
		return array_merge(
			$ignored_keys,
			[
				self::PAYLOAD_HASH_META,
				Outgoing_Post::DISTRIBUTED_POST_META,
				Incoming_Post::NETWORK_POST_ID_META,
				Incoming_Post::PAYLOAD_META,
				Incoming_Post::UNLINKED_META,
				Incoming_Post::ATTACHMENT_META,
				Incoming_Post::STATUS_ON_PUBLISH_META,
				Distributor_Migrator::MIGRATION_DATA_META,
			]
		);
	}

	/**
	 * Whether a given post is distributed.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 *
	 * @return bool Whether the post is distributed.
	 */
	public static function is_post_distributed( $post ) {
		return (bool) self::get_distributed_post( $post );
	}

	/**
	 * Whether a given post is an incoming post. This will also return true if
	 * the post is unlinked.
	 *
	 * Since the Incoming_Post object queries the post by post meta on
	 * instantiation, this method is more efficient for checking if a post is
	 * incoming.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 *
	 * @return bool Whether the post is an incoming post.
	 */
	public static function is_post_incoming( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		return (bool) get_post_meta( $post->ID, Incoming_Post::PAYLOAD_META, true );
	}

	/**
	 * Get a distributed post.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 *
	 * @return Outgoing_Post|null The distributed post or null if not found, or we couldn't create one.
	 */
	public static function get_distributed_post( $post ) {
		try {
			$outgoing_post = new Outgoing_Post( $post );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
		return $outgoing_post->is_distributed() ? $outgoing_post : null;
	}

	/**
	 * Trigger post distribution.
	 *
	 * @param WP_Post|Outgoing_Post|int $post              The post object or ID.
	 * @param string                    $status_on_publish The post status on publish. Default is draft.
	 *
	 * @return void
	 */
	public static function distribute_post( $post, $status_on_publish = 'draft' ) {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		if ( $post instanceof Outgoing_Post ) {
			$distributed_post = $post;
		} else {
			$distributed_post = self::get_distributed_post( $post );
		}
		if ( $distributed_post ) {
			$payload      = $distributed_post->get_payload( $status_on_publish );
			$payload_hash = $distributed_post->get_payload_hash( $payload );
			$post         = $distributed_post->get_post();
			// Skip if the payload hash is the same.
			if ( get_post_meta( $post->ID, self::PAYLOAD_HASH_META, true ) === $payload_hash ) {
				return;
			}
			Data_Events::dispatch( 'network_post_updated', $payload );
			// Store payload hash to prevent unnecessary updates.
			update_post_meta( $post->ID, self::PAYLOAD_HASH_META, $payload_hash );
		}
	}

	/**
	 * Trigger a partial post distribution.
	 *
	 * @param WP_Post|Outgoing_Post|int $post           The post object or ID.
	 * @param string[]                  $post_data_keys The post data keys to update.
	 *
	 * @return void|WP_Error The error if the payload is invalid.
	 */
	public static function distribute_post_partial( $post, $post_data_keys ) {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		if ( is_string( $post_data_keys ) ) {
			$post_data_keys = [ $post_data_keys ];
		}
		if ( $post instanceof Outgoing_Post ) {
			$distributed_post = $post;
		} else {
			$distributed_post = self::get_distributed_post( $post );
		}
		if ( $distributed_post ) {
			$payload_hash = $distributed_post->get_payload_hash();
			$post         = $distributed_post->get_post();
			// Skip if the payload hash is the same.
			if ( get_post_meta( $post->ID, self::PAYLOAD_HASH_META, true ) === $payload_hash ) {
				return;
			}
			$payload = $distributed_post->get_partial_payload( $post_data_keys );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
			Data_Events::dispatch( 'network_post_updated', $payload );
			// Store payload hash to prevent unnecessary updates.
			update_post_meta( $post->ID, self::PAYLOAD_HASH_META, $payload_hash );
		}
	}
}
