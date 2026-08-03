<?php
/**
 * Renders the shared Newspack admin header on the Collections list screen.
 *
 * @package Newspack
 */

namespace Newspack\Collections;

use Newspack\Wizards\Traits\Admin_Header;

defined( 'ABSPATH' ) || exit;

/**
 * Mounts the shared Newspack <Page> header on the Collections list table screen,
 * mirroring how Listings_Wizard uses the Admin_Header trait.
 */
class List_Screen_Header {

	use Admin_Header;

	/**
	 * Set up hooks.
	 */
	public static function init() {
		add_action( 'current_screen', [ new self(), 'maybe_init_header' ] );
	}

	/**
	 * Initialize the admin header when viewing a Collections list screen.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 */
	public function maybe_init_header( $screen ) {
		$screens = self::get_screens();
		if ( ! isset( $screens[ $screen->id ] ) ) {
			return;
		}
		$this->admin_header_init( [ 'breadcrumbs' => $screens[ $screen->id ] ] );
	}

	/**
	 * Map of admin screen IDs to their breadcrumb trail.
	 *
	 * @return array<string, array<array{label: string}>>
	 */
	private static function get_screens() {
		return [
			'edit-' . Post_Type::get_post_type() => [ [ 'label' => __( 'Collections', 'newspack-plugin' ) ], [ 'label' => __( 'All Collections', 'newspack-plugin' ) ] ],
			'edit-' . Collection_Category_Taxonomy::get_taxonomy() => [ [ 'label' => __( 'Collections', 'newspack-plugin' ) ], [ 'label' => __( 'Categories', 'newspack-plugin' ) ] ],
			'edit-' . Collection_Section_Taxonomy::get_taxonomy() => [ [ 'label' => __( 'Collections', 'newspack-plugin' ) ], [ 'label' => __( 'Sections', 'newspack-plugin' ) ] ],
		];
	}
}
