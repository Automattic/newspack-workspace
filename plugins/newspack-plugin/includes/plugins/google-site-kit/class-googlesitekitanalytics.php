<?php
/**
 * Google Site Kit integration class.
 *
 * @package Newspack
 */

namespace Newspack;

use Google\Site_Kit\Context;
use Google\Site_Kit\Modules\Analytics_4;
use Google\Site_Kit\Modules\Analytics_4\Settings;
use Google\Site_Kit\Core\Modules\Module;
use Google\Site_Kit\Core\Authentication\Clients\Google_Site_Kit_Client;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin as Google_Service_GoogleAnalyticsAdmin;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin\GoogleAnalyticsAdminV1betaCustomDimension;

defined( 'ABSPATH' ) || exit;

/**
 * Class extending Site Kit's Module, in order to easily access GA data via
 * Site Kit's Analytics Admin service.
 */
class GoogleSiteKitAnalytics extends Module {
	public function register() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		return true;
	}
	public function setup_info() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		return true;
	}

	/**
	 * Set up the Analytics Admin service, so the module can use it.
	 *
	 * @param Google_Site_Kit_Client $client Google client instance.
	 */
	protected function setup_services( Google_Site_Kit_Client $client ) {
		return array(
			'analyticsadmin' => new Google_Service_GoogleAnalyticsAdmin( $client ),
		);
	}

	/**
	 * Return data needed to set up Site Kit's GA4 settings.
	 *
	 * @param string $account_id Account ID.
	 */
	public function get_ga4_settings( $account_id ) {
		$analyticsadmin      = $this->get_service( 'analyticsadmin' );
		$properties_response = $analyticsadmin->properties->listProperties(
			array(
				'filter' => 'parent:accounts/' . $account_id,
			)
		);
		// Only proceed if there is exactly one GA4 property - otherwise we don't
		// know which to pick.
		if ( 1 !== count( $properties_response['properties'] ) ) {
			return false;
		}
		$property             = $properties_response['properties'][0];
		$datastreams_response = $analyticsadmin
			->properties_dataStreams // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			->listPropertiesDataStreams(
				$property['name']
			);
		// Only proceed if there is one GA4 data stream - otherwise we don't
		// know which to pick.
		if ( 1 !== count( $datastreams_response['dataStreams'] ) ) {
			return false;
		}
		$datastream = $datastreams_response['dataStreams'][0];
		preg_match( '/\d+$/', $property['name'], $property_id_matches );
		$property_id = $property_id_matches[0];
		preg_match( '/\d+$/', $datastream['name'], $webstreamdata_id_matches );
		$webstreamdata_id = $webstreamdata_id_matches[0];
		if ( ! $property_id || ! $webstreamdata_id ) {
			return false;
		}
		return [
			'propertyID'      => $property_id,
			'webDataStreamID' => $webstreamdata_id,
			'measurementID'   => $datastream['webStreamData']['measurementId'],
		];
	}

	/**
	 * List custom dimensions for a GA4 property.
	 *
	 * @param string $property_id GA4 property ID.
	 * @return array List of custom dimension objects (each with parameterName, displayName, scope, name).
	 */
	public function list_custom_dimensions( $property_id ) {
		$analyticsadmin = $this->get_service( 'analyticsadmin' );
		$dimensions     = [];
		$page_token     = null;
		do {
			$params = [];
			if ( $page_token ) {
				$params['pageToken'] = $page_token;
			}
			$response = $analyticsadmin
				->properties_customDimensions // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				->listPropertiesCustomDimensions( 'properties/' . $property_id, $params );
			$items    = isset( $response['customDimensions'] ) && is_array( $response['customDimensions'] ) ? $response['customDimensions'] : [];
			foreach ( $items as $dimension ) {
				$dimensions[] = [
					'name'          => isset( $dimension['name'] ) ? $dimension['name'] : '',
					'parameterName' => isset( $dimension['parameterName'] ) ? $dimension['parameterName'] : '',
					'displayName'   => isset( $dimension['displayName'] ) ? $dimension['displayName'] : '',
					'scope'         => isset( $dimension['scope'] ) ? $dimension['scope'] : '',
				];
			}
			$page_token = isset( $response['nextPageToken'] ) ? $response['nextPageToken'] : null;
		} while ( $page_token );
		return $dimensions;
	}

	/**
	 * Create an event-scoped custom dimension on a GA4 property.
	 *
	 * @param string $property_id    GA4 property ID.
	 * @param string $parameter_name Event parameter name.
	 * @param string $display_name   Display name shown in GA4 UI.
	 */
	public function create_custom_dimension( $property_id, $parameter_name, $display_name ) {
		$analyticsadmin = $this->get_service( 'analyticsadmin' );
		$dimension      = new GoogleAnalyticsAdminV1betaCustomDimension();
		$dimension->setParameterName( $parameter_name );
		$dimension->setDisplayName( $display_name );
		$dimension->setScope( 'EVENT' );
		return $analyticsadmin
			->properties_customDimensions // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			->create( 'properties/' . $property_id, $dimension );
	}
}
