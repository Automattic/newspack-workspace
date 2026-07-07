<?php
/**
 * Newspack Insights — Gates section (NPPD-1604).
 *
 * Gates tab scope: gate exposure, free + paid reader conversion,
 * conversion-journey funnel, per-gate breakdown. Metrics are backed by
 * BigQuery via the Newspack Manager query proxy (NPPD-1630).
 *
 * Visibility is gated by the standard {@see Insights_Wizard::is_enabled()}
 * flag — the `NEWSPACK_INSIGHTS_ENABLED` constant.
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
	 * Initialize. Bails early when the Insights feature flag is off.
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
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
	 * Register the Tab 4 REST route and warm the gates tab.
	 *
	 * Called only when the Insights feature flag is enabled (enforced by init()).
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\Newspack\Insights\Prewarm::init();
		$controller = new Gates_REST_Controller();
		add_action(
			'rest_api_init',
			function () use ( $controller ) {
				$controller->register_routes();
			}
		);
		\Newspack\Insights\Prewarm::register_tab( 'gates', [ $controller, 'warm_window' ], [ $controller, 'durable_key_for' ] );
	}
}
