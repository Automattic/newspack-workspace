<?php
/**
 * Newspack Story Budget plugin initialization.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * Class to handle the plugin initialization
 */
class Initializer {
	/**
	 * Runs the initialization.
	 */
	public static function init() {
		Budgets::init();
		Fields::init();
		Fields\Statuses::init();
		Search::init();
		API::init();
		Admin::init();
		CLI::init();
	}
}
