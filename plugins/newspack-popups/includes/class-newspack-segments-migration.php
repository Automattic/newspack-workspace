<?php
/**
 * Newspack Segments Migration
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * This class holds all the methods used to migrate segments to the new
 * structured introduced in the Rearchitecture done in July/August 2023
 *
 * TODO: Once this is thoroughly tested, we should also remove legacy data from DB, such as
 * * Segment configuration that now lives on criteria
 * * selected_segment_id prompt meta, that now lives on the taxonomy
 */
final class Newspack_Segments_Migration {

	/**
	 * The current DB version, used to perform updates in the data in case of change in the structure
	 *
	 * @var int
	 */
	const DB_VERSION = 3;

	/**
	 * The DB version option name. Where the current option is stored.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION_NAME = 'newspack_segments_db_version';

	/**
	 * Option name for whether we should bother trying to migrate reader data.
	 * If there are no legacy reader tables, then there's no need to migrate.
	 *
	 * @var string
	 */
	const SHOULD_MIGRATE_READER_DATA_OPTION_NAME = 'newspack_should_migrate_reader_data';

	/**
	 * Initializes the class
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_update_db_version' ], 99 ); // After segments taxonomy is registered.

		// User data on-demand migration.
		add_action( 'wp', [ __CLASS__, 'migrate_user_data' ] );
		add_action( 'user_register', [ __CLASS__, 'migrate_new_user_data' ], 10, 2 );
	}

	/**
	 * Checks if the DB version has changed and updates the data if needed
	 *
	 * @return void
	 */
	public static function maybe_update_db_version() {
		$current_db_version = (int) get_option( self::DB_VERSION_OPTION_NAME, 0 );
		if ( $current_db_version < self::DB_VERSION ) {
			self::update_db_version( $current_db_version );
		}
	}

	/**
	 * Updates the DB version option and performs the needed updates
	 *
	 * @param int $current_db_version The current DB version.
	 * @return void
	 */
	public static function update_db_version( $current_db_version ) {
		if ( $current_db_version < 1 ) {
			self::update_db_version_to_1();
		}
		if ( $current_db_version < 2 ) {
			self::update_db_version_to_2();
		}
		if ( $current_db_version < 3 ) {
			self::update_db_version_to_3();
		}
		update_option( self::DB_VERSION_OPTION_NAME, self::DB_VERSION );

		// Workaround the options bug on persistent cache.
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Updates the DB version to 1, when the segments were migrated from a single option entry into terms of a taxonomy
	 *
	 * @return void
	 */
	public static function update_db_version_to_1() {
		$old_segments = get_option( Newspack_Popups_Segmentation::SEGMENTS_OPTION_NAME );
		$id_mapping   = [];

		if ( ! is_array( $old_segments ) || empty( $old_segments ) ) {
			return;
		}

		foreach ( $old_segments as $old_segment ) {
			$insert                           = Newspack_Segments_Model::create_segment( $old_segment );
			$new_segment                      = end( $insert );
			$id_mapping[ $old_segment['id'] ] = $new_segment['id'];
		}

		$popups = Newspack_Popups_Model::retrieve_popups( true, true );
		foreach ( $popups as $popup ) {
			$meta_value = get_post_meta( $popup['id'], 'selected_segment_id', true );
			if ( $meta_value ) {
				// Create a backup of the old value.
				update_post_meta( $popup['id'], 'selected_segment_id_bkp', $meta_value );
				foreach ( $id_mapping as $old_id => $new_id ) {
					$meta_value = str_replace( $old_id, $new_id, $meta_value );
				}
				update_post_meta( $popup['id'], 'selected_segment_id', $meta_value );
			}
		}
	}

	/**
	 * Updates the DB version to 2, when the segments relationship with prompts was changed from a post meta to term relationship
	 *
	 * @return void
	 */
	public static function update_db_version_to_2() {
		$prompts = Newspack_Popups_Model::retrieve_popups( true, true );
		foreach ( $prompts as $prompt ) {
			$old_segments = get_post_meta( $prompt['id'], 'selected_segment_id', true );
			if ( empty( $old_segments ) ) {
				continue;
			}
			$segments_ids = explode( ',', $old_segments );
			$segment_ids  = array_map( 'intval', $segments_ids );
			wp_set_object_terms( $prompt['id'], $segment_ids, Newspack_Segments_Model::TAX_SLUG );
		}
	}

	/**
	 * Updates the DB version to 3, when the segments configuration was replaced with criteria
	 *
	 * @return void
	 */
	public static function update_db_version_to_3() {
		$segments = Newspack_Segments_Model::get_segments( true );
		foreach ( $segments as $segment ) {
			$segment = self::migrate_criteria_configuration( $segment );
			Newspack_Segments_Model::update_segment( $segment );
		}
	}

	/**
	 * Migrate criteria configuration.
	 *
	 * @param array $segment Segment.
	 *
	 * @return array
	 */
	public static function migrate_criteria_configuration( $segment ) {
		if ( empty( $segment['configuration'] ) || ! empty( $segment['criteria'] ) ) {
			return $segment;
		}
		$configuration = $segment['configuration'];
		$criteria      = [];
		// Migrate posts read.
		if ( ! empty( $configuration['min_posts'] ) || ! empty( $configuration['max_posts'] ) ) {
			$criteria[] = [
				'criteria_id' => 'articles_read',
				'value'       => [
					'min' => ! empty( $configuration['min_posts'] ) ? $configuration['min_posts'] : 0,
					'max' => ! empty( $configuration['max_posts'] ) ? $configuration['max_posts'] : 0,
				],
			];
		}
		// Migrate posts read in session.
		if ( ! empty( $configuration['min_session_posts'] ) || ! empty( $configuration['max_session_posts'] ) ) {
			$criteria[] = [
				'criteria_id' => 'articles_read_in_session',
				'value'       => [
					'min' => ! empty( $configuration['min_session_posts'] ) ? $configuration['min_session_posts'] : 0,
					'max' => ! empty( $configuration['max_session_posts'] ) ? $configuration['max_session_posts'] : 0,
				],
			];
		}
		// Migrate favorite categories.
		if ( ! empty( $configuration['favorite_categories'] ) ) {
			$criteria[] = [
				'criteria_id' => 'favorite_categories',
				'value'       => $configuration['favorite_categories'],
			];
		}
		// Migrate newsletter subscribed.
		if ( ! empty( $configuration['is_subscribed'] ) ) {
			$criteria[] = [
				'criteria_id' => 'newsletter',
				'value'       => 'subscribers',
			];
		}
		// Migrate newsletter not subscribed.
		if ( ! empty( $configuration['is_not_subscribed'] ) ) {
			$criteria[] = [
				'criteria_id' => 'newsletter',
				'value'       => 'non-subscribers',
			];
		}
		// Migrate donor.
		if ( ! empty( $configuration['is_donor'] ) ) {
			$criteria[] = [
				'criteria_id' => 'donation',
				'value'       => 'donors',
			];
		}
		// Migrate not donor.
		if ( ! empty( $configuration['is_not_donor'] ) ) {
			$criteria[] = [
				'criteria_id' => 'donation',
				'value'       => 'non-donors',
			];
		}
		// Migrate former donor.
		if ( ! empty( $configuration['is_former_donor'] ) ) {
			$criteria[] = [
				'criteria_id' => 'donation',
				'value'       => 'former-donors',
			];
		}
		// Migrate has reader account.
		if ( ! empty( $configuration['is_logged_in'] ) ) {
			$criteria[] = [
				'criteria_id' => 'user_account',
				'value'       => 'with-account',
			];
		}
		// Migrate without reader account.
		if ( ! empty( $configuration['is_not_logged_in'] ) ) {
			$criteria[] = [
				'criteria_id' => 'user_account',
				'value'       => 'without-account',
			];
		}
		// Migrate referrer sources to match.
		if ( ! empty( $configuration['referrers'] ) ) {
			$criteria[] = [
				'criteria_id' => 'sources_to_match',
				'value'       => $configuration['referrers'],
			];
		}
		// Migrate referrer sources to exclude.
		if ( ! empty( $configuration['referrers_not'] ) ) {
			$criteria[] = [
				'criteria_id' => 'sources_to_exclude',
				'value'       => $configuration['referrers_not'],
			];
		}
		if ( ! empty( $criteria ) ) {
			$segment['criteria'] = $criteria;
		}
		return $segment;
	}

	/**
	 * Checks for the existence of legacy tables and cache the result.
	 * No need to migrate reader data if the old tables don't exist.
	 */
	public static function should_migrate_reader_data() {
		$should_migrate = \get_option( self::SHOULD_MIGRATE_READER_DATA_OPTION_NAME, null );

		if ( null === $should_migrate ) {
			global $wpdb;
			$should_migrate = ! empty( $wpdb->get_results( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'newspack_campaigns_reader_events' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			\add_option( self::SHOULD_MIGRATE_READER_DATA_OPTION_NAME, $should_migrate );

			// Avoid notoptions cache error.
			\wp_cache_delete( 'notoptions', 'options' );
			\wp_cache_delete( 'alloptions', 'options' );
		}

		return $should_migrate;
	}

	/**
	 * Migrate user data from the 'wp_newspack_campaigns_reader_events' table to
	 * the reader data library.
	 *
	 * Runs once on an authenticated reader pageload.
	 */
	public static function migrate_user_data() {
		if (
			! is_user_logged_in() ||
			get_user_meta( get_current_user_id(), 'newspack_popups_reader_data_migrated', true ) ||
			! class_exists( 'Newspack\Reader_Data' ) ||
			! self::should_migrate_reader_data()
		) {
			return;
		}

		$user_id = get_current_user_id();

		global $wpdb;

		// Fetch the user's client ids.
		$client_ids = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"
					SELECT DISTINCT client_id
					FROM {$wpdb->prefix}newspack_campaigns_reader_events
					WHERE type = %s AND context = %s
				",
				'user_account',
				$user_id
			),
			ARRAY_N
		);
		if ( empty( $client_ids ) ) {
			update_user_meta( $user_id, 'newspack_popups_reader_data_migrated', true );
			return;
		}
		$client_ids = array_map(
			function( $client_id ) {
				return $client_id[0];
			},
			$client_ids
		);

		self::migrate_client_ids_to_user( $client_ids, $user_id );
	}

	/**
	 * Look for client IDs attached to the registered user email to migrate data
	 * generated before registration.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $userdata The raw array of data passed to wp_insert_user().
	 */
	public static function migrate_new_user_data( $user_id, $userdata ) {
		if ( empty( $userdata['user_email'] ) || ! self::should_migrate_reader_data() ) {
			return;
		}

		global $wpdb;

		// Fetch all client IDs attached to the email.
		$client_ids = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"
					SELECT DISTINCT client_id
					FROM {$wpdb->prefix}newspack_campaigns_reader_events
					WHERE type = 'subscription' AND context = %s
				",
				$userdata['user_email']
			),
			ARRAY_N
		);

		if ( empty( $client_ids ) ) {
			return;
		}

		$client_ids = array_map(
			function( $client_id ) {
				return $client_id[0];
			},
			$client_ids
		);

		self::migrate_client_ids_to_user( $client_ids, $user_id );
	}

	/**
	 * Migrate data from a list of client IDs to a user ID.
	 *
	 * @param string[] $client_ids Client IDs.
	 * @param int      $user_id    User ID.
	 */
	public static function migrate_client_ids_to_user( $client_ids, $user_id ) {
		if ( empty( $client_ids ) ) {
			return;
		}

		global $wpdb;

		$client_id_placeholders = implode( ', ', array_fill( 0, count( $client_ids ), '%s' ) );
		$events_sql             = "
			SELECT * FROM {$wpdb->prefix}newspack_campaigns_reader_events
			WHERE client_id IN ( $client_id_placeholders ) AND type IN ( 'subscription', 'donation', 'donation_cancelled' )
			ORDER BY date_created ASC
		";
		$events_query           = call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $events_sql ], $client_ids ) );
		$events                 = $wpdb->get_results( $events_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		// Migrate events.
		$data = [
			'is_newsletter_subscriber' => false,
			'is_donor'                 => false,
			'is_former_donor'          => false,
		];
		foreach ( $events as $event ) {
			if ( 'subscription' === $event['type'] ) {
				$data['is_newsletter_subscriber'] = true;
			} elseif ( 'donation' === $event['type'] ) {
				$data['is_donor']        = true;
				$data['is_former_donor'] = false;
			} elseif ( 'donation_cancelled' === $event['type'] ) {
				$data['is_donor']        = false;
				$data['is_former_donor'] = true;
			}
		}
		foreach ( $data as $key => $value ) {
			$existing_value = \Newspack\Reader_Data::get_data( $user_id, $key );
			// Bail if the value already exists. The more recent value should be kept.
			if ( $existing_value ) {
				continue;
			}
			\Newspack\Reader_Data::update_item( $user_id, $key, wp_json_encode( $value ) );
		}

		// Clean up migrated client IDs.
		$delete_sql = "DELETE FROM {$wpdb->prefix}newspack_campaigns_reader_events WHERE client_id IN ( $client_id_placeholders )";
		$wpdb->query( call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $delete_sql ], $client_ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		// Update user meta so we don't run this again for this user.
		update_user_meta( $user_id, 'newspack_popups_reader_data_migrated', true );
	}
}

Newspack_Segments_Migration::init();
