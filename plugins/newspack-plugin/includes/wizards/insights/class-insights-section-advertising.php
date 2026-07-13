<?php
/**
 * Newspack Insights — Advertising section (NPPD-1602, data layer NPPD-1663).
 *
 * Advertising tab scope: GAM-driven ad performance — impressions, revenue,
 * eCPM, fill rate, viewability, and revenue/performance breakdowns. Reads
 * Google Ad Manager live via the SOAP ReportService client (NPPD-1662), not
 * BigQuery.
 *
 * The data layer (REST route + Action Scheduler refresh) registers from
 * {@see self::register_hooks()} via {@see \Newspack\Insights\Advertising_REST_Controller}
 * and {@see \Newspack\Insights\Advertising_Metric}. It is gated behind the
 * Insights flag; tab visibility and whether any GAM report actually runs are
 * governed at runtime by {@see \Newspack\Insights\Advertising_Metric::is_tab_visible()}
 * (Google Ad Manager active), so the stack stays dormant on non-GAM sites.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\Advertising_REST_Controller;
use Newspack\Insights\Advertising_Metric;

defined( 'ABSPATH' ) || exit;

/**
 * Advertising section.
 */
class Insights_Section_Advertising {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Advertising';

	/**
	 * Initialize. Loads the Tab 8 data layer and registers its REST route +
	 * Action Scheduler refresh handler (NPPD-1663). No-op unless the Insights
	 * flag is enabled; the GAM-active runtime check governs tab visibility and
	 * whether any report actually runs.
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		self::load_dependencies();
		self::register_hooks();
	}

	/**
	 * Include Tab 8 PHP files (metric orchestrator + REST controller), matching
	 * the per-section include convention.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'derived/class-cross-system-metrics.php';
		include_once $base . 'broadstreet/class-client.php';
		include_once $base . 'metrics/class-advertising-metric.php';
		include_once $base . 'api/class-advertising-rest-controller.php';
	}

	/**
	 * Register the Tab 8 REST route and the background refresh handler.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action(
			'rest_api_init',
			function () {
				$controller = new Advertising_REST_Controller();
				$controller->register_routes();
			}
		);
		add_action(
			Advertising_Metric::REFRESH_ACTION,
			[ Advertising_Metric::class, 'run_scheduled_refresh' ]
		);
	}
}
