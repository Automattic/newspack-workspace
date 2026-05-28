<?php
/**
 * Newspack Popups View As
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main Newspack Popups View As Class.
 */
final class Newspack_Popups_View_As {
	/**
	 * The single instance of the class.
	 *
	 * @var Newspack_Popups_View_As
	 */
	protected static $instance = null;

	/**
	 * Main Newspack Popups View As Instance.
	 * Ensures only one instance of Newspack Popups View As Instance is loaded or can be loaded.
	 *
	 * @return Newspack Popups View As Instance - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the "view as" feature specification.
	 *
	 * @return string "View as" specification.
	 */
	public static function viewing_as_spec() {
		if ( ! Newspack_Popups::is_user_admin() ) {
			return false;
		}
		if ( isset( $_GET['view_as'] ) && $_GET['view_as'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return sanitize_text_field( $_GET['view_as'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Parse "view as" spec.
	 *
	 * @param string|null $raw_spec Raw spec. If null, read from $_GET['view_as'].
	 * @return object Parsed spac.
	 */
	public static function parse_view_as( $raw_spec = null ) {
		if ( empty( $raw_spec ) ) {
			$raw_spec = self::viewing_as_spec();
		}

		if ( empty( $raw_spec ) ) {
			return [];
		}

		return array_reduce(
			explode( ';', $raw_spec ),
			function( $acc, $item ) {
				$parts = explode( ':', $item );
				if ( 1 === count( $parts ) ) {
					$acc[ $parts[0] ] = true;
				} else {
					$acc[ $parts[0] ] = $parts[1];
				}
				return $acc;
			},
			[]
		);
	}
}

Newspack_Popups_View_As::instance();
