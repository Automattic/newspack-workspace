<?php
/**
 * Newspack Network Content Distribution admin bar.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Utils\Icons;
use Newspack_Network\Utils\Sites;
use WP_Admin_Bar;
use WP_Post;

/**
 * Front-end admin bar menu for distributing the post being viewed.
 */
class Admin_Bar {

	/**
	 * Memoized get_sites() results, keyed by post ID.
	 *
	 * Three hooks ask the same question about the same post on every front-end
	 * view, and answering it costs a capability chain, a post-meta read and a
	 * network-sites lookup. Nothing that feeds it changes within a request.
	 *
	 * @var array<int, array>
	 */
	private static $sites_cache = [];

	/**
	 * Discard the memoized site lists.
	 *
	 * For tests and long-running processes, where the state behind the memo can
	 * change within one PHP process.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$sites_cache = [];
	}

	/**
	 * Whether the distribute menu should render for a given post.
	 *
	 * Does not check query context (is_singular()); callers on the front end must verify that themselves.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 *
	 * @return bool
	 */
	public static function should_render( $post ) {
		if ( is_admin() ) {
			return false;
		}

		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( ! current_user_can( Admin::CAPABILITY ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		if ( ! in_array( $post->post_type, Content_Distribution_Class::get_distributed_post_types(), true ) ) {
			return false;
		}

		if ( self::is_structural_page( $post ) ) {
			return false;
		}

		if ( Content_Distribution_Class::is_post_incoming( $post ) ) {
			return false;
		}

		// Sites::get_hub() always returns an entry, even a blank one when no role is configured.
		$sites = array_filter(
			Sites::get_all_sites_without_current(),
			function ( $site ) {
				return ! empty( $site['url'] );
			}
		);

		return ! empty( $sites );
	}

	/**
	 * Whether the given page is the site's static front page or its posts page.
	 *
	 * Compares the Reading options directly rather than calling is_front_page()/is_home(),
	 * which need query context should_render() does not have. Gated on 'show_on_front'
	 * because a stale page ID can outlive a switch back to "Your latest posts".
	 *
	 * @param WP_Post $post The post to check.
	 *
	 * @return bool
	 */
	private static function is_structural_page( WP_Post $post ) {
		if ( 'page' !== $post->post_type || 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}

		return in_array( $post->ID, [ (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ], true );
	}

	/**
	 * The network sites the given post can be distributed to.
	 *
	 * Empty whenever the menu should not render, so callers can gate on this
	 * alone rather than re-running should_render() themselves.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 *
	 * @return array List of [ 'name', 'url', 'distributed' ] arrays.
	 */
	public static function get_sites( $post ) {
		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
		if ( isset( self::$sites_cache[ $post_id ] ) ) {
			return self::$sites_cache[ $post_id ];
		}

		self::$sites_cache[ $post_id ] = self::build_sites( $post );

		return self::$sites_cache[ $post_id ];
	}

	/**
	 * Build the site list for the given post, without the memo.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 *
	 * @return array List of [ 'name', 'url', 'distributed' ] arrays.
	 */
	private static function build_sites( $post ) {
		if ( ! self::should_render( $post ) ) {
			return [];
		}

		$post = get_post( $post );

		try {
			$distributed = ( new Outgoing_Post( $post ) )->get_distribution();
		} catch ( \InvalidArgumentException $e ) {
			return [];
		}

		$distributed = array_map( 'untrailingslashit', (array) $distributed );

		$sites = [];
		foreach ( Sites::get_all_sites_without_current() as $site ) {
			$url = untrailingslashit( $site['url'] );
			if ( empty( $url ) ) {
				continue;
			}
			$sites[] = [
				'name'        => $site['name'],
				'url'         => $url,
				'distributed' => in_array( $url, $distributed, true ),
			];
		}

		return $sites;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar_menu' ], 100 );
		// After priority 10, where core's wp_common_block_scripts_and_styles() fires
		// 'enqueue_block_assets' and newspack-plugin registers the 'newspack-ui' handles.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ], 11 );
		add_action( 'wp_footer', [ __CLASS__, 'render_modal' ], 10 );
	}

	/**
	 * Whether the Newspack UI design system is available to depend on.
	 *
	 * The modal, its snackbar, and its styling come from newspack-plugin's
	 * 'newspack-ui' handle; without it the feature has nothing to render into.
	 * Both handles are checked: WordPress silently drops a script whose
	 * dependency is unregistered, which would leave a Distribute button that
	 * opens nothing and logs no error.
	 *
	 * @return bool
	 */
	private static function newspack_ui_available() {
		return wp_style_is( 'newspack-ui', 'registered' ) && wp_script_is( 'newspack-ui', 'registered' );
	}

	/**
	 * The post the menu applies to on the current request.
	 *
	 * @return WP_Post|null
	 */
	private static function get_queried_post() {
		if ( ! is_singular() || is_preview() ) {
			return null;
		}

		$post = get_queried_object();

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Add the distribute menu to the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 *
	 * @return void
	 */
	public static function admin_bar_menu( $wp_admin_bar ) {
		$post = self::get_queried_post();
		if ( ! $post || ! self::newspack_ui_available() ) {
			return;
		}

		$sites = self::get_sites( $post );
		if ( empty( $sites ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			[
				'id'    => 'newspack-network-distribute',
				'title' => sprintf(
					'<span class="newspack-network-distribute-icon">%s</span>%s',
					Icons::broadcast(),
					esc_html__( 'Distribute', 'newspack-network' )
				),
				// A real link so it is natively clickable and keyboard-activatable;
				// the JS preventDefault()s the '#' and opens the modal.
				'href'  => '#',
			]
		);
	}

	/**
	 * Render the distribute modal in the footer.
	 *
	 * A top-level element (outside the admin bar's stacking context), mirroring
	 * Newspack's reader-auth modal.
	 *
	 * @return void
	 */
	public static function render_modal() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$post = self::get_queried_post();
		if ( ! $post || ! self::newspack_ui_available() ) {
			return;
		}

		$sites = self::get_sites( $post );
		if ( empty( $sites ) ) {
			return;
		}

		// get_modal_markup() escapes every dynamic value; the rest is static markup and an inline SVG.
		echo self::get_modal_markup( $sites ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the distribute modal markup.
	 *
	 * Echoed raw, so every dynamic value is escaped here. The outer wrapper carries
	 * the .newspack-ui class so Newspack UI's descendant-scoped button and
	 * checkbox styles apply inside it.
	 *
	 * @param array $sites List of [ 'name', 'url', 'distributed' ] arrays.
	 *
	 * @return string
	 */
	private static function get_modal_markup( array $sites ) {
		// Newspack UI's own layout primitive, so the spacing comes from the design
		// system rather than from this plugin.
		$row_stack   = 'newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--align-center newspack-ui__stack--gap-1';
		$list_stack  = 'newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1';
		$panel_stack = 'newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-5';
		$button_stack = 'newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2';

		$rows = '';
		foreach ( $sites as $index => $site ) {
			$id = 'newspack-network-distribute-site-' . (int) $index;

			// Distribution is additive, so an already-distributed site is checked and
			// locked. The reason is spelled out for assistive tech, which otherwise
			// reports only that the checkbox is disabled.
			$state = '';
			$note  = '';
			if ( $site['distributed'] ) {
				$state = sprintf( ' checked disabled aria-describedby="%s"', esc_attr( $id . '-note' ) );
				$note  = sprintf(
					'<span id="%1$s" class="screen-reader-text">%2$s</span>',
					esc_attr( $id . '-note' ),
					esc_html__( 'Already distributed', 'newspack-network' )
				);
			}

			$rows .= sprintf(
				'<label class="newspack-network-distribute-site %6$s" for="%1$s"><input type="checkbox" id="%1$s" value="%2$s"%3$s><span class="newspack-network-distribute-site-name">%4$s</span>%5$s</label>',
				esc_attr( $id ),
				esc_attr( $site['url'] ),
				$state,
				esc_html( $site['name'] ),
				$note,
				esc_attr( $row_stack )
			);
		}

		$select_all = '';
		if ( count( $sites ) > 1 ) {
			$select_all = sprintf(
				'<label class="newspack-network-distribute-all %2$s"><input type="checkbox" class="newspack-network-distribute-all-toggle">%1$s</label>',
				esc_html__( 'Select all', 'newspack-network' ),
				esc_attr( $row_stack )
			);
		}

		// Inlined so no cross-plugin Newspack_UI_Icons call is needed; a hardcoded, safe literal.
		$close_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--close"><path d="m13.06 12 6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12Z" /></svg>';

		// Distribution is additive and cannot be undone from here, so the selection is
		// confirmed before it is sent. The message is written by the JS, which knows
		// the count; the panel is inert without it. Wide primary over wide ghost,
		// as on the reader-activation screens.
		$confirm = sprintf(
			'<div class="newspack-network-distribute-confirm %3$s" hidden><p class="newspack-network-distribute-confirm-message" tabindex="-1"></p><div class="%4$s"><button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-network-distribute-confirm-submit"><span>%1$s</span></button><button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-network-distribute-back">%2$s</button></div></div>',
			esc_html__( 'Distribute', 'newspack-network' ),
			esc_html__( 'Back', 'newspack-network' ),
			esc_attr( $panel_stack ),
			esc_attr( $button_stack )
		);

		return sprintf(
			'<div class="newspack-ui"><div id="newspack-network-distribute-modal" class="newspack-ui__modal-container" data-state="closed"><div class="newspack-ui__modal-container__overlay"></div><div class="newspack-ui__modal newspack-ui__modal--small" role="dialog" aria-modal="true" aria-labelledby="newspack-network-distribute-modal-title"><header class="newspack-ui__modal__header"><h2 id="newspack-network-distribute-modal-title" class="newspack-ui__font--l">%1$s</h2><button type="button" class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close"><span class="screen-reader-text">%2$s</span>%3$s</button></header><section class="newspack-ui__modal__content"><fieldset class="newspack-network-distribute-form %8$s"><legend class="screen-reader-text">%4$s</legend><div class="%9$s">%5$s%6$s</div><button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-network-distribute-submit" disabled>%7$s</button></fieldset>%10$s</section></div></div></div>',
			esc_html__( 'Distribute to network sites', 'newspack-network' ),
			esc_html__( 'Close', 'newspack-network' ),
			$close_icon,
			esc_html__( 'Distribute to', 'newspack-network' ),
			$select_all,
			$rows,
			esc_html__( 'Distribute', 'newspack-network' ),
			esc_attr( $panel_stack ),
			esc_attr( $list_stack ),
			$confirm
		);
	}

	/**
	 * The cache-busting version for a plugin asset.
	 *
	 * Guards filemtime() because dist/ is absent in an unbuilt checkout.
	 *
	 * @param string $relative_path Path to the asset, relative to the plugin directory.
	 *
	 * @return int|false
	 */
	private static function asset_version( $relative_path ) {
		$path = NEWSPACK_NETWORK_PLUGIN_DIR . $relative_path;

		return file_exists( $path ) ? filemtime( $path ) : false;
	}

	/**
	 * Enqueue the front-end assets.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$post = self::get_queried_post();
		if ( ! $post || ! self::newspack_ui_available() ) {
			return;
		}

		if ( empty( self::get_sites( $post ) ) ) {
			return;
		}

		wp_enqueue_script(
			'newspack-network-admin-bar',
			plugins_url( '../../dist/admin-bar.js', __FILE__ ),
			[ 'newspack-ui', 'wp-i18n' ],
			self::asset_version( 'dist/admin-bar.js' ),
			true
		);
		wp_set_script_translations( 'newspack-network-admin-bar', 'newspack-network', NEWSPACK_NETWORK_PLUGIN_DIR . 'languages' );
		wp_register_style(
			'newspack-network-admin-bar',
			plugins_url( '../../dist/admin-bar.css', __FILE__ ),
			[ 'newspack-ui' ],
			self::asset_version( 'dist/admin-bar.css' )
		);
		wp_style_add_data( 'newspack-network-admin-bar', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-network-admin-bar' );

		// The bundle owns its own strings, via @wordpress/i18n and the 'wp-i18n'
		// dependency above, so plural forms follow the locale's own rules.
		wp_localize_script(
			'newspack-network-admin-bar',
			'newspack_network_admin_bar',
			[
				'restUrl'       => rest_url( 'newspack-network/v1/content-distribution/distribute/' . $post->ID ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'defaultStatus' => Admin::get_default_distribution_status(),
			]
		);
	}
}
