<?php
/**
 * Newspack Insights — Newsletter Ads section (NPPD-1861).
 *
 * Newsletter Ads tab scope: performance of newsletter ads sold and served
 * through newspack-newsletters — lifetime tracking counters, windowed
 * impressions/clicks from the dated ad-stats table, flat-over-flight revenue
 * proration, and per-ad / per-advertiser / per-newsletter breakdowns.
 *
 * The data layer registers from {@see self::register_hooks()} via
 * {@see \Newspack\Insights\Newsletter_Ads_REST_Controller} and
 * {@see \Newspack\Insights\Newsletter_Ads_Metric}. Everything computes
 * synchronously from local SQL — no Action Scheduler refresh (unlike
 * Advertising's GAM jobs). Every newsletters-plugin touch inside the data
 * layer is defensively guarded, so this section is safe to init even when
 * newspack-newsletters is inactive.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\Newsletter_Ads_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter Ads section.
 */
class Insights_Section_Newsletter_Ads {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Newsletter Ads';

	/**
	 * Initialize. Loads the Newsletter Ads data layer and registers its REST
	 * routes. No-op unless the Insights flag is enabled.
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		self::load_dependencies();
		self::register_hooks();
	}

	/**
	 * Include the Newsletter Ads PHP files (metric orchestrator + REST
	 * controller), matching the per-section include convention.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'metrics/class-newsletter-ads-metric.php';
		include_once $base . 'api/class-newsletter-ads-rest-controller.php';
	}

	/**
	 * Register the Newsletter Ads REST routes.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action(
			'rest_api_init',
			function () {
				$controller = new Newsletter_Ads_REST_Controller();
				$controller->register_routes();
			}
		);
	}
}
