<?php
/**
 * Custom subscription details table template.
 * - Always show "next payment" date, even if it's empty.
 * - Always show "payment method", even if no next payment date.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

$is_group_member_subscription = Group_Subscription::is_group_subscription( $subscription ) && Group_Subscription::user_is_member( get_current_user_id(), $subscription );

?>
<table class="shop_table subscription_details">
	<tbody>
		<?php // No status row. ?>
		<?php do_action( 'wcs_subscription_details_table_before_dates', $subscription ); ?>
		<?php
		$dates_to_display = apply_filters(
			'wcs_subscription_details_table_dates_to_display',
			[
				'start_date'              => _x( 'First payment', 'customer subscription table header', 'newspack-plugin' ),
				'last_order_date_created' => _x( 'Latest payment', 'customer subscription table header', 'newspack-plugin' ),
				'next_payment'            => _x( 'Next payment', 'customer subscription table header', 'newspack-plugin' ),
				'end'                     => _x( 'End date', 'customer subscription table header', 'newspack-plugin' ),
				'trial_end'               => _x( 'Trial end date', 'customer subscription table header', 'newspack-plugin' ),
			],
			$subscription
		);
		foreach ( $dates_to_display as $date_type => $date_title ) :
			?>
			<?php $date = $subscription->get_date( $date_type ); ?>
			<?php if ( ! empty( $date ) || $date_type === 'next_payment' ) : ?>
				<tr>
					<td><?php echo esc_html( $date_title ); ?></td>
					<td><?php echo esc_html( empty( $date ) ? '—' : $subscription->get_date_to_display( $date_type ) ); ?></td>
				</tr>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php do_action( 'wcs_subscription_details_table_after_dates', $subscription ); ?>
		<?php if ( \WCS_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal( $subscription ) ) : ?>
			<tr>
				<td><?php esc_html_e( 'Auto renew', 'newspack-plugin' ); ?></td>
				<td>
					<div class="wcs-auto-renew-toggle">
						<?php

						$toggle_classes = array( 'subscription-auto-renew-toggle', 'subscription-auto-renew-toggle--hidden' );

						if ( $subscription->is_manual() ) {
							$toggle_label     = __( 'Enable auto renew', 'newspack-plugin' );
							$toggle_classes[] = 'subscription-auto-renew-toggle--off';

							if ( \WCS_Staging::is_duplicate_site() ) {
								$toggle_classes[] = 'subscription-auto-renew-toggle--disabled';
							}
						} else {
							$toggle_label     = __( 'Disable auto renew', 'newspack-plugin' );
							$toggle_classes[] = 'subscription-auto-renew-toggle--on';
						}
						?>
						<a href="#" class="<?php echo esc_attr( implode( ' ', $toggle_classes ) ); ?>" aria-label="<?php echo esc_attr( $toggle_label ); ?>"><i class="subscription-auto-renew-toggle__i" aria-hidden="true"></i></a>
						<?php if ( \WCS_Staging::is_duplicate_site() ) : ?>
								<small class="subscription-auto-renew-toggle-disabled-note"><?php echo esc_html__( 'Using the auto-renewal toggle is disabled while in staging mode.', 'newspack-plugin' ); ?></small>
						<?php endif; ?>
					</div>
				</td>
			</tr>
		<?php endif; ?>
		<?php
		if ( $is_group_member_subscription ) :
			$owner = get_user_by( 'id', $subscription->get_user_id() );
			if ( $owner ) :
				?>
				<tr>
					<td><?php esc_html_e( 'Subscription owner', 'newspack-plugin' ); ?></td>
					<td>
						<?php
						echo wp_kses_post(
							'<a href="mailto:' . esc_attr( sanitize_email( $owner->user_email ) ) . '">' . esc_html( $owner->display_name ? $owner->display_name : $owner->user_email ) . '</a>'
						);
						?>
					</td>
				</tr>
			<?php endif; ?>
		<?php else : ?>
			<?php do_action( 'wcs_subscription_details_table_before_payment_method', $subscription ); ?>
		<tr>
			<td><?php esc_html_e( 'Payment method', 'newspack-plugin' ); ?></td>
			<td>
				<span data-is_manual="<?php echo esc_attr( wc_bool_to_string( $subscription->is_manual() ) ); ?>" class="subscription-payment-method"><?php echo esc_html( $subscription->get_payment_method_to_display( 'customer' ) ); ?></span>
			</td>
		</tr>
		<?php endif; ?>
		<?php do_action( 'woocommerce_subscription_before_actions', $subscription ); ?>
		<?php // Action buttons moved to Newspack's subscription-header.php template. ?>
		<?php do_action( 'woocommerce_subscription_after_actions', $subscription ); ?>
	</tbody>
</table>

<?php
$notes = ! $is_group_member_subscription ? $subscription->get_customer_order_notes() : false;
if ( $notes ) :
	?>
	<h2><?php esc_html_e( 'Subscription updates', 'newspack-plugin' ); ?></h2>
	<ol class="woocommerce-OrderUpdates commentlist notes">
		<?php foreach ( $notes as $note ) : ?>
		<li class="woocommerce-OrderUpdate comment note">
			<div class="woocommerce-OrderUpdate-inner comment_container">
				<div class="woocommerce-OrderUpdate-text comment-text">
					<p class="woocommerce-OrderUpdate-meta meta"><?php echo esc_html( date_i18n( _x( 'l jS \o\f F Y, h:ia', 'date on subscription updates list. Will be localized', 'newspack-plugin' ), wcs_date_to_time( $note->comment_date ) ) ); ?></p>
					<div class="woocommerce-OrderUpdate-description description">
						<?php echo wp_kses_post( wpautop( wptexturize( $note->comment_content ) ) ); ?>
					</div>
						<div class="clear"></div>
					</div>
				<div class="clear"></div>
			</div>
		</li>
		<?php endforeach; ?>
	</ol>
<?php endif; ?>
