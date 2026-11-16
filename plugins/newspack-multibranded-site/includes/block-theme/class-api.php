<?php
/**
 * Newspack Multibranded site: block theme brand settings API.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Block_Theme;

use Newspack_Multibranded_Site\Taxonomy;

/**
 * What the brand settings screen needs on a block theme, and the endpoints that make copies
 * of menus and template parts for a brand.
 *
 * Brand choices themselves are saved as brand term meta through the brand REST endpoint, with
 * the rest of the brand's settings. Copies are made straight away, since they're regular menus
 * and template parts; the screen then selects the copy, and saving the brand applies it.
 */
class Api {

	const NAMESPACE = 'newspack-multibranded-site/v1';

	/**
	 * Initializes.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Registers the routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/brands/(?P<id>\d+)/copy-part',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'copy_part' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'slot'    => [
						'type'     => 'string',
						'enum'     => Block_Theme::SLOTS,
						'required' => true,
					],
					'replace' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/brands/(?P<id>\d+)/copy-menu',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'copy_menu' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'source' => [
						'type'     => 'integer',
						'required' => true,
					],
					'empty'  => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
	}

	/**
	 * Copies are menus and template parts, so they need the Site Editor capability as well
	 * as the one for managing brands.
	 *
	 * @return bool
	 */
	public static function permission_callback() {
		$taxonomy = get_taxonomy( Taxonomy::SLUG );
		return $taxonomy && current_user_can( $taxonomy->cap->manage_terms ) && current_user_can( 'edit_theme_options' );
	}

	/**
	 * The brand a request is for.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_Term|\WP_Error
	 */
	private static function get_brand( $request ) {
		$brand = get_term( (int) $request['id'], Taxonomy::SLUG );
		if ( ! $brand instanceof \WP_Term ) {
			return new \WP_Error( 'newspack_multibranded_site_invalid_brand', __( 'That brand does not exist.', 'newspack-multibranded-site' ), [ 'status' => 404 ] );
		}
		return $brand;
	}

	/**
	 * Copies a slot's main part for a brand, or refreshes the brand's earlier copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function copy_part( $request ) {
		$brand = self::get_brand( $request );
		if ( is_wp_error( $brand ) ) {
			return $brand;
		}
		$part = Layouts::copy_part( $brand, $request['slot'], (bool) $request['replace'] );
		if ( is_wp_error( $part ) ) {
			return $part;
		}
		if ( ! $part ) {
			return new \WP_Error( 'newspack_multibranded_site_copy_failed', __( 'The copy could not be made.', 'newspack-multibranded-site' ), [ 'status' => 500 ] );
		}
		return rest_ensure_response( self::part_data( $part ) );
	}

	/**
	 * Copies a menu for a brand, or makes an empty one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function copy_menu( $request ) {
		$brand = self::get_brand( $request );
		if ( is_wp_error( $brand ) ) {
			return $brand;
		}
		$menu = Layouts::copy_nav( $brand, (int) $request['source'], (bool) $request['empty'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		return rest_ensure_response( self::menu_data( $menu ) );
	}

	/**
	 * A menu, as the brand settings screen lists it.
	 *
	 * @param \WP_Post $menu Menu.
	 * @return array
	 */
	private static function menu_data( $menu ) {
		return [
			'value'   => (int) $menu->ID,
			'label'   => $menu->post_title ? $menu->post_title : __( '(no title)', 'newspack-multibranded-site' ),
			'editUrl' => Block_Theme::site_editor_url( 'wp_navigation', $menu->ID ),
		];
	}

	/**
	 * A template part, as the brand settings screen lists it, with the menus inside it.
	 *
	 * @param \WP_Block_Template $part Template part.
	 * @return array
	 */
	private static function part_data( $part ) {
		$navs = [];
		foreach ( Layouts::part_navs( $part ) as $nav_id => $fallback ) {
			$navs[] = [
				'id'       => (int) $nav_id,
				'fallback' => (bool) $fallback,
			];
		}
		return [
			'slug'    => $part->slug,
			'title'   => $part->title ? $part->title : $part->slug,
			'area'    => $part->area,
			'source'  => $part->source,
			'editUrl' => Block_Theme::site_editor_url( 'wp_template_part', $part->slug ),
			'navs'    => $navs,
			'copyOf'  => $part->wp_id ? (string) get_post_meta( $part->wp_id, Layouts::COPY_META_KEY, true ) : '',
		];
	}

	/**
	 * What the brand settings screen needs on a block theme.
	 *
	 * @return array|null Null when the active theme isn't a block theme.
	 */
	public static function get_wizard_data() {
		if ( ! Block_Theme::is_active() ) {
			return null;
		}

		$labels = Block_Theme::get_slot_labels();
		$slots  = [];
		$parts  = [];
		foreach ( Block_Theme::SLOTS as $slot ) {
			$area = Block_Theme::slot_area( $slot );
			$main = Block_Theme::get_part( Block_Theme::main_part_slug( $slot ) );
			if ( $main ) {
				$parts[ $main->slug ] = self::part_data( $main );
			}
			$slots[] = [
				'slot'  => $slot,
				'label' => $labels[ $slot ],
				'area'  => $area,
				'main'  => Block_Theme::main_part_slug( $slot ),
			];
		}
		foreach ( [ 'header', 'footer', 'sidebar' ] as $area ) {
			foreach ( Block_Theme::parts_in_area( $area ) as $part ) {
				$parts[ $part->slug ] = self::part_data( $part );
			}
		}

		$menus = array_map(
			[ __CLASS__, 'menu_data' ],
			get_posts(
				[
					'post_type'      => 'wp_navigation',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
				]
			)
		);

		$palettes = [];
		foreach ( Colors::variation_palettes() as $title => $colors ) {
			$palettes[] = [
				'title'  => $title,
				'colors' => $colors,
			];
		}

		return [
			'slots'       => $slots,
			'parts'       => $parts,
			'menus'       => $menus,
			'palettes'    => $palettes,
			'colorPrefix' => Colors::PREFIX,
		];
	}
}
