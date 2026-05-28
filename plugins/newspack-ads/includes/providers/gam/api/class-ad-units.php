<?php
/**
 * Newspack Ads GAM Ad Units
 *
 * @package Newspack
 */

namespace Newspack_Ads\Providers\GAM\Api;

use Newspack_Ads\Providers\GAM\Api\Api_Object;
use Google\AdsApi\AdManager\Util\v202511\StatementBuilder;
use Google\AdsApi\AdManager\v202511\ServiceFactory;
use Google\AdsApi\AdManager\v202511\InventoryService;
use Google\AdsApi\AdManager\v202511\Size;
use Google\AdsApi\AdManager\v202511\EnvironmentType;
use Google\AdsApi\AdManager\v202511\AdUnit;
use Google\AdsApi\AdManager\v202511\AdUnitParent;
use Google\AdsApi\AdManager\v202511\AdUnitSize;
use Google\AdsApi\AdManager\v202511\AdUnitTargetWindow;
use Google\AdsApi\AdManager\v202511\ArchiveAdUnits as ArchiveAdUnitsAction;
use Google\AdsApi\AdManager\v202511\ActivateAdUnits as ActivateAdUnitsAction;
use Google\AdsApi\AdManager\v202511\DeactivateAdUnits as DeactivateAdUnitsAction;
use Google\AdsApi\AdManager\v202511\ApiException;

/**
 * Newspack Ads GAM Ad Units
 */
final class Ad_Units extends Api_Object {
	/**
	 * Create inventory service.
	 *
	 * @return InventoryService Inventory service.
	 */
	private function get_inventory_service() {
		$service_factory = new ServiceFactory();
		return $service_factory->createInventoryService( $this->session );
	}

	/**
	 * Create a statement builder for ad unit retrieval.
	 *
	 * @param int     $parent_id        Optional parent ad unit id.
	 * @param int[]   $ids              Optional array of ad unit ids.
	 * @param boolean $include_archived Whether to include archived ad units.
	 *
	 * @return StatementBuilder Statement builder.
	 */
	private static function get_statement_builder( $parent_id = null, $ids = [], $include_archived = false ) {
		// Get all non-archived ad units, unless ids are specified.
		$statement_builder = new StatementBuilder();
		if ( ! empty( $ids ) ) {
			$statement_builder->where( 'ID IN(' . implode( ', ', $ids ) . ')' );
		} elseif ( ! $include_archived ) {
			if ( $parent_id ) {
				$statement_builder->where( 'parentId = ' . $parent_id . " AND Status IN('ACTIVE')" );
			} else {
				$statement_builder->where( "Status IN('ACTIVE')" );
			}
		}
		$statement_builder->orderBy( 'name ASC' )->limit( StatementBuilder::SUGGESTED_PAGE_LIMIT );
		return $statement_builder;
	}

	/**
	 * Get parent ad units with children.
	 *
	 * @return array Array of serialzied AdUnits.
	 */
	public function get_parent_ad_units() {
		$statement_builder = self::get_statement_builder();
		$statement_builder->where( "hasChildren = TRUE AND Status IN('ACTIVE')" );
		$inventory_service = $this->get_inventory_service();
		$page = $inventory_service->getAdUnitsByStatement(
			$statement_builder->toStatement()
		);

		$ad_units = [];
		// Retrieve a small amount of items at a time, paging through until all items have been retrieved.
		$total_result_set_size = 0;
		do {
			$page = $inventory_service->getAdUnitsByStatement(
				$statement_builder->toStatement()
			);

			if ( $page->getResults() !== null ) {
				$total_result_set_size = $page->getTotalResultSetSize();
				foreach ( $page->getResults() as $item ) {
					$ad_unit_name = $item->getName();
					if ( 0 === strpos( $ad_unit_name, 'ca-pub-' ) ) {
						// There are these phantom ad units with 'ca-pub-<int>' names.
						continue;
					}
					$ad_units[] = $item;
				}
			}
			$statement_builder->increaseOffsetBy( StatementBuilder::SUGGESTED_PAGE_LIMIT );
		} while ( $statement_builder->getOffset() < $total_result_set_size );

		return array_map( [ __CLASS__, 'get_serialized_ad_unit' ], $ad_units );
	}

	/**
	 * Get all GAM Ad Units in the user's network.
	 * If $ids parameter is not specified, will return all ad units found.
	 *
	 * @param int     $parent_id        Optional parent ad unit id.
	 * @param int[]   $ids              Optional array of ad unit ids.
	 * @param boolean $include_archived Whether to include archived ad units.
	 *
	 * @return AdUnit[] Array of AdUnits.
	 */
	private function get_ad_units( $parent_id = null, $ids = [], $include_archived = false ) {
		$gam_ad_units      = [];
		$statement_builder = self::get_statement_builder( $parent_id, $ids, $include_archived );
		$inventory_service = $this->get_inventory_service();

		// Retrieve a small amount of items at a time, paging through until all items have been retrieved.
		$total_result_set_size = 0;
		do {
			$page = $inventory_service->getAdUnitsByStatement(
				$statement_builder->toStatement()
			);

			if ( $page->getResults() !== null ) {
				$total_result_set_size = $page->getTotalResultSetSize();
				foreach ( $page->getResults() as $item ) {
					$ad_unit_name = $item->getName();
					if ( 0 === strpos( $ad_unit_name, 'ca-pub-' ) ) {
						// There are these phantom ad units with 'ca-pub-<int>' names.
						continue;
					}
					$gam_ad_units[] = $item;
				}
			}
			$statement_builder->increaseOffsetBy( StatementBuilder::SUGGESTED_PAGE_LIMIT );
		} while ( $statement_builder->getOffset() < $total_result_set_size );

		return $gam_ad_units;
	}

	/**
	 * Get all GAM Ad Units in the user's network, serialized.
	 *
	 * @param int     $parent_id        Optional parent ad unit id.
	 * @param int[]   $ids              Optional array of ad unit ids.
	 * @param boolean $include_archived Whether to include archived ad units.
	 *
	 * @return array[]|\WP_Error Array of serialized ad units or error.
	 */
	public function get_serialized_ad_units( $parent_id = null, $ids = [], $include_archived = false ) {
		try {
			$ad_units            = $this->get_ad_units( $parent_id, $ids, $include_archived );
			$ad_units_serialised = [];
			foreach ( $ad_units as $ad_unit ) {
				$ad_units_serialised[] = $this->get_serialized_ad_unit( $ad_unit );
			}
			return $ad_units_serialised;
		} catch ( ApiException $e ) {
			return $this->api->get_error( $e, __( 'Unable to fetch ad units.', 'newspack-ads' ) );
		}
	}

	/**
	 * Get serialized ad unit parent.
	 *
	 * @param AdUnitParent $parent An ad unit parent.
	 *
	 * @return array
	 */
	public static function get_serialized_parent( $parent ) {
		return [
			'id'   => $parent->getId(),
			'name' => $parent->getName(),
			'code' => $parent->getAdUnitCode(),
		];
	}

	/**
	 * Serialize Ad Unit.
	 *
	 * @param AdUnit $gam_ad_unit An AdUnit.
	 *
	 * @return array Ad Unit configuration.
	 */
	private function get_serialized_ad_unit( $gam_ad_unit ) {
		$parent_path = $gam_ad_unit->getParentPath();
		if ( $parent_path ) {
			$path = array_map( [ __CLASS__, 'get_serialized_parent' ], $parent_path );
		} else {
			$path = [];
		}

		// Remove path that matches `ca-pub-<int>` pattern.
		$path = array_values(
			array_filter(
				$path,
				function( $parent ) {
					return ! preg_match( '/^ca-pub-\d+$/', $parent['code'] );
				}
			)
		);

		$ad_unit = [
			'id'           => $gam_ad_unit->getId(),
			'path'         => $path,
			'code'         => $gam_ad_unit->getAdUnitCode(),
			'status'       => $gam_ad_unit->getStatus(),
			'name'         => $gam_ad_unit->getName(),
			'fluid'        => $gam_ad_unit->getIsFluid(),
			'has_children' => $gam_ad_unit->getHasChildren(),
			'sizes'        => [],
		];
		$sizes   = $gam_ad_unit->getAdUnitSizes();
		if ( $sizes ) {
			foreach ( $sizes as $size ) {
				$size               = $size->getSize();
				$ad_unit['sizes'][] = [ $size->getWidth(), $size->getHeight() ];
			}
		}
		return $ad_unit;
	}

	/**
	 * Change status of a single GAM Ad Unit.
	 *
	 * @param int    $id Id of the ad unit to archive.
	 * @param string $status Desired status of the ad unit.
	 */
	public function update_ad_unit_status( $id, $status ) {
		try {
			switch ( $status ) {
				case 'ACTIVE':
					$action = new ActivateAdUnitsAction();
					break;
				case 'INACTIVE':
					$action = new DeactivateAdUnitsAction();
					break;
				case 'ARCHIVE':
					$action = new ArchiveAdUnitsAction();
					break;
				default:
					return false;
			}
			$inventory_service = $this->get_inventory_service();

			$statement_builder = self::get_statement_builder( null, [ $id ] );
			$result            = $inventory_service->performAdUnitAction(
				$action,
				$statement_builder->toStatement()
			);
			if ( null !== $result && $result->getNumChanges() > 0 ) {
				return true;
			} else {
				return false;
			}
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Modify a GAM Ad Unit.
	 *
	 * Given a configuration object and an AdUnit instance, return modified AdUnit.
	 * If the AdUnit is not provided, create a new one.
	 *
	 * @param array  $config  Configuration for the Ad Unit.
	 * @param AdUnit $ad_unit Ad Unit.
	 *
	 * @return AdUnit Ad Unit.
	 */
	private function modify_ad_unit( $config, $ad_unit = null ) {
		$name     = $config['name'];
		$sizes    = $config['sizes'];
		$is_fluid = isset( $config['fluid'] ) && $config['fluid'];
		$slug     = substr( sanitize_title( $name ), 0, 80 ); // Ad unit code can have 100 characters at most.

		if ( null === $ad_unit ) {
			$ad_unit = new AdUnit();
			$ad_unit->setAdUnitCode( uniqid( $slug . '-' ) );
			$network = $this->api->get_network();
			if ( ! empty( $config['parent_id'] ) ) {
				$ad_unit->setParentId( $config['parent_id'] );
			} else {
				$ad_unit->setParentId( $network->getEffectiveRootAdUnitId() );
			}
			$ad_unit->setTargetWindow( AdUnitTargetWindow::BLANK );
		}

		$ad_unit->setName( $name );
		$ad_unit->setIsFluid( $is_fluid );

		$ad_unit_sizes = [];
		foreach ( $sizes as $size_spec ) {
			$size = new Size();
			$size->setWidth( $size_spec[0] );
			$size->setHeight( $size_spec[1] );
			$size->setIsAspectRatio( false );
			$ad_unit_size = new AdUnitSize();
			$ad_unit_size->setSize( $size );
			$ad_unit_size->setEnvironmentType( EnvironmentType::BROWSER );
			$ad_unit_sizes[] = $ad_unit_size;
		}
		$ad_unit->setAdUnitSizes( $ad_unit_sizes );

		if ( isset( $config['status'] ) ) {
			$status          = $config['status'];
			$existing_status = $ad_unit->getStatus();
			if ( $existing_status !== $status ) {
				$this->update_ad_unit_status( $ad_unit->getId(), $status );
			}
		}

		return $ad_unit;
	}

	/**
	 * Update Ad Unit.
	 *
	 * @param array $config Ad Unit configuration.
	 *
	 * @return AdUnit|\WP_Error Updated AdUnit or error.
	 */
	public function update_ad_unit( $config ) {
		try {
			$inventory_service = $this->get_inventory_service();
			$found_ad_units    = $this->get_ad_units( null, [ $config['id'] ] );
			if ( empty( $found_ad_units ) ) {
				return $this->api->get_error( null, __( 'Ad Unit was not found.', 'newspack-ads' ) );
			}
			$result = $inventory_service->updateAdUnits(
				[ $this->modify_ad_unit( $config, $found_ad_units[0] ) ]
			);
			if ( empty( $result ) ) {
				return $this->api->get_error( null, __( 'Ad Unit was not updated.', 'newspack-ads' ) );
			}
			return $result[0];
		} catch ( ApiException $e ) {
			return $this->api->get_error( $e, __( 'Ad Unit was not updated.', 'newspack-ads' ) );
		}
	}

	/**
	 * Create a GAM Ad Unit.
	 *
	 * @param array $config Configuration of the ad unit.
	 *
	 * @return array|\WP_Error Created ad unit or error.
	 */
	public function create_ad_unit( $config ) {
		try {
			$inventory_service = $this->get_inventory_service();
			$ad_unit           = $this->modify_ad_unit( $config );
			$created_ad_units  = $inventory_service->createAdUnits( [ $ad_unit ] );
			if ( empty( $created_ad_units ) ) {
				return $this->api->get_error( null, __( 'Ad Unit was not created.', 'newspack-ads' ) );
			}
			return $this->get_serialized_ad_unit( $created_ad_units[0] );
		} catch ( ApiException $e ) {
			return $this->api->get_error( $e, __( 'Ad Unit was not created.', 'newspack-ads' ) );
		}
	}
}
