<?php
/**
 * Meter Loader.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Contribution_Meter\Meters;

defined( 'ABSPATH' ) || exit;

// Load meter interface and implementations.
require_once __DIR__ . '/interface-meter.php';
require_once __DIR__ . '/class-linear-meter.php';
require_once __DIR__ . '/class-circular-meter.php';

/**
 * Loader for meter types.
 */
class Loader {

	/**
	 * Meter type mapping.
	 */
	public const TYPES = [
		'linear'   => Linear_Meter::class,
		'circular' => Circular_Meter::class,
	];

	/**
	 * Get meter class for a given style.
	 *
	 * @param string $style Meter style ('linear' or 'circular').
	 * @return string Meter class name.
	 */
	public static function get_meter_class( $style ) {
		return self::TYPES[ $style ] ?? self::TYPES['linear'];
	}
}
