<?php
/**
 * Newspack Insights — App section (Tab 10, NPPD-1882).
 *
 * Mobile-app analytics for Pugpig ("Bolt") app publishers, live from the GA4 Data
 * API against a publisher-selected app property. Conditional: only initializes on
 * sites where the tab is visible (app publisher, or fixture mode).
 *
 * PR0 registers the connection/property-selection REST surface via
 * {@see \Newspack\Insights\App_REST_Controller}. Metric endpoints follow.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\App_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * App section.
 */
class Insights_Section_App {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'App';

	/**
	 * Initialize. No-op unless the App tab is visible for this site, so
	 * non-app publishers pay nothing.
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() || ! Insights_Wizard::is_app_tab_visible() ) {
			return;
		}
		self::load_dependencies();
		self::register_hooks();
	}

	/**
	 * Include the App tab PHP files (metric orchestrator + REST controller).
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'metrics/class-app-metric.php';
		include_once $base . 'api/class-app-rest-controller.php';
	}

	/**
	 * Register the App REST routes.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		$controller = new App_REST_Controller();
		add_action(
			'rest_api_init',
			function () use ( $controller ) {
				$controller->register_routes();
			}
		);
	}
}
