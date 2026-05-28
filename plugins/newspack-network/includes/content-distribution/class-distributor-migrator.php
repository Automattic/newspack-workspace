<?php
/**
 * Newspack Network Content Distribution Distributor Migrator.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution;
use Newspack\Data_Events;
use Newspack_Network\Utils\Network;
use WP_Error;
use WP_CLI;
use InvalidArgumentException;

/**
 * Distributor Migrator Class.
 */
class Distributor_Migrator {

	const MIGRATION_LOCK_TRANSIENT_NAME = 'newspack_network_distributor_migration_lock';

	const MIGRATION_DATA_META = '_newspack_network_distributor_migration_data';

	/**
	 * Log indentation level.
	 *
	 * @var int
	 */
	private static $log_indentation = 0;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_data_event_actions' ] );
		add_filter( 'map_meta_cap', [ __CLASS__, 'filter_migration_lock_cap' ], 10, 4 );
		add_action( 'admin_notices', [ __CLASS__, 'migration_lock_notice' ] );
	}

	/**
	 * Log a message.
	 *
	 * @param string $message The message to log.
	 */
	public static function log( $message ) {
		$message = str_repeat( '  ', self::$log_indentation ) . $message;
		if ( defined( 'WP_CLI' ) ) {
			WP_CLI::log( $message );
		} elseif ( method_exists( 'Newspack\Logger', 'log' ) ) {
			\Newspack\Logger::log( $message );
		}
	}

	/**
	 * Register data event actions.
	 */
	public static function register_data_event_actions() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		Data_Events::register_action( 'newspack_network_distributor_migrate_incoming_posts' );
	}

	/**
	 * Filter capabilities to implement the migration lock.
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id The user ID.
	 * @param array    $args    Adds context to the capability check, typically
	 *                          starting with an object ID.
	 *
	 * @return string[] Primitive capabilities required of the user.
	 */
	public static function filter_migration_lock_cap( $caps, $cap, $user_id, $args ) {
		if ( 'edit_post' !== $cap ) {
			return $caps;
		}

		$locked = get_transient( self::MIGRATION_LOCK_TRANSIENT_NAME );
		if ( ! $locked ) {
			return $caps;
		}

		$post_id = $args[0];
		if ( ! Content_Distribution::is_post_distributed( $post_id ) ) {
			return $caps;
		}

		$caps[] = 'do_not_allow';

		return $caps;
	}

	/**
	 * Render the migration lock notice.
	 */
	public static function migration_lock_notice() {
		$locked = get_transient( self::MIGRATION_LOCK_TRANSIENT_NAME );
		if ( ! $locked ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p><?php _e( 'Editing distributed posts is temporarily disabled due to a recent migration. Editing should be restored in a few minutes.', 'newspack-network' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Get a network site URL from a given URL.
	 *
	 * @param string $url The URL to match.
	 *
	 * @return string|false The network site URL, or false if not found.
	 */
	protected static function get_network_url( $url ) {
		$network_urls = Network::get_networked_urls();
		$network_url  = array_filter(
			$network_urls,
			function( $network_url ) use ( $url ) {
				return false !== strpos( $url, $network_url );
			}
		);
		$network_url  = array_shift( $network_url );
		return $network_url ? $network_url : false;
	}

	/**
	 * Migrate an incoming post.
	 *
	 * @param int $post_id The ID of the post to migrate.
	 *
	 * @return WP_Error|void WP_Error on failure, void on success.
	 */
	public static function migrate_incoming_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', __( 'Post not found.', 'newspack-network' ) );
		}

		$original_post_id = get_post_meta( $post_id, 'dt_original_post_id', true );
		if ( ! $original_post_id ) {
			return new WP_Error( 'original_post_id_not_found', __( 'Original post ID not found.', 'newspack-network' ) );
		}

		$original_site_url = get_post_meta( $post_id, 'dt_original_site_url', true );
		if ( ! $original_site_url ) {
			return new WP_Error( 'original_site_url_not_found', __( 'Original site URL not found.', 'newspack-network' ) );
		}

		$network_url = self::get_network_url( $original_site_url );
		if ( empty( $network_url ) ) {
			return new WP_Error(
				'site_url_not_networked',
				sprintf(
					// translators: original site URL.
					__( 'Site URL "%s" is not networked.', 'newspack-network' ),
					$original_site_url
				)
			);
		}

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

		// Instantiate an Outgoing_Post to configure its origin.
		$outgoing_post = new Outgoing_Post( $post_id );
		$payload       = $outgoing_post->get_payload( $post->post_status );

		// Modify payload to match the origin.
		$payload['site_url']        = $network_url;
		$payload['post_id']         = $original_post_id;
		$payload['post_url']        = get_post_meta( $post_id, 'dt_original_post_url', true );
		$payload['sites']           = [ get_bloginfo( 'url' ) ]; // This can contain other sites, but we just care about the current site at this moment.
		$payload['network_post_id'] = md5( md5( $network_url ) . $original_post_id );

		// Delete Distributor meta from the payload.
		foreach ( $distributor_meta as $meta_key ) {
			unset( $payload['post_data']['post_meta'][ $meta_key ] );
		}

		// Store payload for insertion.
		update_post_meta( $post_id, Incoming_Post::PAYLOAD_META, $payload );

		try {
			$incoming_post = new Incoming_Post( $post_id );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'incoming_post_error', $e->getMessage() );
		}

		// Match the unlinked state.
		if ( get_post_meta( $post_id, 'dt_unlinked', true ) ) {
			$incoming_post->set_unlinked();
		}

		// Insert the incoming post.
		$insert = $incoming_post->insert();
		if ( is_wp_error( $insert ) ) {
			return $insert;
		}

		// Delete Distributor meta from the post.
		foreach ( $distributor_meta as $meta_key ) {
			delete_post_meta( $post_id, $meta_key );
		}
	}

	/**
	 * Get all Distributor subscriptions.
	 *
	 * @return int[] Array of Distributor subscription IDs.
	 */
	public static function get_distributor_subscriptions() {
		return get_posts(
			[
				'post_type'      => 'dt_subscription',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);
	}

	/**
	 * Validate whether a post can be migrated.
	 *
	 * @param int $post_id The ID of the post to check.
	 *
	 * @return true|WP_Error True if the post can be migrated, WP_Error on failure.
	 */
	public static function can_migrate_outgoing_post( $post_id ) {
		$connection_map = get_post_meta( $post_id, 'dt_connection_map', true );
		if ( ! $connection_map || empty( $connection_map['external'] ) ) {
			return new WP_Error( 'no_connection_map', __( 'No connections found.', 'newspack-network' ) );
		}

		if ( ! empty( $connection_map['internal'] ) ) {
			return new WP_Error( 'internal_connection', __( 'This post contains internal connections, which are not supported.', 'newspack-network' ) );
		}

		$subscriptions = get_post_meta( $post_id, 'dt_subscriptions', true );
		if ( ! $subscriptions ) {
			return new WP_Error( 'subscriptions_not_found', __( 'Subscriptions not found.', 'newspack-network' ) );
		}

		foreach ( $subscriptions as $subscription_id ) { // phpcs:ignore WordPressVIPMinimum.Functions.CheckReturnValue.NonCheckedVariable
			$can_migrate_subscription = self::can_migrate_subscription( $subscription_id );
			if ( is_wp_error( $can_migrate_subscription ) ) {
				return $can_migrate_subscription;
			}
		}

		return true;
	}

	/**
	 * Dispatch the migration of the incoming posts.
	 *
	 * @param array $incoming_posts Array of incoming posts to migrate.
	 */
	protected static function dispatch_incoming_posts_migration( $incoming_posts ) {
		Data_Events::dispatch(
			'newspack_network_distributor_migrate_incoming_posts',
			[ 'incoming_posts' => $incoming_posts ]
		);
		// Lock the editing of the migrated posts for 10 minutes.
		set_transient( self::MIGRATION_LOCK_TRANSIENT_NAME, 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Migrate batch of posts from Distributor to Newspack Network Content Distribution.
	 *
	 * @param int[] $post_ids The IDs of the posts to migrate.
	 *
	 * @return WP_Error|void WP_Error on failure, void on success.
	 */
	public static function migrate_outgoing_posts( $post_ids ) {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return new WP_Error( 'data_events_not_found', __( 'Data Events not found.', 'newspack-network' ) );
		}

		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return new WP_Error( 'invalid_post_ids', __( 'Invalid post IDs.', 'newspack-network' ) );
		}

		$incoming_posts = [];

		$errors = new WP_Error();
		foreach ( $post_ids as $post_id ) {
			$subscriptions = get_post_meta( $post_id, 'dt_subscriptions', true );
			if ( empty( $subscriptions ) || ! is_array( $subscriptions ) ) {
				$errors->add(
					'no_subscriptions',
					sprintf(
						// translators: post ID.
						__( 'No subscriptions found for post %d.', 'newspack-network' ),
						$post_id
					)
				);
				continue;
			}
			foreach ( $subscriptions as $subscription_id ) { // phpcs:ignore WordPressVIPMinimum.Functions.CheckReturnValue.NonCheckedVariable
				$remote_post_id = get_post_meta( $subscription_id, 'dt_subscription_remote_post_id', true );
				$site_url       = get_post_meta( $subscription_id, 'dt_subscription_target_url', true );
				$migration_result = self::migrate_subscription( $subscription_id, false );
				if ( is_wp_error( $migration_result ) ) {
					$errors->add( $migration_result->get_error_code(), $migration_result->get_error_message() );
					continue;
				}
				$incoming_posts[] = [
					'site_url' => self::get_network_url( $site_url ),
					'post_id'  => $remote_post_id,
				];
			}
		}

		if ( ! empty( $incoming_posts ) ) {
			self::dispatch_incoming_posts_migration( $incoming_posts );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}
	}

	/**
	 * Migrate a post from Distributor to Newspack Network Content Distribution.
	 *
	 * @param int  $post_id                The ID of the post to migrate.
	 * @param bool $migrate_incoming_posts Whether to migrate incoming posts.
	 *
	 * @return Outgoing_Post|WP_Error Outgoing_Post on success, WP_Error on failure.
	 */
	public static function migrate_outgoing_post( $post_id, $migrate_incoming_posts = true ) {
		$can_migrate = self::can_migrate_outgoing_post( $post_id );
		if ( is_wp_error( $can_migrate ) ) {
			return $can_migrate;
		}

		$subscriptions = get_post_meta( $post_id, 'dt_subscriptions', true );

		$outgoing_post = null;
		foreach ( $subscriptions as $subscription_id ) { // phpcs:ignore WordPressVIPMinimum.Functions.CheckReturnValue.NonCheckedVariable
			$migration_result = self::migrate_subscription( $subscription_id, $migrate_incoming_posts );
			if ( is_wp_error( $migration_result ) ) {
				return $migration_result;
			}
			$outgoing_post = $migration_result;
		}

		return $outgoing_post;
	}

	/**
	 * Validate whether a subscription can be migrated.
	 *
	 * @param int $subscription_id The ID of the subscription to check.
	 *
	 * @return true|WP_Error True if the subscription can be migrated, WP_Error on failure.
	 */
	public static function can_migrate_subscription( $subscription_id ) {
		$subscription = get_post( $subscription_id );
		if ( ! $subscription ) {
			return new WP_Error( 'subscription_not_found', __( 'Subscription not found.', 'newspack-network' ) );
		}

		$post_id = get_post_meta( $subscription_id, 'dt_subscription_post_id', true );
		$post    = get_post( $post_id );
		if ( ! $post_id || ! $post || empty( $post->ID ) ) {
			return new WP_Error( 'post_not_found', __( 'Post not found.', 'newspack-network' ) );
		}

		$target_url = get_post_meta( $subscription_id, 'dt_subscription_target_url', true );
		if ( ! $target_url ) {
			return new WP_Error( 'target_url_not_found', __( 'Target URL not found.', 'newspack-network' ) );
		}

		$network_url = self::get_network_url( $target_url );
		if ( empty( $network_url ) ) {
			return new WP_Error(
				'target_url_not_networked',
				sprintf(
					// translators: target URL.
					__( 'Target URL "%s" is not networked.', 'newspack-network' ),
					$target_url
				)
			);
		}

		return true;
	}

	/**
	 * Migrate subscriptions from Distributor to Newspack Network Content Distribution.
	 *
	 * @param int[] $subscription_ids The IDs of the subscriptions to migrate.
	 *
	 * @return WP_Error|void WP_Error on failure, void on success.
	 */
	public static function migrate_subscriptions( $subscription_ids ) {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return new WP_Error( 'data_events_not_found', __( 'Data Events not found.', 'newspack-network' ) );
		}

		if ( empty( $subscription_ids ) || ! is_array( $subscription_ids ) ) {
			return new WP_Error( 'invalid_subscription_ids', __( 'Invalid subscription IDs.', 'newspack-network' ) );
		}

		$incoming_posts = [];

		$errors = new WP_Error();
		foreach ( $subscription_ids as $subscription_id ) {
			self::$log_indentation++;
			self::log( sprintf( 'Migrating subscription %d.', $subscription_id ) );
			$remote_post_id = get_post_meta( $subscription_id, 'dt_subscription_remote_post_id', true );
			$site_url       = get_post_meta( $subscription_id, 'dt_subscription_target_url', true );
			self::$log_indentation++;
			$migration_result = self::migrate_subscription( $subscription_id, false );
			--self::$log_indentation;
			if ( is_wp_error( $migration_result ) ) {
				self::log( sprintf( 'Error migrating subscription %d (post: %d, target: %s): %s.', $subscription_id, $remote_post_id, $site_url, $migration_result->get_error_message() ) );
				$errors->add( $migration_result->get_error_code(), $migration_result->get_error_message() );
			} else {
				$site_url = self::get_network_url( $site_url );
				self::log( sprintf( 'Migrated subscription %d for remote post %d on %s.', $subscription_id, $remote_post_id, $site_url ) );
				$incoming_posts[] = [
					'site_url' => $site_url,
					'post_id'  => $remote_post_id,
				];
			}
			--self::$log_indentation;
		}

		if ( ! empty( $incoming_posts ) ) {
			self::log( sprintf( 'Dispatching incoming posts migration for %d posts.', count( $incoming_posts ) ) );
			self::dispatch_incoming_posts_migration( $incoming_posts );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}
	}

	/**
	 * Migrate a post subscription from Distributor to Newspack Network Content Distribution.
	 *
	 * @param int  $subscription_id       The ID of the subscription to migrate.
	 * @param bool $migrate_incoming_post Whether to migrate incoming post.
	 *
	 * @return Outgoing_Post|WP_Error Outgoing_Post on success, WP_Error on failure.
	 */
	public static function migrate_subscription( $subscription_id, $migrate_incoming_post = true ) {
		$can_migrate = self::can_migrate_subscription( $subscription_id );
		if ( is_wp_error( $can_migrate ) ) {
			return $can_migrate;
		}

		$post_id = get_post_meta( $subscription_id, 'dt_subscription_post_id', true );
		$remote_post_id = get_post_meta( $subscription_id, 'dt_subscription_remote_post_id', true );
		$target_url = get_post_meta( $subscription_id, 'dt_subscription_target_url', true );
		$network_url = self::get_network_url( $target_url );

		// Configure distribution.
		try {
			$outgoing_post = new Outgoing_Post( $post_id );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'outgoing_post_error', $e->getMessage() );
		}
		$distribution = $outgoing_post->set_distribution( [ $network_url ] );
		if (
			is_wp_error( $distribution ) &&
			// Ignore error if the post is already distributed.
			'update_failed' !== $distribution->get_error_code()
		) {
			return $distribution;
		}
		self::log( sprintf( 'Set distribution for post %d to %s.', $post_id, $network_url ) );

		// Clear the subscription meta from the post.
		$subscriptions = get_post_meta( $post_id, 'dt_subscriptions', true );
		if ( empty( $subscriptions ) ) {
			self::log( sprintf( 'No subscription meta found for post %d.', $post_id ) );
		} else {
				$subscriptions = array_diff( $subscriptions, [ $subscription_id ] );
			if ( empty( $subscriptions ) ) {
				delete_post_meta( $post_id, 'dt_subscriptions' );
				self::log( sprintf( 'Deleted subscriptions for post %d.', $post_id ) );
			} else {
				update_post_meta( $post_id, 'dt_subscriptions', $subscriptions );
				self::log( sprintf( 'Updated subscriptions for post %d.', $post_id ) );
			}
		}

		// Clear the connection map from the post.
		$connection_map = get_post_meta( $post_id, 'dt_connection_map', true );
		if ( empty( $connection_map ) ) {
			self::log( sprintf( 'No connection map meta found for post %d.', $post_id ) );
		} else {
			if ( ! empty( $connection_map['external'] ) ) {
				foreach ( $connection_map['external'] as $connection_id => $value ) {
					if ( absint( $value['post_id'] ) === absint( $remote_post_id ) ) {
						unset( $connection_map['external'][ $connection_id ] );
					}
				}
			}
			if ( empty( $connection_map['external'] ) && empty( $connection_map['internal'] ) ) {
				delete_post_meta( $post_id, 'dt_connection_map' );
				self::log( sprintf( 'Deleted connection map for post %d.', $post_id ) );
			} else {
				update_post_meta( $post_id, 'dt_connection_map', $connection_map );
				self::log( sprintf( 'Updated connection map for post %d.', $post_id ) );
			}
		}

		// Delete the subscription post.
		wp_delete_post( $subscription_id );
		self::log( sprintf( 'Deleted subscription %d.', $subscription_id ) );

		// Add migration data to the post.
		add_post_meta(
			$post_id,
			self::MIGRATION_DATA_META,
			[
				'timestamp'       => time(),
				'subscription_id' => $subscription_id,
				'remote_post_id'  => $remote_post_id,
				'target_url'      => $target_url,
			]
		);

		if ( $migrate_incoming_post ) {
			self::dispatch_incoming_posts_migration(
				[
					[
						'site_url' => $network_url,
						'post_id'  => $remote_post_id,
					],
				]
			);
		}

		return $outgoing_post;
	}
}
