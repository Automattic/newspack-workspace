<?php
/**
 * Google Site Kit Analytics Data integration class.
 *
 * @package Newspack
 */

namespace Newspack;

use Google\Site_Kit\Core\Modules\Module;
use Google\Site_Kit\Core\Authentication\Clients\Google_Site_Kit_Client;
use Google\Site_Kit_Dependencies\Google\Service\AnalyticsData as Google_Service_AnalyticsData;
use Google\Site_Kit_Dependencies\Google\Service\AnalyticsData\RunReportRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Class extending Site Kit's Module, in order to run GA4 Data API reports
 * through Site Kit's authenticated client. Load it only after
 * `GoogleSiteKit::is_active()`, since the parent class is Site Kit's.
 */
class GoogleSiteKitAnalyticsData extends Module {
	public function register() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		return true;
	}
	public function setup_info() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		return true;
	}

	/**
	 * Set up the Analytics Data service, so the module can use it.
	 *
	 * @param Google_Site_Kit_Client $client Google client instance.
	 */
	protected function setup_services( Google_Site_Kit_Client $client ) {
		return [
			'analyticsdata' => new Google_Service_AnalyticsData( $client ),
		];
	}

	/**
	 * Whether the current user holds a Site Kit token. Reads stored state only.
	 *
	 * @return bool
	 */
	public function is_authenticated() {
		return $this->authentication->is_authenticated();
	}

	/**
	 * Run one report against a GA4 property.
	 *
	 * @param string $property_id GA4 property ID, digits only.
	 * @param array  $request     Report request in the Data API's JSON shape.
	 * @return array The response in the same shape.
	 */
	public function run_report( $property_id, array $request ) {
		$response = $this->get_service( 'analyticsdata' )->properties->runReport( 'properties/' . $property_id, new RunReportRequest( $request ) );
		$decoded = json_decode( wp_json_encode( $response->toSimpleObject() ), true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
