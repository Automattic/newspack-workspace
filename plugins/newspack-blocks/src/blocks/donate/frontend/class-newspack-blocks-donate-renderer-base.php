<?php
/**
 * Server-side rendering of the `newspack-blocks/donate` block.
 *
 * @package WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Donate block rendering functionality.
 */
abstract class Newspack_Blocks_Donate_Renderer_Base {
	const FREQUENCY_PARAM   = 'donation_frequency';
	const TIER_PARAM_PREFIX = 'donation_value_';

	private static $configurations_cache = []; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	/**
	 * Get translatable frequency label.
	 *
	 * @param string $frequency_slug Frequency slug.
	 * @param bool   $hide_once_label Whether to hide the "once" label.
	 *
	 * @return string
	 */
	public static function get_frequency_label( $frequency_slug, $hide_once_label = false ) {
		switch ( $frequency_slug ) {
			case 'once':
				return $hide_once_label ? '' : ' ' . __( 'once', 'newspack-blocks' );
			case 'month':
				return ' ' . __( 'per month', 'newspack-blocks' );
			case 'year':
				return ' ' . __( 'per year', 'newspack-blocks' );
		}
	}

	/**
	 * Get configuration, based on block attributes and global settings.
	 *
	 * @param array $attributes Block attributes.
	 */
	public static function get_configuration( $attributes = [] ) {
		$attributes_hash = md5( wp_json_encode( $attributes ) );
		if ( isset( self::$configurations_cache[ $attributes_hash ] ) ) {
			return self::$configurations_cache[ $attributes_hash ];
		}
		$configuration = Newspack\Donations::get_donation_settings();
		if ( is_wp_error( $configuration ) ) {
			return new \WP_Error( 'newspack_blocks_donation', __( 'Could not retrieve donation settings.', 'newspack-blocks' ) );
		}

		$configuration['defaultFrequency'] = $attributes['defaultFrequency'];

		/* If block is in "manual" mode, override certain state properties with values stored in attributes */
		if ( Newspack_Blocks::can_use_name_your_price() && ! empty( $attributes['manual'] ) ) {
			// Migrate old attributes.
			if ( empty( $attributes['amounts'] ) && isset( $attributes['suggestedAmounts'] ) ) {
				$other_amount = $configuration['amounts']['month'][3];
				if ( isset( $attributes['suggestedAmountUntiered'] ) ) {
					$other_amount = $attributes['suggestedAmountUntiered'];
				}
				$suggested_amounts     = $attributes['suggestedAmounts'];
				$multiplied_amounts    = [
					$suggested_amounts[0] * 12,
					$suggested_amounts[1] * 12,
					$suggested_amounts[2] * 12,
					$other_amount * 12,
				];
				$attributes['amounts'] = [
					'once'  => $multiplied_amounts,
					'month' => [
						$suggested_amounts[0],
						$suggested_amounts[1],
						$suggested_amounts[2],
						$other_amount,
					],
					'year'  => $multiplied_amounts,
				];
			}
			$configuration['tiered'] = $attributes['tiered'] && Newspack_Blocks::can_use_name_your_price();
			if ( isset( $attributes['amounts'] ) && ! empty( $attributes['amounts'] ) ) {
				$configuration['amounts'] = $attributes['amounts'];
			}

			if ( isset( $attributes['minimumDonation'] ) ) {
				$configuration['minimumDonation'] = $attributes['minimumDonation'];
			}

			// Override defaults with manual config.
			$configuration['defaultFrequency']    = $attributes['defaultFrequency'];
			$configuration['disabledFrequencies'] = $attributes['disabledFrequencies'];
		}

		// Ensure default frequency is valid (not disabled).
		foreach ( array_keys( $configuration['disabledFrequencies'] ) as $frequency_slug ) {
			if ( $configuration['defaultFrequency'] === $frequency_slug && $configuration['disabledFrequencies'][ $frequency_slug ] ) {
				$non_disabled_frequencies          = array_keys(
					array_filter(
						$configuration['disabledFrequencies'],
						function ( $item ) {
							return ! $item;
						}
					)
				);
				$configuration['defaultFrequency'] = $non_disabled_frequencies[0];
			}
		}

		$is_tiers_based                        = $configuration['tiered'] && 'tiers' === $attributes['layoutOption'];
		$configuration['is_tier_based_layout'] = $is_tiers_based;
		$tier_style                            = $attributes['tierStyle'];

		$frequencies = [
			'once'  => __( 'One-time', 'newspack-blocks' ),
			'month' => __( 'Monthly', 'newspack-blocks' ),
			'year'  => __( 'Annually', 'newspack-blocks' ),
		];
		foreach ( array_keys( $frequencies ) as $frequency_slug ) {
			if ( $configuration['disabledFrequencies'][ $frequency_slug ] ) {
				unset( $frequencies[ $frequency_slug ] );
			}
		}
		$configuration['frequencies'] = $frequencies;

		/* If block has additional CSS class(es)  */
		if ( isset( $attributes['className'] ) ) {
			$classname = $attributes['className'];
		} else {
			$classname = 'is-style-default';
		}

		$layout_version = ( $is_tiers_based ? 'tiers' : 'frequency' );
		$class_names    = [
			'wp-block-newspack-blocks-donate',
			'wpbnbd',
			'wpbnbd--' . $layout_version . '-based',
			'wpbnbd--platform-' . $configuration['platform'],
			$classname,
			'wpbnbd-frequencies--' . count( $configuration['frequencies'] ),
			$is_tiers_based ? 'wpbnbd--tier-style-' . $tier_style : null,
		];
		if ( ! Newspack_Blocks::can_use_name_your_price() ) {
			$class_names[] = 'wpbnbd--nyp-disabled';
		}
		$configuration['container_classnames'] = implode( ' ', $class_names );

		if ( isset( $configuration['minimumDonation'] ) ) {
			foreach ( $configuration['amounts'] as $frequency => $amounts ) {
				foreach ( $amounts as $index => $amount ) {
					$configuration['amounts'][ $frequency ][ $index ] = max( (float) $configuration['minimumDonation'], $amount );
				}
			}
		}

		$configuration['uid'] = uniqid();

		self::$configurations_cache[ $attributes_hash ] = $configuration;

		return $configuration;
	}

	/**
	 * Get style for a button.
	 *
	 * @param array $attributes Block attributes.
	 * @param array $is_reverse_style Reverse background/foreground colors.
	 */
	protected static function get_button_style( $attributes, $is_reverse_style = false ) {
		$button_color      = $attributes['buttonColor'];
		$button_text_color = Newspack_Blocks::get_color_for_contrast( $button_color );
		if ( $is_reverse_style ) {
			return 'border-color: ' . esc_attr( $button_color ) . '; color: ' . esc_attr( $button_color ) . '; background: transparent;';
		} else {
			return 'border-color: ' . esc_attr( $button_color ) . '; background-color: ' . esc_attr( $button_color ) . '; color: ' . esc_attr( $button_text_color ) . ';';
		}
	}

	/**
	 * Render hidden form inputs.
	 *
	 * @param array $attributes The block attributes.
	 */
	protected static function render_hidden_form_inputs( $attributes ) {
		ob_start();
		/**
		 * Action to add custom fields before the form fields of the donation block.
		 */
		do_action( 'newspack_blocks_donate_before_form_fields' );

		$currency = function_exists( 'get_woocommerce_currency' ) ? \get_woocommerce_currency() : 'USD';

		$donate_child_ids = [];
		if ( method_exists( 'Newspack\Donations', 'get_donation_product' ) ) {
			$donate_child_ids['year']  = Newspack\Donations::get_donation_product( 'year' );
			$donate_child_ids['month'] = Newspack\Donations::get_donation_product( 'month' );
			$donate_child_ids['once']  = Newspack\Donations::get_donation_product( 'once' );
		}
		wp_referer_field();
		?>
			<input type='hidden' name='newspack_donate' value='1' />
			<input type='hidden' name='donation_currency' value='<?php echo esc_attr( $currency ); ?>' />
			<input type='hidden' name='frequency_ids' value='<?php echo esc_attr( wp_json_encode( $donate_child_ids ) ); ?>' />
		<?php

		if ( ! \Newspack_Blocks\Modal_Checkout::has_unsupported_payment_gateway() ) {
			foreach ( [ [ 'afterSuccessBehavior', 'after_success_behavior' ], [ 'afterSuccessButtonLabel', 'after_success_button_label' ], [ 'afterSuccessURL', 'after_success_url' ] ] as $attribute ) {
				$attribute_name = $attribute[0];
				$param_name     = $attribute[1];
				$value          = isset( $attributes[ $attribute_name ] ) ? $attributes[ $attribute_name ] : '';
				?>
					<input type='hidden' name='<?php echo esc_attr( $param_name ); ?>' value='<?php echo esc_attr( $value ); ?>' />
				<?php
			}
		}
		return ob_get_clean();
	}

	/**
	 * Render the block HTML.
	 *
	 * @param array $attributes Block attributes.
	 */
	abstract public static function render( $attributes );
}
