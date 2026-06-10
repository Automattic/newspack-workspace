<?php
/**
 * Newspack Insights — Prompts section (NPPD-1607).
 *
 * Tab 5 scope: prompt exposure, engagement, free + paid reader
 * conversion, revenue from prompts, the impression → engagement →
 * conversion funnel, and per-prompt / per-intent / per-placement
 * performance breakdowns. Sourced from Newspack Campaigns (prompt)
 * GA4 events. Phase 1 ships the full UI with placeholder data;
 * Phase 2 swaps the underlying metric implementations to BigQuery via
 * the Newspack Manager query proxy.
 *
 * Unlike Gates, the Prompts tab has no separate preview flag — it is
 * gated solely by {@see Insights_Wizard::is_enabled()}. The
 * `feat/insights-rsm` integration branch is the staging area; the tab
 * is not visible to publishers until that branch merges to main as a
 * single Insights v1 release event.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\Prompts_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Prompts section.
 */
class Insights_Section_Prompts {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Prompts';

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
	 * Include Tab 5 PHP files.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'metrics/class-prompts-metric.php';
		include_once $base . 'api/class-prompts-rest-controller.php';
	}

	/**
	 * Register the Tab 5 REST route.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action(
			'rest_api_init',
			function () {
				$controller = new Prompts_REST_Controller();
				$controller->register_routes();
			}
		);
	}
}
