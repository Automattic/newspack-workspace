<?php
/**
 * Utility functions for Newspack Listings Importer class.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings\Importer_Utils;

/**
 * Format the data from CSV as UTF-8.
 *
 * @since 1.0
 * @param string $data Data to be formatted.
 * @param string $file_encoding File encoding of the data.
 */
function format_data( $data, $file_encoding = 'UTF-8' ) {
	if ( ! function_exists( 'mb_convert_encoding' ) || ! function_exists( 'mb_detect_encoding' ) ) {
		return $data;
	}
	return mb_convert_encoding( $data, $file_encoding, mb_detect_encoding( $data, 'auto' ) );
}

/**
 * Backdoor MapBox request to bulid and populate the Jetpack Map block programmatically.
 *
 * @param string $address Location to search for.
 * @return string|bool Block markup with attributes preset, or false.
 */
function get_map( $address = false ) {
	$api_key = get_option( 'jetpack_mapbox_api_key', '' );

	if ( ! $address || ! $api_key ) {
		return false;
	}

	$base_url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/';
	$full_url = $base_url . urlencode( $address ) . '.json?access_token=' . $api_key;

	$response = rest_request( $full_url );

	if ( $response && isset( $response->features ) && ! empty( $response->features[0] ) ) {
		$location = reset( $response->features );
		return build_map( $location );
	}

	return false;
}

/**
 * Build a jetpack/map block using location data retrieved from the MapBox API.
 *
 * @param object $location Location data from Mapbox.
 * @return string|bool Block markup with attributes preset, or false.
 */
function build_map( $location = false ) {
	// Return false if missing required data.
	if (
		! $location ||
		! isset( $location->id ) ||
		! isset( $location->place_name ) ||
		! isset( $location->geometry ) ||
		! isset( $location->geometry->coordinates ) ||
		! isset( $location->text )
	) {
		return false;
	}

	// Map marker data.
	$points = [
		[
			'placeTitle'  => $location->text,
			'title'       => $location->text,
			'caption'     => $location->place_name,
			'id'          => $location->id,
			'coordinates' => [
				'longitude' => $location->geometry->coordinates[0],
				'latitude'  => $location->geometry->coordinates[1],
			],
		],
	];

	// Map block attributes.
	$map_block_data = [
		'points'    => $points,
		'zoom'      => 13,
		'mapCenter' => [
			'lng' => $points[0]['coordinates']['longitude'],
			'lat' => $points[0]['coordinates']['latitude'],
		],
	];

	$list_items = array_map(
		function ( $point ) {
			$link = add_query_arg(
				array(
					'api'   => 1,
					'query' => $point['coordinates']['latitude'] . ',' . $point['coordinates']['longitude'],
				),
				'https://www.google.com/maps/search/'
			);
			return sprintf( '<li><a href="%s">%s</a></li>', esc_url( $link ), $point['title'] );
		},
		$points
	);

	// Build jetpack/map block with attributes.
	$map_block  = '<!-- wp:jetpack/map ' . wp_json_encode( $map_block_data ) . ' -->' . PHP_EOL;
	$map_block .= sprintf(
		'<div class="wp-block-jetpack-map" data-map-style="default" data-map-details="true" data-points="%1$s" data-zoom="%2$d" data-map-center="%3$s" data-marker-color="red" data-show-fullscreen-button="true">',
		esc_html( wp_json_encode( $map_block_data['points'] ) ),
		(int) $map_block_data['zoom'],
		esc_html( wp_json_encode( $map_block_data['mapCenter'] ) )
	);
	$map_block .= '<ul>' . implode( "\n", $list_items ) . '</ul>';
	$map_block .= '</div>' . PHP_EOL;
	$map_block .= '<!-- /wp:jetpack/map -->';

	return $map_block;
}

/**
 * Clean up a content string, stripping unwanted tags and shortcode-like strings.
 * Unlike WP core's strip_shortcodes, it will strip any string with shortcode-like syntax,
 * not just those that match registered shortcodes.
 *
 * @param string $content Content string to process.
 * @return string Filtered content string.
 */
function clean_content( $content ) {
	$allowed_elements  = wp_kses_allowed_html( 'post' );
	$unwanted_elements = [ 'div', 'section' ]; // Array of tag names we want to strip from the content.

	foreach ( $unwanted_elements as $unwanted_element ) {
		if ( isset( $allowed_elements[ $unwanted_element ] ) ) {
			unset( $allowed_elements[ $unwanted_element ] );
		}
	}

	// Strip out style attributes.
	$content = preg_replace( '/(<[^>]+) style=".*?"/i', '$1', $content );

	// Strip out shortcode-like strings.
	$content = preg_replace( '/\[[^\]]+\]/', '', $content );

	// Add missing <p> tags.
	$content = wpautop( $content );

	// Strip out unwanted tags.
	return wp_kses( $content, $allowed_elements );
}

/**
 * Get data from a remote URL via cURL.
 *
 * @param string $url The URL to request data from.
 * @return object|bool Data from the request, or false if we can't fetch.
 */
function rest_request( $url ) {
	$response = wp_remote_get( $url ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get

	// If the API request fails, print the error.
	if ( is_wp_error( $response ) ) {
		WP_CLI::error( 'Error! ' . $response->get_error_message() );
	}

	// Retrieve the data from the REST response.
	$data = wp_remote_retrieve_body( $response );

	if ( ! empty( $data ) ) {
		return json_decode( $data );
	}

	return false;
}
