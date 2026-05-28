<?php
/**
 * Everlit plugin configuration manager.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

require_once NEWSPACK_ABSPATH . '/includes/configuration_managers/class-configuration-manager.php';

/**
 * Provide an interface for configuring and querying the configuration of Everlit.
 */
class Everlit_Configuration_Manager extends Configuration_Manager {

	/**
	 * The slug of the plugin.
	 *
	 * @var string
	 */
	public $slug = 'everlit';

	/**
	 * Configure Everlit for Newspack use.
	 *
	 * @return bool Enforce configurability.
	 */
	public function configure() {
		return true;
	}

	/**
	 * Is Everlit installed and connected?
	 *
	 * @return bool Plugin ready state.
	 */
	public function is_configured() {
		if ( ! static::is_enabled() ) {
			return false;
		}
		$toc = get_option( 'everlit_clickwrap_accepted', [] ) ?? [];
		[ 'token' => $token ] = get_option( 'everlit_settings', [] ) ?? [];
		if ( $this->is_active() && $token !== null && isset( $toc['timestamp'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Newspack Manager conditional check.
	 *
	 * @return bool If Newspack Manager is installed and connected.
	 */
	public static function is_enabled() {
		return method_exists( 'Newspack_Manager', 'is_connected_to_manager' ) && \Newspack_Manager::is_connected_to_manager();
	}
}
