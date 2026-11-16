<?php
/**
 * Newspack Multibranded site: brand notices in the Site Editor.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Block_Theme;

use Newspack_Multibranded_Site\Admin;

/**
 * Tells the person editing a menu or template part which brands it affects, and warns
 * when the theme's Header or Footer no longer loads the parts brands choose between.
 *
 * Notices are worked out when the Site Editor loads, so they reflect brand choices saved
 * before then, and the editor needs a reload after a Reset to show the new state.
 */
class Editor_Notices {

	/**
	 * Initializes.
	 */
	public static function init() {
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue' ] );
	}

	/**
	 * Enqueues the notices on the Site Editor.
	 */
	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'site-editor' !== $screen->id || ! Block_Theme::get_brands() ) {
			return;
		}

		$asset_file = NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR . 'dist/siteEditorNotices.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'newspack-brand-site-editor-notices',
			plugins_url( '../../dist/siteEditorNotices.js', __FILE__ ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_localize_script(
			'newspack-brand-site-editor-notices',
			'newspackBrandSiteEditorNotices',
			[
				'notices'   => self::get_notices(),
				'backUrl'   => class_exists( 'Newspack\Newspack' ) ? admin_url( 'admin.php?page=newspack-settings#/additional-brands' ) : admin_url( 'admin.php?page=' . Admin::MULTI_BRANDED_PAGE_SLUG ),
				'backLabel' => __( 'Manage brands', 'newspack-multibranded-site' ),
			]
		);
	}

	/**
	 * Notices, keyed by "post type:ID" as the Site Editor names what's open.
	 *
	 * @return array
	 */
	public static function get_notices() {
		$usage   = Layouts::usage();
		$notices = [];
		foreach ( $usage['navs'] as $nav_id => $entry ) {
			$notices[ 'wp_navigation:' . $nav_id ] = self::usage_notice( $entry, get_the_title( $nav_id ) );
		}
		foreach ( $usage['parts'] as $slug => $entry ) {
			$part = Block_Theme::get_part( $slug );
			if ( $part ) {
				$notices[ 'wp_template_part:' . $part->id ] = self::usage_notice( $entry, $part->title ? $part->title : $slug );
			}
		}
		foreach ( [ 'header', 'footer' ] as $area ) {
			foreach ( get_block_templates( [ 'area' => $area ], 'wp_template_part' ) as $part ) {
				$key = 'wp_template_part:' . $part->id;
				if ( ! isset( $notices[ $key ] ) && Block_Theme::is_wrapper( $part ) ) {
					$notices[ $key ] = self::wrapper_notice( $part, $area );
				}
			}
		}
		return $notices;
	}

	/**
	 * Which brands a menu or part affects.
	 *
	 * @param array  $usage Usage entry from Layouts::usage().
	 * @param string $title The menu or part's title.
	 * @return array [ 'status' => info|warning, 'text' => string ]
	 */
	public static function usage_notice( $usage, $title ) {
		$brands = array_values( $usage['brands'] );
		if ( $usage['main'] && $brands ) {
			return [
				'status' => 'warning',
				'text'   => sprintf(
					/* translators: 1: menu or template part title, 2: list of brand names. */
					_n(
						'“%1$s” is shared: it appears on the main site and on the %2$s brand. Changes here affect all of them.',
						'“%1$s” is shared: it appears on the main site and on the %2$s brands. Changes here affect all of them.',
						count( $brands ),
						'newspack-multibranded-site'
					),
					$title,
					wp_sprintf( '%l', $brands )
				),
			];
		}
		if ( $usage['main'] ) {
			return [
				'status' => 'info',
				'text'   => sprintf(
					/* translators: %s: menu or template part title. */
					__( '“%s” appears on the main site only. Every brand uses its own version.', 'newspack-multibranded-site' ),
					$title
				),
			];
		}
		if ( count( $brands ) > 1 ) {
			return [
				'status' => 'warning',
				'text'   => sprintf(
					/* translators: 1: menu or template part title, 2: list of brand names. */
					__( '“%1$s” is used by the %2$s brands. Changes here affect all of them.', 'newspack-multibranded-site' ),
					$title,
					wp_sprintf( '%l', $brands )
				),
			];
		}
		return [
			'status' => 'info',
			'text'   => sprintf(
				/* translators: 1: menu or template part title, 2: brand name. */
				__( '“%1$s” is used by the %2$s brand. Changes here only affect %2$s pages.', 'newspack-multibranded-site' ),
				$title,
				$brands[0] ?? ''
			),
		];
	}

	/**
	 * Notice for a whole header or footer. Brand choices only apply inside its desktop and
	 * mobile slots, so removing or detaching one stops brand choices applying there.
	 *
	 * @param \WP_Block_Template $part Template part.
	 * @param string             $area header or footer.
	 * @return array [ 'status' => info|warning, 'text' => string ]
	 */
	public static function wrapper_notice( $part, $area ) {
		$blocks  = parse_blocks( $part->content );
		$desktop = '' !== Block_Theme::find_slot_part( $blocks, $area . '-desktop' );
		$mobile  = '' !== Block_Theme::find_slot_part( $blocks, $area . '-mobile' );

		$messages = 'footer' === $area
			? [
				'ok'      => __( 'Brands can use their own desktop and mobile footers inside this footer. Everything else here is shared by every brand.', 'newspack-multibranded-site' ),
				'desktop' => __( 'This footer doesn’t load a desktop footer part anymore, so every custom brand shows the blocks here, even brands with their own footer template parts set. To fix it, reset this footer or put a desktop footer part back.', 'newspack-multibranded-site' ),
				'mobile'  => __( 'This footer doesn’t load a mobile footer part anymore, so every custom brand shows the blocks here, even brands with their own footer template parts set. To fix it, reset this footer or put a mobile footer part back.', 'newspack-multibranded-site' ),
				'both'    => __( 'This footer doesn’t load desktop and mobile footer parts anymore, so every custom brand shows the blocks here, even brands with their own footer template parts set. To fix it, reset this footer or put those parts back.', 'newspack-multibranded-site' ),
			]
			: [
				'ok'      => __( 'Brands can use their own desktop and mobile headers inside this header. Everything else here is shared by every brand.', 'newspack-multibranded-site' ),
				'desktop' => __( 'This header doesn’t load a desktop header part anymore, so every custom brand shows the blocks here, even brands with their own header template parts set. To fix it, reset this header or put a desktop header part back.', 'newspack-multibranded-site' ),
				'mobile'  => __( 'This header doesn’t load a mobile header part anymore, so every custom brand shows the blocks here, even brands with their own header template parts set. To fix it, reset this header or put a mobile header part back.', 'newspack-multibranded-site' ),
				'both'    => __( 'This header doesn’t load desktop and mobile header parts anymore, so every custom brand shows the blocks here, even brands with their own header template parts set. To fix it, reset this header or put those parts back.', 'newspack-multibranded-site' ),
			];

		if ( $desktop && $mobile ) {
			return [
				'status' => 'info',
				'text'   => $messages['ok'],
			];
		}
		if ( ! $desktop && ! $mobile ) {
			$missing = 'both';
		} else {
			$missing = $desktop ? 'mobile' : 'desktop';
		}
		return [
			'status' => 'warning',
			'text'   => $messages[ $missing ],
		];
	}
}
