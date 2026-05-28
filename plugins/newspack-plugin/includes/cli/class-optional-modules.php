<?php
/**
 * CLI commands to manage optional modules.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * CLI commands to manage optional modules.
 */
class Optional_Modules {

	/**
	 * Initialize.
	 *
	 * @return void
	 * @throws \Exception If something goes wrong.
	 */
	public static function init(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		self::register_commands();
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * @throws \Exception If something goes wrong.
	 */
	public static function register_commands(): void {
		$module_name = [
			'type'        => 'positional',
			'name'        => 'module-name',
			'description' => 'Name of the module to activate/deactivate',
			'optional'    => false,
		];

		\WP_CLI::add_command(
			'newspack optional-module activate',
			[ __CLASS__, 'cmd_activate_module' ],
			[
				'shortdesc' => 'Activate an optional module.',
				'synopsis'  => [
					$module_name,
				],
			]
		);
		\WP_CLI::add_command(
			'newspack optional-module deactivate',
			[ __CLASS__, 'cmd_deactivate_module' ],
			[
				'shortdesc' => 'Deactivate an optional module.',
				'synopsis'  => [
					$module_name,
				],
			]
		);
	}

	/**
	 * Command callback to activate an optional module.
	 *
	 * @param array $pos_args   Positional args.
	 * @param array $assoc_args Associative args.
	 *
	 * @throws \Exception If something goes wrong.
	 */
	public static function cmd_activate_module( array $pos_args, array $assoc_args ): void {
		$module_name       = $pos_args[0];
		$available_modules = \Newspack\Optional_Modules::get_available_optional_modules();
		if ( ! in_array( $module_name, $available_modules ) ) {
			\WP_CLI::error( sprintf( 'Module is not available. These are the available modules: %s', implode( ', ', $available_modules ) ) );
		}

		if ( \Newspack\Optional_Modules::is_optional_module_active( $module_name ) ) {
			\WP_CLI::warning( "Cannot activate module – it's already active." );
			return;
		}

		$settings = \Newspack\Optional_Modules::activate_optional_module( $module_name );
		if ( ! empty( $settings[ \Newspack\Optional_Modules::MODULE_ENABLED_PREFIX . $module_name ] ) ) {
			\WP_CLI::success( 'Module activated.' );
		} else {
			\WP_CLI::error( 'Failed to activate module.' );
		}
	}

	/**
	 * Command callback to deactivate an optional module.
	 *
	 * @param array $pos_args   Positional args.
	 * @param array $assoc_args Associative args.
	 *
	 * @throws \Exception If something goes wrong.
	 */
	public static function cmd_deactivate_module( array $pos_args, array $assoc_args ) {
		$module_name = $pos_args[0];

		if ( ! \Newspack\Optional_Modules::is_optional_module_active( $module_name ) ) {
			\WP_CLI::warning( 'Module is not active. Cannot deactivate it' );
			return;
		}

		$settings = \Newspack\Optional_Modules::deactivate_optional_module( $module_name );
		if ( empty( $settings[ \Newspack\Optional_Modules::MODULE_ENABLED_PREFIX . $module_name ] ) ) {
			\WP_CLI::success( 'Module deactivated.' );
		} else {
			\WP_CLI::error( 'Failed to deactivate module.' );
		}
	}
}
