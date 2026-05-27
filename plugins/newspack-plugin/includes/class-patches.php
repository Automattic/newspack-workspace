<?php
/**
 * Patches for known issues affecting Newspack sites.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Patches {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'wpseo_enhanced_slack_data', [ __CLASS__, 'use_cap_for_slack_preview' ] );
		add_action( 'admin_menu', [ __CLASS__, 'add_patterns_menu_link' ] );
		add_action( 'admin_menu', [ __CLASS__, 'add_pattern_categories_menu_link' ] );
		add_action( 'admin_menu', [ __CLASS__, 'remove_core_appearance_menu_links' ] );
		add_action( 'manage_edit-wp_block_columns', [ __CLASS__, 'add_custom_columns' ] );
		add_action( 'manage_edit-wp_block_sortable_columns', [ __CLASS__, 'add_sortable_columns' ] );
		add_action( 'manage_wp_block_posts_custom_column', [ __CLASS__, 'custom_column_content' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'add_pattern_category_filter' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_patterns_by_category' ] );
		add_filter( 'manage_edit-wp_pattern_category_columns', [ __CLASS__, 'remove_pattern_category_count' ] );
		add_action( 'manage_wp_pattern_category_custom_column', [ __CLASS__, 'render_pattern_category_posts_count' ], 10, 3 );
		add_filter( 'map_meta_cap', [ __CLASS__, 'prevent_accidental_page_deletion' ], 10, 4 );
		add_action( 'pre_post_update', [ __CLASS__, 'prevent_unpublish_front_page' ], 10, 2 );
		add_action( 'pre_get_posts', [ __CLASS__, 'maybe_display_author_page' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'restrict_others_posts' ] );
		add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'restrict_media_library_access_ajax' ] );
		add_filter( 'script_loader_tag', [ __CLASS__, 'add_async_defer_support' ], 10, 2 );
		add_filter( 'script_loader_tag', [ __CLASS__, 'add_amp_plus_attr_support' ], 10, 2 );

		// Disable WooCommerce image regeneration to prevent regenerating thousands of images.
		add_filter( 'woocommerce_background_image_regeneration', '__return_false' );

		// Disable Publicize automated sharing for WooCommerce products.
		add_action( 'init', [ __CLASS__, 'disable_publicize_for_products' ] );

		// Fix an issue when running The Events Calendar where all posts block items have same date.
		add_action( 'tribe_events_views_v2_after_make_view', [ __CLASS__, 'remove_tec_extra_excerpt_filtering' ], 1 );

		// Prevent Customizer "Additional CSS" from leaking into the iframed block editor in WP 7.0+.
		if ( version_compare( get_bloginfo( 'version' ), '7.0', '>=' ) ) {
			add_filter( 'block_editor_settings_all', [ __CLASS__, 'strip_customizer_css_from_editor' ], 999 );
		}
	}

	/**
	 * Add async/defer support to `wp_script_add_data()`
	 *
	 * See https://github.com/WordPress/WordPress/blob/bab3bdf2df4ea57766793932719665a14c810698/wp-content/themes/twentytwenty/classes/class-twentytwenty-script-loader.php.
	 *
	 * @link https://core.trac.wordpress.org/ticket/12009
	 *
	 * @param string $tag The script tag.
	 * @param string $handle The script handle.
	 *
	 * @return @string Script HTML string.
	 */
	public static function add_async_defer_support( $tag, $handle ) {
		foreach ( array( 'async', 'defer' ) as $attr ) {
			if ( ! wp_scripts()->get_data( $handle, $attr ) ) {
				continue;
			}
			// Prevent adding attribute when already added in #12009.
			if ( ! preg_match( ":\s$attr(=|>|\s):", $tag ) ) {
				$tag = preg_replace( ':(?=></script>):', " $attr", $tag, 1 );
			}
			// Only allow async or defer, not both.
			break;
		}
		return $tag;
	}

	/**
	 * Similar to async/defer support from `add_async_defer_support()`, adds
	 * 'amp-plus' support to `wp_script_add_data()`.
	 *
	 * @param string $tag The script tag.
	 * @param string $handle The script handle.
	 *
	 * @return @string Script HTML string.
	 */
	public static function add_amp_plus_attr_support( $tag, $handle ) {
		$data_name = 'amp-plus';
		$attr      = 'data-amp-plus-allowed';
		if ( ! wp_scripts()->get_data( $handle, $data_name ) ) {
			return $tag;
		}
		if ( ! preg_match( ":\s$attr(=|>|\s):", $tag ) ) {
			$tag = preg_replace( ':(?=></script>):', " $attr", $tag, 1 );
		}
		return $tag;
	}

	/**
	 * Use the Co-Author in Slack preview metadata instead of the regular post author if needed.
	 *
	 * @param array $slack_data Array of data which will be output in twitter:data tags.
	 * @return array Modified $slack_data
	 */
	public static function use_cap_for_slack_preview( $slack_data ) {
		if ( function_exists( 'coauthors' ) && is_single() && isset( $slack_data[ __( 'Written by', 'wordpress-seo' ) ] ) ) {
			$slack_data[ __( 'Written by', 'wordpress-seo' ) ] = coauthors( null, null, null, null, false );
		}

		return $slack_data;
	}

	/**
	 * Add a menu link in WP Admin to easily edit and manage patterns.
	 */
	public static function add_patterns_menu_link() {
		add_submenu_page( 'edit.php', 'manage_patterns', __( 'Patterns' ), 'edit_posts', 'edit.php?post_type=wp_block', '', 2 );
	}

	/**
	 * Add a menu link in WP Admin to easily edit and manage pattern categories.
	 */
	public static function add_pattern_categories_menu_link() {
		add_submenu_page( 'edit.php', 'manage_pattern_categories', __( 'Pattern Categories', 'newspack-plugin' ), 'edit_posts', 'edit-tags.php?taxonomy=wp_pattern_category', '', 3 );
	}

	/**
	 * Remove the Design link from the Appearance menu for the Classic theme.
	 */
	public static function remove_core_appearance_menu_links() {
		if ( wp_is_block_theme() ) {
			return;
		}
		remove_submenu_page( 'themes.php', 'site-editor.php' );
	}

	/**
	 * Add a custom column to the patterns list table.
	 *
	 * @param array $columns An associative array of column headings.
	 */
	public static function add_custom_columns( $columns ) {
		$columns['sync_status'] = __( 'Sync status', 'newspack' );
		return $columns;
	}

	/**
	 * Add a sortable custom column to the patterns list table.
	 *
	 * @param array $columns An associative array of column headings.
	 */
	public static function add_sortable_columns( $columns ) {
		$columns['sync_status'] = __( 'Sync status', 'newspack' );
		return $columns;
	}

	/**
	 * Render sync status in the custom column content.
	 *
	 * @param string $column The column name.
	 * @param int    $post_id The post ID.
	 */
	public static function custom_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'sync_status':
				$sync_status = get_post_meta( $post_id, 'wp_pattern_sync_status', true );
				printf(
					'%s',
					empty( $sync_status ) ? esc_html( __( 'synced', 'newspack' ) ) : esc_html( $sync_status )
				);
				break;
		}
	}

	/**
	 * Add pattern category filter dropdown to the patterns list page.
	 */
	public static function add_pattern_category_filter() {
		global $typenow;

		// Only show on wp_block post type.
		if ( 'wp_block' !== $typenow ) {
			return;
		}

		$taxonomy = 'wp_pattern_category';
		$terms    = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			]
		);

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return;
		}

		$current_term = isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<label class="screen-reader-text" for="filter-by-pattern-category"><?php esc_html_e( 'Filter by Pattern Category', 'newspack-plugin' ); ?></label>
		<select name="<?php echo esc_attr( $taxonomy ); ?>" id="filter-by-pattern-category">
			<option value=""><?php esc_html_e( 'All Pattern Categories', 'newspack-plugin' ); ?></option>
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $current_term, $term->slug ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Filter patterns by category when the filter is applied.
	 *
	 * @param WP_Query $query The query object.
	 */
	public static function filter_patterns_by_category( $query ) {
		global $pagenow, $typenow;

		// Only apply on the patterns list page.
		if ( ! is_admin() || 'edit.php' !== $pagenow || 'wp_block' !== $typenow || ! $query->is_main_query() ) {
			return;
		}

		$taxonomy = 'wp_pattern_category';
		$term_slug = isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! empty( $term_slug ) ) {
			$query->set(
				'tax_query',
				[
					[
						'taxonomy' => $taxonomy,
						'field'    => 'slug',
						'terms'    => $term_slug,
					],
				]
			);
		}
	}

	/**
	 * Dynamically look up post IDs for protected pages.
	 *
	 * @return array Array of post IDs for "special" pages.
	 */
	public static function get_protected_page_ids() {
		$protected_page_ids = [];

		// Homepage.
		$front_page = intval( get_option( 'page_on_front', -1 ) );
		if ( 0 < $front_page ) {
			$protected_page_ids[] = $front_page;
		}

		// Blog posts page.
		$posts_page = intval( get_option( 'page_for_posts', -1 ) );
		if ( 0 < $posts_page ) {
			$protected_page_ids[] = $posts_page;
		}

		// Privacy policy page.
		$privacy_policy = intval( get_option( 'wp_page_for_privacy_policy', -1 ) );
		if ( 0 < $privacy_policy ) {
			$protected_page_ids[] = $privacy_policy;
		}

		// Donate page.
		$donate_page = intval( get_option( Donations::DONATION_PAGE_ID_OPTION, -1 ) );
		if ( 0 < $donate_page ) {
			$protected_page_ids[] = $donate_page;
		}

		// WooCommerce pages.
		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'wc_privacy_policy_page_id' ) ) {
			// WooCommerce myaccount page.
			$account_page = wc_get_page_id( 'myaccount' );
			if ( 0 < $account_page ) {
				$protected_page_ids[] = $account_page;
			}

			// WooCommerce shop page.
			$shop = wc_get_page_id( 'shop' );
			if ( 0 < $shop ) {
				$protected_page_ids[] = $shop;
			}

			// WooCommerce cart page.
			$cart = wc_get_page_id( 'cart' );
			if ( 0 < $cart ) {
				$protected_page_ids[] = $cart;
			}

			// WooCommerce checkout page.
			$checkout = wc_get_page_id( 'checkout' );
			if ( 0 < $checkout ) {
				$protected_page_ids[] = $checkout;
			}

			// WooCommerce view_order page.
			$view_order = wc_get_page_id( 'view_order' );
			if ( 0 < $view_order ) {
				$protected_page_ids[] = $view_order;
			}

			// WooCommerce terms page.
			$terms = wc_get_page_id( 'terms' );
			if ( 0 < $terms ) {
				$protected_page_ids[] = $terms;
			}

			// WooCommerce privacy policy page.
			$wc_privacy_policy = wc_privacy_policy_page_id();
			if ( 0 < $wc_privacy_policy ) {
				$protected_page_ids[] = $wc_privacy_policy;
			}
		}

		return $protected_page_ids;
	}

	/**
	 * Prevent deletion of essential pages such as the homepage, blog posts page, and WooCommerce pages.
	 *
	 * @param array  $caps Primitive capabilities required of the user.
	 * @param string $cap Capability being checked.
	 * @param int    $user_id The user ID.
	 * @param array  $args Adds context to the capability check, typically starting with an object ID.
	 *
	 * @return array Filtered array of capabilities.
	 */
	public static function prevent_accidental_page_deletion( $caps, $cap, $user_id, $args ) {
		// If no $args to check, bail early.
		if ( empty( $args ) ) {
			return $caps;
		}

		$post_id = $args[0]; // First item is usually the post ID.

		// If $post_id isn't a valid post, bail early.
		if ( false === get_post_type( $post_id ) ) {
			return $caps;
		}

		// If the current page ID is protected, and the capability being checked is for deletion, do not allow.
		if ( 'delete_post' === $cap && in_array( $post_id, self::get_protected_page_ids(), true ) ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	/**
	 * Prevent unpublishing of the homepage.
	 *
	 * @param int   $post_id ID of post being updated.
	 * @param array $data Array of unslashed post data.
	 */
	public static function prevent_unpublish_front_page( $post_id, $data ) {
		$homepage_id = intval( get_option( 'page_on_front', 0 ) );

		// No need to run if not the homepage, or the homepage isn't a static page.
		if ( ! $homepage_id || $homepage_id !== $post_id ) {
			return;
		}

		$post         = get_post( $post_id );
		$old_status   = $post->post_status;
		$new_status   = $data['post_status'];
		$is_published = 'publish' === $old_status;

		// Prevent attempts to unpublish a published homepage.
		if ( $is_published && 'publish' !== $new_status ) {
			wp_die( __( 'Please choose a new homepage before attempting to unpublish this page.', 'newspack-plugin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Force author pages for non-valid author roles to 404.
	 * Prevents author pages for users like subscribers and donors from being publicly accessible.
	 *
	 * @param WP_Query $query The WP query object.
	 */
	public static function maybe_display_author_page( $query ) {
		if ( $query->is_admin() || ! $query->is_main_query() || ! $query->is_author() ) {
			return;
		}

		$author_name = $query->query_vars['author_name'];
		$user        = get_user_by( 'slug', $author_name );

		// For CAP guest authors, $user will be false.
		if ( ! $user || ! isset( $user->roles ) ) {
			return;
		}

		if ( is_array( $user->roles ) ) {
			/**
			 * Filter to add/remove the default user roles that are allowed to have public author archives.
			 *
			 * @param array $allowed_user_roles Array of WP user roles that can have author archives,
			 */
			$allowed_user_roles = apply_filters(
				'newspack_user_roles_with_author_archives',
				[
					'administrator',
					'editor',
					'author',
					'contributor',
					Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
				]
			);

			// Sometimes, authors who leave a publication are set to be subscribers. We still want those authors to have archives.
			$has_no_posts = function_exists( '\wpcom_vip_count_user_posts' ) ?
				0 === (int) \wpcom_vip_count_user_posts( $user->ID, 'post', true ) :
				0 === (int) count_user_posts( $user->ID, 'post', true ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts

			if ( $has_no_posts && 0 === count( array_intersect( $user->roles, $allowed_user_roles ) ) ) {
				$query->set_404();
			}
		}
	}

	/**
	 * Restrict non-privileged users from seeing posts not owned by them.
	 * An author without the edit_others_* cap will not be able to edit the posts,
	 * but still can view the list of posts. This method prevents that.
	 * Affects all admin post lists and the legacy (non-AJAX) media library list page.
	 *
	 * @param WP_Query $query Query to alter.
	 */
	public static function restrict_others_posts( $query ) {
		global $current_screen;
		$current_user_id = get_current_user_id();

		// If not in a dashboard page or there's no user to check permissions for.
		if ( ! $current_screen || ! $current_user_id || ! $query->is_main_query() ) {
			return;
		}

		$is_media_library = 'upload' === $current_screen->id && 'attachment' === $query->get( 'post_type' );
		$is_posts_list    = 'edit' === $current_screen->base;

		// If the user can't edit others' posts, only allow them to view their own posts.
		if (
			( $is_media_library || $is_posts_list )
			&& ! Capabilities::current_user_can( 'edit_others_posts', $current_screen->post_type )
		) {
			$query->set( 'author', $current_user_id ); // phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts
			add_filter( 'wp_count_posts', [ __CLASS__, 'fix_post_counts' ], 10, 2 );
		}
	}

	/**
	 * Updates the post counts shown alongside each status in admin post lists.
	 *
	 * @param object $counts Post counts keyed by status.
	 * @param string $type Post type.
	 *
	 * @return object Filtered $counts object.
	 */
	public static function fix_post_counts( $counts, $type ) {
		$current_user_id = get_current_user_id();
		foreach ( (array) $counts as $status => $count ) {
			if ( 0 < $count ) {
				$args = [
					'author'      => $current_user_id,
					'post_status' => $status,
					'post_type'   => $type,
				];

				$results         = new \WP_Query( $args );
				$counts->$status = $results->found_posts;
			}
		}
		return $counts;
	}

	/**
	 * Restrict non-privileged users from seeing media library items not uploaded by them.
	 * Affects media library AJAX requests.
	 *
	 * @param array $query_args Query args for the AJAX request.
	 *
	 * @return array Filtered query args.
	 */
	public static function restrict_media_library_access_ajax( $query_args ) {
		$current_user_id = get_current_user_id();

		if (
			$current_user_id
			&& ! current_user_can( 'edit_others_posts' )
			&& ! current_user_can( 'edit_files' )
			&& ! current_user_can( 'newspack_view_others_media' )
		) {
			$query_args['author'] = $current_user_id;
		}

		return $query_args;
	}

	/**
	 * Disable automated social media sharing of WooCommerce products via Publicize.
	 */
	public static function disable_publicize_for_products() {
		remove_post_type_support( 'product', 'publicize' );
	}

	/**
	 * The 'action_include_filters_excerpt' hooked on this action to modify the 'Read More' text by The Events Calendar
	 * causes issues because of the weird `avoiding_filter_loop` usage in the call stack. It introduces a race condition that
	 * messes up the query that the posts block uses by resetting the query early, and WP will think the current posts block item is
	 * the parent Page that the posts block is embedded on.
	 *
	 * @see https://github.com/the-events-calendar/the-events-calendar/blob/0b8caed6049ee6c16bb3d1e06ea9026d995f636e/src/Tribe/Views/V2/Hooks.php#L92
	 * @see https://github.com/the-events-calendar/the-events-calendar/blob/77910c9de7f5640064d4eef4eb4841a523f27719/src/Tribe/Views/V2/Template/Excerpt.php#L71-L73
	 */
	public static function remove_tec_extra_excerpt_filtering() {
		remove_all_actions( 'tribe_events_views_v2_after_make_view' );
	}

	/**
	 * Remove the Customizer "Additional CSS" entry from the block editor styles array.
	 *
	 * WP core injects `wp_get_custom_css()` into the editor's styles array as
	 * `[ '__unstableType' => 'user', 'isGlobalStyles' => false ]`. Since WP 7.0
	 * iframes the post editor when all blocks use Block API v3+, any front-end
	 * `body { ... }` rule in Customizer → Additional CSS now lands on the
	 * editor canvas. The front-end is unaffected — that copy is printed by
	 * `wp_custom_css_cb()` on `wp_head`.
	 *
	 * @param array $settings Block editor settings.
	 * @return array
	 */
	public static function strip_customizer_css_from_editor( $settings ) {
		if ( empty( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			return $settings;
		}

		/**
		 * Filters whether to strip Customizer Additional CSS from the editor iframe.
		 *
		 * @param bool $strip Default true.
		 */
		if ( ! apply_filters( 'newspack_strip_customizer_css_in_editor', true ) ) {
			return $settings;
		}

		// Note: `__unstableType` is a private WP core key. Re-verify on WP upgrades.
		$settings['styles'] = array_values(
			array_filter(
				$settings['styles'],
				function ( $entry ) {
					return ! (
						isset( $entry['__unstableType'] )
						&& 'user' === $entry['__unstableType']
						&& empty( $entry['isGlobalStyles'] )
					);
				}
			)
		);

		return $settings;
	}

	/**
	 * Remove the pattern category count from the edit screen and add custom posts count column.
	 *
	 * @param array $columns The columns array.
	 * @return array Modified columns array.
	 */
	public static function remove_pattern_category_count( $columns ) {
		unset( $columns['posts'] );
		$columns['posts_count'] = __( 'Patterns Count', 'newspack-plugin' );
		return $columns;
	}

	/**
	 * Render the custom posts count column for pattern categories.
	 *
	 * @param string $content The column content.
	 * @param string $column_name The column name.
	 * @param int    $term_id The term ID.
	 */
	public static function render_pattern_category_posts_count( $content, $column_name, $term_id ) {
		if ( 'posts_count' !== $column_name ) {
			return $content;
		}

		$term = get_term( $term_id, 'wp_pattern_category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $content;
		}

		$count = $term->count;
		$url   = admin_url( 'edit.php?post_type=wp_block&wp_pattern_category=' . $term->slug );

		printf(
			'<a href="%s">%d</a>',
			esc_url( $url ),
			esc_html( $count )
		);
	}
}
Patches::init();
