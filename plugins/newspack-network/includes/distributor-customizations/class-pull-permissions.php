<?php
/**
 * Newspack Distributor Tweak who can pull content
 *
 * @package Newspack
 */

namespace Newspack_Network\Distributor_Customizations;

/**
 * Class to allow editors to pull content
 */
class Pull_Permissions {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		add_filter( 'dt_capabilities', [ __CLASS__, 'filter_distributor_menu_cap' ] );
		add_filter( 'dt_pull_capabilities', [ __CLASS__, 'filter_distributor_menu_cap' ] );
	}

	/**
	 * Allow editors to pull content
	 */
	public static function filter_distributor_menu_cap() {
		return 'edit_others_posts';
	}
}
