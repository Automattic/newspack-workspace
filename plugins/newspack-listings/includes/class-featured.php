<?php
/**
 * Newspack Listings - Infrastructure for featured listings.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use Newspack_Listings\Core;
use Newspack_Listings\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Products class.
 * Sets up WooCommerce products for listings.
 */
final class Featured {
	/**
	 * The meta keys for featured listing meta.
	 */
	const META_KEYS = [
		'featured' => 'newspack_listings_featured',
		'expires'  => 'newspack_listings_featured_expires',
	];

	/**
	 * Hook name for the cron job used to check featured expiration dates daily.
	 */
	const CRON_HOOK = 'newspack_listings_expiration_checker';

	/**
	 * Installed version number of the custom table.
	 */
	const TABLE_VERSION = '1.1';

	/**
	 * Option name for the installed version number of the custom table.
	 */
	const TABLE_VERSION_OPTION = '_newspack_listings_priority_version';

	/**
	 * The single instance of the class.
	 *
	 * @var $instance
	 */
	protected static $instance = null;

	/**
	 * Main Featured instance.
	 * Ensures only one instance of Featured is loaded or can be loaded.
	 *
	 * @return Featured - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		register_activation_hook( NEWSPACK_LISTINGS_FILE, [ __CLASS__, 'create_custom_table' ] );
		add_action( 'init', [ __CLASS__, 'check_update_version' ] );
		add_action( 'init', [ __CLASS__, 'register_featured_meta' ] );
		add_action( 'init', [ __CLASS__, 'cron_init' ] );
		add_action( 'save_post', [ __CLASS__, 'update_featured_status_on_save' ], 10, 2 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'check_expired_featured_items' ] );
		add_filter( 'posts_clauses', [ __CLASS__, 'sort_featured_listings' ], 10, 2 );
		add_filter( 'post_class', [ __CLASS__, 'add_featured_classes' ] );
		add_filter( 'newspack_blocks_term_classes', [ __CLASS__, 'add_featured_classes' ], 10, 3 );
	}

	/**
	 * Get events table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'newspack_listings_priority';
	}

	/**
	 * Checks if the custom table has been created and is up-to-date.
	 * If not, run the create_custom_table method.
	 * See: https://codex.wordpress.org/Creating_Tables_with_Plugins
	 */
	public static function check_update_version() {
		$current_version = get_option( self::TABLE_VERSION_OPTION, false );

		if ( self::TABLE_VERSION !== $current_version ) {
			self::create_custom_table();
			self::populate_custom_table();
			update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
		}
	}

	/**
	 * Create a custom DB table to store feature priority data.
	 * Avoids the use of slow post meta for query sorting purposes.
	 * Only create the table if it doesn't already exist.
	 */
	public static function create_custom_table() {
		global $wpdb;
		$table_name = self::get_table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) != $table_name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$charset_collate = $wpdb->get_charset_collate();
			$sql             = "CREATE TABLE $table_name (
				-- Post ID.
				post_id bigint(20) unsigned NOT NULL,
				-- Feature priority: 1–9 if featured, 0 if not.
				feature_priority tinyint(1) unsigned NOT NULL,
				PRIMARY KEY (post_id),
				KEY (feature_priority)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.dbDelta_dbdelta
		}
	}

	/**
	 * When the table is created, ensure that all featured items and their priority are populated in the custom table.
	 */
	public static function populate_custom_table() {
		// Start with first page of 100 results, then we'll see if there are more pages to iterate through.
		$current_page = 1;
		$args         = [
			'post_type'      => array_values( Core::NEWSPACK_LISTINGS_POST_TYPES ),
			'post_status'    => [ 'draft', 'future', 'pending', 'private', 'publish', 'trash' ],
			'posts_per_page' => 100,
			'paged'          => $current_page,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				[
					'key'   => self::META_KEYS['featured'],
					'value' => 1,
				],
			],
		];

		// Get featured listings with an expiration date.
		$results          = new \WP_Query( $args );
		$number_of_pages  = $results->max_num_pages;
		$default_priority = 5;

		foreach ( $results->posts as $featured_listing ) {
			$priority = get_post_meta( $featured_listing->ID, 'newspack_listings_featured_priority', true );

			if ( ! $priority ) {
				$priority = $default_priority;
			}

			self::update_priority( $featured_listing->ID, $priority );
		}

		// If there were more than 1 page of results, repeat with subsequent pages until all posts are processed.
		if ( 1 < $number_of_pages ) {
			while ( $current_page < $number_of_pages ) {
				$current_page++;
				$args['paged'] = $current_page;
				$results       = new \WP_Query( $args );

				foreach ( $results->posts as $featured_listing ) {
					$priority = get_post_meta( $featured_listing->ID, 'newspack_listings_featured_priority', true );

					if ( ! $priority ) {
						$priority = $default_priority;
					}

					self::update_priority( $featured_listing->ID, $priority );
				}
			}
		}
	}

	/**
	 * Set the feature priority for the given post ID in the custom table.
	 *
	 * @param int $post_id Post ID. Will use current post if none given.
	 * @param int $priority Priority to set, from 0–9.
	 *
	 * @return int|false The number of rows affected, or false on error.
	 */
	public static function update_priority( $post_id = null, $priority = 0 ) {
		global $wpdb;

		if ( null === $post_id ) {
			$post_id = get_the_ID();
		}

		$table_name = self::get_table_name();

		if ( 0 < $priority ) {
			$result = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				[
					'post_id'          => $post_id,
					'feature_priority' => $priority,
				],
				[
					'%d',
					'%d',
				]
			);
		} else {
			// If passing 0, delete any found rows.
			$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				[ 'post_id' => $post_id ],
				'%d'
			);
		}

		return $result;
	}

	/**
	 * Get the priority value (0–9) for the given or current post ID.
	 *
	 * @param int $post_id Post ID. Will use current post if none given.
	 *
	 * @return int The post's priority, 1–9 if featured, or 0 if not.
	 */
	public static function get_priority( $post_id = null ) {
		global $wpdb;

		if ( null === $post_id ) {
			$post_id = get_the_ID();
		}

		$table_name = self::get_table_name();
		$priority   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT feature_priority FROM %1$s WHERE post_id = %2$d', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$table_name,
				$post_id
			)
		);

		// Will also return 0 if the $post_id doesn't exist in the table.
		return intval( $priority );
	}

	/**
	 * Register post meta fields for featured listing status.
	 */
	public static function register_featured_meta() {
		$meta_config = [
			'featured' => [
				'auth_callback'     => [ 'Core', 'can_edit_posts' ],
				'default'           => false,
				'description'       => __( 'Is this listing a featured listing?', 'newspack-listings' ),
				'sanitize_callback' => 'rest_sanitize_boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'type'              => 'boolean',
			],
			'expires'  => [
				'auth_callback'     => [ 'Core', 'can_edit_posts' ],
				'default'           => '',
				'description'       => __( 'When should the listing’s featured status expire?', 'newspack-listings' ),
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
				'type'              => 'string',
			],
		];

		foreach ( Core::NEWSPACK_LISTINGS_POST_TYPES as $post_type => $post_type_slug ) {
			foreach ( $meta_config as $key => $settings ) {
				$settings['object_subtype'] = $post_type_slug;
				register_meta(
					'post',
					self::META_KEYS[ $key ],
					$settings
				);
			}
		}
	}

	/**
	 * Is the given/current post a featured post?
	 *
	 * @param int $post_id Post ID. If none given, use the current post ID.
	 *
	 * @return boolean True if the listing is currently featured.
	 */
	public static function is_featured( $post_id = null ) {
		if ( null === $post_id ) {
			$post_id = get_the_ID();
		}

		return get_post_meta( $post_id, self::META_KEYS['featured'], true );
	}

	/**
	 * Get the expiration date string for the given/current post.
	 *
	 * @param int $post_id Post ID. If none given, use the current post ID.
	 *
	 * @return string Featured status expiration date, or an empty string if none.
	 */
	public static function get_featured_expiration( $post_id = null ) {
		if ( null === $post_id ) {
			$post_id = get_the_ID();
		}

		return get_post_meta( $post_id, self::META_KEYS['expires'], true );
	}

	/**
	 * Show featured items in query results first, in order of feature priority.
	 * Then order by the query's original ordering criteria, if any were specified.
	 * Limit query modifications to only queries that will include listing posts.
	 *
	 * @param string[] $clauses Associative array of the clauses for the query.
	 * @param WP_Query $query   The WP_Query instance (passed by reference).
	 *
	 * @return string[] Transformed clauses for the query.
	 */
	public static function sort_featured_listings( $clauses, $query ) {
		// Only category, tag, or listing post type archive pages, for now.
		if (
			$query->is_category() ||
			$query->is_tag() ||
			$query->is_post_type_archive( array_values( Core::NEWSPACK_LISTINGS_POST_TYPES ) ) ||
			boolval( $query->get( 'is_curated_list' ) )
		) {
			global $wpdb;
			$table_name = self::get_table_name();

			if ( false === strpos( $clauses['join'], "LEFT JOIN {$table_name}" ) ) {
				$clauses['join']   .= "
					LEFT JOIN {$table_name}
					ON (
						{$wpdb->prefix}posts.ID = {$table_name}.post_id
					) ";
				$clauses['orderby'] = "{$table_name}.feature_priority DESC, " . $clauses['orderby'];
			}
		}

		return $clauses;
	}

	/**
	 * Append featured classes to the given array of class names.
	 *
	 * @param array    $classes Array of class names.
	 * @param array    $class  An array of additional class names added to the post.
	 * @param int|null $post_id The post ID. If not given, will get for the current post.
	 *
	 * @return array Filtered array of class names.
	 */
	public static function add_featured_classes( $classes, $class = [], $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( Core::is_listing( get_post_type( $post_id ) ) ) {
			$feature_classes = [];
			$is_featured     = self::is_featured( $post_id );
			if ( $is_featured ) {
				$feature_priority  = self::get_priority( $post_id );
				$feature_classes[] = 'featured-listing';
				$feature_classes[] = 'featured-listing-priority-' . strval( $feature_priority );
				$classes           = array_merge( $classes, $class, $feature_classes );
			}
		}

		return $classes;
	}

	/**
	 * Set up the cron job. Will run once daily and remove featured status for all listings whose expiration date has passed.
	 */
	public static function cron_init() {
		register_deactivation_hook( NEWSPACK_LISTINGS_FILE, [ __CLASS__, 'cron_deactivate' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( Utils\get_next_midnight(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the cron job when this plugin is deactivated.
	 */
	public static function cron_deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Feature priority is updated on the fly via the block editor.
	 * However, if you never touch the default priority control, it won't be updated in the custom table.
	 * This ensures that the feature priority is set on save if the post is featured but has no priority.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post WP_Post object being saved.
	 */
	public static function update_featured_status_on_save( $post_id, $post ) {
		if ( ! Core::is_listing( $post->post_type ) ) {
			return;
		}

		$is_featured      = get_post_meta( $post_id, self::META_KEYS['featured'], true );
		$feature_priority = self::get_priority( $post_id );

		// If the post is set to be featured but has no priority, set the default.
		if ( $is_featured && 0 === $feature_priority ) {
			self::update_priority( $post_id, 5 );
		}

		// If the post is set to not be featured, delete any priority values.
		if ( ! $is_featured ) {
			self::update_priority( $post_id, 0 );
		}
	}

	/**
	 * Set featured status, priority, and expiration date for the given post.
	 *
	 * @param int    $post_id Post ID to update.
	 * @param int    $priority Priority level (1-9) to set. If not given, will default to 5.
	 * @param string $expires Date string for the expiration date, in YYYY-MM-DDT00:00:00 format. Will not set if none given.
	 *
	 * @return boolean True if the post updated to featured; false if the post wasn't updated or doesn't exist.
	 */
	public static function set_featured_status( $post_id = null, $priority = 5, $expires = null ) {
		if ( null === $post_id ) {
			return false;
		}
		// Set featured status.
		update_post_meta( $post_id, self::META_KEYS['featured'], true );

		// Set feature priority.
		self::update_priority( $post_id, $priority );

		// Set expiration, if given and a valid time string.
		if ( $expires && false !== Utils\convert_string_to_date_time( $expires ) ) {
			update_post_meta( $post_id, self::META_KEYS['expires'], $expires );
		}
	}

	/**
	 * Unset featured status for the given post. Also delete the query priority meta key.
	 *
	 * @param int     $post_id Post ID to update.
	 * @param boolean $ignore_expiration If passed true, skip checking the expiration date and immediately unset featured status.
	 *
	 * @return boolean True if the post was featured and updated to unfeatured; false if the post wasn't updated or doesn't exist.
	 */
	public static function unset_featured_status( $post_id = null, $ignore_expiration = false ) {
		if ( null === $post_id ) {
			return false;
		}

		$unset_featured = false;

		if ( $ignore_expiration ) {
			$unset_featured = true;
		} else {
			$expiration_date = self::get_featured_expiration( $post_id );
			$timezone        = get_option( 'timezone_string', 'UTC' );

			// Guard against 'Unknown or bad timezone' PHP error.
			if ( empty( trim( $timezone ) ) ) {
				$timezone = 'UTC';
			}

			$parsed_date    = new \DateTime( $expiration_date, new \DateTimeZone( $timezone ) );
			$unset_featured = 0 > $parsed_date->getTimestamp() - time();
		}

		// If the expiration date has already passed, remove the featured status and query priority.
		if ( $unset_featured ) {
			update_post_meta( $post_id, self::META_KEYS['featured'], false );
			self::update_priority( $post_id, 0 );
			return true;
		}

		return false;
	}

	/**
	 * Check for featured items whose expiration date has passed, and remove their featured status.
	 * Realistically, most sites shouldn't have this many featured items at a time, but just in case,
	 * fetch results in batches of 100 and iterate through the batches so all results are processed.
	 */
	public static function check_expired_featured_items() {
		$args = [
			'post_status' => [ 'draft', 'future', 'pending', 'private', 'publish', 'trash' ],
			'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				[
					'key'   => self::META_KEYS['featured'],
					'value' => 1,
				],
				[
					'key'     => self::META_KEYS['expires'],
					'compare' => 'EXISTS',
				],
				[
					'key'     => self::META_KEYS['expires'],
					'compare' => '!=',
					'value'   => '',
				],
			],
		];

		Utils\execute_callback_with_paged_query( $args, [ __CLASS__, 'unset_featured_status' ] );
	}
}

Featured::instance();
