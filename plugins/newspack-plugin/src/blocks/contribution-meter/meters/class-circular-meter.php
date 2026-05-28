<?php
/**
 * Circular Meter.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Contribution_Meter\Meters;

use Newspack\Contribution_Meter\Contribution_Meter;

defined( 'ABSPATH' ) || exit;

/**
 * Circular progress indicator meter.
 */
class Circular_Meter implements Meter {

	/**
	 * Thickness values in pixels.
	 */
	public const THICKNESS = [
		'xs' => 4,
		's'  => 8,
		'm'  => 12,
		'l'  => 16,
	];

	/**
	 * Render the circular meter.
	 *
	 * @param float $amount_raised Amount raised.
	 * @param int   $goal Goal amount.
	 * @param float $percentage Percentage completed.
	 * @param array $attributes Block attributes.
	 */
	public static function render( $amount_raised, $goal, $percentage, $attributes ) {
		$viewbox_size      = 72;
		$stroke_width      = isset( self::THICKNESS[ $attributes['thickness'] ] ) ? self::THICKNESS[ $attributes['thickness'] ] : self::THICKNESS['s'];
		$visual_percentage = $percentage > 100 ? 100 : $percentage; // Cap visual progress at 100%.

		$radius        = ( $viewbox_size / 2 ) - ( $stroke_width / 2 );
		$circumference = 2 * M_PI * $radius;
		$offset        = $circumference - ( ( $visual_percentage / 100 ) * $circumference );

		$center_x = $viewbox_size / 2;
		$center_y = $viewbox_size / 2;

		$svg_style = '';
		if ( ! empty( $attributes['progressBarColor'] ) ) {
			$svg_style = sprintf( 'color: %s;', esc_attr( $attributes['progressBarColor'] ) );
		}
		?>
		<div class="contribution-meter__circular">
			<div class="contribution-meter__circle-container">
				<svg class="contribution-meter__circle" viewBox="0 0 <?php echo esc_attr( $viewbox_size ); ?> <?php echo esc_attr( $viewbox_size ); ?>" role="img" aria-label="<?php esc_attr_e( 'Contribution progress indicator', 'newspack-plugin' ); ?>" <?php echo $svg_style ? 'style="' . esc_attr( $svg_style ) . '"' : ''; ?>>
					<title><?php esc_html_e( 'Contribution Meter', 'newspack-plugin' ); ?></title>
					<desc>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: percentage, 2: formatted goal amount */
								__( '%1$s%% progress toward %2$s goal', 'newspack-plugin' ),
								$percentage,
								Contribution_Meter::format_currency( $goal )
							)
						);
						?>
					</desc>

					<!-- Background track -->
					<circle
						class="contribution-meter__circle-track"
						cx="<?php echo esc_attr( $center_x ); ?>"
						cy="<?php echo esc_attr( $center_y ); ?>"
						r="<?php echo esc_attr( $radius ); ?>"
						fill="none"
						stroke-width="<?php echo esc_attr( $stroke_width ); ?>"
					/>

					<!-- Progress circle -->
					<circle
						class="contribution-meter__circle-progress"
						cx="<?php echo esc_attr( $center_x ); ?>"
						cy="<?php echo esc_attr( $center_y ); ?>"
						r="<?php echo esc_attr( $radius ); ?>"
						fill="none"
						stroke-width="<?php echo esc_attr( $stroke_width ); ?>"
						stroke-dasharray="<?php echo esc_attr( $circumference ); ?>"
						stroke-dashoffset="<?php echo esc_attr( $offset ); ?>"
						stroke-linecap="butt"
						transform="rotate(-90 <?php echo esc_attr( $center_x ); ?> <?php echo esc_attr( $center_y ); ?>)"
					/>
				</svg>

				<?php if ( $attributes['showPercentage'] ) : ?>
					<div class="contribution-meter__circle-percentage newspack-ui__font--2xs">
						<?php echo esc_html( $percentage ); ?>%
					</div>
				<?php endif; ?>
			</div>

			<div class="contribution-meter__data">
				<?php if ( $attributes['showAmountRaised'] ) : ?>
					<span class="contribution-meter__amount-raised newspack-ui__font--m">
						<?php echo esc_html( Contribution_Meter::format_currency( $amount_raised ) ); ?> <?php esc_html_e( 'raised', 'newspack-plugin' ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $attributes['showGoal'] ) : ?>
					<span class="contribution-meter__goal<?php echo ! $attributes['showAmountRaised'] ? ' contribution-meter__goal--primary newspack-ui__font--m' : ''; ?>">
						<?php echo esc_html( Contribution_Meter::format_currency( $goal ) ); ?> <?php esc_html_e( 'goal', 'newspack-plugin' ); ?>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
