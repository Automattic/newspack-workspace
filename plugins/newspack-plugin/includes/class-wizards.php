<?php
/**
 * Newspack Wizards manager.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Wizards\Newspack\Newspack_Settings;
use Newspack\Memberships;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the wizards.
 */
class Wizards {

	/**
	 * Information about all of the wizards.
	 * See `init` for structure of the data.
	 *
	 * @var array
	 */
	protected static $wizards = [];

	/**
	 * Initialize.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'init_wizards' ] );
		// Allow custom menu order.
		add_filter( 'custom_menu_order', '__return_true' );
		// Fix menu order for wizards with parent menu items.
		add_filter( 'menu_order', [ __CLASS__, 'menu_order' ], 11 );
	}

	/**
	 * Initialize wizards.
	 */
	public static function init_wizards() {
		self::$wizards = [
			'components-demo'         => new Components_Demo(),
			// v2 Information Architecture.
			'newspack-dashboard'      => new Newspack_Dashboard(),
			'setup'                   => new Setup_Wizard(),
			'newspack-settings'       => new Newspack_Settings(
				[
					'sections' => [
						'custom-events'    => 'Newspack\Wizards\Newspack\Custom_Events_Section',
						'emails'           => 'Newspack\Wizards\Newspack\Emails_Section',
						'social-pixels'    => 'Newspack\Wizards\Newspack\Pixels_Section',
						'recirculation'    => 'Newspack\Wizards\Newspack\Recirculation_Section',
						'syndication'      => 'Newspack\Wizards\Newspack\Syndication_Section',
						'seo'              => 'Newspack\Wizards\Newspack\Seo_Section',
						'collections'      => 'Newspack\Wizards\Newspack\Collections_Section',
						'print'            => 'Newspack\Wizards\Newspack\Print_Section',
						'nextdoor'         => 'Newspack\Wizards\Newspack\Nextdoor_Section',
						'primary-category' => 'Newspack\Wizards\Newspack\Primary_Category_Section',
						'privacy'          => 'Newspack\Wizards\Newspack\Privacy_Section',
					],
				]
			),
			'advertising-display-ads' => new Advertising_Display_Ads(),
			'advertising-sponsors'    => new Advertising_Sponsors(),
			'audience'                => new Audience_Wizard(),
			'audience-campaigns'      => new Audience_Campaigns(),
			'audience-content-gates'  => new Audience_Content_Gates(),
			'audience-donations'      => new Audience_Donations(),
			'audience-integrations'   => new Audience_Integrations(),
			'listings'                => new Listings_Wizard(),
			'network'                 => new Network_Wizard(),
			'newsletters'             => new Newsletters_Wizard(),
			'premium-newsletters'     => new Premium_Newsletters_Wizard(),
		];
		if ( Memberships::is_active() ) {
			self::$wizards['audience-subscriptions'] = new Audience_Subscriptions();
		}
	}

	/**
	 * Get a wizard's object.
	 *
	 * @param string $wizard_slug The wizard to get. Use slug from self::$wizards.
	 * @return Wizard | bool The wizard on success, false on failure.
	 */
	public static function get_wizard( $wizard_slug ) {
		if ( isset( self::$wizards[ $wizard_slug ] ) ) {
			return self::$wizards[ $wizard_slug ];
		}

		return false;
	}

	/**
	 * Get a wizard's URL.
	 *
	 * @param string $wizard_slug The wizard to get URL for. Use slug from self::$wizards.
	 * @return string | bool The URL on success, false on failure.
	 */
	public static function get_url( $wizard_slug ) {
		$wizard = self::get_wizard( $wizard_slug );
		if ( $wizard ) {
			return $wizard->get_url();
		}

		return false;
	}

	/**
	 * Get all the URLs for all the wizards.
	 *
	 * @return array of slug => URL pairs.
	 */
	public static function get_urls() {
		$urls = [];
		foreach ( self::$wizards as $slug => $wizard ) {
			$urls[ $slug ] = $wizard->get_url();
		}

		return $urls;
	}

	/**
	 * Get a wizard's name.
	 *
	 * @param string $wizard_slug The wizard to get name for. Use slug from self::$wizards.
	 * @return string | bool The name on success, false on failure.
	 */
	public static function get_name( $wizard_slug ) {
		$wizard = self::get_wizard( $wizard_slug );
		if ( $wizard ) {
			return $wizard->get_name();
		}

		return false;
	}

	/**
	 * Get whether a wizard is completed.
	 *
	 * @param string $wizard_slug The wizard to get completion for. Use slug from self::$wizards.
	 * @return bool True if completed. False otherwise.
	 */
	public static function is_completed( $wizard_slug ) {
		$wizard = self::get_wizard( $wizard_slug );
		if ( $wizard ) {
			return $wizard->is_completed();
		}

		return false;
	}

	/**
	 * Update menu order for wizards with parent menu items.
	 *
	 * @param array $menu_order The current menu order.
	 *
	 * @return array The updated menu order.
	 */
	public static function menu_order( $menu_order ) {
		$index = array_search( 'newspack-dashboard', $menu_order, true );
		if ( false === $index ) {
			return $menu_order;
		}
		$ordered_wizards = [];
		foreach ( self::$wizards as $slug => $wizard ) {
			if ( ! empty( $wizard->parent_menu ) && ! empty( $wizard->parent_menu_order ) ) {
				$ordered_wizards[ $wizard->parent_menu_order ] = $wizard->parent_menu;
			}
		}
		if ( empty( $ordered_wizards ) ) {
			return $menu_order;
		}
		ksort( $ordered_wizards );
		foreach ( array_reverse( $ordered_wizards ) as $menu_item ) {
			$key = array_search( $menu_item, $menu_order, true );
			if ( false === $key ) {
				continue;
			}
			array_splice( $menu_order, $key, 1 );
			array_splice( $menu_order, $index + 1, 0, $menu_item );
		}
		return $menu_order;
	}
}
Wizards::init();
