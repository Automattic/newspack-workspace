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
		return match ( $provider->service ) {
			'mailchimp'        => '*|EMAIL|*',
			'campaign_monitor' => '[email]',
			'constant_contact' => '[[emailAddress]]',
			'active_campaign'  => '%EMAIL%',
			default            => '',
		};
	}

	/**
	 * Get the ESP merge tag for an arbitrary merge field.
	 *
	 * The counterpart to get_email_address_tag() for any field: callers embed
	 * the returned tag in newsletter content and the ESP substitutes the
	 * recipient's value at send time. Used to carry a per-recipient field value
	 * (e.g. a donor-status flag) into a link without Newspack needing to know
	 * the recipient at render time.
	 *
	 * The field name is used verbatim, so callers must pass the exact ESP merge
	 * tag (e.g. the Mailchimp merge field's tag, not its display label).
	 *
	 * @param string $field The ESP merge field tag.
	 *
	 * @return string The merge tag (e.g. '*|DONORSTATUS|*'), or '' when there is
	 *                no provider, the field is empty, or the provider is unsupported.
	 */
	public static function get_merge_tag( $field ) {
		$field = trim( (string) $field );
		if ( '' === $field ) {
			return '';
		}
		$provider = \Newspack_Newsletters::get_service_provider();
		if ( empty( $provider ) ) {
			return '';
		}
		return match ( $provider->service ) {
			'mailchimp'        => '*|' . $field . '|*',
			'campaign_monitor' => '[' . $field . ']',
			'constant_contact' => '[[' . $field . ']]',
			'active_campaign'  => '%' . $field . '%',
			default            => '',
		};
	}
}
