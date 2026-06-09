<?php
/**
 * Newspack Insights — Donors section (NPPD-1617).
 *
 * Donors tab scope: donation activity. Mirrors the Subscribers
 * section's wiring: loads the Tab 7 PHP files (Donors_Storage_*,
 * Donors_Metric, Donors_REST_Controller — the shared Storage_Detector
 * and Donation_Product_Classifier from Tab 6 are reused as-is) and
 * registers the `newspack-insights/v1/donors` REST route.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Insights\Donors_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Donors section.
 */
class Insights_Section_Donors {

	/**
	 * Display label for this tab. Must match the React tab label.
	 *
	 * @var string
	 */
	const SECTION_NAME = 'Donors';

	/**
	 * Initialize.
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		self::load_dependencies();
		self::register_hooks();
	}

	/**
	 * Include Tab 7 PHP files. Tab 6 already loaded the shared
	 * storage_interface, storage_detector, and donation classifier
	 * via Insights_Section_Subscribers::load_dependencies(), but
	 * boot ordering can vary so we re-include defensively
	 * (include_once is idempotent).
	 *
	 * @return void
	 */
	private static function load_dependencies(): void {
		$base = NEWSPACK_ABSPATH . 'includes/wizards/insights/';
		include_once $base . 'storage/class-storage-detector.php';
		include_once $base . 'classifiers/class-donation-product-classifier.php';
		include_once $base . 'storage/class-donors-storage-interface.php';
		include_once $base . 'storage/class-hpos-donors-storage.php';
		include_once $base . 'storage/class-legacy-donors-storage.php';
		include_once $base . 'metrics/class-donors-metric.php';
		include_once $base . 'api/class-donors-rest-controller.php';
	}

	/**
	 * Register the Tab 7 REST route.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action(
			'rest_api_init',
			function () {
				$controller = new Donors_REST_Controller();
				$controller->register_routes();
			}
		);
	}
}
