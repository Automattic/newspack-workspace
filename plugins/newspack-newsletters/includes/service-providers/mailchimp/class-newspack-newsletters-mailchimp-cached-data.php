<?php
/**
 * Mailchimp Cached data
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack_Newsletters_Mailchimp_Api as Mailchimp;

/**
 * Mailchimp cached class data
 *
 * This class handles fetching and caching segments and interests data from Mailchimp
 *
 * The purpose of this class is to implement a non-obstrusive cache, in which refreshing the cache will happen in the background in async requests
 * and will never keep the user waiting.
 *
 * The cache is stored in an option, and will be considered expired after 20 minutes. Every time we retrieve the cache, we check its age,
 * if it's expired, we trigger an async request to refresh it.
 *
 * Also, as a redundant strategy, we have a CRON job that will trigger the async requests to refresh the cache for all lists every 10 minutes.
 *
 * If the cache refresh fails, we will store the error in a separate option, and will only surface it to the user after 20 minutes.
 * In every admin page we will display a generic Warning message, telling the user to go to Newsletters > Settings to see the errors.
 * In Newsletters > Settings we will output the errors details.
 */
final class Newspack_Newsletters_Mailchimp_Cached_Data {

	/**
	 * The cache option name
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'newspack_nl_mailchimp_cache';

	/**
	 * The name of the option where we store errors
	 *
	 * @var string
	 */
	const ERRORS_OPTION = 'newspack_nl_mailchimp_cache_errors';

	/**
	 * The ajax action name used to dispatch the cache refresh
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'newspack_nl_mailchimp_refresh_cached_data';

	/**
	 * The cron hook name that trigger the cache refresh on the background
	 *
	 * @var string
	 */
	const CRON_HOOK = 'newspack_nl_mailchimp_refresh_cache';

	/**
	 * We store errors when an API request fails, but we will only surface these errors to the user after this time
	 *
	 * @var int
	 */
	const SURFACE_ERRORS_AFTER = 20 * HOUR_IN_SECONDS;

	/**
	 * Memoized data to be served across the same request
	 *
	 * @var array
	 */
	private static $memoized_data = [];

	/**
	 * Initializes this class
	 */
	public static function init() {

		if ( 'mailchimp' !== Newspack_Newsletters::service_provider() ) {
			return;
		}

		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'handle_dispatch_refresh' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ __CLASS__, 'handle_dispatch_refresh' ] );

		// Invalidate all cached data if API key changes.
		add_action( 'update_option_newspack_mailchimp_api_key', [ __CLASS__, 'invalidate_cache' ] );

		add_action( self::CRON_HOOK, [ __CLASS__, 'handle_cron' ] );
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] ); // phpcs:ignore

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_10_minutes', self::CRON_HOOK );
		}

		add_action( 'admin_notices', [ __CLASS__, 'maybe_show_error' ] );
	}

	/**
	 * Adds a custom interval to WP Cron
	 *
	 * @param array $schedules The current schedules.
	 *
	 * @return array
	 */
	public static function add_cron_interval( $schedules ) {
		$schedules['every_10_minutes'] = [
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every ten minutes', 'newspack_newsletters' ),
		];
		return $schedules;
	}

	/**
	 * Get audiences (lists).
	 *
	 * @param int|null $limit (Optional) The maximum number of items to return. If not given, will get all items.
	 *
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array|WP_Error The audiences, or WP_Error if there was an error.
	 */
	public static function get_lists( $limit = null ) {
		// If we've already gotten or fetched lists in this request, return those.
		if ( ! empty( self::$memoized_data['lists'] ) ) {
			return self::$memoized_data['lists'];
		}

		$data = get_option( self::get_lists_cache_key() );
		if ( ! $data || self::is_cache_expired() ) {
			Newspack_Newsletters_Logger::log( 'Mailchimp cache: No data found. Fetching lists from ESP.' );
			$data = self::fetch_lists( $limit );
		} else {
			Newspack_Newsletters_Logger::log( 'Mailchimp cache: serving from cache' );
		}

		self::$memoized_data['lists'] = $data;
		if ( $limit ) {
			$data = array_slice( $data, 0, $limit );
		}
		return $data;
	}

	/**
	 * Get segments of a given audience (list)
	 *
	 * @param string $list_id The audience (list) ID.
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The audience segments
	 */
	public static function get_segments( $list_id ) {
		$data = self::get_data( $list_id );
		return $data['segments'] ?? null;
	}

	/**
	 * Get Interest Categories (aka Groups) of a given audience
	 *
	 * @param string $list_id The audience (list) ID.
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The audience interest categories
	 */
	public static function get_interest_categories( $list_id ) {
		$data = self::get_data( $list_id );
		return $data['interest_categories'] ?? null;
	}

	/**
	 * Get tags for a given audience
	 *
	 * @param string $list_id The audience (list) ID.
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The audience tags
	 */
	public static function get_tags( $list_id ) {
		$data = self::get_data( $list_id );
		return $data['tags'] ?? null;
	}

	/**
	 * Get folders.
	 *
	 * TODO: This is not cached because the cache structure requires a list ID,
	 * which shouldn't be required for folders.
	 *
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The list folders
	 */
	public static function get_folders() {
		return self::fetch_folders();
	}

	/**
	 * Get merge_fields of a given list
	 *
	 * @param string $list_id The audience (list) ID.
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The list merge_fields
	 */
	public static function get_merge_fields( $list_id ) {
		$data = self::get_data( $list_id );
		return $data['merge_fields'] ?? null;
	}

	/**
	 * Retrieves the main Mailchimp instance
	 *
	 * @return Newspack_Newsletters_Mailchimp
	 */
	private static function get_mc_instance() {
		return Newspack_Newsletters_Mailchimp::instance();
	}

	/**
	 * Get the cache key for the cached lists data.
	 */
	private static function get_lists_cache_key() {
		return self::OPTION_PREFIX . '_lists';
	}

	/**
	 * Get the cache key for a given list
	 *
	 * @param string $list_id The List ID.
	 * @return string The cache key
	 */
	private static function get_cache_key( $list_id ) {
		return self::OPTION_PREFIX . '_' . $list_id;
	}

	/**
	 * Get the cache date key for a given list or all lists
	 *
	 * @param string $list_id The List ID, or 'lists' for the cached lists data.
	 * @return string The cache key
	 */
	private static function get_cache_date_key( $list_id = 'lists' ) {
		return self::OPTION_PREFIX . '_date_' . $list_id;
	}

	/**
	 * Checks if the cache is expired for a given list
	 *
	 * @param string $list_id The List ID.
	 * @return boolean
	 */
	private static function is_cache_expired( $list_id = 'lists' ) {
		$cache_date = get_option( self::get_cache_date_key( $list_id ) );
		return $cache_date && ( time() - $cache_date ) > 20 * MINUTE_IN_SECONDS;
	}

	/**
	 * Updates the cache for a given list
	 *
	 * @param string $list_id The List ID.
	 * @param array  $data The data to cache.
	 * @return void
	 */
	private static function update_cache( $list_id, $data ) {
		update_option( self::get_cache_key( $list_id ), $data, false ); // auto-load false.
		update_option( self::get_cache_date_key( $list_id ), time(), false ); // auto-load false.
		self::$memoized_data[ $list_id ] = $data;
		self::clear_errors( $list_id );
		Newspack_Newsletters_Logger::log( 'Mailchimp cache: Cache for list ' . $list_id . ' updated' );
	}

	/**
	 * Clears the cache errors for a given list
	 *
	 * @param string|null $list_id The List ID, or null for all lists.
	 * @return void
	 */
	private static function clear_errors( $list_id = null ) {
		if ( ! $list_id ) {
			delete_option( self::ERRORS_OPTION );
			return;
		}
		$errors = get_option( self::ERRORS_OPTION, [] );
		if ( isset( $errors[ $list_id ] ) ) {
			unset( $errors[ $list_id ] );
			update_option( self::ERRORS_OPTION, $errors );
			Newspack_Newsletters_Logger::log( 'Mailchimp cache: Clearing errors for ' . $list_id );
		}
	}

	/**
	 * Stores the last error for a given list, if the cache is older than self::SURFACE_ERRORS_AFTER
	 *
	 * @param string|null $list_id The List ID, or null for all lists.
	 * @param string      $error The error message.
	 */
	private static function maybe_add_error( $list_id = null, $error = '' ) {
		Newspack_Newsletters_Logger::log(
			sprintf(
				'Mailchimp cache: handling error while fetching cache for %s',
				$list_id ? 'list ' . $list_id : 'all lists'
			)
		);
		if ( ! $list_id ) {
			$list_id = 'lists';
		}
		if ( ! $error ) {
			$error = __( 'Unknown error', 'newspack_newsletters' );
		}
		$cache_date = get_option( self::get_cache_date_key( $list_id ) );
		if ( $cache_date && ( time() - $cache_date ) > self::SURFACE_ERRORS_AFTER ) {
			$errors             = get_option( self::ERRORS_OPTION, [] );
			$errors[ $list_id ] = $error;
			update_option( self::ERRORS_OPTION, $errors );
			Newspack_Newsletters_Logger::log( 'Mailchimp cache: error stored' );
		}
	}

	/**
	 * Shows an error message to the user if we have errors in the cache
	 *
	 * @return void
	 */
	public static function maybe_show_error() {
		$errors = get_option( self::ERRORS_OPTION, [] );
		if ( ! empty( $errors ) ) {
			$screen = get_current_screen();
			if ( ! $screen ) {
				return;
			}
			if ( 'newspack_nl_cpt_page_newspack-newsletters-settings-admin' !== $screen->base ) {
				self::show_generic_warning();
				return;
			}
			$hours = (int) self::SURFACE_ERRORS_AFTER / HOUR_IN_SECONDS;
			?>
			<div class="notice notice-error">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s is the number of hours a cache must be expired for us to surface this error */
							__(
								'Error retrieving data from Mailchimp. We were not able to refresh the list of Audiences and groups in the last %s hours.',
								'newspack_newsletters'
							),
							$hours
						)
					);
					?>
				</p>
				<ul>
					<?php foreach ( $errors as $list_id => $error ) : ?>
						<li>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %1$s is the list ID, %2$s is the error message */
									__( 'List %1$s: %2$s', 'newspack_newsletters' ),
									$list_id,
									$error
								)
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}

	/**
	 * Shows a generic warning when we can't fetch data from Mailchimp
	 *
	 * @return void
	 */
	private static function show_generic_warning() {
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo esc_html(
					__(
						'Newspack Newsletters is having trouble fetching data from Mailchimp Audiences. Please visit Newsletters > Settings for more details.',
						'newspack_newsletters'
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Gets the raw data for a given List
	 *
	 * @param string $list_id The List ID.
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The list data with segments and interest_categories
	 */
	private static function get_data( $list_id ) {

		Newspack_Newsletters_Logger::log( 'Mailchimp cache: getting data for list ' . $list_id );

		if ( ! empty( self::$memoized_data[ $list_id ] ) ) {
			return self::$memoized_data[ $list_id ];
		}

		$data = get_option( self::get_cache_key( $list_id ) );
		if ( $data ) {
			Newspack_Newsletters_Logger::log( 'Mailchimp cache: serving from cache' );
			self::$memoized_data[ $list_id ] = $data;

			if ( self::is_cache_expired( $list_id ) ) {
				Newspack_Newsletters_Logger::log( 'Mailchimp cache: cache expired. Dispatching refresh' );
				self::dispatch_refresh( $list_id );
			}
			return $data;
		}

		Newspack_Newsletters_Logger::log( 'Mailchimp cache: No data found. Dispatching refresh' );
		self::dispatch_refresh( $list_id );

		return [];
	}

	/**
	 * Dispatches a new request to refresh the cache
	 *
	 * @param string $list_id The List ID or null for the cache for all lists.
	 * @return void
	 */
	private static function dispatch_refresh( $list_id = null ) {
		// If no list_id is provided, refresh the lists cache.
		if ( ! $list_id ) {
			self::fetch_lists();
			return;
		}

		if ( ! function_exists( 'wp_create_nonce' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		$url = add_query_arg(
			[
				'action'   => self::AJAX_ACTION,
				'_wpnonce' => wp_create_nonce( self::AJAX_ACTION ),
			],
			admin_url( 'admin-ajax.php' )
		);

		$body = [
			'list_id' => $list_id,
		];

		wp_remote_post(
			$url,
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'body'      => $body,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'   => $_COOKIE, // phpcs:ignore
			]
		);
	}

	/**
	 * Handles the ajax action to refresh the cache
	 *
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return void
	 */
	public static function handle_dispatch_refresh() {
		Newspack_Newsletters_Logger::log( 'Mailchimp cache: Handling ajax request to refresh cache' );
		check_admin_referer( self::AJAX_ACTION );
		$list_id = isset( $_POST['list_id'] ) ? sanitize_text_field( $_POST['list_id'] ) : null;
		if ( ! $list_id ) {
			die;
		}
		try {
			self::refresh_cached_data( $list_id );
		} catch ( Exception $e ) {
			Newspack_Newsletters_Logger::log( 'Mailchimp cache: Error refreshing cache: ' . $e->getMessage() );
		}
		die;
	}

	/**
	 * Fetches data from the server and updates the cache
	 *
	 * @param string $list_id The List ID.
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The list data with segments and interest_categories
	 */
	private static function refresh_cached_data( $list_id ) {
		Newspack_Newsletters_Logger::log( 'Mailchimp cache: Refreshing cache' );
		try {
			$segments            = self::fetch_segments( $list_id );
			$interest_categories = self::fetch_interest_categories( $list_id );
			$tags                = self::fetch_tags( $list_id );
			$folders             = self::fetch_folders();
			$merge_fields        = self::fetch_merge_fields( $list_id );
			$list_data           = [
				'segments'            => $segments,
				'interest_categories' => $interest_categories,
				'tags'                => $tags,
				'folders'             => $folders,
				'merge_fields'        => $merge_fields,
			];

			// Update the cache.
			self::update_cache( $list_id, $list_data );

			return $list_data;
		} catch ( Exception $e ) {
			self::maybe_add_error( $list_id, $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Invalidate cached data by clearing the cache date key for all lists.
	 */
	public static function invalidate_cache() {
		Newspack_Newsletters_Logger::log( 'Mailchimp cache: Invalidating cached data' );
		delete_option( self::get_cache_date_key() );
		delete_option( self::get_lists_cache_key() );
		self::clear_errors();
	}

	/**
	 * Handles the cron job and triggers the async requests to refresh the cache for all lists
	 *
	 * @return void
	 */
	public static function handle_cron() {
		Newspack_Newsletters_Logger::log( 'Mailchimp cache: Handling cron request to refresh cache' );
		try {
			$lists = self::fetch_lists(); // Force a cache refresh.
		} catch ( Exception $e ) {
			Newspack_Newsletters_Logger::log( 'Mailchimp cache: Error refreshing lists cache: ' . $e->getMessage() );
			self::maybe_add_error( null, $e->getMessage() );
			return;
		}

		foreach ( $lists as $list ) {
			Newspack_Newsletters_Logger::log( 'Mailchimp cache: Dispatching request to refresh cache for list ' . $list['id'] );
			self::dispatch_refresh( $list['id'] );
		}
	}

	/**
	 * Fetches all audiences (lists) from the Mailchimp server
	 *
	 * @param int|null $limit (Optional) The maximum number of items to return. If not given, will get all items.
	 *
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array|WP_Error The audiences, or WP_Error if there was an error.
	 */
	public static function fetch_lists( $limit = null ) {
		$mc = new Mailchimp( ( self::get_mc_instance() )->api_key() );

		$lists_response = ( self::get_mc_instance() )->validate(
			$mc->get(
				'lists',
				[
					'count'  => $limit ?? 1000,
					'fields' => 'lists.name,lists.id,lists.web_id,lists.stats.member_count',
				]
			),
			__( 'Error retrieving Mailchimp lists.', 'newspack_newsletters' )
		);

		// Cache the lists (only if we got them all).
		if ( ! $limit ) {
			update_option( self::get_lists_cache_key(), $lists_response['lists'], false ); // auto-load false.
			update_option( self::get_cache_date_key(), time(), false ); // auto-load false.
			self::clear_errors();
		}

		return $lists_response['lists'];
	}

	/**
	 * Fetches a single segment by segment ID + list ID.
	 *
	 * @param string $segment_id The segment ID.
	 * @param string $list_id The audience (list) ID.
	 *
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The audience segment
	 */
	public static function fetch_segment( $segment_id, $list_id ) {
		$mc = new Mailchimp( ( self::get_mc_instance() )->api_key() );
		$response = ( self::get_mc_instance() )->validate(
			$mc->get(
				"lists/$list_id/segments/$segment_id",
				[
					'fields' => 'id,name,member_count,type,options,list_id',
				],
				60
			),
			__( 'Error retrieving Mailchimp segment with ID: ', 'newspack_newsletters' ) . $segment_id
		);

		return $response;
	}

	/**
	 * Fetches the segments for a given List from the Mailchimp server
	 *
	 * @param string   $list_id The audience (list) ID.
	 * @param int|null $limit (Optional) The maximum number of items to return. If not given, will get all items.
	 *
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The audience segments
	 */
	public static function fetch_segments( $list_id, $limit = null ) {
		$segments = [];

		$mc = new Mailchimp( ( self::get_mc_instance() )->api_key() );

		$saved_segments_response  = ( self::get_mc_instance() )->validate(
			$mc->get(
				"lists/$list_id/segments",
				[
					'type'  => 'saved', // 'saved' or 'static' segments. 'static' segments are actually the same thing as tags, so we can exclude them from this request as we fetch tags separately.
					'count' => $limit ?? 1000,
				],
				60
			),
			__( 'Error retrieving Mailchimp segments.', 'newspack_newsletters' )
		);
		$segments = $saved_segments_response['segments'];

		return $segments;
	}

	/**
	 * Fetches the interest_categories (aka Groups) for a given List from the Mailchimp server
	 *
	 * @param string   $list_id The audience (list) ID.
	 * @param int|null $limit (Optional) The maximum number of items to return. If not given, will get all items.
	 *
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The audience interest_categories
	 */
	private static function fetch_interest_categories( $list_id, $limit = null ) {
		$mc = new Mailchimp( ( self::get_mc_instance() )->api_key() );
		$interest_categories = $list_id ? ( self::get_mc_instance() )->validate(
			$mc->get( "lists/$list_id/interest-categories", [ 'count' => $limit ?? 1000 ], 60 ),
			__( 'Error retrieving Mailchimp groups.', 'newspack_newsletters' )
		) : null;

		if ( $interest_categories && count( $interest_categories['categories'] ) ) {
			foreach ( $interest_categories['categories'] as &$category ) {
				$category_id           = $category['id'];
				$category['interests'] = ( self::get_mc_instance() )->validate(
					$mc->get( "lists/$list_id/interest-categories/$category_id/interests", [ 'count' => $limit ?? 1000 ], 60 ),
					__( 'Error retrieving Mailchimp groups.', 'newspack_newsletters' )
				);
			}
		}

		return $interest_categories;
	}

	/**
	 * Fetches the tags for a given audience (list) from the Mailchimp server
	 *
	 * @param string   $list_id The audience (list) ID.
	 * @param int|null $limit (Optional) The maximum number of items to return. If not given, will get all items.
	 *
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The audience tags
	 */
	public static function fetch_tags( $list_id, $limit = null ) {
		$mc = new Mailchimp( ( self::get_mc_instance() )->api_key() );
		$tags = $list_id ? ( self::get_mc_instance() )->validate(
			$mc->get(
				"lists/$list_id/segments",
				[
					'type'  => 'static', // 'saved' or 'static' segments. Tags are called 'static' segments in Mailchimp's API.
					'count' => $limit ?? 1000,
				],
				60
			),
			__( 'Error retrieving Mailchimp tags.', 'newspack_newsletters' )
		) : null;

		if ( $tags && count( $tags['segments'] ) ) {
			return $tags['segments'];
		}

		return [];
	}

	/**
	 * Fetches the campaign folders.
	 *
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The list folders
	 */
	private static function fetch_folders() {
		$mc = new Mailchimp( ( self::get_mc_instance() )->api_key() );
		$response = ( self::get_mc_instance() )->validate(
			$mc->get( 'campaign-folders', [ 'count' => 1000 ], 60 ),
			__( 'Error retrieving Mailchimp folders.', 'newspack_newsletters' )
		);
		return $response['folders'];
	}

	/**
	 * Fetches the merge fields for a given List from the Mailchimp server
	 *
	 * @param string $list_id The List ID.
	 * @throws Exception In case of errors while fetching data from the server.
	 * @return array The list interest_categories
	 */
	private static function fetch_merge_fields( $list_id ) {
		$mc = new Mailchimp( ( self::get_mc_instance() )->api_key() );
		$response = ( self::get_mc_instance() )->validate(
			$mc->get(
				"lists/$list_id/merge-fields",
				[
					'count' => 1000,
				],
				60
			),
			__( 'Error retrieving Mailchimp list merge fields.', 'newspack_newsletters' )
		);
		return $response['merge_fields'];
	}
}

Newspack_Newsletters_Mailchimp_Cached_Data::init();
