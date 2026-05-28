<?php
/**
 * Meter Interface.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Contribution_Meter\Meters;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for contribution meter types.
 */
interface Meter {

	/**
	 * Render the meter.
	 *
	 * @param float $amount_raised Amount raised.
	 * @param int   $goal Goal amount.
	 * @param float $percentage Percentage completed.
	 * @param array $attributes Block attributes.
	 */
	public static function render( $amount_raised, $goal, $percentage, $attributes );
}
