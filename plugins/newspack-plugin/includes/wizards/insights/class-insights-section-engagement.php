<?php
/**
 * Newspack Insights — Engagement section (NPPD-1602).
 *
 * Engagement tab scope: how deeply readers interact with content —
 * scroll depth, time on page, return visits. Distinct from Audience
 * (who) by focusing on behavior depth (how).
 *
 * The Tab 2 data layer (GA4-first, NPPD-1648) registers its REST route
 * from {@see self::register_hooks()} via {@see \Newspack\Insights\Engagement_REST_Controller}.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\Engagement_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Engagement section.
 */
class Insights_Section_Engagement {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Engagement';

	/**
	 * Initialize. Loads the Tab 2 data layer and registers its REST route
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
	 * Include Tab 2 PHP files (metric orchestrator + REST controller),
	 * matching the per-section include convention.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'metrics/class-engagement-metric.php';
		include_once $base . 'api/class-engagement-rest-controller.php';
	}

	/**
	 * Register the Tab 2 REST route.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action(
			'rest_api_init',
			function () {
				$controller = new Engagement_REST_Controller();
				$controller->register_routes();
			}
		);
	}
}
