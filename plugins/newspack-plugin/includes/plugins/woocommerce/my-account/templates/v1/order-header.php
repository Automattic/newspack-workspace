<?php
/**
 * Header for My Account single order pages: the order number, its status, the date it was placed,
 * the subscriptions it pays for and its actions, followed by a notice to pay when the order is payable.
 * Note that this template is not a standard WooCommerce template.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

$order   = $args['order']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$actions = $args['actions'];

$pay_action = $actions['pay'] ?? null;
unset( $actions['pay'] );
if ( $pay_action ) {
	$amount             = html_entity_decode( \wp_strip_all_tags( \wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES, 'UTF-8' );
	$pay_action['name'] = sprintf(
		/* translators: %s: the amount due. */
		__( 'Pay %s', 'newspack-plugin' ),
		$amount
	);
}

if ( ! empty( $actions['cancel'] ) ) {
	$cancel_action         = $actions['cancel'];
	$cancel_action['name'] = __( 'Cancel order', 'newspack-plugin' );
	unset( $actions['cancel'] );
	$actions['cancel'] = $cancel_action;
}

ob_start();
\woocommerce_order_again_button( $order );
$order_again = trim( ob_get_clean() );

$order_status    = $order->get_status();
$status_modifier = My_Account_UI_V1::get_status_modifier( $order_status );
$badge_classes   = [ 'newspack-ui__badge', 'newspack-ui__badge--' . ( $status_modifier ? $status_modifier : 'secondary' ) ];
$date_created    = $order->get_date_created();
?>
<header class="newspack-my-account__subscription--header newspack-my-account__order-header newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--vertical-below-mobile newspack-ui__stack--wrap newspack-ui__stack--justify-between newspack-ui__stack--align-start newspack-ui__stack--gap-4">
	<div class="newspack-my-account__order-heading newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
		<div class="newspack-my-account__subscription--title newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--align-center newspack-ui__stack--gap-2">
			<?php if ( isset( \wc_get_account_menu_items()['orders'] ) ) : ?>
				<a href="<?php echo \esc_url( \wc_get_account_endpoint_url( 'orders' ) ); ?>" class="newspack-my-account__subscription--back-link newspack-ui__button newspack-ui__button--ghost newspack-ui__button--icon newspack-ui__button--small newspack-ui__spacing-bottom--0" title="<?php \esc_attr_e( 'Back to orders', 'newspack-plugin' ); ?>" aria-label="<?php \esc_attr_e( 'Back to orders', 'newspack-plugin' ); ?>">
					<?php Newspack_UI_Icons::print_svg( 'chevronLeft' ); ?>
				</a>
			<?php endif; ?>
			<h1 class="newspack-my-account__page-title newspack-ui__font--l">
				<?php
				/* translators: %s: the order number. */
				echo \esc_html( sprintf( __( 'Order #%s', 'newspack-plugin' ), $order->get_order_number() ) );
				?>
			</h1>
			<span class="<?php echo \esc_attr( implode( ' ', $badge_classes ) ); ?>">
				<?php echo \esc_html( My_Account_UI_V1::get_order_status_label( $order ) ); ?>
			</span>
		</div>
		<?php if ( $date_created ) : ?>
			<p class="newspack-my-account__order-date newspack-ui__font--xs">
				<?php
				/* translators: %s: the date the order was placed. */
				echo \esc_html( sprintf( __( 'Placed on %s', 'newspack-plugin' ), \wc_format_datetime( $date_created ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php foreach ( My_Account_UI_V1::get_order_subscription_lines( $order ) as $subscription_line ) : ?>
			<p class="newspack-my-account__order-subscription newspack-ui__font--xs"><?php echo $subscription_line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?></p>
		<?php endforeach; ?>
	</div>
	<?php if ( ! empty( $actions ) || $order_again ) : ?>
		<div class="newspack-my-account__subscription--actions newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--vertical-below-mobile newspack-ui__stack--wrap newspack-ui__stack--gap-1">
			<?php $order_again_printed = false; ?>
			<?php foreach ( $actions as $key => $action_link ) : ?>
				<?php
				if ( ! $order_again_printed ) {
					echo $order_again; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup from the escaped order-again template.
					$order_again_printed = true;
				}
				$classes = [
					'newspack-ui__button',
					'newspack-ui__button--small',
					'newspack-ui__button--medium-on-mobile',
					'newspack-ui__button--outline',
					\sanitize_html_class( $key ),
				];
				if ( 'cancel' === $key ) {
					$classes[] = 'newspack-ui__button--destructive';
				}
				$aria_label = 'cancel' === $key || empty( $action_link['aria-label'] ) ? '' : $action_link['aria-label'];
				?>
				<a class="<?php echo \esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo \esc_url( $action_link['url'] ); ?>"<?php echo $aria_label ? ' aria-label="' . \esc_attr( $aria_label ) . '"' : ''; ?>><?php echo \esc_html( $action_link['name'] ); ?></a>
			<?php endforeach; ?>
			<?php
			if ( ! $order_again_printed ) {
				echo $order_again; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup from the escaped order-again template.
			}
			?>
		</div>
	<?php endif; ?>
</header>
<?php if ( $pay_action ) : ?>
	<div class="newspack-ui__notice newspack-ui__notice--warning newspack-my-account__order-payment-notice" role="status">
		<?php Newspack_UI::print_notice_icon( 'warning' ); ?>
		<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--align-start newspack-ui__stack--gap-2">
			<p><?php echo \esc_html( WooCommerce_Update_Payment_Notice::get_order_payment_message( $order, $amount ) ); ?></p>
			<a class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--small newspack-ui__button--medium-on-mobile pay" href="<?php echo \esc_url( $pay_action['url'] ); ?>"><?php echo \esc_html( $pay_action['name'] ); ?></a>
		</div>
	</div>
<?php endif; ?>
