<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Class Newsletters Test ActiveCampaign Usage Reports
 *
 * @package Newspack_Newsletters
 */

/**
 * Mock AC API.
 */
class Newspack_Newsletters_Active_Campaign_Test_Wrapper {
	public function api_v3_request( $endpoint, $method = 'GET', $params = [] ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$response = [ 'meta' => [] ];
		switch ( $endpoint ) {
			case 'contacts':
				$contacts = [
					[
						'id'    => 1,
						'email' => 'alice@mail.com',
						'cdate' => '2023-01-01 00:00:00',
						'udate' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
					],
					[
						'id'    => 2,
						'email' => 'jane@mail.com',
						'cdate' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
						'udate' => null,
					],
					[
						'id'    => 3,
						'email' => 'bob@mail.com',
						'cdate' => '2023-01-01 00:00:00',
						'udate' => null,
					],
				];
				if ( isset( $params['query'], $params['query']['status'] ) ) {
					$contacts = array_filter(
						$contacts,
						function ( $contact ) use ( $params ) {
							if ( 1 === $params['query']['status'] ) {
								return null === $contact['udate'];
							} elseif ( 2 === $params['query']['status'] ) {
								return null !== $contact['udate'];
							}
							return true;
						}
					);
				}
				$response['meta']['total'] = count( $contacts );
				$response['contacts']      = $contacts;
				break;
			case 'campaigns':
				$campaigns = [
					[
						'id'               => 1,
						'status'           => 5,
						'sdate'            => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
						'send_amt'         => 10,
						'uniqueopens'      => 5,
						'uniquelinkclicks' => 2,
					],
					[
						'id'               => 2,
						'status'           => 5,
						'sdate'            => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
						'send_amt'         => 5,
						'uniqueopens'      => 3,
						'uniquelinkclicks' => 1,
					],
					[
						'id'               => 3,
						'status'           => 5,
						'sdate'            => gmdate( 'Y-m-d H:i:s', strtotime( '-3 day' ) ),
						'send_amt'         => 100,
						'uniqueopens'      => 40,
						'uniquelinkclicks' => 10,
					],
					// Campaign sent more than 30 days ago should be ignored.
					[
						'id'               => 4,
						'status'           => 5,
						'sdate'            => gmdate( 'Y-m-d H:i:s', strtotime( '-31 day' ) ),
						'send_amt'         => 99,
						'uniqueopens'      => 99,
						'uniquelinkclicks' => 99,
					],
				];
				$response['campaigns']     = [ $campaigns[ (int) $params['query']['offset'] ] ]; // return one item per page.
				$response['meta']['total'] = count( $campaigns );
				break;
		}
		return $response;
	}
}

/**
 * Test ActiveCampaign Usage Reports.
 */
class ActiveCampaignUsageReportsTest extends WP_UnitTestCase {
	public function test_get_usage_report_initial() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$expected_report = new Newspack_Newsletters_Service_Provider_Usage_Report();
		// The initial report's campaigns data should be empty, because there is no prior data to compare the values with.
		// The campaigns data can only be meaningful when compared with prior data.
		$expected_report->emails_sent    = 0;
		$expected_report->opens          = 0;
		$expected_report->clicks         = 0;
		$expected_report->subscribes     = 2;
		$expected_report->unsubscribes   = 0; // unsubs also rely on prior data.
		$expected_report->total_contacts = 2;

		$usage_report_object = new Newspack_Newsletters_Active_Campaign_Usage_Reports( new Newspack_Newsletters_Active_Campaign_Test_Wrapper() );
		$usage_report_object->campaign_fetch_batch_size = 1;
		$actual_report = $usage_report_object->get_usage_report();

		$this->assertEquals( $expected_report->to_array(), $actual_report->to_array() );
	}

	public function test_get_usage_report_with_prior_data() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

		update_option( Newspack_Newsletters_Active_Campaign_Usage_Reports::LAST_UNSUBS_DATA_OPTION_NAME, 0 );

		update_option(
			Newspack_Newsletters_Active_Campaign_Usage_Reports::LAST_CAMPAIGNS_DATA_OPTION_NAME,
			[
				1   => [
					'emails_sent' => 9,
					'opens'       => 1,
					'clicks'      => 1,
				],
				3   => [
					'emails_sent' => 100,
					'opens'       => 40,
					'clicks'      => 10,
				],
				// A campaign present in the prior data but not in the current data should be ignored.
				100 => [
					'emails_sent' => 88,
					'opens'       => 88,
					'clicks'      => 88,
				],
			]
		);
		$expected_report                 = new Newspack_Newsletters_Service_Provider_Usage_Report();
		$expected_report->emails_sent    = 6;
		$expected_report->opens          = 7;
		$expected_report->clicks         = 2;
		$expected_report->subscribes     = 2;
		$expected_report->unsubscribes   = 1;
		$expected_report->total_contacts = 2;

		$usage_report_object = new Newspack_Newsletters_Active_Campaign_Usage_Reports( new Newspack_Newsletters_Active_Campaign_Test_Wrapper() );
		$usage_report_object->campaign_fetch_batch_size = 1;
		$actual_report = $usage_report_object->get_usage_report();

		$this->assertEquals( $expected_report->to_array(), $actual_report->to_array() );
	}
}
