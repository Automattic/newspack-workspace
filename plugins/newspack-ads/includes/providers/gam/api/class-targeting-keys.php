<?php
/**
 * Newspack Ads GAM Default Targeting Keys
 *
 * @package Newspack
 */

namespace Newspack_Ads\Providers\GAM\Api;

use Newspack_Ads\Providers\GAM\Api;
use Newspack_Ads\Providers\GAM\Api\Api_Object;
use Google\AdsApi\AdManager\v202511\Statement;
use Google\AdsApi\AdManager\v202511\String_ValueMapEntry;
use Google\AdsApi\AdManager\v202511\TextValue;
use Google\AdsApi\AdManager\v202511\SetValue;
use Google\AdsApi\AdManager\v202511\CustomTargetingKey;
use Google\AdsApi\AdManager\v202511\CustomTargetingValue;
use Google\AdsApi\AdManager\v202511\ServiceFactory;

/**
 * Newspack Ads GAM Default Targeting Keys
 */
final class Targeting_Keys extends Api_Object {
	/**
	 * Custom targeting keys.
	 *
	 * @var string[]
	 */
	public static $default_targeting_keys = [
		'id',
		'slug',
		'category',
		'post_type',
		'template',
		'site',
		'reader_status',
	];

	/**
	 * Sanitize URL to be used as targeting value.
	 *
	 * @param string $url The URL to sanitize.
	 *
	 * @return string The sanitized URL.
	 */
	public static function sanitize_url( $url ) {
		// Remove the protocol.
		$url = str_replace( 'https://', '', $url );
		$url = str_replace( 'http://', '', $url );
		// Remove the trailing slash.
		$url = rtrim( $url, '/' );
		// Remove the www. subdomain.
		$url = str_replace( 'www.', '', $url );
		// Truncate to 40 characters.
		$length = strlen( $url );
		if ( $length > 40 ) {
			$url = substr( $url, 0, 20 ) . '...' . substr( $url, $length - 17 );
		}
		return $url;
	}

	/**
	 * Get config for generating a targeting key-val.
	 *
	 * @param string $key The key name.
	 *
	 * @return array[
	 *   'type'            => string,
	 *   'reportable_type' => string|null,
	 *   'values'          => string[]
	 * ]
	 */
	private static function get_targeting_key_config( $key ) {
		$config = [
			'type'            => 'FREEFORM',
			'reportable_type' => null,
			'values'          => [],
		];
		switch ( $key ) {
			case 'site':
				$config['type']            = 'PREDEFINED';
				$config['reportable_type'] = 'CUSTOM_DIMENSION';
				$config['values']          = [ self::sanitize_url( \get_bloginfo( 'url' ) ) ];
				break;
		}
		return $config;
	}

	/**
	 * Create a custom targeting key-val segmentation with optional values.
	 *
	 * @param string   $name            The name of the key.
	 * @param string[] $values          Optional values.
	 * @param string   $type            The type of the key. Defaults to 'FREEFORM'.
	 * @param string   $reportable_type The reportable type of the key. Defaults to null.
	 *
	 * @return array[
	 *  'targeting_key'  => CustomTargetingKey,
	 *  'found_values'   => CustomTargetingValue[],
	 *  'created_values' => CustomTargetingValue[]
	 * ]
	 *
	 * @throws \Exception If there is an error while communicating with the API.
	 */
	public function create_targeting_key( $name, $values = [], $type = 'FREEFORM', $reportable_type = null ) {
		$service = ( new ServiceFactory() )->createCustomTargetingService( $this->session );

		$statement = new Statement(
			"WHERE name = :name AND status = 'ACTIVE'",
			[
				new String_ValueMapEntry(
					'name',
					new SetValue(
						[
							new TextValue( $name ),
						]
					)
				),
			]
		);

		$targeting_key = null;
		$found_keys    = $service->getCustomTargetingKeysByStatement( $statement )->getResults();
		$created       = false;
		if ( empty( $found_keys ) ) {
			$targeting_key = $service->createCustomTargetingKeys(
				[
					( new CustomTargetingKey() )
						->setName( $name )
						->setType( $type )
						->setReportableType( $reportable_type )
						->setStatus( 'ACTIVE' ),
				]
			)[0];
			$created       = true;
		} else {
			$targeting_key = $found_keys[0];
		}

		$found_values   = [];
		$created_values = [];
		if ( $targeting_key && ! empty( $values ) ) {
			$upsert_values_result = $this->upsert_targeting_key_values( $targeting_key, $values );
			$found_values         = $upsert_values_result['found_values'];
			$created_values       = $upsert_values_result['created_values'];
		}
		return [
			'created'        => $created,
			'targeting_key'  => $targeting_key,
			'found_values'   => $found_values,
			'created_values' => $created_values,
		];
	}

	/**
	 * Upsert values for a custom targeting key.
	 *
	 * @param CustomTargetingKey $targeting_key The targeting key.
	 * @param string[]           $values        Values to upsert.
	 */
	public function upsert_targeting_key_values( $targeting_key, $values ) {
		$service          = ( new ServiceFactory() )->createCustomTargetingService( $this->session );
		$key_id           = $targeting_key->getId();

		// Discard empty values.
		$values = array_values( array_filter( $values ) );

		$values_statement = new Statement(
			"WHERE customTargetingKeyId = :key_id AND name = :name AND status = 'ACTIVE'",
			[
				new String_ValueMapEntry(
					'key_id',
					new SetValue(
						[
							new TextValue( $key_id ),
						]
					)
				),
				new String_ValueMapEntry(
					'name',
					new SetValue(
						array_map(
							function ( $key ) {
								return new TextValue( $key );
							},
							$values
						)
					)
				),
			]
		);
		$found_values     = (array) $service->getCustomTargetingValuesByStatement( $values_statement )->getResults();
		$values_to_create = array_values(
			array_diff(
				$values,
				array_map(
					function ( $value ) {
						return $value->getName();
					},
					$found_values
				)
			)
		);
		$created_values   = $service->createCustomTargetingValues(
			array_map(
				function ( $value ) use ( $key_id ) {
					return ( new CustomTargetingValue() )->setCustomTargetingKeyId( $key_id )->setName( $value );
				},
				$values_to_create
			)
		);
		return [
			'found_values'   => is_array( $found_values ) && ! empty( $found_values ) ? $found_values : [],
			'created_values' => is_array( $created_values ) && ! empty( $created_values ) ? $created_values : [],
		];
	}

	/**
	 * Update custom targeting keys with predefined values if necessary.
	 *
	 * @return string[] Created custom targeting keys names or empty array if none was created.
	 *
	 * @throws \Exception If there is an error while communicating with the API.
	 */
	public function update_default_targeting_keys() {
		$keys         = self::$default_targeting_keys;
		$created_keys = [];
		foreach ( $keys as $key ) {
			$targeting_key_config = self::get_targeting_key_config( $key );
			$targeting_key        = $this->create_targeting_key(
				$key,
				$targeting_key_config['values'],
				$targeting_key_config['type'],
				$targeting_key_config['reportable_type']
			);
			if ( $targeting_key['targeting_key'] && $targeting_key['created'] ) {
				$created_keys[] = $key;
			}
		}
		return $created_keys;
	}
}
