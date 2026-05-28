<?php
/**
 * Class Newsletters Test usage reports.
 *
 * @package Newspack_Newsletters
 */

/**
 * Newsletters Usage Reports Test.
 */
class Usage_Reports_Test extends WP_UnitTestCase {
	/**
	 * Test usage report.
	 */
	public function test_usage_report_no_api_key() {

		delete_option( 'newspack_mailchimp_api_key' );

		self::assertSame(
			Newspack_Newsletters_Mailchimp_Usage_Reports::get_usage_report(),
			null
		);
	}

	/**
	 * Test usage report with invalid API key.
	 */
	public function test_usage_report_invalid_api_key() {

		update_option( 'newspack_mailchimp_api_key', 'asd123' );

		self::assertSame(
			Newspack_Newsletters_Mailchimp_Usage_Reports::get_usage_report()->get_error_code(),
			'newspack_newsletters_mailchimp_error'
		);
	}

	/**
	 * Test usage report with basic data.
	 */
	public function test_usage_report_basic() {

		update_option( 'newspack_mailchimp_api_key', 'asd123' );

		add_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get_response' ], 10, 3 );
		$report = Newspack_Newsletters_Mailchimp_Usage_Reports::get_usage_report();
		remove_filter( 'mailchimp_mock_get', [ __CLASS__, 'mock_get_response' ] );

		self::assertEquals(
			$report->to_array(),
			[
				'date'           => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
				'emails_sent'    => 0,
				'opens'          => 0,
				'clicks'         => 0,
				'unsubscribes'   => 0,
				'subscribes'     => 0,
				'total_contacts' => 0,
				'growth_rate'    => 0,
			]
		);
	}

	/**
	 * Mock get response.
	 *
	 * @param string $method Request method.
	 * @param string $path   Request path.
	 * @param array  $options Request options.
	 *
	 * @return array Mock response.
	 */
	public static function mock_get_response( $method, $path, $options ) {
		return [ 'lists' => [] ];
	}
}
