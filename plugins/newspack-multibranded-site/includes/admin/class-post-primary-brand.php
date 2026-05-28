<?php
/**
 * Newspack Post Primary Brand.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Admin;

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Admin;
use Newspack_Multibranded_Site\Meta\Post_Primary_Brand as Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Post Primary Brand.
 */
class Post_Primary_Brand {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_filter( 'wpseo_primary_term_taxonomies', array( __CLASS__, 'remove_yoast_primary_term' ) );
	}

	/**
	 * Enqueues the scripts to the Post edit screen.
	 */
	public static function enqueue_scripts() {
		$screen = get_current_screen();
		if ( 'post' !== $screen->base || ! in_array( $screen->post_type, Taxonomy::get_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'newspack-post-primary-brand',
			plugins_url( '../../dist/postPrimaryBrand.js', __FILE__ ),
			array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-url' ),
			filemtime( NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR . '/dist/postPrimaryBrand.js' ),
			true
		);

		wp_enqueue_style(
			'newspack-post-primary-brand',
			plugins_url( '../../dist/postPrimaryBrand.css', __FILE__ ),
			[],
			filemtime( NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR . '/dist/postPrimaryBrand.js' )
		);

		$page_slug = class_exists( 'Newspack\Newspack' ) ? 'newspack-settings#/additional-brands' : Admin::MULTI_BRANDED_PAGE_SLUG;
		wp_localize_script(
			'newspack-post-primary-brand',
			'newspackPostPrimaryBrandVars',
			array(
				'adminURL'                  => admin_url( 'admin.php?page=' . $page_slug ),
				'taxonomySlug'              => Taxonomy::SLUG,
				'metaKey'                   => Taxonomy::PRIMARY_META_KEY,
				'postTypesWithPrimaryBrand' => Meta::get_post_types(),
			)
		);
	}

	/**
	 * Removes Brands from the list of taxonomies for which Yoast will add a "primary term" selector.
	 *
	 * @param \WP_Taxonomy[] $taxonomies List of taxonomies.
	 * @return \WP_Taxonomy[]
	 */
	public static function remove_yoast_primary_term( $taxonomies ) {
		if ( ! empty( $taxonomies[ Taxonomy::SLUG ] ) ) {
			unset( $taxonomies[ Taxonomy::SLUG ] );
		}
		return $taxonomies;
	}
}
