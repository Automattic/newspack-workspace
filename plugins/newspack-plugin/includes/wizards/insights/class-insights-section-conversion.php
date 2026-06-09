<?php
/**
 * Newspack Insights — Conversion Journey section (NPPD-1602).
 *
 * Conversion Journey tab scope: the funnel from anonymous reader to
 * registered to subscriber. Time-to-convert distributions, step-over-step
 * drop-off, and attribution to gates/prompts. Lives between Engagement
 * (depth of consumption) and the per-surface tabs (Gates, Prompts).
 *
 * Future REST endpoints for the Conversion tab register from
 * {@see self::register_hooks()}. Currently a stub; the React side renders
 * a "Coming soon" placeholder for this tab.
 *
 * @package Newspack
 */

namespace Newspack;

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
	 * Initialize. Hooks REST endpoints for this tab when implementations
	 * arrive (NPPD-1609).
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
