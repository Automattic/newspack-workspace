<?php
/**
 * Renders the tiers-based Donate block.
 *
 * @package WordPress
 */

defined( 'ABSPATH' ) || exit;

require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/donate/frontend/class-newspack-blocks-donate-renderer-base.php';

use Newspack_Blocks\Modal_Checkout\Checkout_Data;

/**
 * Renders the tiers-based Donate block.
 */
class Newspack_Blocks_Donate_Renderer_Tiers_Based extends Newspack_Blocks_Donate_Renderer_Base {
	/**
	 * Get displayed amounts.
	 *
	 * @param array $amounts Amounts.
	 */
	private static function get_displayed_amounts( $amounts ) {
		$disabled_in_tiers_based_layout_tier_index = 3;
		return array_slice( $amounts, 0, $disabled_in_tiers_based_layout_tier_index );
	}

	/**
	 * Render the single option.
	 *
	 * @param array  $attributes Block attributes.
	 * @param int    $index Option index.
	 * @param int    $amount Option amount.
	 * @param string $selected_frequency Selected frequency.
	 * @param bool   $is_any_recommended Is any option recommended.
	 */
	private static function render_single_tier( $attributes, $index, $amount, $selected_frequency, $is_any_recommended ) {
		$configuration         = self::get_configuration( $attributes );
		$has_recommend_label   = isset( $attributes['tiersBasedOptions'][ $index ]['recommendLabel'] ) && ! empty( $attributes['tiersBasedOptions'][ $index ]['recommendLabel'] );
		$has_description       = isset( $attributes['tiersBasedOptions'][ $index ]['description'] ) && ! empty( $attributes['tiersBasedOptions'][ $index ]['description'] );
		$is_reverse_style      = ! $has_recommend_label && $is_any_recommended;
		$button_style_attr     = 'style="' . self::get_button_style( $attributes, $is_reverse_style ) . '"';

		ob_start();
		?>
		<div class="wpbnbd__tiers__tier <?php echo $has_recommend_label ? 'wpbnbd__tiers__tier--recommended' : ''; ?>">
			<div class="wpbnbd__tiers__top">
				<h2 class="wpbnbd__tiers__heading">
					<?php echo esc_html( $attributes['tiersBasedOptions'][ $index ]['heading'] ); ?>
				</h2>
				<?php if ( $has_recommend_label ) : ?>
				<h3 class="wpbnbd__tiers__recommend-label">
					<?php echo esc_html( wp_strip_all_tags( $attributes['tiersBasedOptions'][ $index ]['recommendLabel'] ) ); ?>
				</h3>
				<?php endif; ?>
			</div>
			<div class="wpbnbd__tiers__amount">
				<span>
					<?php foreach ( $configuration['frequencies'] as $frequency_slug => $frequency_name ) : ?>
						<?php $amount = $configuration['amounts'][ $frequency_slug ][ $index ]; ?>
						<span
							style="<?php echo $frequency_slug === $selected_frequency ? '' : 'display:none;'; ?>"
							class="donation-tier__<?php echo esc_attr( $frequency_slug ); ?>"
							data-frequency-slug="<?php echo esc_attr( $frequency_slug ); ?>"
							data-amount="<?php echo esc_attr( $amount ); ?>"
							data-tier-index="<?php echo esc_attr( $index ); ?>"
							data-checkout="<?php echo esc_attr( wp_json_encode( Checkout_Data::get_donation_checkout_data( $frequency_slug, $amount ) ) ); ?>"
						>
							<?php echo wp_kses_post( Newspack_Blocks::get_formatted_amount( $amount, $frequency_slug ) ); ?>
						</span>
					<?php endforeach; ?>
				</span>
			</div>
			<button
				type='submit'
				name="<?php echo esc_attr( self::TIER_PARAM_PREFIX ); ?><?php echo esc_attr( $selected_frequency ); ?>"
				value="<?php echo esc_attr( $configuration['amounts'][ $selected_frequency ][ $index ] ); ?>"
				data-tier-index="<?php echo esc_attr( $index ); ?>"
				<?php echo $button_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			>
				<?php echo esc_html( $attributes['tiersBasedOptions'][ $index ]['buttonText'] ); ?>
			</button>
			<?php if ( $has_description ) : ?>
			<div class="wpbnbd__tiers__description">
				<?php echo wp_kses_post( $attributes['tiersBasedOptions'][ $index ]['description'] ); ?>
			</div>
			<?php endif; ?>
		</div>

		<?php
		return ob_get_clean();
	}

	/**
	 * Render the tiers-based layout.
	 *
	 * @param array $attributes Block attributes.
	 */
	public static function render( $attributes ) {
		$configuration             = self::get_configuration( $attributes );
		$displayed_frequencies     = array_keys( $configuration['frequencies'] );
		if ( isset( $attributes['defaultFrequency'] ) && in_array( $attributes['defaultFrequency'], $displayed_frequencies, true ) ) {
			$intial_selected_frequency = $attributes['defaultFrequency'];
		} else {
			$intial_selected_frequency = $displayed_frequencies[0];
		}

		$displayed_amounts          = self::get_displayed_amounts( $configuration['amounts'][ $intial_selected_frequency ] );
		$config_related_attributes  = array_map(
			function ( $option ) {
				return [ 'heading' => $option['heading'] ];
			},
			$attributes['tiersBasedOptions']
		);

		$configuration_for_tiers_based = [
			self::FREQUENCY_PARAM,
			self::TIER_PARAM_PREFIX,
			$intial_selected_frequency,
			$config_related_attributes,
			$configuration['amounts'],
			( new DateTime() )->modify( '+1 month' )->format( 'F d, Y' ),
			( new DateTime() )->modify( '+1 year' )->format( 'F d, Y' ),
			$attributes['buttonColor'],
		];

		$is_any_recommended = array_reduce(
			$attributes['tiersBasedOptions'],
			function ( $carry, $item ) {
				return ( $carry || ( isset( $item['recommendLabel'] ) && ! empty( $item['recommendLabel'] ) ) );
			},
			false
		);

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( $configuration['container_classnames'] ); ?>"
			id="<?php echo esc_attr( $configuration['uid'] ); ?>"
			data-tiers-based-config="<?php echo esc_attr( htmlspecialchars( wp_json_encode( $configuration_for_tiers_based ), ENT_QUOTES, 'UTF-8' ) ); ?>"
		>
			<form data-is-init-form>
				<div class="wpbnbd__tiers__view">
					<?php echo self::render_hidden_form_inputs( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="<?php echo esc_attr( self::FREQUENCY_PARAM ); ?>" value="<?php echo esc_attr( $intial_selected_frequency ); ?>">
					<div class="wpbnbd__tiers">
						<?php if ( count( $configuration['frequencies'] ) > 1 ) : ?>
							<div class="wpbnbd__tiers__selection">
								<?php foreach ( $configuration['frequencies'] as $frequency_slug => $frequency_name ) : ?>
									<button
										type="button"
										data-frequency-slug="<?php echo esc_attr( $frequency_slug ); ?>"
										data-frequency-label="<?php echo esc_attr( self::get_frequency_label( $frequency_slug ) ); ?>"
										class="wpbnbd__button <?php echo $intial_selected_frequency === $frequency_slug ? 'wpbnbd__button--active' : ''; ?>"
									><?php echo esc_html( $frequency_name ); ?></button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<?php
							$keys = array_keys( $configuration['frequencies'] );
							$frequency_slug = reset( $keys );
						?>
						<div class="wpbnbd__tiers__options">
							<?php foreach ( $displayed_amounts as $index => $amount ) : ?>
								<?php
									echo self::render_single_tier( $attributes, $index, $configuration['amounts'][ $frequency_slug ][ $index ], $intial_selected_frequency, $is_any_recommended ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							<?php endforeach; ?>
						</div>
						<ul class="wpbnbd__tiers__options__dots">
							<?php foreach ( $displayed_amounts as $index => $amount ) : ?>
								<li></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>

			</form>
		</div>

		<?php
		return apply_filters( 'newspack_blocks_donate_block_html', ob_get_clean(), $attributes );
	}
}
