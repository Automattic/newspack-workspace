<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Class to handle the Blog Name Customization
 */
class Popups_Should_Display_Prompt {

	/**
	 * Initializes
	 */
	public static function init() {
		add_filter( 'newspack_popups_should_display_prompt', [ __CLASS__, 'filter_should_display' ], 10, 2 );
	}

	/**
	 * Performs additional checks to determine if a popup should be displayed.
	 *
	 * @param bool   $should_display Should popup be shown.
	 * @param object $popup The popup to assess.
	 * @return bool Should popup be shown.
	 */
	public static function filter_should_display( $should_display, $popup ) {
		if ( ! $should_display ) {
			return false; // if the prompt didn't meet previous criteria, don't show it.
		}

		if ( ! is_array( $popup ) || empty( $popup['id'] ) ) {
			return $should_display;
		}
		$popup_terms = get_the_terms( $popup['id'], Taxonomy::SLUG );

		if ( false === $popup_terms ) {
			return $should_display; // Popup not assigned to any brand. Nothing to check.
		}

		$brand = Taxonomy::get_current();
		if ( ! $brand ) {
			return false; // We are not currently on any brand, but the prompt is assigned to one or more brands, so don't show it.
		}

		$popup_term_ids = wp_list_pluck( $popup_terms, 'term_id' );
		return in_array( $brand->term_id, $popup_term_ids, true );
	}
}
