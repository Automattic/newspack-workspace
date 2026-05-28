<?php
/**
 * Linear Meter.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Contribution_Meter\Meters;

use Newspack\Contribution_Meter\Contribution_Meter;

defined( 'ABSPATH' ) || exit;

/**
 * Linear progress bar meter.
 */
class Linear_Meter implements Meter {

	/**
	 * Render the linear meter.
	 *
	 * @param float $amount_raised Amount raised.
	 * @param int   $goal Goal amount.
	 * @param float $percentage Percentage completed.
	 * @param array $attributes Block attributes.
	 */
	public static function render( $amount_raised, $goal, $percentage, $attributes ) {
		$has_any_text = $attributes['showGoal'] || $attributes['showAmountRaised'] || $attributes['showPercentage'];

		$bar_style = '';
		if ( ! empty( $attributes['progressBarColor'] ) ) {
			$bar_style = sprintf( 'color: %s;', esc_attr( $attributes['progressBarColor'] ) );
		}

		$progress_style = sprintf( 'width: %s%%;', esc_attr( $percentage > 100 ? 100 : $percentage ) ); // Cap visual progress at 100%.

		// Determine text alignment class based on what's displayed.
		$text_align_class = '';
		if (
			( $attributes['showPercentage'] && ! $attributes['showGoal'] && ! $attributes['showAmountRaised'] ) ||
			( ! $attributes['showAmountRaised'] && $attributes['showGoal'] && ! $attributes['showPercentage'] )
		) {
			$text_align_class = ' contribution-meter__text--right';
		}
		$text_style = '';
		if ( $attributes['showPercentage'] && ! $attributes['showGoal'] && ! $attributes['showAmountRaised'] ) {
			$text_style = $progress_style;
		}

		?>
		<div class="contribution-meter__linear">
			<?php if ( $has_any_text ) : ?>
				<div class="contribution-meter__text<?php echo esc_attr( $text_align_class ); ?>"<?php $text_style && printf( ' style="%s"', esc_attr( $text_style ) ); ?>>
					<?php // Percentage comes first when: Goal + Percentage (no raised). ?>
					<?php if ( $attributes['showPercentage'] && ! $attributes['showAmountRaised'] && $attributes['showGoal'] ) : ?>
						<span class="contribution-meter__percentage">
							<?php echo esc_html( $percentage ); ?>%
						</span>
					<?php endif; ?>

					<?php // Raised + Goal combined. ?>
					<?php if ( $attributes['showAmountRaised'] && $attributes['showGoal'] ) : ?>
						<span class="contribution-meter__amount-raised-goal">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: amount raised, 2: goal amount */
									__( '%1$s raised of %2$s goal', 'newspack-plugin' ),
									Contribution_Meter::format_currency( $amount_raised ),
									Contribution_Meter::format_currency( $goal )
								)
							);
							?>
						</span>
					<?php endif; ?>

					<?php // Raised only. ?>
					<?php if ( $attributes['showAmountRaised'] && ! $attributes['showGoal'] ) : ?>
						<span class="contribution-meter__amount-raised">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: amount raised */
									__( '%s raised', 'newspack-plugin' ),
									Contribution_Meter::format_currency( $amount_raised )
								)
							);
							?>
						</span>
					<?php endif; ?>

					<?php // Goal (when not combined with raised). ?>
					<?php if ( ! $attributes['showAmountRaised'] && $attributes['showGoal'] ) : ?>
						<span class="contribution-meter__goal">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: goal amount */
									__( '%s goal', 'newspack-plugin' ),
									Contribution_Meter::format_currency( $goal )
								)
							);
							?>
						</span>
					<?php endif; ?>

					<?php // Percentage comes last in all other cases. ?>
					<?php if ( $attributes['showPercentage'] && ! ( ! $attributes['showAmountRaised'] && $attributes['showGoal'] ) ) : ?>
						<span class="contribution-meter__percentage">
							<?php echo esc_html( $percentage ); ?>%
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="contribution-meter__bar-container" role="progressbar" aria-valuenow="<?php echo esc_attr( $percentage ); ?>" aria-valuemin="0" aria-valuemax="100" <?php echo $bar_style ? 'style="' . esc_attr( $bar_style ) . '"' : ''; ?>>
				<div class="contribution-meter__bar-track">
					<div class="contribution-meter__bar-progress" style="<?php echo esc_attr( $progress_style ); ?>"></div>
				</div>
			</div>
		</div>
		<?php
	}
}
