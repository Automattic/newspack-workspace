<?php
/**
 * Service Provider: Constant_Contact Usage Reports
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Usage reports for Constant_Contact.
 */
class Newspack_Newsletters_Constant_Contact_Usage_Reports {
	/**
	 * Name of the option to store the last result under.
	 */
	const LAST_CAMPAIGNS_DATA_OPTION_NAME = 'newspack_newsletters_constant_contact_last_report';

	/**
	 * Get the SDK.
	 *
	 * @return Newspack_Newsletters_Constant_Contact_SDK|WP_Error
	 */
	public static function get_sdk() {
		$cc = Newspack_Newsletters_Constant_Contact::instance();
		if ( ! $cc->has_valid_connection() ) {
			return new WP_Error( 'no_valid_connection', __( 'No valid connection to Constant Contact.', 'newspack-newsletters' ) );
		}
		return new Newspack_Newsletters_Constant_Contact_SDK( $cc->api_key(), $cc->api_secret(), $cc->access_token() );
	}

	/**
	 * Get yesterday date range
	 *
	 * @param string $key The key to add to the array with the range.
	 *
	 * @return array
	 */
	public static function get_yesterday_range( $key ) {
		$before = gmdate( 'Y-m-d' ); // today.
		$after  = gmdate( 'Y-m-d', strtotime( '-2 day' ) ); // the day before yesterday.
		return [
			$key . '_after'  => $after . 'T23:59:59Z',
			$key . '_before' => $before . 'T00:00:00Z',
		];
	}

	/**
	 * Get contacts data
	 *
	 * @return array
	 */
	public static function get_contacts_data() {
		$sdk = self::get_sdk();
		if ( is_wp_error( $sdk ) ) {
			return $sdk;
		}
		$subscribes   = $sdk->get_contacts_count( self::get_yesterday_range( 'created' ) );
		$unsubscribes = $sdk->get_contacts_count( self::get_yesterday_range( 'optout' ) );

		return compact( 'subscribes', 'unsubscribes' );
	}

	/**
	 * Get the total number of active contacts
	 *
	 * @return int
	 */
	public static function get_total_active_contacts() {
		$sdk = self::get_sdk();
		if ( is_wp_error( $sdk ) ) {
			return $sdk;
		}
		$contacts = $sdk->get_contacts_count( [ 'status' => 'active' ] );
		return $contacts;
	}

	/**
	 * Get default campaign data.
	 */
	private static function get_default_campaign_data() {
		return [
			'emails_sent' => 0,
			'opens'       => 0,
			'clicks'      => 0,
		];
	}

	/**
	 * Get campaign data - emails sent, opens, and clicks.
	 *
	 * @param int $last_n_days Number of last days to get the data about.
	 */
	private function get_campaign_data( $last_n_days ) {
		$last_campaigns_data = get_option( self::LAST_CAMPAIGNS_DATA_OPTION_NAME );
		$campaigns_data      = self::get_default_campaign_data();

		$current_campaign_data = $this->get_current_campaign_data( $last_n_days );
		update_option( self::LAST_CAMPAIGNS_DATA_OPTION_NAME, $current_campaign_data );

		if ( ! $last_campaigns_data ) {
			// No data about campaigns yet, so there is nothing to compare the new data with.
			return $campaigns_data;
		}

		foreach ( $current_campaign_data as $campaign_id => $current_data ) {
			$prior_data = self::get_default_campaign_data();
			// From the current totals, subtract the totals from the last report.
			if ( isset( $last_campaigns_data[ $campaign_id ] ) ) {
				// Only consider campaigns that are present in the current response.
				$prior_data = $last_campaigns_data[ $campaign_id ];
			}
			$campaigns_data['emails_sent'] += $current_data['emails_sent'] - $prior_data['emails_sent'];
			$campaigns_data['opens']       += $current_data['opens'] - $prior_data['opens'];
			$campaigns_data['clicks']      += $current_data['clicks'] - $prior_data['clicks'];
		}
		return $campaigns_data;
	}

	/**
	 * Get campaign data - emails sent, opens, and clicks live from the ESP.
	 *
	 * @param int $last_n_days Number of last days to get the data about.
	 */
	public function get_current_campaign_data( $last_n_days ) {
		$sdk              = self::get_sdk();
		$campaigns_result = $sdk->get_campaigns_summaries();
		$cutoff_datetime  = strtotime( '-' . $last_n_days . ' days' );

		if ( \is_wp_error( $campaigns_result ) ) {
			return $campaigns_result;
		}

		$campaigns_data = [];

		foreach ( $campaigns_result->bulk_email_campaign_summaries as $campaign ) {
			if ( ! isset( $campaign->last_sent_date ) ) {
				continue;
			}
			// If the send date is before the cutoff date, break out.
			$campaign_send_date = strtotime( $campaign->last_sent_date );
			if ( $campaign_send_date < $cutoff_datetime ) {
				break;
			}
			$campaigns_data[ $campaign->campaign_id ] = [
				'emails_sent' => intval( $campaign->unique_counts->sends ),
				'opens'       => intval( $campaign->unique_counts->opens ),
				'clicks'      => intval( $campaign->unique_counts->clicks ),
			];
		}
		return $campaigns_data;
	}

	/**
	 * Creates a usage report.
	 *
	 * @return array Usage report.
	 */
	public function get_usage_report() {
		$report = new Newspack_Newsletters_Service_Provider_Usage_Report();

		// Get contact data to retrieve subs and unsubs.
		$contacts_data = $this->get_contacts_data();
		if ( \is_wp_error( $contacts_data ) ) {
			return $contacts_data;
		}

		// Get campaign data to retrieve emails sent, opens, and clicks.
		$campaign_data = $this->get_campaign_data( 30 ); // Consider Campaigns sent up to 30 days in the past.
		if ( \is_wp_error( $campaign_data ) ) {
			return $campaign_data;
		}

		$report->emails_sent = $campaign_data['emails_sent'];
		$report->opens       = $campaign_data['opens'];
		$report->clicks      = $campaign_data['clicks'];

		$report->total_contacts = $this->get_total_active_contacts();
		$report->subscribes     = $contacts_data['subscribes'];
		$report->unsubscribes   = $contacts_data['unsubscribes'];

		return $report;
	}
}
