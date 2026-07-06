<?php
/**
 * Fast Checkout Donate Selector — server-side registration.
 *
 * @package Newspack_Blocks
 */

namespace Newspack_Blocks\Fast_Checkout_Donate_Selector;

use Newspack_Blocks\Fast_Checkout;

const BLOCK_SLUG = 'fast-checkout-donate-selector';
const BLOCK_NAME = 'newspack-blocks/' . BLOCK_SLUG;

/**
 * Register the block.
 */
function register_block() {
	register_block_type_from_metadata(
		__DIR__ . '/block.json',
		[
			'render_callback' => __NAMESPACE__ . '\\render_block',
		]
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_block' );

/**
 * Enqueue the frontend view bundle when this block is present on the page.
 */
function enqueue_assets() {
	if ( is_admin() || ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! has_block( BLOCK_NAME, $post ) ) {
		return;
	}
	\Newspack_Blocks::enqueue_view_assets( BLOCK_SLUG );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );

/**
 * Get the WC Subscriptions period for a product, or empty string.
 *
 * @param int $product_id Product ID.
 * @return string `month`, `year`, etc. — or empty string for one-time.
 */
function get_subscription_period( $product_id ) {
	$period = get_post_meta( $product_id, '_subscription_period', true );
	return is_string( $period ) ? $period : '';
}

/**
 * Get the NYP min/max/suggested for a product (or empty array if not NYP).
 *
 * @param int $product_id Product ID.
 * @return array{min:string,max:string,suggested:string}|null
 */
function get_nyp_config( $product_id ) {
	if ( ! class_exists( '\WC_Name_Your_Price_Helpers' ) ) {
		return null;
	}
	if ( ! \WC_Name_Your_Price_Helpers::is_nyp( $product_id ) ) {
		return null;
	}
	return [
		'min'       => (string) \WC_Name_Your_Price_Helpers::get_minimum_price( $product_id ),
		'max'       => (string) \WC_Name_Your_Price_Helpers::get_maximum_price( $product_id ),
		'suggested' => (string) \WC_Name_Your_Price_Helpers::get_suggested_price( $product_id ),
	];
}

/**
 * Map a WC Subscriptions period code to a "/ monthly", "/ yearly" suffix.
 *
 * @param string $period The period code.
 * @return string Suffix or empty.
 */
function suffix_for_period( $period ) {
	switch ( $period ) {
		case 'day':
			return ' / ' . __( 'daily', 'newspack-blocks' );
		case 'week':
			return ' / ' . __( 'weekly', 'newspack-blocks' );
		case 'month':
			return ' / ' . __( 'monthly', 'newspack-blocks' );
		case 'year':
			return ' / ' . __( 'yearly', 'newspack-blocks' );
		default:
			return '';
	}
}

/**
 * Map a WC Subscriptions period code to a frequency label
 * ("One-time donation", "Monthly donation", etc.).
 *
 * @param string $period The period code.
 * @return string Label.
 */
function label_for_period( $period ) {
	switch ( $period ) {
		case 'day':
			return __( 'Daily donation', 'newspack-blocks' );
		case 'week':
			return __( 'Weekly donation', 'newspack-blocks' );
		case 'month':
			return __( 'Monthly donation', 'newspack-blocks' );
		case 'year':
			return __( 'Yearly donation', 'newspack-blocks' );
		default:
			return __( 'One-time donation', 'newspack-blocks' );
	}
}

/**
 * Render the donate selector SSR shell.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content Inner content (unused).
 * @param object $block   Block instance with context.
 * @return string Rendered HTML.
 */
function render_block( $attrs, $content, $block ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return '';
	}

	$product_id = (int) ( $block->context['newspack-blocks/fastCheckoutProductId'] ?? 0 );
	if ( ! $product_id ) {
		return '';
	}
	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->is_type( 'grouped' ) ) {
		return '';
	}

	$child_ids = array_map( 'intval', $product->get_children() );
	if ( empty( $child_ids ) ) {
		return '';
	}

	// Build per-child data and skip non-NYP / non-purchasable children.
	$children = [];
	foreach ( $child_ids as $child_id ) {
		$child = wc_get_product( $child_id );
		if ( ! $child || ! $child->is_purchasable() ) {
			continue;
		}
		$nyp_config = get_nyp_config( $child_id );
		if ( null === $nyp_config ) {
			// Donate selector targets NYP children. Skip non-NYP.
			continue;
		}
		$period     = get_subscription_period( $child_id );
		$children[] = [
			'id'        => $child_id,
			'name'      => label_for_period( $period ),
			'period'    => $period,
			'min'       => $nyp_config['min'],
			'max'       => $nyp_config['max'],
			'suggested' => $nyp_config['suggested'],
		];
	}

	if ( empty( $children ) ) {
		return '';
	}

	// Sort by frequency: one-time → daily → weekly → monthly → yearly.
	$period_rank = [
		''      => 0,
		'day'   => 1,
		'week'  => 2,
		'month' => 3,
		'year'  => 4,
	];
	usort(
		$children,
		function ( $a, $b ) use ( $period_rank ) {
			return ( $period_rank[ $a['period'] ] ?? 99 ) - ( $period_rank[ $b['period'] ] ?? 99 );
		}
	);

	// Resolve the currently-selected child: query param > context attribute > first.
	$current_child = (int) ( $block->context['newspack-blocks/fastCheckoutGroupedChild'] ?? 0 );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET[ Fast_Checkout::QP_GROUPED_CHILD ] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$qp_raw = wp_unslash( $_GET[ Fast_Checkout::QP_GROUPED_CHILD ] );
		$qp     = (int) filter_var( $qp_raw, FILTER_SANITIZE_NUMBER_INT );
		if ( $qp > 0 ) {
			$current_child = $qp;
		}
	}
	$valid_ids = array_column( $children, 'id' );
	if ( ! $current_child || ! in_array( $current_child, $valid_ids, true ) ) {
		$current_child = (int) $valid_ids[0];
	}

	$current = null;
	foreach ( $children as $c ) {
		if ( $c['id'] === $current_child ) {
			$current = $c;
			break;
		}
	}
	if ( null === $current ) {
		return '';
	}

	// Resolve the initial input value: fc_price > attribute > current child's suggested.
	$initial_amount = $current['suggested'];
	$attr_price     = $block->context['newspack-blocks/fastCheckoutNypPrice'] ?? '';
	if ( $attr_price && is_numeric( $attr_price ) ) {
		$initial_amount = (string) $attr_price;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET[ Fast_Checkout::QP_PRICE ] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$qp_price = sanitize_text_field( wp_unslash( $_GET[ Fast_Checkout::QP_PRICE ] ) );
		if ( $qp_price && is_numeric( $qp_price ) ) {
			$initial_amount = $qp_price;
		}
	}
	if ( $current['max'] && (float) $initial_amount > (float) $current['max'] ) {
		$initial_amount = $current['max'];
	}
	if ( $current['min'] && (float) $initial_amount < (float) $current['min'] ) {
		$initial_amount = $current['min'];
	}

	$wrapper_attributes = get_block_wrapper_attributes(
		[
			'data-product-id'    => (string) $product_id,
			'data-source-post'   => (string) get_the_ID(),
			'data-current-child' => (string) $current_child,
			'data-children'      => wp_json_encode( $children ),
		]
	);

	$input_id    = 'fc-donate-amount-' . $product_id;
	$suffix_text = suffix_for_period( $current['period'] );
	$range_label = '';
	if ( $current['min'] && $current['max'] ) {
		$range_label = sprintf(
			/* translators: 1: min price, 2: max price, 3: suggested price */
			__( '%1$s – %2$s · suggested %3$s', 'newspack-blocks' ),
			wc_price( $current['min'] ),
			wc_price( $current['max'] ),
			wc_price( $current['suggested'] )
		);
	}

	ob_start();
	?>
	<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="wp-block-newspack-blocks-fast-checkout-donate-selector__frequencies">
			<?php foreach ( $children as $c ) : ?>
				<?php $radio_id = 'fc-donate-child-' . $c['id']; ?>
				<label for="<?php echo esc_attr( $radio_id ); ?>">
					<input
						id="<?php echo esc_attr( $radio_id ); ?>"
						type="radio"
						name="fc_donate_child"
						value="<?php echo esc_attr( (string) $c['id'] ); ?>"
						data-period="<?php echo esc_attr( $c['period'] ); ?>"
						data-min="<?php echo esc_attr( $c['min'] ); ?>"
						data-max="<?php echo esc_attr( $c['max'] ); ?>"
						data-suggested="<?php echo esc_attr( $c['suggested'] ); ?>"
						<?php checked( $current_child, $c['id'] ); ?>
					/>
					<span><?php echo esc_html( $c['name'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="wp-block-newspack-blocks-fast-checkout-donate-selector__amount">
			<label for="<?php echo esc_attr( $input_id ); ?>">
				<?php esc_html_e( 'Amount', 'newspack-blocks' ); ?>
			</label>
			<div class="wp-block-newspack-blocks-fast-checkout-donate-selector__input-wrapper">
				<input
					id="<?php echo esc_attr( $input_id ); ?>"
					type="number"
					name="fc_donate_price"
					step="0.01"
					<?php if ( $current['min'] ) : ?>
						min="<?php echo esc_attr( $current['min'] ); ?>"
					<?php endif; ?>
					<?php if ( $current['max'] ) : ?>
						max="<?php echo esc_attr( $current['max'] ); ?>"
					<?php endif; ?>
					value="<?php echo esc_attr( $initial_amount ); ?>"
				/>
				<span class="wp-block-newspack-blocks-fast-checkout-donate-selector__suffix"><?php echo esc_html( $suffix_text ); ?></span>
			</div>
			<?php if ( $range_label ) : ?>
				<p class="wp-block-newspack-blocks-fast-checkout-donate-selector__hint">
					<?php echo wp_kses_post( $range_label ); ?>
				</p>
			<?php endif; ?>
			<p
				class="wp-block-newspack-blocks-fast-checkout-donate-selector__notice"
				role="status"
				aria-live="polite"
				hidden
			></p>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
