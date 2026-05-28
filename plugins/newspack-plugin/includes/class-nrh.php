<?php
/**
 * Newspack News Revenue Hub feature management.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Handles News Revenue Hub functionality.
 */
class NRH {
	/**
	 * Allowed config keys for NRH settings.
	 *
	 * @var array
	 */
	protected static $allowed_keys = [
		'nrh_organization_id',
		'nrh_custom_domain',
		'nrh_salesforce_campaign_id',
	];

	/**
	 * Add hooks.
	 */
	public static function init() {
		\add_filter( 'newspack_donation_checkout_url', [ __CLASS__, 'redirect_to_nrh_checkout' ], 10, 3 );
		\add_filter( 'newspack_blocks_donate_block_html', [ __CLASS__, 'handle_custom_campaign_id' ], 10, 2 );
		\add_filter( 'allowed_redirect_hosts', [ __CLASS__, 'allow_redirect_to_nrh' ] );
	}

	/**
	 * Get all News Revenue Hub settings.
	 *
	 * @return array Array of settings.
	 */
	public static function get_settings() {
		$settings = \get_option( NEWSPACK_NRH_CONFIG, [] );

		if ( method_exists( '\Newspack_Popups_Settings', 'donor_landing_page' ) ) {
			$settings['donor_landing_page'] = null;
			$donor_landing_page             = \Newspack_Popups_Settings::donor_landing_page();

			if ( $donor_landing_page ) {
				$settings['donor_landing_page'] = [
					'label' => \get_the_title( $donor_landing_page ),
					'value' => $donor_landing_page,
				];
			}
		}

		return $settings;
	}

	/**
	 * Get a specific News Revenue Hub setting by key name.
	 * Validates given key against valid keys.
	 *
	 * @param string $key Key of setting to get.
	 *
	 * @return string|boolean Value of setting if found, false otherwise.
	 */
	public static function get_setting( $key ) {
		if ( ! in_array( $key, self::$allowed_keys, true ) ) {
			return false;
		}

		$settings = self::get_settings();
		return isset( $settings[ $key ] ) ? \wp_strip_all_tags( $settings[ $key ] ) : false;
	}

	/**
	 * Update News Revenue Hub settings.
	 * Validates given data against valid keys.
	 *
	 * @param array $data Array of settings to update.
	 *
	 * @return boolean True if settings were updated, false otherwise.
	 */
	public static function update_settings( $data ) {
		$settings = self::get_settings();

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, self::$allowed_keys, true ) ) {
				$settings[ $key ] = $value;
			} elseif ( 'donor_landing_page' === $key && method_exists( '\Newspack_Popups_Settings', 'update_setting' ) ) {
				// Update the donor landing page in Campaigns settings.
				\Newspack_Popups_Settings::update_setting( 'donor_settings', 'newspack_popups_donor_landing_page', ! empty( $value['value'] ) ? (string) $value['value'] : 0 );
			}
		}

		// Don't want to save this extra key which is used only for the front-end display.
		unset( $settings['donor_landing_page'] );

		return \update_option( NEWSPACK_NRH_CONFIG, $settings );
	}

	/**
	 * Strips protocol from a domain, if it contains one.
	 *
	 * @param string|boolean $domain Domain to strip protocol from. Will accept falsy values as well.
	 *
	 * @return string|boolean Domain without protocol or false if the given domain is empty.
	 */
	public static function strip_protocol( $domain ) {
		if ( empty( $domain ) ) {
			return false;
		}

		$domain_parts = explode( '//', $domain );

		return \wp_unslash( end( $domain_parts ) );
	}

	/**
	 * Redirect to the NRH checkout page when the donation form is submitted if possible.
	 *
	 * @param string $checkout_url URL of checkout page.
	 * @param float  $donation_value Amount of donation.
	 * @param string $donation_frequency 'month', 'year', or 'once'.
	 * @return string Modified $checkout_url.
	 */
	public static function redirect_to_nrh_checkout( $checkout_url, $donation_value, $donation_frequency ) {
		// Mapping of Newspack -> NRH donation frequencies.
		$donation_frequencies = [
			'month' => 'monthly',
			'year'  => 'yearly',
			'once'  => 'once',
		];

		$nrh_config = self::get_settings();

		$organization_id = self::get_setting( 'nrh_organization_id' );
		$custom_domain   = self::strip_protocol( self::get_setting( 'nrh_custom_domain' ) );
		$salesforce_id   = self::get_setting( 'nrh_salesforce_campaign_id' );
		$custom_campaign = filter_input( INPUT_GET, 'campaign', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $custom_campaign ) {
			$salesforce_id = \wp_strip_all_tags( $custom_campaign );
		}
		$donation_value     = floatval( $donation_value );
		$donation_frequency = isset( $donation_frequencies[ $donation_frequency ] ) ? $donation_frequencies[ $donation_frequency ] : false;

		if ( empty( $organization_id ) || empty( $donation_value ) || empty( $donation_frequency ) ) {
			return $checkout_url;
		}

		// Remove donations from the cart since the customer won't be checking out on site.
		Donations::remove_donations_from_cart();

		$url = sprintf(
			'https://%s/?amount=%.1f&frequency=%s&org_id=%s',
			! empty( $custom_domain ) ? $custom_domain : $organization_id . '.fundjournalism.org',
			$donation_value,
			$donation_frequency,
			$organization_id
		);

		if ( ! empty( $salesforce_id ) ) {
			$url .= '&campaign=' . esc_attr( $salesforce_id );
		}

		return $url;
	}

	/**
	 * Add a hidden campaign input when a custom campaign is present in the GET request.
	 *
	 * @param string $html The donate form html.
	 * @param array  $attributes Block attributes.
	 * @return string modified $html.
	 */
	public static function handle_custom_campaign_id( $html, $attributes ) {
		// Don't add a global campaign ID if there is already a campaign ID.
		if ( stripos( $html, "name='campaign'" ) || stripos( $html, 'name="campaign"' ) ) {
			return $html;
		}

		$custom_campaign = filter_input( INPUT_GET, 'campaign', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! $custom_campaign ) {
			return $html;
		}

		$custom_campaign = '<input type="hidden" name="campaign" value="' . esc_attr( $custom_campaign ) . '"/>';
		$html            = str_replace( '</form>', $custom_campaign . '</form>', $html );
		return $html;
	}

	/**
	 * Add the potential NRH checkout URLs as allowed redirect targets.
	 *
	 * @param array $hosts Array of allowed hosts.
	 * @return array Modified $hosts.
	 */
	public static function allow_redirect_to_nrh( $hosts ) {
		$organization_id = self::get_setting( 'nrh_organization_id' );
		$custom_domain   = self::strip_protocol( self::get_setting( 'nrh_custom_domain' ) );
		$allowed_urls    = [
			'checkout.fundjournalism.org',
			$organization_id . '.fundjournalism.org',
		];

		if ( ! empty( $custom_domain ) ) {
			$allowed_urls[] = $custom_domain;
		}

		$hosts = array_merge( $hosts, $allowed_urls );

		return $hosts;
	}

	/**
	 * Get NRH config.
	 */
	public static function get_nrh_config() {
		return get_option( NEWSPACK_NRH_CONFIG, [] );
	}
}
NRH::init();
