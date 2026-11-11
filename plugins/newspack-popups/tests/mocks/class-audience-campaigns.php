<?php
/**
 * Mock of newspack-plugin's Campaigns wizard.
 *
 * The A/B Tests tab exists only in a newspack-plugin whose wizard has this
 * method, so its presence is what lets the redirect and "View results" point
 * at the tab. Once required, it stays defined for the rest of the run.
 *
 * @package Newspack_Popups
 */

namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Audience_Campaigns' ) ) {
	/**
	 * Minimal stand-in for the wizard that holds the tab.
	 */
	class Audience_Campaigns {
		/**
		 * Whether the wizard should show the tab.
		 *
		 * @return bool
		 */
		public static function is_ab_testing_enabled() {
			return true;
		}
	}
}
