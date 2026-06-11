<?php
/**
 * Newspack Insights — Audience section (NPPD-1602).
 *
 * Audience tab scope: who is reading, where they come from, how
 * engaged the audience is at a high level. The "lobby" of the Insights
 * page — answers "what's the shape of my audience this period?"
 *
 * The Tab 1 data layer (GA4-first, NPPD-1648) registers its REST route
 * from {@see self::register_hooks()} via {@see \Newspack\Insights\Audience_REST_Controller}.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\Audience_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Audience section.
 */
class Insights_Section_Audience {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Audience';

	/**
	 * Initialize. Loads the Tab 1 data layer and registers its REST route
	 * (NPPD-1648).
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		self::load_dependencies();
		self::register_hooks();
	}

	/**
	 * Include Tab 1 PHP files (metric orchestrator + REST controller),
	 * matching the per-section include convention.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'metrics/class-audience-metric.php';
		include_once $base . 'api/class-audience-rest-controller.php';
	}

	/**
	 * Register the Tab 1 REST route.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action(
			'rest_api_init',
			function () {
				$controller = new Audience_REST_Controller();
				$controller->register_routes();
			}
		);
	}
}
