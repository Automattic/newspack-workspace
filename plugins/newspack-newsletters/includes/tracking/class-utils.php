<?php
/**
 * Newspack Newsletters Tracking Utils.
 *
 * @package Newspack
 */

namespace Newspack_Newsletters\Tracking;

/**
 * Tracking Utils Class.
 */
final class Utils {
	/**
	 * Get the email address tag for the tracking pixel.
	 */
	public static function get_email_address_tag() {
		$provider = \Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return '';
		}
		$provider_name = $provider->service;
		switch ( $provider_name ) {
			case 'mailchimp':
				return '*|EMAIL|*';
			case 'campaign_monitor':
				return '[email]';
			case 'constant_contact':
				return '[[emailAddress]]';
			case 'active_campaign':
				return '%EMAIL%';
			default:
				return '';
		}
	}
}
