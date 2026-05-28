<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Class Newsletters Test Mailchimp Usage Reports
 *
 * @package Newspack_Newsletters
 */

/**
 * Test Mailchimp Usage Reports.
 */
class MailchimpUsageReportsTest extends WP_UnitTestCase {

	/**
	 * Mock database
	 *
	 * @var array
	 */
	public static $database;

	/**
	 * Test set up.
	 */
	public static function set_up_before_class() {
		// Set an ESP.
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'test-us1' );

		self::$database = [
			'lists'   => [
				[
					'id'    => 'test-list-1',
					'name'  => 'Test List',
					'stats' => [ 'member_count' => 42 ],
				],
				[
					'id'    => 'test-list-2',
					'name'  => 'Test List 2',
					'stats' => [ 'member_count' => 21 ],
				],
			],
			'reports' => [
				[
					// Sent at 8am today – should be disregarded.
					'id'          => 'campaign-today',
					'emails_sent' => 121,
					'opens'       => [ 'unique_opens' => 131 ],
					'clicks'      => [ 'unique_subscriber_clicks' => 141 ],
					'send_time'   => gmdate( 'Y-m-d\T08:00:00P' ),
				],
				[
					// Sent at 8am yesterday.
					'id'          => 'campaign-yesterday',
					'emails_sent' => 12,
					'opens'       => [ 'unique_opens' => 13 ],
					'clicks'      => [ 'unique_subscriber_clicks' => 14 ],
					'send_time'   => gmdate( 'Y-m-d\T08:00:00P', strtotime( '-1 day' ) ),
				],
				[
					// Sent at 8am the day before yesterday.
					'id'          => 'campaign-day-before-yesterday',
					'emails_sent' => 22,
					'opens'       => [ 'unique_opens' => 23 ],
					'clicks'      => [ 'unique_subscriber_clicks' => 24 ],
					'send_time'   => gmdate( 'Y-m-d\T08:00:00P', strtotime( '-2 day' ) ),
				],
				[
					// Sent at 8am a week ago.
					'id'          => 'campaign-week-ago',
					'emails_sent' => 32,
					'opens'       => [ 'unique_opens' => 33 ],
					'clicks'      => [ 'unique_subscriber_clicks' => 34 ],
					'send_time'   => gmdate( 'Y-m-d\T08:00:00P', strtotime( '-7 day' ) ),
				],
			],
		];
	}

	/**
	 * Setup.
	 */
	public function set_up() {
		delete_option( Newspack_Newsletters_Mailchimp_Usage_Reports::REPORTS_OPTION_NAME );
		add_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get_response' ], 10, 3 );
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		delete_option( Newspack_Newsletters_Mailchimp_Usage_Reports::REPORTS_OPTION_NAME );
		remove_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get_response' ] );
	}

	public static function mock_get_response( $response, $endpoint, $args = [] ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( preg_match( '/lists\/.*\/activity/', $endpoint ) ) {
			$activity = [];
			for ( $day_index = 0; $day_index < $args['count']; $day_index++ ) {
				$activity[] = [
					'day'              => gmdate( 'Y-m-d', strtotime( "-$day_index day" ) ),
					// To accurately reflect MC API, the sent/opens/clicks data will be empty.
					// It will be empty for recent 2-3 days in live API.
					'emails_sent'      => 0,
					'unique_opens'     => 0,
					'recipient_clicks' => 0,
					// As many as the day index, just to be predictable.
					'subs'             => $day_index,
					'unsubs'           => $day_index,
				];
			}
			return [
				'activity' => $activity,
			];
		}
		switch ( $endpoint ) {
			case 'lists':
				return [
					'lists' => self::$database['lists'],
				];
			case 'reports':
				return [
					'reports' => self::$database['reports'],
				];
			default:
				return [];
		}
	}

	public function test_get_usage_report_mailchimp_initial() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$expected_report = new Newspack_Newsletters_Service_Provider_Usage_Report(
			[

				'emails_sent'    => 12,
				'opens'          => 13,
				'clicks'         => 14,
				'subscribes'     => 2,
				'unsubscribes'   => 2,
				'total_contacts' => 63,
			]
		);

		$actual_report = ( new Newspack_Newsletters_Mailchimp_Usage_Reports() )->get_usage_report();

		$this->assertEquals( $expected_report->to_array(), $actual_report->to_array() );
	}

	public function test_get_usage_report_mailchimp_with_prior_data() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		// Saved prior data.
		$saved_campaign_reports = [
			// Values "from yesterday", which will have to be subtracted from the new report's values.
			'campaign-yesterday' => [
				'emails_sent' => 4,
				'opens'       => 3,
				'clicks'      => 2,
				'send_time'   => gmdate( 'Y-m-d\T08:00:00P', strtotime( '-1 day' ) ),
			],
		];
		update_option( Newspack_Newsletters_Mailchimp_Usage_Reports::REPORTS_OPTION_NAME, $saved_campaign_reports );

		$expected_report = new Newspack_Newsletters_Service_Provider_Usage_Report(
			[

				'emails_sent'    => 12 - 4,
				'opens'          => 13 - 3,
				'clicks'         => 14 - 2,
				'subscribes'     => 2,
				'unsubscribes'   => 2,
				'total_contacts' => 63,
			]
		);
		$actual_report = ( new Newspack_Newsletters_Mailchimp_Usage_Reports() )->get_usage_report();

		$this->assertEquals( $expected_report->to_array(), $actual_report->to_array() );
	}

	public function test_get_usage_report_mailchimp_backfill() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$actual_reports = ( new Newspack_Newsletters_Mailchimp_Usage_Reports() )->get_usage_reports( 10 );
		$this->assertCount( 10, $actual_reports );
		$serialized_actual_reports = array_map(
			function( $report ) {
				return $report->to_array();
			},
			$actual_reports
		);
		$last_report = end( $serialized_actual_reports );
		$this->assertEquals( 0, $last_report['emails_sent'] );
		$this->assertEquals( 0, $last_report['opens'] );
		$this->assertEquals( 0, $last_report['clicks'] );
		// Mock API returns 1*<day index> per list, there are two mock lists and day index will be 10 here.
		$this->assertEquals( 20, $last_report['unsubscribes'] );
		$this->assertEquals( 20, $last_report['subscribes'] );
	}

	public function test_get_usage_report_mailchimp_backfill_with_prior_data() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		// Saved prior data.
		$saved_campaign_reports = [
			'campaign-week-ago' => [
				'emails_sent' => 22,
				'opens'       => 10,
				'clicks'      => 10,
				'send_time'   => gmdate( 'Y-m-d\T08:00:00P', strtotime( '-7 day' ) ),
			],
		];
		update_option( Newspack_Newsletters_Mailchimp_Usage_Reports::REPORTS_OPTION_NAME, $saved_campaign_reports );

		$actual_reports = ( new Newspack_Newsletters_Mailchimp_Usage_Reports() )->get_usage_reports( 10 );
		$serialized_actual_reports = array_map(
			function( $report ) {
				return $report->to_array();
			},
			$actual_reports
		);
		$last_report = end( $serialized_actual_reports );
		$this->assertEquals( 0, $last_report['emails_sent'] );
		$this->assertEquals( 0, $last_report['opens'] );
		$this->assertEquals( 0, $last_report['clicks'] );
	}
}
