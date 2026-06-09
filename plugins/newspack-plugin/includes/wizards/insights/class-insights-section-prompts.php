<?php
/**
 * Newspack Insights — Prompts section (NPPD-1602).
 *
 * Prompts tab scope: per-campaign / per-prompt performance from the
 * Campaigns surface. Impressions, click-throughs, gate fires attributed
 * to specific overlay / inline / popup prompts. Complements the Gates
 * tab — Gates is "what conversion surface fired"; Prompts is "what
 * editorial campaign drove the fire."
 *
 * Future REST endpoints for the Prompts tab register from
 * {@see self::register_hooks()}. Currently a stub; the React side renders
 * a "Coming soon" placeholder for this tab.
 *
 * @package Newspack
 */

namespace Newspack;

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
	 * Initialize. Hooks REST endpoints for this tab when implementations
	 * arrive (NPPD-1607).
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
