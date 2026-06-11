<?php
/**
 * Newspack Insights — Gates section (NPPD-1604).
 *
 * Gates tab scope: gate exposure, free + paid reader conversion,
 * conversion-journey funnel, per-gate breakdown. Phase 1 ships the
 * full UI with placeholder data; Phase 2 (NPPD-1630) swaps the
 * underlying metric implementations to BigQuery via the Newspack
 * Manager query proxy.
 *
 * Visibility is gated by both the standard {@see Insights_Wizard::is_enabled()}
 * flag AND the additional {@see Insights_Wizard::is_gates_preview_enabled()}
 * constant — set `NEWSPACK_INSIGHTS_GATES_PREVIEW` to surface the
 * preview tab on a given environment.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\Gates_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Gates section.
 */
class Insights_Section_Gates {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Gates';

	/**
	 * Initialize. Bails early when either the parent Insights feature
	 * flag or the Gates preview flag is off.
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		if ( ! Insights_Wizard::is_gates_preview_enabled() ) {
			return;
		}
		self::load_dependencies();
		self::register_hooks();
	}

	/**
	 * Include Tab 4 PHP files.
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'metrics/class-gates-metric.php';
		include_once $base . 'api/class-gates-rest-controller.php';
	}

	/**
	 * Register the Tab 4 REST route.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action(
			'rest_api_init',
			function () {
				$controller = new Gates_REST_Controller();
				$controller->register_routes();
			}
		);
	}
}
