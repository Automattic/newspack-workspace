<?php
/**
 * Renders the frequency-based Donate block.
 *
 * @package WordPress
 */

defined( 'ABSPATH' ) || exit;

require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/donate/frontend/class-newspack-blocks-donate-renderer-base.php';

use Newspack_Blocks\Modal_Checkout\Checkout_Data;

/**
 * Renders the frequency-based Donate block.
 */
class Newspack_Blocks_Donate_Renderer_Frequency_Based extends Newspack_Blocks_Donate_Renderer_Base {
	/**
	 * Renders the frequency selection of the donation form.
	 *
	 * @param string $frequency_slug Frequency slug.
	 * @param string $frequency_name Frequency name.
	 * @param number $uid Unique ID.
	 * @param array  $configuration The donations settings.
	 *
	 * @return string
	 */
	private static function render_frequency_selection( $frequency_slug, $frequency_name, $uid, $configuration ) {
		ob_start();
		?>
		<input
			type='radio'
			value='<?php echo esc_attr( $frequency_slug ); ?>'
			id='newspack-donate-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>'
			name="<?php echo esc_attr( self::FREQUENCY_PARAM ); ?>"
			<?php
			checked(
				$configuration['defaultFrequency'],
				$frequency_slug
			);
			?>
		/>
		<label for='newspack-donate-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>'>
			<?php echo esc_html( $frequency_name ); ?>
		</label>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the frequency selection of the donation form in accessible tabs.
	 *
	 * @param string $frequency_slug Frequency slug.
	 * @param string $frequency_name Frequency name.
	 * @param number $uid Unique ID.
	 * @param array  $configuration The donations settings.
	 *
	 * @return string
	 */
	private static function render_frequency_tab( $frequency_slug, $frequency_name, $uid, $configuration ) {
		ob_start();
		?>
		<button
			role='tab'
			type='button'
			aria-controls='tab-panel-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>'
			class="wpbnbd__button freq-label<?php echo esc_attr( $frequency_slug === $configuration['defaultFrequency'] ? ' wpbnbd__button--active' : '' ); ?>"
			data-tab-id="<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>"
			id="tab-newspack-donate-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>"
			<?php echo "aria-selected='" . ( $frequency_slug === $configuration['defaultFrequency'] ? 'true' : 'false' ) . "'"; ?>
			>
				<?php echo esc_html( $frequency_name ); ?>
		</button>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the footer of the donation form.
	 *
	 * @param array $attributes The block attributes.
	 *
	 * @return string
	 */
	private static function render_footer( $attributes ) {
		$campaign          = $attributes['campaign'] ?? false;
		$button_style_attr = 'style="' . self::get_button_style( $attributes ) . '"';

		ob_start();
		?>
		<p class='wp-block-newspack-blocks-donate__thanks thanks'>
			<?php echo wp_kses_post( $attributes['thanksText'] ); ?>
		</p>

		<button type='submit' <?php echo $button_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php echo wp_kses_post( $attributes['buttonText'] ); ?>
		</button>
		<?php if ( $campaign ) : ?>
			<input type='hidden' name='campaign' value='<?php echo esc_attr( $campaign ); ?>' />
		<?php endif; ?>

		<?php
		return ob_get_clean();
	}

	/**
	 * Render the form header.
	 *
	 * @param array  $configuration The donations settings.
	 * @param string $uid ID of the block.
	 */
	private static function render_form_header( $configuration, $uid ) {
		?>
			<?php if ( ! self::renders_single_frequency( $configuration ) ) : ?>
				<div role='tablist' class='tab-container'>
					<?php foreach ( $configuration['frequencies'] as $frequency_slug => $frequency_name ) : ?>
						<?php echo self::render_frequency_tab( $frequency_slug, $frequency_name, $uid, $configuration ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php
	}

	/**
	 * Does the block render just a single frequency?
	 *
	 * @param array $configuration The donations settings.
	 */
	private static function renders_single_frequency( $configuration ) {
		return count( $configuration['frequencies'] ) === 1;
	}

	/**
	 * Render the frequency-based layout.
	 *
	 * @param array $attributes Block attributes.
	 */
	public static function render( $attributes ) {
		$uid = wp_rand( 10000, 99999 ); // Unique identifier to prevent labels colliding with other instances of Donate block.

		$configuration = self::get_configuration( $attributes );

		// Calculate the value for the "step" property of the custom amount donation value `input` element.
		$input_element_step_attribute = '1';
		$thousand_separator = get_option( 'woocommerce_price_thousand_sep', false );
		$decimals = get_option( 'woocommerce_price_num_decimals', 2 );
		if ( $thousand_separator !== '.' && $decimals > 0 ) {
			// According to the HTML spec, a float can only use a dot (https://html.spec.whatwg.org/dev/common-microsyntaxes.html#valid-floating-point-number).
			// Therefore, an `input[type="number"]` with a `,` decimal separator is invalid HTML.
			// To support separators other than a dot would mean using a standard `input[type="text"]` element,
			// with JS handling the validation and input filtering. This might be revisited in the future.
			$input_element_step_attribute = '0.' . str_repeat( '0', $decimals - 1 ) . '1';
		}

		ob_start();

		/**
		 * For AMP-compatibility, the donation forms were implemented as pure HTML forms (no JS).
		 * Each frequency and tier option is a radio input, styled to look like a button.
		 * As the radio inputs are checked/unchecked, fields are hidden/displayed using only CSS.
		 */
		if ( ! $configuration['tiered'] ) :
			?>
		<div
			class="untiered <?php echo esc_html( $configuration['container_classnames'] ); ?>"
			id="<?php echo esc_attr( $configuration['uid'] ); ?>"
		>
			<form>
				<?php echo self::render_hidden_form_inputs( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class='wp-block-newspack-blocks-donate__options'>
					<div class='wp-block-newspack-blocks-donate__frequencies frequencies'>
						<?php echo self::render_form_header( $configuration, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php foreach ( $configuration['frequencies'] as $frequency_slug => $frequency_name ) : ?>
							<?php $amount = $configuration['amounts'][ $frequency_slug ][3]; ?>
							<div
								class="wp-block-newspack-blocks-donate__frequency donation-frequency__<?php echo esc_attr( $frequency_slug ); ?> frequency"
								id='tab-panel-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>'
								role='tabpanel'
								aria-labelledby='tab-newspack-donate-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>'
								data-checkout='<?php echo esc_attr( wp_json_encode( Checkout_Data::get_donation_checkout_data( $frequency_slug, $amount ) ) ); ?>'
								<?php ( $frequency_slug === $configuration['defaultFrequency'] ? 'tabindex="0"' : '' ); ?>
							>
								<?php echo self::render_frequency_selection( $frequency_slug, $frequency_name, $uid, $configuration ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<div class='input-container'>
									<?php if ( Newspack_Blocks::can_use_name_your_price() ) : ?>
									<label
										class='donate-label'
										for='newspack-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-untiered-input'
									>
										<?php echo esc_html__( 'Donation amount', 'newspack-blocks' ); ?>
										<?php echo esc_html( trim( self::get_frequency_label( $frequency_slug, true ) ) ); ?>
									</label>
									<div class='wp-block-newspack-blocks-donate__money-input money-input'>
										<span class='currency'>
											<?php echo esc_html( $configuration['currencySymbol'] ); ?>
										</span>
										<input
											type='number'
											min='<?php echo esc_attr( $configuration['minimumDonation'] ); ?>'
											name='donation_value_<?php echo esc_attr( $frequency_slug ); ?>_untiered'
											value='<?php echo esc_attr( $amount ); ?>'
											id='newspack-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-untiered-input'
											step='<?php echo esc_attr( $input_element_step_attribute ); ?>'
										/>
									</div>
									<?php else : ?>
									<input
										type='radio'
										name='donation_value_<?php echo esc_attr( $frequency_slug ); ?>'
										value='<?php echo esc_attr( $amount ); ?>'
										id='newspack-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-untiered-input'
										checked
									/>
									<label
										class='tier-select-label tier-label'
										for='newspack-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-untiered-input'
									>
										<?php echo wp_kses_post( Newspack_Blocks::get_formatted_amount( $amount, $frequency_slug ) ); ?>
									</label>
									<?php endif; ?>
								</div>
							</div>

						<?php endforeach; ?>
					</div>
				</div>
					<?php echo self::render_footer( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</form>
		</div>

			<?php
			else :
				$suggested_amounts = $configuration['amounts'];
				?>

		<div
			class="tiered <?php echo esc_html( $configuration['container_classnames'] ); ?>"
			id="<?php echo esc_attr( $configuration['uid'] ); ?>"
		>
			<form>
				<?php echo self::render_hidden_form_inputs( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class='wp-block-newspack-blocks-donate__options'>
					<div class='wp-block-newspack-blocks-donate__frequencies frequencies'>
						<?php echo self::render_form_header( $configuration, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php foreach ( $configuration['frequencies'] as $frequency_slug => $frequency_name ) : ?>
							<div
								class='wp-block-newspack-blocks-donate__frequency frequency'
								id='tab-panel-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>'
								role='tabpanel'
								aria-labelledby='tab-newspack-donate-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>'
								<?php ( $frequency_slug === $configuration['defaultFrequency'] ? 'tabindex="0"' : '' ); ?>
								>
								<?php echo self::render_frequency_selection( $frequency_slug, $frequency_name, $uid, $configuration ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<div class='wp-block-newspack-blocks-donate__tiers tiers'>
									<?php foreach ( $suggested_amounts[ $frequency_slug ] as $index => $amount ) : ?>
										<?php
											$decimals         = intval( $amount ) == floatval( $amount ) ? 0 : 2;
											$format_options   = [ 'decimals' => $decimals ];
											$formatted_amount = function_exists( 'wc_price' ) ?
												wp_strip_all_tags( \wc_price( (float) $amount, $format_options ) ) :
												$configuration['currencySymbol'] . number_format( $amount, $decimals );
										?>
										<div
											class='wp-block-newspack-blocks-donate__tier donation-tier__<?php echo esc_attr( $frequency_slug ); ?>'
											data-checkout='<?php echo esc_attr( wp_json_encode( Checkout_Data::get_donation_checkout_data( $frequency_slug, $amount ) ) ); ?>'
										>
											<?php if ( 3 === $index ) : // The "other" tier. ?>
												<input
													type='radio'
													class='other-input'
													name='donation_value_<?php echo esc_attr( $frequency_slug ); ?>'
													value='other'
													id='newspack-tier-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-other'
												/>
												<label
													class='tier-select-label tier-label'
													for='newspack-tier-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-other'
												>
												<?php echo esc_html__( 'Other', 'newspack-blocks' ); ?>
												</label>
												<label
													class='odl'
													for='newspack-tier-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-other-input'
												>
												<?php echo esc_html__( 'Donation amount', 'newspack-blocks' ); ?>
												<?php echo esc_html( trim( self::get_frequency_label( $frequency_slug, true ) ) ); ?>
												</label>
												<div class='wp-block-newspack-blocks-donate__money-input money-input'>
													<span class='currency'>
													<?php echo esc_html( $configuration['currencySymbol'] ); ?>
													</span>
													<input
														type='number'
														min='<?php echo esc_attr( $configuration['minimumDonation'] ); ?>'
														name='donation_value_<?php echo esc_attr( $frequency_slug ); ?>_other'
														id='newspack-tier-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-other-input'
														step='<?php echo esc_attr( $input_element_step_attribute ); ?>'
													/>
												</div>
												<?php
												else :
													?>
												<input
													type='radio'
													name='donation_value_<?php echo esc_attr( $frequency_slug ); ?>'
													value='<?php echo esc_attr( $amount ); ?>'
													id='newspack-tier-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-<?php echo (int) $index; ?>'
													<?php checked( 1, $index ); ?>
												/>
												<label
													class='tier-select-label tier-label'
													for='newspack-tier-<?php echo esc_attr( $frequency_slug . '-' . $uid ); ?>-<?php echo (int) $index; ?>'
												>
													<?php echo wp_kses_post( $formatted_amount ); ?>
												</label>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
									<?php if ( self::renders_single_frequency( $configuration ) ) : ?>
										<div class='wp-block-newspack-blocks-donate__frequency-label'>
											<div>
											<?php echo esc_html( trim( self::get_frequency_label( $frequency_slug, true ) ) ); ?>

											</div>
										</div>
									<?php endif; ?>
								</div>
							</div>

						<?php endforeach; ?>
					</div>
				</div>
					<?php echo self::render_footer( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</form>
		</div>
				<?php
		endif;

			return apply_filters( 'newspack_blocks_donate_block_html', ob_get_clean(), $attributes );
	}
}
