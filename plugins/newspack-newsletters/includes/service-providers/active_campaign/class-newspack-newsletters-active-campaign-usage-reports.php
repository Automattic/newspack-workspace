<?php
/**
 * Service Provider: Active_Campaign Usage Reports
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Usage reports for Active_Campaign.
 */
class Newspack_Newsletters_Active_Campaign_Usage_Reports {
	/**
	 * Name of the option to store the last result under.
	 */
	const LAST_CAMPAIGNS_DATA_OPTION_NAME = 'newspack_newsletters_active_campaign_last_report';

	/**
	 * Name of the option to store the last result under.
	 */
	const LAST_UNSUBS_DATA_OPTION_NAME = 'newspack_newsletters_active_campaign_last_unsubs_count';

	/**
	 * Newspack_Newsletters_Active_Campaign instance.
	 *
	 * @var Newspack_Newsletters_Active_Campaign
	 */
	private $ac_instance;

	/**
	 * Number of campaigns to fetch in a single request.
	 *
	 * @var integer
	 */
	public $campaign_fetch_batch_size = 20;

	/**
	 * Constructor with dependency injection for the sake of tests.
	 *
	 * @param Newspack_Newsletters_Active_Campaign $active_campaign Active Campaign instance.
	 */
	public function __construct( $active_campaign = null ) {
		if ( null === $active_campaign ) {
			$active_campaign = new Newspack_Newsletters_Active_Campaign();
		}
		$this->ac_instance = $active_campaign;
	}

	/**
	 * Get contacts data - subs and unsubs.
	 */
	private function get_contacts_data() {
		$subs = $this->get_subs_count();
		if ( \is_wp_error( $subs ) ) {
			return $subs;
		}
		$unsubs = $this->get_unsubs_count();
		if ( \is_wp_error( $unsubs ) ) {
			return $unsubs;
		}

		return [
			'subs'   => $subs,
			'unsubs' => $unsubs,
		];
	}

	/**
	 * Gets the count of subscribers for the last day.
	 *
	 * @return int|WP_Error The number of subscribers for the last day, or a WP_Error object on failure.
	 */
	private function get_subs_count() {
		$ac     = $this->ac_instance;
		$params = [
			'query' => [
				'limit'   => 1,
				'status'  => 1,
				'filters' => [
					'created_before' => gmdate( 'Y-m-d' ),
					'created_after'  => gmdate( 'Y-m-d', strtotime( '-1 days' ) ),
				],
			],
		];

		$contacts_result = $ac->api_v3_request( 'contacts', 'GET', $params );
		if ( \is_wp_error( $contacts_result ) ) {
			return $contacts_result;
		}

		$total = intval( $contacts_result['meta']['total'] );
		return $total;
	}

	/**
	 * Gets the count of unsubscribers for the last day.
	 *
	 * There's no way to filter for unsubscribes on a given day. Filtering by updated_after is also not reliable.
	 * Let's take the total number of unsubscribes, and subtract the total number of unsubscribes from the previous day.
	 * This is also not perfect, but it's the best alternative I've found.
	 *
	 * @return int|WP_Error The number of unsubscribers for the last day, or a WP_Error object on failure.
	 */
	private function get_unsubs_count() {
		$last_count  = get_option( self::LAST_UNSUBS_DATA_OPTION_NAME );
		$last_exists = true;
		if ( false === $last_count ) {
			$last_exists = false;
			$last_count  = 0;
		}

		$ac     = $this->ac_instance;
		$params = [
			'query' => [
				'limit'  => 1,
				'status' => 2,
			],
		];

		$contacts_result = $ac->api_v3_request( 'contacts', 'GET', $params );
		if ( \is_wp_error( $contacts_result ) ) {
			return $contacts_result;
		}

		$total = intval( $contacts_result['meta']['total'] );

		update_option( self::LAST_UNSUBS_DATA_OPTION_NAME, $total );
		if ( $last_exists ) {
			return $total - (int) $last_count;
		} else {
			return 0;
		}
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

		$current_campaign_data = $this->get_current_campaign_data( $last_n_days );
		if ( \is_wp_error( $current_campaign_data ) ) {
			return $current_campaign_data;
		}
		update_option( self::LAST_CAMPAIGNS_DATA_OPTION_NAME, $current_campaign_data );

		$campaigns_data = self::get_default_campaign_data();

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
	private function get_current_campaign_data( $last_n_days ) {
		$ac = $this->ac_instance;

		$cutoff_datetime = strtotime( '-' . $last_n_days . ' days' );

		// Avoid timeouts by fetching campaigns in batches.
		$batch_size       = $this->campaign_fetch_batch_size;
		$batch            = 0;
		$campaigns_result = [];

		while ( $batch < ceil( 100 / $batch_size ) ) { // Assuming there will be no more than 100 campaigns in the requested period (last n days).
			$offset = $batch_size * $batch;
			$params = [
				'query' => [
					'limit'  => $batch_size,
					'offset' => $offset,
					'orders' => [ 'sdate' => 'DESC' ],
				],
			];

			$campaigns_response = $ac->api_v3_request( 'campaigns', 'GET', $params );

			if ( \is_wp_error( $campaigns_response ) ) {
				return $campaigns_response;
			}

			$campaigns_result = array_merge( $campaigns_result, $campaigns_response['campaigns'] );

			// if oldest campaign is older than the cutoff date, break out.
			$oldest_campaign = end( $campaigns_response['campaigns'] );
			$campaign_send_date = strtotime( $oldest_campaign['sdate'] );
			if ( $campaign_send_date < $cutoff_datetime ) {
				break;
			}

			if ( count( $campaigns_response['campaigns'] ) < $batch_size ) {
				break;
			}

			$batch++;
		}

		$campaigns_data = [];

		foreach ( $campaigns_result as $campaign ) {
			if (
				! isset( $campaign['sdate'] )
				|| 5 != $campaign['status'] // Status "5" is "completed. See https://www.activecampaign.com/api/example.php?call=campaign_list.
				) {
				continue;
			}
			// If the send date is before the cutoff date, break out.
			$campaign_send_date = strtotime( $campaign['sdate'] );
			if ( $campaign_send_date < $cutoff_datetime ) {
				break;
			}
			$campaigns_data[ intval( $campaign['id'] ) ] = [
				'emails_sent' => intval( $campaign['send_amt'] ),
				'opens'       => intval( $campaign['uniqueopens'] ),
				'clicks'      => intval( $campaign['uniquelinkclicks'] ),
			];
		}
		return $campaigns_data;
	}

	/**
	 * Get total number of active contacts.
	 */
	private function get_total_active_contacts() {
		$ac              = $this->ac_instance;
		$contacts_result = $ac->api_v3_request(
			'contacts',
			'GET',
			[
				'query' => [
					'limit'  => 1,
					'status' => 1,
				],
			]
		);
		return (int) $contacts_result['meta']['total'];
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

		$report->subscribes   = $contacts_data['subs'];
		$report->unsubscribes = $contacts_data['unsubs'];

		return $report;
	}
}
