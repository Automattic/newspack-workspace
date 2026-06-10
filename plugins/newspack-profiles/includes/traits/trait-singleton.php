<?php
/**
 * Singleton trait for the Newspack Profiles plugin.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\Traits;

/**
 * Singleton trait for implementing the singleton pattern.
 */
trait Singleton {

	/**
	 * Protected constructor to prevent direct object creation.
	 * Override it in the derived class if needed.
	 */
	protected function __construct() {}

	/**
	 * Prevent object cloning
	 */
	final protected function __clone() {}

	/**
	 * Get the singleton instance of the class.
	 *
	 * @return object Singleton instance of the class.
	 */
	final public static function get_instance(): object {
		static $instance = array();

		$called_class = get_called_class();

		if ( ! isset( $instance[ $called_class ] ) ) {
			$instance[ $called_class ] = new $called_class();
		}

		return $instance[ $called_class ];
	}
}
