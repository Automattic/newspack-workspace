<?php
/**
 * Newspack Story Budget - utility class for CLI commands.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * CLI class.
 */
class CLI {
	// Prefix for all CLI commands: `wp newspack story <command> <args...>`.
	const COMMAND_PREFIX = 'newspack story ';

	/**
	 * Add hook to register CLI commands.
	 *
	 * @return void
	 */
	public static function init() {
		\add_action( 'init', [ __CLASS__, 'register_comands' ] );
		include_once NEWSPACK_STORY_BUDGET_PLUGIN_DIR . 'includes/cli/class-field.php';
		include_once NEWSPACK_STORY_BUDGET_PLUGIN_DIR . 'includes/cli/class-budget.php';
		include_once NEWSPACK_STORY_BUDGET_PLUGIN_DIR . 'includes/cli/class-story.php';
	}

	/**
	 * Adds CLI commands.
	 *
	 * @return void
	 */
	public static function register_comands() {
		if ( ! defined( 'WP_CLI' ) ) {
			return;
		}

		// Commands for managing story budget fields.
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'field list',
			[ 'Newspack_Story_Budget\CLI\Field', 'cli_field_list' ]
		);

		// Commands for managing story budgets.
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'budget list',
			[ 'Newspack_Story_Budget\CLI\Budget', 'cli_budget_list' ]
		);
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'budget get',
			[ 'Newspack_Story_Budget\CLI\Budget', 'cli_budget_get' ]
		);
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'budget create',
			[ 'Newspack_Story_Budget\CLI\Budget', 'cli_budget_create' ]
		);
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'budget delete',
			[ 'Newspack_Story_Budget\CLI\Budget', 'cli_budget_delete' ]
		);
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'budget update',
			[ 'Newspack_Story_Budget\CLI\Budget', 'cli_budget_update' ]
		);
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'budget add-stories',
			[ 'Newspack_Story_Budget\CLI\Budget', 'cli_budget_add_stories' ]
		);
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'budget remove-stories',
			[ 'Newspack_Story_Budget\CLI\Budget', 'cli_budget_remove_stories' ]
		);


		// Commands for managing stories.
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'list',
			[ 'Newspack_Story_Budget\CLI\Story', 'cli_story_list' ]
		);
		\WP_CLI::add_command(
			self::COMMAND_PREFIX . 'get',
			[ 'Newspack_Story_Budget\CLI\Story', 'cli_story_get' ]
		);
	}
}
