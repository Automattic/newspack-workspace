<?php
/**
 * Starter content management class.
 *
 * @package Newspack
 */

namespace Newspack;

require_once NEWSPACK_COMPOSER_ABSPATH . 'autoload.php';

use WP_Error, Newspack\Theme_Manager, Newspack\Starter_Content_Generated, Newspack\Starter_Content_WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Manages Starter Content generation and cleanup.
 */
class Starter_Content {

	/**
	 * Supported starter content approaches.
	 *
	 * @var array of strings
	 */
	private static $supported_starter_content_approaches = [ 'generated', 'import' ];

	/**
	 * Option key to store active starter content approach.
	 *
	 * @var string
	 */
	private static $starter_content_approach_option = '_newspack_starter_content_approach';

	/**
	 * Option key to store the URL of the site being moved.
	 *
	 * @var string
	 */
	private static $starter_content_site_url_option = '_newspack_starter_content_site_url';

	/**
	 * Post meta key tagging the page built from a homepage layout.
	 *
	 * @var string
	 */
	private static $layout_homepage_meta = '_newspack_layout_homepage';

	/**
	 * Option key holding the store currency the identity import set, until setup completes.
	 *
	 * @var string
	 */
	const IMPORTED_CURRENCY_OPTION = 'newspack_setup_imported_currency';

	/**
	 * Get the active starter content approach.
	 *
	 * @return bool|string False if no starter content provider initialized. One of $supported_starter_content_approaches if initialized.
	 */
	protected static function get_starter_content_approach() {
		$saved = get_option( self::$starter_content_approach_option, false );
		if ( $saved && in_array( $saved, self::$supported_starter_content_approaches ) ) {
			return $saved;
		}

		return false;
	}

	/**
	 * Get the active starter content provider.
	 *
	 * @return bool|Starter_Content_Provider False if no starter content provider initialized. Instance of provider if initialized.
	 */
	protected static function get_starter_content_provider() {
		$approach = self::get_starter_content_approach();
		if ( ! $approach || ! in_array( $approach, self::$supported_starter_content_approaches ) ) {
			return false;
		}

		$provider = 'generated' === $approach ? new Starter_Content_Generated() : new Starter_Content_WordPress();
		return $provider;
	}

	/**
	 * Initialize starter content infra.
	 *
	 * @param string $approach The approach to use when generating starter content ('generated' or 'import').
	 * @param string $existing_site_url URL of site to migrate (optional).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function initialize( $approach, $existing_site_url = '' ) {
		if ( ! in_array( $approach, self::$supported_starter_content_approaches ) ) {
			return new WP_Error( 'newspack_starter_content', __( 'Invalid starter content approach', 'newspack' ) );
		}

		$args     = [
			'site' => $existing_site_url,
		];
		$provider = 'generated' === $approach ? new Starter_Content_Generated() : new Starter_Content_WordPress();
		$result   = $provider::initialize( $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_option( self::$starter_content_approach_option, $approach );
		if ( 'import' === $approach ) {
			update_option( self::$starter_content_site_url_option, trailingslashit( esc_url_raw( $existing_site_url ) ) );
		}
		return true;
	}

	/**
	 * Create the Xth starter post.
	 *
	 * @param int $post_index The number of post to create (1st, second, etc.).
	 * @return int|WP_Error Post ID on success or if post already exists. WP_Error on failure.
	 */
	public static function create_post( $post_index ) {
		$provider = self::get_starter_content_provider();
		if ( ! $provider ) {
			return new WP_Error( 'newspack_starter_content', __( 'Starter content is not initialized correctly', 'newspack' ) );
		}

		return $provider::create_post( $post_index );
	}

	/**
	 * Create the starter homepage.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function create_homepage() {
		$provider = self::get_starter_content_provider();
		if ( ! $provider ) {
			return new WP_Error( 'newspack_starter_content', __( 'Starter content is not initialized correctly', 'newspack' ) );
		}

		return $provider::create_homepage();
	}

	/**
	 * Make sure the site has a front page, without any sample content.
	 *
	 * Keeps an assigned front page that isn't trashed and isn't a layout page, reuses (and republishes)
	 * a layout page built earlier, and otherwise builds one from homepage layout 1.
	 *
	 * @return int|WP_Error Page ID on success, WP_Error on failure.
	 */
	public static function create_layout_homepage(): int|WP_Error {
		$front_id     = (int) get_option( 'page_on_front' );
		$front_status = $front_id ? get_post_status( $front_id ) : false;
		if ( 'page' === get_option( 'show_on_front' ) && $front_status && 'trash' !== $front_status && ! get_post_meta( $front_id, self::$layout_homepage_meta, true ) ) {
			return $front_id;
		}

		$existing = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => 'any',
				'meta_key'       => self::$layout_homepage_meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		if ( ! empty( $existing ) ) {
			$page_id = (int) $existing[0];
			if ( 'publish' !== get_post_status( $page_id ) ) {
				wp_publish_post( $page_id );
			}
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_id );
			return $page_id;
		}

		$pattern = self::get_homepage_pattern( 0 );
		if ( ! $pattern || empty( $pattern['content'] ) ) {
			return new WP_Error( 'newspack_starter_content', __( 'Homepage layout not found', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$page_id = wp_insert_post(
			[
				'post_title'   => __( 'Homepage', 'newspack-plugin' ),
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $pattern['content'],
			],
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}
		update_post_meta( $page_id, self::$layout_homepage_meta, 1 );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		set_theme_mod( 'hide_front_page_title', true );
		return $page_id;
	}

	/**
	 * Whether a page is a homepage the setup wizard built: the layout page or the starter homepage.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	public static function is_setup_homepage( int $page_id ): bool {
		if ( ! $page_id ) {
			return false;
		}
		return (bool) get_post_meta( $page_id, self::$layout_homepage_meta, true ) || Starter_Content_Provider::is_starter_homepage( $page_id );
	}

	/**
	 * Import the identity (title, tagline, logo, icon, currency) of the site being moved.
	 *
	 * @return array|WP_Error Map of imported items, WP_Error if the approach is not import.
	 */
	public static function import_identity(): array|WP_Error {
		if ( 'import' !== self::get_starter_content_approach() ) {
			return new WP_Error( 'newspack_starter_content', __( 'Starter content is not set up to import a site', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		$site_url = (string) get_option( self::$starter_content_site_url_option, '' );
		return Starter_Content_WordPress::import_identity( $site_url );
	}

	/**
	 * Get all homepage layouts.
	 *
	 * @return array[] Homepage patterns.
	 */
	public static function get_homepage_patterns() {
		$patterns_directory = dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/block-patterns/homepage/';
		$homepage_patterns  = [];
		foreach ( scandir( $patterns_directory ) as $file_name ) {
			if ( '.' !== $file_name && '..' !== $file_name ) {
				$homepage_patterns[] = self::load_homepage_pattern( $patterns_directory . $file_name );
			}
		}
		return $homepage_patterns;
	}

	/**
	 * Get one homepage layout.
	 *
	 * @param int $index Zero-based layout index.
	 * @return array|false Homepage pattern, false if not found.
	 */
	public static function get_homepage_pattern( $index ) {
		$index_padded = str_pad( (int) $index + 1, 2, '0', STR_PAD_LEFT );
		$file_path    = dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/block-patterns/homepage/' . $index_padded . '.php';
		return file_exists( $file_path ) ? self::load_homepage_pattern( $file_path ) : false;
	}

	/**
	 * Load a homepage pattern file.
	 *
	 * @param string $file_path File path of the pattern.
	 * @return array The pattern.
	 */
	private static function load_homepage_pattern( $file_path ) {
		// The pattern files expect these variables to be defined.
		$donate_block    = '';
		$subscribe_block = '';
		return require $file_path;
	}

	/**
	 * Determine whether starter content has been created yet.
	 *
	 * @return bool True if yes, false if not.
	 */
	public static function has_created_starter_content() {
		$provider = self::get_starter_content_provider();
		if ( ! $provider ) {
			return false;
		}

		return $provider::has_created_starter_content();
	}

	/**
	 * Remove starter content.
	 */
	public static function remove_starter_content() {
		$provider = self::get_starter_content_provider();
		if ( ! $provider ) {
			return false;
		}

		return $provider::remove_starter_content();
	}

	/**
	 * Set up theme.
	 */
	public static function initialize_theme() {
		Theme_Manager::install_activate_theme();
		set_theme_mod( 'header_solid_background', false );
		set_theme_mod( 'header_simplified', false );
		return true;
	}

	/**
	 * Get theme style.
	 *
	 * @return string Style id.
	 */
	public static function get_theme() {
		$theme_object = wp_get_theme();
		return $theme_object->get_stylesheet();
	}

	/**
	 * Is this an E2E testing environment?
	 *
	 * @return bool E2E testing environment?
	 */
	public static function is_e2e() {
		/**
		 * Indicates the site is running in E2E (end-to-end) testing mode.
		 * Used to adjust behavior during automated testing.
		 *
		 * @constant NEWSPACK_IS_E2E
		 * @type     bool
		 * @default  Normal operation
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_IS_E2E', true );
		 */
		return defined( 'NEWSPACK_IS_E2E' ) && NEWSPACK_IS_E2E;
	}
}
