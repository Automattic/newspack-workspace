<?php
/**
 * The A/B Tests dashboard: its page, its stats route and the provider it reads from.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tools → A/B Tests, behind the Campaigns A/B flag.
 */
final class Newspack_Popups_AB_Dashboard {
	/**
	 * The provider supplying per-variant counts on this site, if any.
	 *
	 * @return Newspack_Popups_AB_Stats_Provider|null
	 */
	public static function get_provider() {
		/**
		 * Supplies the stats provider the A/B Tests dashboard reads from.
		 *
		 * One provider per site. Return an object implementing
		 * Newspack_Popups_AB_Stats_Provider; anything else counts as no
		 * provider, and the dashboard shows every test as still collecting.
		 *
		 * @param Newspack_Popups_AB_Stats_Provider|null $provider The provider; null when none is registered.
		 */
		$provider = apply_filters( 'newspack_popups_ab_stats_provider', null );
		return $provider instanceof Newspack_Popups_AB_Stats_Provider ? $provider : null;
	}
}
