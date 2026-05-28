<?php
/**
 * Trait for managing WordPress hooks.
 *
 * @package Newspack
 */

namespace Newspack\Collections\Traits;

/**
 * Trait providing hook management functionality.
 */
trait Hook_Manager {
	/**
	 * Get the hooks configuration from the implementing class.
	 *
	 * @return array Array of hook configurations.
	 */
	abstract protected static function get_hooks();

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		self::manage_hooks( true );
	}

	/**
	 * Unregister hooks.
	 */
	public static function unregister_hooks() {
		self::manage_hooks( false );
	}

	/**
	 * Manage hooks registration/unregistration.
	 *
	 * @param bool $register Whether to register (true) or unregister (false) hooks.
	 */
	private static function manage_hooks( $register ) {
		$action = $register ? 'add_action' : 'remove_action';
		foreach ( static::get_hooks() as $hook ) {
			call_user_func_array( $action, $hook );
		}
	}
}
