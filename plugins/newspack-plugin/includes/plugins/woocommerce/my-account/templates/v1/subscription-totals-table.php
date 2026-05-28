<?php
/**
 * Custom subscription totals table template.
 * Appears before the subscription details table instead of after it.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

$items = $subscription->get_items();
?>
<table class="shop_table order_details">
	<thead>
		<tr>
			<?php if ( $allow_item_removal ) : ?>
			<th class="product-remove" style="width: 3em;">&nbsp;</th>
			<?php endif; ?>
			<th class="product-name"><?php echo esc_html_x( 'Amount', 'table headings in notification email', 'newspack-plugin' ); ?></th>
			<th class="product-total">
				<?php
				if ( 2 >= count( $totals ) && isset( $totals['order_total']['value'] ) ) {
					echo wp_kses_post( $totals['order_total']['value'] );
				}
				?>
			</th>
		</tr>
	</thead>
	<?php if ( 2 < count( $totals ) ) : ?>
	<tfoot>
		<?php
		foreach ( $totals as $key => $total ) :
			if ( $total['type'] === 'subtotal' ) {
				$total['label'] = _n(
					'Subscription',
					'Subscriptions',
					count( $items ),
					'newspack-plugin'
				);
			}
			?>
			<tr>
				<th scope="row" <?php echo ( $allow_item_removal ) ? 'colspan="2"' : ''; ?>><?php echo esc_html( str_replace( ':', '', $total['label'] ) ); ?></th>
				<td class="<?php echo esc_attr( $total['type'] ); ?>"><?php echo wp_kses_post( $total['value'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tfoot>
	<?php endif; ?>
</table>
