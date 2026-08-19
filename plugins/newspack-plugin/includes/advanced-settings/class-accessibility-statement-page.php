<?php
/**
 * Newspack Accessibility Statement Page functionality.
 *
 * @package Newspack
 */

namespace Newspack;

/**
 * Accessibility Statement Page class.
 */
final class Accessibility_Statement_Page {

	/**
	 * Option holding the ID of the page the site uses.
	 */
	const OPTION_NAME = 'newspack_accessibility_statement_page_id';

	/**
	 * Where the page ID used to live. Theme mods are per theme, so every theme
	 * and style variation kept its own, and a site lost the page each time it
	 * switched. Still read until the upgrade has run, and still written on
	 * create, so a plugin rolled back to before this change finds the page
	 * where it looks for it instead of making a second one.
	 */
	const LEGACY_THEME_MOD = 'accessibility_statement_page_id';

	/**
	 * Marks the upgrade as done so the theme scan runs once per site, including
	 * on sites that turn out to have nothing to migrate. Autoloaded, so the
	 * debug reset clears it together with the pointer rather than leaving a
	 * site that believes it has already migrated and has nothing to show.
	 */
	const MIGRATION_FLAG = 'newspack_accessibility_statement_migrated';

	/**
	 * The slug reserved for the page.
	 */
	const PAGE_SLUG = 'accessibility-statement';

	/**
	 * Add hooks.
	 */
	public static function init(): void {
		// Register REST routes.
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ], 10 );
		add_action( 'admin_init', [ __CLASS__, 'migrate_legacy_theme_mod' ], 10 );
		// Add post status to accessibility statement page.
		add_filter( 'display_post_states', [ __CLASS__, 'post_status' ], 10, 2 );
	}

	/**
	 * Get the ID of the page the site uses, whether or not it still exists.
	 *
	 * Falls back to the legacy theme mod when no usable page is stored. That
	 * covers the window between the plugin updating and the next admin request,
	 * which on an auto-updating site can be days, and it covers a page an older
	 * version created while the plugin was rolled back.
	 *
	 * @return int The stored page ID, which may point at a page that has since
	 *             been trashed or deleted, or 0 if no pointer was ever stored.
	 */
	public static function get_page_id(): int {
		$page_id = (int) get_option( self::OPTION_NAME, 0 );
		if ( $page_id && self::usable_page( $page_id ) ) {
			return $page_id;
		}

		$legacy_id = (int) get_theme_mod( self::LEGACY_THEME_MOD );
		if ( $legacy_id && self::usable_page( $legacy_id ) ) {
			return $legacy_id;
		}

		return $page_id;
	}

	/**
	 * Get the stored page, if it is still there to be used.
	 *
	 * @return \WP_Post|null The page, or null if none is stored, it has been
	 *                       deleted, or it sits in the trash.
	 */
	private static function get_stored_page(): ?\WP_Post {
		$page_id = self::get_page_id();
		if ( ! $page_id ) {
			return null;
		}

		return self::usable_page( $page_id );
	}

	/**
	 * Resolve a page ID to a page the site can still use.
	 *
	 * @param int $page_id The page ID.
	 * @return \WP_Post|null The page, or null if it is gone, trashed, or not a page.
	 */
	private static function usable_page( int $page_id ): ?\WP_Post {
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return null;
		}

		return $page;
	}

	/**
	 * Shape a page for the wizard and the theme.
	 *
	 * @param \WP_Post $page The page.
	 * @return array{editUrl: ?string, pageUrl: string|false, status: string, title: string} The page data.
	 */
	private static function page_data( \WP_Post $page ): array {
		return [
			'editUrl' => get_edit_post_link( $page->ID, 'raw' ),
			'pageUrl' => get_permalink( $page->ID ),
			'status'  => $page->post_status,
			'title'   => get_the_title( $page->ID ),
		];
	}

	/**
	 * Create an accessibility statement page, unless the site already has one.
	 *
	 * Idempotent against a stored pointer, which is what a repeat click hits.
	 * Two genuinely simultaneous requests can still each insert: WordPress has
	 * no atomic option claim, and a second request's write is invisible to this
	 * one anyway, because the first miss is cached for the rest of the request.
	 *
	 * @return array{editUrl: ?string, pageUrl: string|false, status: string, title: string}|\WP_Error
	 *         The page data, or an error if it could not be created.
	 */
	public static function create_page(): array|\WP_Error {
		$existing = self::get_stored_page();
		if ( $existing ) {
			return self::page_data( $existing );
		}

		// Get the Accessibility Statement boilerplate content.
		ob_start();
		require __DIR__ . '/class-accessibility-statement-boilerplate.php';
		$page_content = ob_get_clean();

		$page_id = wp_insert_post(
			[
				'post_title'   => __( 'Accessibility Statement', 'newspack-plugin' ),
				'post_name'    => self::PAGE_SLUG,
				'post_status'  => 'draft',
				'post_type'    => 'page',
				'post_content' => $page_content,
			],
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		update_option( self::OPTION_NAME, $page_id, true );
		set_theme_mod( self::LEGACY_THEME_MOD, $page_id );

		$page = self::usable_page( $page_id );
		if ( ! $page ) {
			return new \WP_Error(
				'newspack_accessibility_statement_page_missing',
				esc_html__( 'The accessibility statement page could not be created.', 'newspack-plugin' )
			);
		}

		return self::page_data( $page );
	}

	/**
	 * Get accessibility statement page data.
	 * The function to actually output the link lives in the Classic Theme.
	 *
	 * Reading never creates the page. The classic theme footer calls this on
	 * every front-end render, so a write here reaches logged-out visitors.
	 *
	 * TODO: Create a block for the Block Theme that outputs the format we need there.
	 *
	 * @return array{editUrl: ?string, pageUrl: string|false, status: string, title: string}|false
	 *         The page data, or false if the site has no usable page.
	 */
	public static function get_page(): array|false {
		$page = self::get_stored_page();

		return $page ? self::page_data( $page ) : false;
	}

	/**
	 * Move the page ID from theme mods to a site-wide option.
	 *
	 * @return void
	 */
	public static function migrate_legacy_theme_mod(): void {
		// admin_init also fires on admin-ajax.php and admin-post.php, both
		// reachable logged out. Anonymous requests should not pay for the scan.
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		if ( (int) get_option( self::OPTION_NAME, 0 ) ) {
			return;
		}

		if ( get_option( self::MIGRATION_FLAG ) ) {
			// Rolling the plugin back and forward again leaves a pointer on the
			// active theme, worth adopting without repeating the whole scan.
			$legacy_id = (int) get_theme_mod( self::LEGACY_THEME_MOD );
			$legacy    = $legacy_id ? get_post( $legacy_id ) : null;
			if ( $legacy && 'page' === $legacy->post_type ) {
				update_option( self::OPTION_NAME, $legacy_id, true );
			}
			return;
		}

		$page_id = self::find_legacy_page_id();
		if ( $page_id ) {
			update_option( self::OPTION_NAME, $page_id, true );
			// The page may have come from a theme that is no longer active;
			// a rolled-back plugin only looks at the active one.
			set_theme_mod( self::LEGACY_THEME_MOD, $page_id );
		} elseif ( get_theme_mod( self::LEGACY_THEME_MOD ) ) {
			remove_theme_mod( self::LEGACY_THEME_MOD );
		}

		// Recorded last: a request that dies inside the scan should retry on the
		// next one rather than abandon the site's page for good.
		update_option( self::MIGRATION_FLAG, 1, true );
	}

	/**
	 * Find the page a site stored before the ID moved to an option.
	 *
	 * A published page wins over a draft: where a site accumulated duplicates,
	 * the published one is the statement the publisher maintains. A trashed page
	 * is still worth pointing at, so restoring it brings the link back rather
	 * than the pointer being lost for good.
	 *
	 * @return int The page ID, or 0 if none was found.
	 */
	private static function find_legacy_page_id(): int {
		$candidates = [];
		foreach ( self::legacy_page_ids() as $page_id ) {
			$page = get_post( $page_id );
			if ( $page && 'page' === $page->post_type ) {
				$candidates[] = $page;
			}
		}

		foreach ( $candidates as $page ) {
			if ( 'publish' === $page->post_status ) {
				return $page->ID;
			}
		}

		foreach ( $candidates as $page ) {
			if ( 'trash' !== $page->post_status ) {
				return $page->ID;
			}
		}

		return $candidates ? $candidates[0]->ID : 0;
	}

	/**
	 * Every page ID a theme's mods point at, active theme or not.
	 *
	 * Read from the options table rather than through wp_get_themes(): mods are
	 * keyed by stylesheet and survive the theme being deleted, so enumerating
	 * installed themes would miss exactly the sites that switched away and
	 * tidied up. Runs once per site, behind the migration flag.
	 *
	 * @return int[]
	 */
	private static function legacy_page_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				$wpdb->esc_like( 'theme_mods_' ) . '%'
			)
		);

		$page_ids = [ (int) get_theme_mod( self::LEGACY_THEME_MOD ) ];
		foreach ( (array) $option_names as $option_name ) {
			$mods       = get_option( $option_name );
			$page_ids[] = is_array( $mods ) ? (int) ( $mods[ self::LEGACY_THEME_MOD ] ?? 0 ) : 0;
		}

		return array_unique( array_filter( $page_ids ) );
	}

	/**
	 * Register REST API endpoints.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'newspack/v1',
			'/wizard/newspack-settings/accessibility-statement',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'api_get_page' ],
					'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
					'args'                => [],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'api_create_page' ],
					'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
					'args'                => [],
				],
			]
		);
	}

	/**
	 * API callback for creating accessibility statement page.
	 *
	 * @return \WP_REST_Response|\WP_Error Response object.
	 */
	public static function api_create_page(): \WP_REST_Response|\WP_Error {
		$result = self::create_page();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * API callback for getting accessibility statement page.
	 *
	 * Where there is no page, the response says whether the site has never
	 * created one ('none') or created one that has since gone ('missing'), so
	 * the wizard can explain which of the two the publisher is looking at.
	 *
	 * @return \WP_REST_Response|\WP_Error Response object.
	 */
	public static function api_get_page(): \WP_REST_Response|\WP_Error {
		$page_data = self::get_page();
		if ( $page_data ) {
			return rest_ensure_response( $page_data );
		}

		return rest_ensure_response( [ 'status' => self::get_page_id() ? 'missing' : 'none' ] );
	}

	/**
	 * Check capabilities for using API.
	 *
	 * @return bool|\WP_Error
	 */
	public static function api_permissions_check(): bool|\WP_Error {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error(
				'newspack_rest_forbidden',
				esc_html__( 'You cannot use this resource.', 'newspack-plugin' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Add post status to accessibility statement page.
	 *
	 * @param mixed $post_states The post states, normally an array.
	 * @param mixed $post The post object, normally a WP_Post.
	 * @return array The post states.
	 */
	public static function post_status( $post_states, $post = null ): array {
		if ( ! is_array( $post_states ) ) {
			$post_states = [];
		}

		if ( $post instanceof \WP_Post && $post->ID === self::get_page_id() ) {
			$post_states['accessibility_statement'] = __( 'Accessibility Statement', 'newspack-plugin' );
		}
		return $post_states;
	}
}
Accessibility_Statement_Page::init();
