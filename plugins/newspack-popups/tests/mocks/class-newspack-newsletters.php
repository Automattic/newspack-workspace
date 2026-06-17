<?php
/**
 * Mock of the newspack-newsletters main class for popups tests.
 *
 * The popups test suite loads only newspack-popups, so cross-plugin classes
 * the code guards on are absent. This stand-in exposes just the newsletter CPT
 * slug used by Newspack_Popups_Segmentation::append_donor_segment_param().
 *
 * @package Newspack_Popups
 */

if ( ! class_exists( 'Newspack_Newsletters' ) ) {
	/**
	 * Minimal stand-in for the newspack-newsletters main class.
	 */
	class Newspack_Newsletters {
		const NEWSPACK_NEWSLETTERS_CPT = 'newspack_nl_cpt';
	}
}
