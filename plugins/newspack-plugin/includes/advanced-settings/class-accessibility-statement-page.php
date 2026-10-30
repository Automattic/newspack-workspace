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
	 * where it looks for it instead of making a second one. Both the write and
	 * the read only ever see the active theme, so the breadcrumb covers a
	 * rollback on a site that has not switched theme since; rolling forward
	 * re-adopts from the option either way.
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
	 * What the stored pointer resolved to this request, keyed by the values it
	 * was resolved from. get_post() caches a hit but not a miss, so a pointer
	 * at a deleted page would otherwise go back to the database on every call,
	 * which on the Pages list table is once per row.
	 *
	 * The blog ID is part of the key because this is a plain static: page IDs
	 * repeat across a network, so without it a switch_to_blog() could be handed
	 * another site's page.
	 *
	 * @var array{key: string, id: int, page: \WP_Post|null}|null
	 */
	private static ?array $resolved = null;

	/**
	 * Add hooks.
	 */
	public static function init(): void {
		// Register REST routes.
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ], 10 );
		add_action( 'admin_init', [ __CLASS__, 'migrate_legacy_theme_mod' ], 10 );
		// Trashing, restoring or deleting the page changes what the pointer
		// resolves to without changing the pointer itself.
		add_action( 'clean_post_cache', [ __CLASS__, 'forget_resolved_page' ], 10 );
		// Add post status to accessibility statement page.
		add_filter( 'display_post_states', [ __CLASS__, 'post_status' ], 10, 2 );
	}

	/**
	 * Drop the resolved pointer so the next read looks it up again.
	 *
	 * @return void
	 */
	public static function forget_resolved_page(): void {
		self::$resolved = null;
	}

	/**
	 * Resolve the stored pointer to an ID and, where it still exists, a page.
	 *
	 * @return array{key: string, id: int, page: \WP_Post|null}
	 */
	private static function resolve(): array {
		$page_id   = (int) get_option( self::OPTION_NAME, 0 );
		$legacy_id = (int) get_theme_mod( self::LEGACY_THEME_MOD );
		$key       = get_current_blog_id() . ':' . $page_id . ':' . $legacy_id;

		if ( self::$resolved && $key === self::$resolved['key'] ) {
			return self::$resolved;
		}

		$page = $page_id ? self::usable_page( $page_id ) : null;
		if ( ! $page && $legacy_id ) {
			$legacy = self::usable_page( $legacy_id );
			if ( $legacy ) {
				$page_id = $legacy_id;
				$page    = $legacy;
			}
		}

		self::$resolved = [
			'key'  => $key,
			'id'   => $page_id,
			'page' => $page,
		];

		return self::$resolved;
	}

	/**
	 * Get the ID of the page the site uses, whether or not it still exists.
	 *
	 * Falls back to the legacy theme mod when no usable page is stored. That
	 * covers the window between the plugin updating and the next admin request,
	 * which on an auto-updating site can be days, and it covers a page an older
	 * version created while the plugin was rolled back.
	 *
	 * That fallback reads the active theme only, and skips a trashed page,
	 * where the upgrade scan reads every theme's mods and keeps a trashed page
	 * on purpose. So a site that switched theme before updating has no link
	 * until the upgrade runs; widening the fallback would put a scan of every
	 * theme's mods on every front-end render.
	 *
	 * @return int The stored page ID, which may point at a page that has since
	 *             been trashed or deleted, or 0 if no pointer was ever stored.
	 */
	public static function get_page_id(): int {
		return self::resolve()['id'];
	}

	/**
	 * Get the stored page, if it is still there to be used.
	 *
	 * @return \WP_Post|null The page, or null if none is stored, it has been
	 *                       deleted, or it sits in the trash.
	 */
	private static function get_stored_page(): ?\WP_Post {
		return self::resolve()['page'];
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
		// reachable logged out, and every logged-in reader reaches the admin
		// through their profile. Only a user who could create the page pays for
		// the scan and its writes; the legacy fallback in get_page_id() covers
		// the site until one of them shows up.
		if ( wp_doing_ajax() || ! current_user_can( 'edit_pages' ) ) {
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
				self::store_page_id( $legacy_id );
			}
			return;
		}

		$page_id = self::find_legacy_page_id();
		if ( $page_id ) {
			// The page may have come from a theme that is no longer active; a
			// rolled-back plugin only looks at the active one, so the breadcrumb
			// has to name the page the site ends up on, not the one found here.
			set_theme_mod( self::LEGACY_THEME_MOD, self::store_page_id( $page_id ) );
		} elseif ( get_theme_mod( self::LEGACY_THEME_MOD ) ) {
			remove_theme_mod( self::LEGACY_THEME_MOD );
		}

		// Recorded last: a request that dies inside the scan should retry on the
		// next one rather than abandon the site's page for good.
		update_option( self::MIGRATION_FLAG, 1, true );
	}

	/**
	 * Store the pointer, unless another request stored one while we were looking.
	 *
	 * The decision to write is made before a lookup that costs several queries,
	 * so a create_page() can land in the gap, and its page is the one the site
	 * should keep. add_option() cannot make that call here: the miss this request
	 * already cached leaves the option in `notoptions`, which is exactly the case
	 * where add_option() skips its own existence check and overwrites. Dropping
	 * the two cache entries first is what makes the re-read see a concurrent
	 * write. That narrows the window to the re-read itself rather than closing
	 * it; no option API can do better, and the cost lands once per site.
	 *
	 * All three option cache entries go, not just the two the autoloaded row
	 * uses: an autoload optimiser can leave the row out of `alloptions`, and the
	 * per-option entry would then serve this request's own miss back to us.
	 *
	 * @param int $page_id The page ID to store.
	 * @return int The page ID the site points at, which is not the one passed in
	 *             when another request got there first.
	 */
	private static function store_page_id( int $page_id ): int {
		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$stored = (int) get_option( self::OPTION_NAME, 0 );
		if ( $stored ) {
			return $stored;
		}

		update_option( self::OPTION_NAME, $page_id, true );

		return $page_id;
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
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'api_create_page' ],
					'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
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
	 * Where there is no page, the response carries a `reason` instead of the
	 * page: whether the site has never created one ('none') or created one that
	 * has since gone ('missing'), so the wizard can explain which of the two the
	 * publisher is looking at. It is its own key because `status` is a post
	 * status, and a client testing `status` against these two names would
	 * misread any post status registered under either.
	 *
	 * @return \WP_REST_Response|\WP_Error Response object.
	 */
	public static function api_get_page(): \WP_REST_Response|\WP_Error {
		$page_data = self::get_page();
		if ( $page_data ) {
			return rest_ensure_response( $page_data );
		}

		return rest_ensure_response( [ 'reason' => self::get_page_id() ? 'missing' : 'none' ] );
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
	 * Core always passes an array and a WP_Post. Another plugin calling the
	 * filter with something else gets it back untouched, rather than having
	 * whatever earlier filters contributed replaced with an empty array.
	 *
	 * @param mixed $post_states The post states, normally an array.
	 * @param mixed $post The post object, normally a WP_Post.
	 * @return mixed The post states.
	 */
	public static function post_status( $post_states, $post = null ) {
		if ( is_array( $post_states ) && $post instanceof \WP_Post && $post->ID === self::get_page_id() ) {
			$post_states['accessibility_statement'] = __( 'Accessibility Statement', 'newspack-plugin' );
		}
		return $post_states;
	}
}
Accessibility_Statement_Page::init();
