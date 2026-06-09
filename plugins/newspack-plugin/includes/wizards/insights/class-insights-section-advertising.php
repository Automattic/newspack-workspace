<?php
/**
 * Newspack Insights — Advertising section (NPPD-1602).
 *
 * Advertising tab scope: GAM-driven ad performance. Impressions, fill
 * rate, eCPM, revenue by placement / ad unit. Requires GA4 to be
 * receiving GAM event data; visibility gated on GAM dataset presence
 * in the publisher's BigQuery export.
 *
 * Future REST endpoints for the Advertising tab register from
 * {@see self::register_hooks()}. Currently a stub; the React side renders
 * a "Coming soon" placeholder for this tab.
 *
 * @package Newspack
 */

namespace Newspack;

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
	 * Initialize. Hooks REST endpoints for this tab when implementations
	 * arrive (NPPD-1618).
	 */
	public static function init() {
		if ( ! Insights_Wizard::is_enabled() ) {
			return;
		}
		self::register_hooks();
	}

	/**
	 * Register hooks for this section. Currently a stub.
	 */
	public static function register_hooks() {}
}
