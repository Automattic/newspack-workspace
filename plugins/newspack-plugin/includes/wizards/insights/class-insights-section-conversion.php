<?php
/**
 * Newspack Insights — Conversion Journey section (NPPD-1602).
 *
 * Conversion Journey tab scope: the reader lifecycle funnel, per-journey
 * conversion funnels, source attribution, time-to-convert cumulative
 * distributions, cohort retention, conversion rate trends, cross-tab
 * influenced attribution, and opportunity buckets. Lives between
 * Engagement (depth of consumption) and the per-surface tabs (Gates,
 * Prompts). Phase 1 ships the full UI with placeholder data; Phase 2
 * (NPPD-1630) swaps the metric implementations to BigQuery via the
 * Newspack Manager query proxy.
 *
 * Like the other Insights tabs, this section is gated solely by
 * {@see Insights_Wizard::is_enabled()} — no separate preview flag. The
 * `feat/insights-rsm` integration branch is the staging area until it
 * merges to main as a single Insights v1 release event.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\Conversion_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Conversion section.
 */
class Insights_Section_Conversion {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Conversion Journey';

	/**
	 * Initialize. Bails early when the parent Insights feature flag is off.
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		self::load_dependencies();
		self::register_hooks();
	}

	/**
	 * Include Tab 3 PHP files.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'metrics/class-conversion-metric.php';
		include_once $base . 'api/class-conversion-rest-controller.php';
	}

	/**
	 * Register the Tab 3 REST route.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action(
			'rest_api_init',
			function () {
				$controller = new Conversion_REST_Controller();
				$controller->register_routes();
			}
		);
	}
}
