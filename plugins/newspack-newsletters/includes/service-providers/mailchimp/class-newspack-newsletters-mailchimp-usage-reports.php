<?php
/**
 * Service Provider: Mailchimp Usage Reports
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

use Newspack_Newsletters_Mailchimp_Api as Mailchimp;

/**
 * Usage reports for Mailchimp.
 */
class Newspack_Newsletters_Mailchimp_Usage_Reports {
	const REPORTS_OPTION_NAME = 'newspack_newsletters_mailchimp_usage_reports';

	/**
	 * Retrieves an instance of the Mailchimp api
	 *
	 * @return Newspack_Newsletters_Mailchimp_Api|WP_Error
	 */
	private static function get_mc_api() {
		$api_key = Newspack_Newsletters_Mailchimp::instance()->api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_empty_api_key',
				__( 'Mailchimp API key is not set.', 'newspack-newsletters' )
			);
		}
		try {
			return new Mailchimp( $api_key );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Get list activity reports for the timeframe between n days in past and yesterday.
	 *
	 * @param string $days_in_past_count How many days in the past to look for.
	 * @return Newspack_Newsletters_Service_Provider_Usage_Report[]|WP_Error Usage reports.
	 */
	private static function get_list_activity_reports( $days_in_past_count = 1 ) {
		$mc_api = self::get_mc_api();

		if ( is_wp_error( $mc_api ) ) {
			return $mc_api;
		}

		$reports = [];
		$lists   = Newspack_Newsletters_Mailchimp::instance()->validate(
			$mc_api->get( 'lists', [ 'count' => 1000 ] )
		);
		if ( ! isset( $lists['lists'] ) ) {
			return $reports;
		}

		foreach ( $lists['lists'] as &$list ) {
			// Get daily activity for each list.
			$activity_response = $mc_api->get(
				'lists/' . $list['id'] . '/activity',
				[
					'count' => $days_in_past_count + 1, // Add 1 to include the current day, which will be disregarded.
				]
			);
			$list['activity']  = $activity_response['activity'];
		}

		for ( $day_index = 1; $day_index <= $days_in_past_count; $day_index++ ) {
			$report = new Newspack_Newsletters_Service_Provider_Usage_Report();
			$report->set_date( gmdate( 'Y-m-d', strtotime( "-$day_index day" ) ) );

			$report_updated = false;
			foreach ( $lists['lists'] as $list_data ) {
				$report->total_contacts += $list_data['stats']['member_count'];
				if ( isset( $list_data['activity'][ $day_index ] ) ) {
					$list_activity_for_day = $list_data['activity'][ $day_index ];
					$report->emails_sent += $list_activity_for_day['emails_sent'];
					$report->opens += $list_activity_for_day['unique_opens'];
					$report->clicks += $list_activity_for_day['recipient_clicks'];
					$report->subscribes += $list_activity_for_day['subs'];
					$report->unsubscribes += $list_activity_for_day['unsubs'];
					$report_updated = true;
				}
			}
			if ( $report_updated ) {
				$reports[] = $report;
			}
		}

		return $reports;
	}

	/**
	 * Get usage reports for last n days.
	 *
	 * @param int $days_in_past How many days in past.
	 * @return Newspack_Newsletters_Service_Provider_Usage_Report[]|WP_Error Usage reports or error.
	 */
	public static function get_usage_reports( $days_in_past ) {

		// Check and bail early if MC is misconfigured.
		$mc_api = self::get_mc_api();
		if ( is_wp_error( $mc_api ) ) {
			return $mc_api;
		}

		// Start with lists activity reports. These are good for historical data and also will provide
		// subscribes and unsubscribes data. However, in order to get recent
		// sent/opens/clicks data, the campaign reports have to be used.
		// It appears that the sent/opens/clicks data in the lists activity are only added after a
		// delay of 2-3 days.

		try {
			// Get the list activity reports.
			$reports = self::get_list_activity_reports( $days_in_past );
		} catch ( Exception $e ) {
			return new WP_Error(
				'newspack_newsletters_mailchimp_error',
				$e->getMessage()
			);
		}

		$campaign_reports = [];
		// Look at reports for campaigns sent at most two weeks ago, unless $days_in_past is larger.
		$campaign_reports_cutoff = $days_in_past > 14 ? $days_in_past : 14;
		$campaign_reports_response = $mc_api->get(
			'reports',
			[
				'since_send_time' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $campaign_reports_cutoff . 'days' ) ),
				'type'            => 'regular', // Email campaigns.
			]
		);
		if ( ! isset( $campaign_reports_response['reports'] ) ) {
			return [ new Newspack_Newsletters_Service_Provider_Usage_Report() ];
		}
		// For each report, save the stats per-campaign.
		foreach ( $campaign_reports_response['reports'] as $campaign_report ) {
			$send_time = $campaign_report['send_time'];
			// Disregards reports for campaigns sent today (this data is incomplete and
			// should surface in tomorrow's report).
			if ( gmdate( 'Y-m-d', strtotime( $send_time ) ) === gmdate( 'Y-m-d' ) ) {
				continue;
			}
			$campaign_reports[ $campaign_report['id'] ] = [
				'emails_sent' => $campaign_report['emails_sent'],
				'opens'       => $campaign_report['opens']['unique_opens'],
				// `unique_subscriber_clicks` will match what's visible in MC UI, but more accurate would be
				// to use `unique_clicks`.
				'clicks'      => $campaign_report['clicks']['unique_subscriber_clicks'],
				'send_time'   => $send_time,
			];
		}

		// Compare to stored reports to compute the delta.
		$saved_reports = get_option( self::REPORTS_OPTION_NAME, [] );

		foreach ( $reports as $report ) {
			$report_start_date = $report->get_date();
			$report_end_date = gmdate( 'Y-m-d', strtotime( $report_start_date ) + DAY_IN_SECONDS );
			foreach ( $campaign_reports as $campaign_id => $campaign_report ) {
				// If the campaign report matches the report timeframe, fill in the data if it's missing.
				if ( $campaign_report['send_time'] >= $report_start_date && $campaign_report['send_time'] < $report_end_date ) {
					$previous_report = isset( $saved_reports[ $campaign_id ] ) ? $saved_reports[ $campaign_id ] : false;
					foreach ( [ 'emails_sent', 'opens', 'clicks' ] as $field ) {
						// Only fill in the field if the value in the initial report is missing.
						if ( $report->$field === 0 ) {
							$report->$field += $campaign_report[ $field ];
							if ( $previous_report ) {
								$report->$field -= $previous_report[ $field ];
							}
						}
					}
				}
			}
		}

		// Save the recent response.
		update_option( self::REPORTS_OPTION_NAME, $campaign_reports );

		return $reports;
	}

	/**
	 * Creates a usage report.
	 *
	 * @return Newspack_Newsletters_Service_Provider_Usage_Report|WP_Error|null Usage report, error or Null in case there's no need to check for a report.
	 */
	public static function get_usage_report() {
		$reports = self::get_usage_reports( 1 );
		if ( \is_wp_error( $reports ) ) {

			// if the api key is not set, Mailchimp is not in use, we shouldn't even try to get the usage report.
			if ( $reports->get_error_code() === 'newspack_newsletters_mailchimp_empty_api_key' ) {
				return null;
			}

			return $reports;
		}
		return reset( $reports );
	}
}
