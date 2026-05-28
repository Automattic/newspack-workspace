<?php
/**
 * Contribution Meter Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Contribution_Meter;

use Newspack\Contribution_Meter\Contribution_Meter;
use Newspack\Blocks\Contribution_Meter\Meters\Loader;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/meters/class-loader.php';

/**
 * Contribution Meter Block Class.
 */
final class Contribution_Meter_Block {

	/**
	 * The block name.
	 *
	 * @var string
	 */
	public const BLOCK_NAME = 'newspack/contribution-meter';

	/**
	 * Default block attributes.
	 */
	public const DEFAULT_ATTRIBUTES = [
		'goalAmount'       => 1000,
		'startDate'        => '',
		'endDate'          => '',
		'progressBarColor' => '',
		'thickness'        => 's',
		'showGoal'         => true,
		'showAmountRaised' => true,
		'showPercentage'   => true,
	];



	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_assets' ] );
	}

	/**
	 * Register newspack contribution meter block.
	 *
	 * @return void
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Enqueue editor assets for the block.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
		// Enqueue the editor script.
		$asset_file = include NEWSPACK_ABSPATH . '/dist/contribution-meter-block.asset.php';
		wp_enqueue_script(
			'newspack-contribution-meter-editor-script',
			\Newspack\Newspack::plugin_url() . '/dist/contribution-meter-block.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		// Calculate minimum allowed start date.
		$start_date_range = get_option( Contribution_Meter::START_DATE_RANGE_OPTION, Contribution_Meter::DEFAULT_START_DATE_RANGE );
		$min_date         = new \DateTime( $start_date_range, new \DateTimeZone( 'UTC' ) );

		// Calculate maximum allowed end date.
		$max_end_date_range = get_option( Contribution_Meter::MAX_END_DATE_RANGE_OPTION, Contribution_Meter::DEFAULT_MAX_END_DATE_RANGE );
		$max_end_date       = new \DateTime( $max_end_date_range, new \DateTimeZone( 'UTC' ) );

		// Get WooCommerce currency settings and date restrictions.
		$editor_data = [
			'currencySymbol'    => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
			'currencyPosition'  => function_exists( 'get_option' ) ? get_option( 'woocommerce_currency_pos', 'left' ) : 'left',
			'thousandSeparator' => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
			'decimalSeparator'  => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
			'decimals'          => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
			'minStartDate'      => $min_date->format( 'Y-m-d' ),
			'maxEndDate'        => $max_end_date->format( 'Y-m-d' ),
		];

		wp_localize_script(
			'newspack-contribution-meter-editor-script',
			'newspack_contribution_meter_data',
			$editor_data
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes The block attributes.
	 *
	 * @return string The block HTML.
	 */
	public static function render_block( array $attributes ) {
		// Sanitize and normalize attributes.
		$attributes = self::sanitize_attributes( wp_parse_args( $attributes, self::DEFAULT_ATTRIBUTES ) );

		// Extract meter style from the block's class name.
		$attributes['meterStyle'] = isset( $attributes['className'] ) && str_contains( $attributes['className'], 'is-style-circular' ) ? 'circular' : 'linear';

		// Get contribution data with optional end date.
		$contribution_data = Contribution_Meter::get_contribution_data( $attributes['startDate'], $attributes['endDate'] );

		if ( is_wp_error( $contribution_data ) ) {
			return sprintf(
				'<div class="wp-block-newspack-contribution-meter"><p>%s</p></div>',
				esc_html( $contribution_data->get_error_message() )
			);
		}

		$amount_raised = $contribution_data['amountRaised'];
		$goal          = $attributes['goalAmount'];

		// Calculate percentage (can exceed 100%, floored to 1 decimal).
		$percentage_raw = $goal > 0 ? ( $amount_raised / $goal ) * 100 : 0;
		$percentage     = floor( $percentage_raw * 10 ) / 10;

		// Build wrapper CSS classes.
		$classes = self::get_block_classes( $attributes );

		$wrapper_attributes = get_block_wrapper_attributes(
			[
				'class' => esc_attr( 'newspack-ui newspack-ui__font--s ' . $classes ),
			]
		);

		// Get meter class from loader.
		$meter_class = Loader::get_meter_class( $attributes['meterStyle'] );

		ob_start();
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php $meter_class::render( $amount_raised, $goal, $percentage, $attributes ); ?>
		</div>
		<?php
		return ob_get_clean();
	}


	/**
	 * Get block CSS classes.
	 *
	 * @param array $attributes Block attributes.
	 * @return string CSS classes.
	 */
	private static function get_block_classes( $attributes ) {
		$classes = [
			'contribution-meter--' . $attributes['meterStyle'],
			'contribution-meter--thickness-' . $attributes['thickness'],
		];

		return implode( ' ', $classes );
	}

	/**
	 * Sanitize and normalize attributes.
	 *
	 * @param array $attributes The block attributes.
	 * @return array Sanitized attributes.
	 */
	private static function sanitize_attributes( array $attributes ) {
		// Sanitize thickness.
		if ( ! in_array( $attributes['thickness'] ?? '', [ 'xs', 's', 'm', 'l' ], true ) ) {
			$attributes['thickness'] = 's';
		}

		// Sanitize goal amount.
		$attributes['goalAmount'] = absint( $attributes['goalAmount'] ?? 0 );

		// Validate start date.
		if ( is_wp_error( Contribution_Meter::validate_date( $attributes['startDate'] ?? '' ) ) ) {
			$attributes['startDate'] = gmdate( 'Y-m-d' ); // Default to today if invalid.
		}

		// Validate end date if provided.
		if ( ! empty( $attributes['endDate'] ) && is_wp_error( Contribution_Meter::validate_date( $attributes['endDate'] ) ) ) {
			$attributes['endDate'] = '';
		}

		// Sanitize color.
		$attributes['progressBarColor'] = ! empty( $attributes['progressBarColor'] ) ? sanitize_hex_color( $attributes['progressBarColor'] ) : '';

		// Sanitize booleans.
		$attributes['showGoal']         = (bool) ( $attributes['showGoal'] ?? false );
		$attributes['showAmountRaised'] = (bool) ( $attributes['showAmountRaised'] ?? false );
		$attributes['showPercentage']   = (bool) ( $attributes['showPercentage'] ?? false );

		return $attributes;
	}
}
