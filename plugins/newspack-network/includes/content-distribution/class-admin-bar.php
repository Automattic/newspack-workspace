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
	 * @param WP_Post|int $post The post object or ID.
	 *
	 * @return array List of [ 'name', 'url', 'distributed' ] arrays.
	 */
	public static function get_sites( $post ) {
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
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_modal' ] );
	}

	/**
	 * Whether the Newspack UI design system is available to depend on.
	 *
	 * The modal, its snackbar, and its styling come from newspack-plugin's
	 * 'newspack-ui' handle; without it the feature has nothing to render into.
	 *
	 * @return bool
	 */
	private static function newspack_ui_available() {
		return wp_style_is( 'newspack-ui', 'registered' );
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
				'meta'  => [ 'menu_title' => __( 'Distribute', 'newspack-network' ) ],
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
		if ( ! $post || ! self::should_render( $post ) || ! self::newspack_ui_available() ) {
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
		$rows = '';
		foreach ( $sites as $index => $site ) {
			$id    = 'newspack-network-distribute-site-' . (int) $index;
			$state = $site['distributed'] ? ' checked disabled' : '';
			$rows .= sprintf(
				'<label class="newspack-network-distribute-site" for="%1$s"><input type="checkbox" id="%1$s" value="%2$s"%3$s><span class="newspack-network-distribute-site-name">%4$s</span></label>',
				esc_attr( $id ),
				esc_attr( $site['url'] ),
				$state,
				esc_html( $site['name'] )
			);
		}

		$select_all = '';
		if ( count( $sites ) > 1 ) {
			$select_all = sprintf(
				'<label class="newspack-network-distribute-all"><input type="checkbox" class="newspack-network-distribute-all-toggle">%s</label>',
				esc_html__( 'Select all', 'newspack-network' )
			);
		}

		// Inlined so no cross-plugin Newspack_UI_Icons call is needed; a hardcoded, safe literal.
		$close_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--close"><path d="m13.06 12 6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12Z" /></svg>';

		return sprintf(
			'<div class="newspack-ui"><div id="newspack-network-distribute-modal" class="newspack-ui__modal-container" data-state="closed"><div class="newspack-ui__modal-container__overlay"></div><div class="newspack-ui__modal newspack-ui__modal--small" role="dialog" aria-modal="true" aria-labelledby="newspack-network-distribute-modal-title"><header class="newspack-ui__modal__header"><h2 id="newspack-network-distribute-modal-title" class="newspack-ui__font--l">%1$s</h2><button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close"><span class="screen-reader-text">%2$s</span>%3$s</button></header><section class="newspack-ui__modal__content"><fieldset class="newspack-network-distribute-form"><legend class="screen-reader-text">%4$s</legend>%5$s%6$s<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-network-distribute-submit" disabled><span>%7$s</span></button></fieldset></section></div></div></div>',
			esc_html__( 'Distribute to network sites', 'newspack-network' ),
			esc_html__( 'Close', 'newspack-network' ),
			$close_icon,
			esc_html__( 'Distribute to', 'newspack-network' ),
			$select_all,
			$rows,
			esc_html__( 'Distribute', 'newspack-network' )
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
		if ( ! $post || ! self::should_render( $post ) || ! self::newspack_ui_available() ) {
			return;
		}

		wp_enqueue_script(
			'newspack-network-admin-bar',
			plugins_url( '../../dist/admin-bar.js', __FILE__ ),
			[ 'newspack-ui' ],
			self::asset_version( 'dist/admin-bar.js' ),
			true
		);
		wp_register_style(
			'newspack-network-admin-bar',
			plugins_url( '../../dist/admin-bar.css', __FILE__ ),
			[ 'newspack-ui' ],
			self::asset_version( 'dist/admin-bar.css' )
		);
		wp_style_add_data( 'newspack-network-admin-bar', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-network-admin-bar' );

		wp_localize_script(
			'newspack-network-admin-bar',
			'newspack_network_admin_bar',
			[
				'restUrl'       => rest_url( 'newspack-network/v1/content-distribution/distribute/' . $post->ID ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'defaultStatus' => Admin::get_default_distribution_status(),
				'i18n'          => [
					/* translators: %s is the number of network sites selected. */
					'submitCount'          => __( 'Distribute (%s)', 'newspack-network' ),
					'distributed'          => [
						/* translators: %s is the number of network sites distributed to. */
						'singular' => __( 'Distributed to %s network site.', 'newspack-network' ),
						/* translators: %s is the number of network sites distributed to. */
						'plural'   => __( 'Distributed to %s network sites.', 'newspack-network' ),
					],
					'distributedAsDraft'   => [
						/* translators: %s is the number of network sites distributed to. */
						'singular' => __( 'Distributed to %s network site as a draft.', 'newspack-network' ),
						/* translators: %s is the number of network sites distributed to. */
						'plural'   => __( 'Distributed to %s network sites as drafts.', 'newspack-network' ),
					],
					'distributedAsPending' => [
						/* translators: %s is the number of network sites distributed to. */
						'singular' => __( 'Distributed to %s network site as pending review.', 'newspack-network' ),
						/* translators: %s is the number of network sites distributed to. */
						'plural'   => __( 'Distributed to %s network sites as pending review.', 'newspack-network' ),
					],
					'distributedAsPublish' => [
						/* translators: %s is the number of network sites distributed to. */
						'singular' => __( 'Distributed to %s network site and published.', 'newspack-network' ),
						/* translators: %s is the number of network sites distributed to. */
						'plural'   => __( 'Distributed to %s network sites and published.', 'newspack-network' ),
					],
					/* translators: %s is the error message. */
					'error'                => __( 'Could not distribute: %s', 'newspack-network' ),
					'invalidResponse'      => __( 'The site did not return a valid response.', 'newspack-network' ),
					'timeout'              => __( 'The request timed out. Please try again.', 'newspack-network' ),
				],
			]
		);
	}
}
