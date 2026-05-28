<?php
/**
 * Trait for handling WordPress registration management.
 *
 * @package Newspack\Collections
 */

namespace Newspack\Collections\Traits;

use Newspack\Optional_Modules;
use Newspack\Optional_Modules\Collections;

defined( 'ABSPATH' ) || exit;

/**
 * Trait for handling post type and taxonomy registration management.
 *
 * This trait provides a common pattern for unregistering and re-registering
 * WordPress objects when settings change or modules are toggled.
 */
trait Registration_Manager {

	/**
	 * Update the object registration.
	 *
	 * @param array $settings Collection module settings.
	 */
	public static function update_registration( $settings ) {
		// Check if the Collections module was enabled.
		$should_register = $settings[ Optional_Modules::MODULE_ENABLED_PREFIX . Collections::MODULE_NAME ] ?? true;

		if ( method_exists( static::class, 'get_post_type' ) ) { // For post types.
			if ( post_type_exists( static::get_post_type() ) ) {
				unregister_post_type( static::get_post_type() );
			}
			if ( $should_register ) {
				static::register_post_type();
			}
		} elseif ( method_exists( static::class, 'get_taxonomy' ) ) { // For taxonomies.
			if ( taxonomy_exists( static::get_taxonomy() ) ) {
				unregister_taxonomy( static::get_taxonomy() );
			}
			if ( $should_register ) {
				static::register_taxonomy();
			}
		}
	}
}
