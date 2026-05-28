<?php
/**
 * Campaign Monitor ESP Usage Report class.
 *
 * @package Newspack
 */

// The response from the API doesn't follow this rule.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

defined( 'ABSPATH' ) || exit;

/**
 * Campaign Monitor ESP Usage Report class
 */
class Newspack_Newsletters_Campaign_Monitor_Usage_Reports {

	/**
	 * Gets the Campaign Monitor Lists API client.
	 *
	 * @param string $list_id List ID.
	 * @return CS_REST_Lists|WP_Error CS_REST_Lists instance or error.
	 */
	private static function get_lists_client( $list_id ) {
		$cm      = Newspack_Newsletters_Campaign_Monitor::instance();
		$api_key = $cm->api_key();

		if ( ! $api_key ) {
			return new WP_Error(
				'newspack_newsletters_missing_api_key',
				__( 'No Campaign Monitor API key available.', 'newspack-newsletters' )
			);
		}

		return new CS_REST_Lists( $list_id, [ 'api_key' => $api_key ] );
	}

	/**
	 * Gets the Campaign Monitor Clients API client.
	 *
	 * @return CS_REST_Clients|WP_Error CS_REST_Clients instance or error.
	 */
	private static function get_clients_client() {
		$cm        = Newspack_Newsletters_Campaign_Monitor::instance();
		$api_key   = $cm->api_key();
		$client_id = $cm->client_id();

		if ( ! $api_key ) {
			return new WP_Error(
				'newspack_newsletters_missing_api_key',
				__( 'No Campaign Monitor API key available.', 'newspack-newsletters' )
			);
		}
		if ( ! $client_id ) {
			return new WP_Error(
				'newspack_newsletters_missing_client_id',
				__( 'No Campaign Monitor Client ID available.', 'newspack-newsletters' )
			);
		}

		return new CS_REST_Clients( $client_id, [ 'api_key' => $api_key ] );
	}

	/**
	 * Gets the full report
	 *
	 * @return Newspack_Newsletters_Service_Provider_Usage_Report
	 */
	public static function get_report() {
		$subs      = self::get_subscribers_info();
		$campaigns = self::get_campaigns_info();
		if ( is_wp_error( $subs ) ) {
			return $subs;
		}
		if ( is_wp_error( $campaigns ) ) {
			return $campaigns;
		}
		return new Newspack_Newsletters_Service_Provider_Usage_Report( array_merge( $subs, $campaigns ) );
	}

	/**
	 * Get total active subscribers and new subscribers and unsubscribers for yesterday.
	 *
	 * @return array|WP_Error Array of total active subscribers and new subscribers and unsubscribers for yesterday, or WP_Error on failure.
	 */
	private static function get_subscribers_info() {
		$cm    = Newspack_Newsletters_Campaign_Monitor::instance();
		$lists = $cm->get_lists();

		if ( is_wp_error( $lists ) ) {
			return $lists;
		}

		$results = [];

		foreach ( $lists as $list ) {
			$results[ $list['id'] ] = self::get_subscribers_for_list( $list['id'] );
		}

		// sum up the results.
		$summed_results = [];
		foreach ( $results as $list_id => $list_results ) {
			if ( is_wp_error( $list_results ) ) {
				return $list_results;
			}
			foreach ( $list_results as $metric => $value ) {
				if ( ! isset( $summed_results[ $metric ] ) ) {
					$summed_results[ $metric ] = 0;
				}
				$summed_results[ $metric ] += $value;
			}
		}

		return $summed_results;
	}

	/**
	 * Get campaigns stats for Campaigns sent the last 30 days.
	 *
	 * @return WP_Error|array Array of campaigns stats, or WP_Error on failure.
	 */
	private static function get_campaigns_info() {
		$api = self::get_clients_client();
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$cut_date    = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$target_date = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$campaigns = $api->get_campaigns( null, null, null, null, $cut_date );
		if ( ! $campaigns->was_successful() ) {
			return new WP_Error(
				'newspack_newsletters_campaign_monitor_error',
				__( 'Could not retrieve Campaign Monitor Campaigns info. Please check your API key.', 'newspack-newsletters' )
			);
		}

		$results        = [
			'emails_sent' => 0,
			'opens'       => 0,
			'clicks'      => 0,
		];
		$campaign_stats = [];

		foreach ( $campaigns->Results as $campaign ) {
			$campaign_sent_date = gmdate( 'Y-m-d', strtotime( $campaign->SentDate ) );
			if ( $campaign_sent_date > $target_date ) {
				continue;
			}

			$sent_on_target_day = $campaign_sent_date === $target_date;

			if ( $sent_on_target_day ) {
				$results['emails_sent'] += $campaign->TotalRecipients;
			}

			$campaign_stats[] = self::get_campaign_summary( $campaign->CampaignID, $sent_on_target_day );
		}

		foreach ( $campaign_stats as $campaign_stat ) {
			foreach ( $campaign_stat as $metric => $value ) {
				if ( ! isset( $results[ $metric ] ) ) {
					$results[ $metric ] = 0;
				}
				$results[ $metric ] += $value;
			}
		}

		return $results;
	}

	/**
	 * Get subscribers stats for a given list
	 *
	 * @param string $list_id List ID.
	 * @return array|WP_Error Array of total active subscribers and new subscribers and unsubscribers for yesterday, or WP_Error on failure.
	 */
	private static function get_subscribers_for_list( $list_id ) {
		$api = self::get_lists_client( $list_id );
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$results = [];

		$response = $api->get_stats();
		if ( ! $response->was_successful() ) {
			return new WP_Error(
				'newspack_newsletters_campaign_monitor_error',
				__( 'Could not retrieve Campaign Monitor subscribers info. Please check your API key.', 'newspack-newsletters' )
			);
		}
		return [
			'total_contacts' => $response->response->TotalActiveSubscribers,
			'subscribes'     => $response->response->NewActiveSubscribersYesterday,
			'unsubscribes'   => $response->response->UnsubscribesYesterday,
		];
	}

	/**
	 * Get a campaign summary
	 *
	 * @param string  $campaign_id Campaign ID.
	 * @param boolean $on_target_day Whether this campaign was sent on the target day.
	 * @return array|WP_Error
	 */
	private static function get_campaign_summary( $campaign_id, $on_target_day = false ) {
		$api = self::get_campaigns_client();
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$summary = $api->get_campaign_summary( $campaign_id );
		if ( ! $summary->was_successful() ) {
			return new WP_Error(
				'newspack_newsletters_campaign_monitor_error',
				__( 'Could not retrieve Campaign Monitor Campaigns info. Please check your API key.', 'newspack-newsletters' )
			);
		}

		$campaign_last_data_option_name = 'np_newsletters_campaign_data_' . $campaign_id;

		$campaign_data = [
			'opens'  => $summary->UniqueOpened,
			'clicks' => $summary->Clicks,
		];

		$campaign_last_data = get_option( $campaign_last_data_option_name );
		update_option( $campaign_last_data_option_name, $campaign_data );

		if ( $on_target_day ) {
			// This campaign was just sent. All the data is from yesterday.
			return $campaign_data;
		}

		if ( ! $campaign_last_data ) {
			// We don't have data about this campaign yet. Most likely this is the first time this method runs.
			return [
				'opens'  => 0,
				'clicks' => 0,
			];
		}

		return [
			'opens'  => $campaign_data['opens'] - $campaign_last_data['opens'],
			'clicks' => $campaign_data['clicks'] - $campaign_last_data['clicks'],
		];
	}
}
