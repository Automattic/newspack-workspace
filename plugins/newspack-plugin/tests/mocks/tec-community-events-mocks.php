<?php
/**
 * The Events Calendar Community Events mocks.
 *
 * @package Newspack\Tests
 */

if ( ! function_exists( 'tribe_is_community_edit_event_page' ) ) {
	/**
	 * Stub for The Events Calendar Community Events plugin helper.
	 *
	 * Reads a global so tests can toggle the TEC community submission page state.
	 *
	 * @return bool
	 */
	function tribe_is_community_edit_event_page() {
		global $newspack_test_is_tec_community_page;
		return $newspack_test_is_tec_community_page ?? false;
	}
}
